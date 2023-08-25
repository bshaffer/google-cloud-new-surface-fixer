<?php

namespace Google\Cloud\Tools;

use PhpCsFixer\AbstractFixer;
use PhpCsFixer\Fixer\FixerInterface;
use PhpCsFixer\Fixer\Import\OrderedImportsFixer;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Tokenizer\Analyzer\NamespaceUsesAnalyzer;
use PhpCsFixer\Tokenizer\Analyzer\Analysis\NamespaceUseAnalysis;
use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;
use ReflectionClass;
use ReflectionMethod;

class NewSurfaceFixer extends AbstractFixer
{
    /**
     * Check if the fixer is a candidate for given Tokens collection.
     *
     * Fixer is a candidate when the collection contains tokens that may be fixed
     * during fixer work. This could be considered as some kind of bloom filter.
     * When this method returns true then to the Tokens collection may or may not
     * need a fixing, but when this method returns false then the Tokens collection
     * need no fixing for sure.
     */
    public function isCandidate(Tokens $tokens): bool
    {
        return true;
    }

    protected function applyFix(\SplFileInfo $file, Tokens $tokens): void
    {
        if (!class_exists('Google\Auth\OAuth2')) {
            throw new \LogicException(
                'In order for Google\Cloud\NewSurfaceFixer to work, you must install the google '
                . 'cloud client library and include its autoloader in .php-cs-fixer.dist.php'
            );
        }

        $clients = [];
        $useDeclarations = UseStatement::getUseDeclarations($tokens);
        foreach (UseStatement::getImportedClients($useDeclarations) as $clientClass => $useDeclaration) {
            $newClientName = ClientVar::getNewClassFromClassname($clientClass);
            if (class_exists($newClientName)) {
                // Rename old clients to new namespaces
                $tokens->overrideRange(
                    $useDeclaration->getStartIndex(),
                    $useDeclaration->getEndIndex(),
                    UseStatement::getTokensFromClassName($newClientName)
                );
                $clients[] = $clientClass;
            }
        }

        // Get variable names for all clients
        $clientShortNames = [];
        foreach ($clients as $clientClass) {
            // Save the client shortnames so we can search for them below
            $parts = explode('\\', $clientClass);
            $shortName = array_pop($parts);
            $clientShortNames[$clientClass] = $shortName;
        }
        $clientVars = array_merge(
            ClientVar::getClientVarsFromNewKeyword($tokens, $clientShortNames),
            ClientVar::getClientVarsFromVarTypehint($tokens, $clientShortNames),
        );

        // Find the RPC methods being called on the clients
        $classesToImport = [];
        $counter = new RequestVariableCounter();
        $importStart = $this->getImportStart($tokens);
        $insertStart = null;
        for ($index = 0; $index < count($tokens); $index++) {
            $clientVar = $clientVars[$tokens[$index]->getContent()] ?? null;
            if (is_null($clientVar)) {
                // The token does not contain a client var
                continue;
            }

            if (!$clientVar->isDeclaredAt($tokens, $index)) {
                // The token looks like our client var but isn't
                continue;
            }

            $operatorIndex = $tokens->getNextMeaningfulToken($index);
            if (!$tokens[$operatorIndex]->isGivenKind(T_OBJECT_OPERATOR)) {
                // The client var is not calling a method
                continue;
            }

            // The method being called by the client variable
            $methodIndex = $tokens->getNextMeaningfulToken($operatorIndex);
            if (!$rpcMethod = $clientVar->getRpcMethod($tokens[$methodIndex]->getContent())) {
                // The method doesn't exist, or is not an RPC call
                continue;
            }

            // Get the arguments being passed to the RPC method
            [$arguments, $firstIndex, $lastIndex] = $this->getRpcCallArguments($tokens, $methodIndex);

            // determine where to insert the new tokens
            $lineStart = $clientVar->getLineStart($tokens);

            // Handle differently when we are dealing with inline PHP
            $isInlinePhpCall = $tokens[$lineStart]->getId() === T_OPEN_TAG;

            $indent = '';
            if (!$isInlinePhpCall) {
                $indent = str_replace("\n", '', $tokens[$lineStart]->getContent());
            }

            $requestClass = $rpcMethod->getRequestClass();
            $requestVarName = $counter->getNextVariableName($requestClass->getShortName());

            // Tokens for the setters called on the new request object
            $requestSetterTokens = $this->getRequestSetterTokens($tokens, $rpcMethod, $arguments, $indent);

            // Tokens for initializing the new request variable
            $newRequestTokens = $this->getInitNewRequestTokens(
                $requestVarName,
                $requestClass->getShortName(),
                count($requestSetterTokens) > 0
            );

            // Add them together
            $newRequestTokens = array_merge(
                [new Token([T_WHITESPACE,  PHP_EOL . $indent])],
                $newRequestTokens,
                $requestSetterTokens,
                [new Token(';')]
            );

            // When inserting for inline PHP, add a newline before the first request variable
            if ($isInlinePhpCall && $counter->isFirstVar()) {
                array_unshift($newRequestTokens, new Token([T_WHITESPACE, PHP_EOL]));
            }

            // Determine where the request variable tokens should be inserted
            if ($isInlinePhpCall) {
                // If we are inline, insert right before the first closing PHP tag
                if (is_null($insertStart)) {
                    $insertStart = $tokens->getNextTokenOfKind($importStart, ['?>', [T_CLOSE_TAG]]) - 1;
                }
            } else {
                // else, insert at beginning of the line of the original RPC call
                $insertStart = $lineStart;
            }

            // insert the request variable tokens
            $tokens->insertAt($insertStart, $newRequestTokens);

            // Replace the original RPC call arguments with the new request variable
            $tokens->overrideRange(
                $firstIndex + 1 + count($newRequestTokens),
                $lastIndex - 1 + count($newRequestTokens),
                [new Token([T_VARIABLE, $requestVarName])]
            );

            // Increment the current $index and $insertStart
            $index = $firstIndex + 1 + count($newRequestTokens);
            if ($isInlinePhpCall) {
                $insertStart = $insertStart + count($newRequestTokens);
            }

            // Add the request class to be imported later
            $classesToImport[$requestClass->getName()] = $requestClass;
        }

        // Import the new request classes
        if ($classesToImport) {
            $requestClassImportTokens = array_map(
                fn($requestClass) => $requestClass->getImportTokens(),
                array_values($classesToImport)
            );
            $tokens->insertAt($importStart, array_merge(...$requestClassImportTokens));
            // Ensure new imports are in the correct order
            $orderFixer = new OrderedImportsFixer();
            $orderFixer->fix($file, $tokens);
        }
    }

    private function getInitNewRequestTokens(
        string $requestVarName,
        string $requestClassShortName,
        bool $parenthesis
    ) {
        // Add the code for creating the $request variable
        return array_filter([
            new Token([T_VARIABLE, $requestVarName]),
            new Token([T_WHITESPACE, ' ']),
            new Token('='),
            new Token([T_WHITESPACE, ' ']),
            $parenthesis ? new Token('(') : null,
            new Token([T_NEW, 'new']),
            new Token([T_WHITESPACE, ' ']),
            new Token([T_STRING, $requestClassShortName]),
            new Token('('),
            new Token(')'),
            $parenthesis ? new Token(')') : null,
        ]);
    }

    private function getRequestSetterTokens(Tokens $tokens, RpcMethod $rpcMethod, array $arguments, string $indent)
    {
        $argIndex = 0;
        $requestSetterTokens = [];
        foreach ($arguments as $startIndex => $argumentTokens) {
            $setters = $this->getSettersFromTokens($tokens, $rpcMethod, $startIndex, $argIndex, $argumentTokens);
            foreach ($setters as $setter) {
                $requestSetterTokens = array_merge(
                    $requestSetterTokens,
                    $this->getTokensForSetter($setter, $indent)
                );
            }
            $argIndex++;
        }
        return $requestSetterTokens;
    }

    private function getSettersFromTokens(
        Tokens $tokens,
        RpcMethod $rpcMethod,
        int $startIndex,
        int $argIndex,
        array $argumentTokens
    ): array {
        $setters = [];
        $setterName = null;
        if ($rpcParameter = $rpcMethod->getParameterAtIndex($argIndex)) {
            // handle array of optional args!
            if ($rpcParameter->isOptionalArgs()) {
                $argumentStart = $tokens->getNextMeaningfulToken($startIndex);
                if ($tokens[$argumentStart]->isGivenKind(CT::T_ARRAY_SQUARE_BRACE_OPEN)) {
                    // If it's an inline array, use the keys to determine the setters
                    $closeIndex = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_ARRAY_SQUARE_BRACE, $argumentStart);
                    $arrayEntries = $this->getSettersFromInlineArray($tokens, $argumentStart, $closeIndex);

                    // Add a setter for each top-level array entry
                    $arrayEntryIndices = array_keys($arrayEntries);
                    $prevStart = $argumentStart;
                    foreach ($arrayEntryIndices as $i => $doubleArrowIndex) {
                        $keyIndex = $tokens->getNextMeaningfulToken($prevStart);
                        if (!$tokens[$keyIndex]->isGivenKind(T_CONSTANT_ENCAPSED_STRING)) {
                            continue;
                        }
                        $setterName = 'set' . ucfirst(trim($tokens[$keyIndex]->getContent(), '"\''));
                        $tokens->removeLeadingWhitespace($doubleArrowIndex + 1);
                        $valueEnd = isset($arrayEntryIndices[$i+1])
                            ? $tokens->getPrevTokenOfKind($arrayEntryIndices[$i+1], [new Token(',')])
                            : $closeIndex;
                        $varTokens = array_slice($tokens->toArray(), $doubleArrowIndex + 1, $valueEnd - $doubleArrowIndex - 1);
                        // Remove trailing whitespace
                        for ($i = count($varTokens)-1; $varTokens[$i]->isGivenKind(T_WHITESPACE); $i--) {
                            unset($varTokens[$i]);
                        }
                        // Remove trailing commas
                        for ($i = count($varTokens)-1; $varTokens[$i]->getContent() === ','; $i--) {
                            unset($varTokens[$i]);
                        }
                        // Remove leading whitespace
                        for ($i = 0; $varTokens[$i]->isGivenKind(T_WHITESPACE); $i++) {
                            unset($varTokens[$i]);
                        }
                        $setters[] = [$setterName, $varTokens];
                        $prevStart = $valueEnd;
                    }
                } elseif ($tokens[$argumentStart]->isGivenKind(T_VARIABLE)) {
                    // if an array is being passed in, find where the array is defined and then do the same
                    $optionalArgsVar = $tokens[$argumentStart]->getContent();
                    for ($index = $argumentStart - 1; $index > 0; $index--) {
                        $token = $tokens[$index];
                        // Find where the optionalArgs variable is defined
                        if ($token->isGivenKind(T_VARIABLE) && $token->getContent() == $optionalArgsVar) {
                            $nextIndex = $tokens->getNextMeaningfulToken($index);
                            if ($tokens[$nextIndex]->getContent() == '=') {
                                $argumentStart = $tokens->getNextMeaningfulToken($nextIndex);
                                if ($tokens[$argumentStart]->isGivenKind(CT::T_ARRAY_SQUARE_BRACE_OPEN)) {
                                    $closeIndex = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_ARRAY_SQUARE_BRACE, $argumentStart);
                                    $arrayEntries = $this->getSettersFromInlineArray($tokens, $argumentStart, $closeIndex);
                                    // Add a setter for each top-level array entry
                                    $arrayEntryIndices = array_keys($arrayEntries);
                                    $prevStart = $argumentStart;
                                    foreach ($arrayEntryIndices as $i => $doubleArrowIndex) {
                                        $keyIndex = $tokens->getNextMeaningfulToken($prevStart);
                                        if ($tokens[$keyIndex]->isGivenKind(T_CONSTANT_ENCAPSED_STRING)) {
                                            $setterName = 'set' . ucfirst(trim($tokens[$keyIndex]->getContent(), '"\''));
                                            $varTokens = [
                                                new Token([T_VARIABLE, $optionalArgsVar]),
                                                new Token([CT::T_ARRAY_SQUARE_BRACE_OPEN, '[']),
                                                clone $tokens[$keyIndex],
                                                new Token([CT::T_ARRAY_SQUARE_BRACE_CLOSE, ']']),
                                            ];
                                            $setters[] = [$setterName, $varTokens];
                                            $valueEnd = isset($arrayEntryIndices[$i+1])
                                                ? $tokens->getPrevTokenOfKind($arrayEntryIndices[$i+1], [new Token(',')])
                                                : $closeIndex;
                                            $prevStart = $valueEnd;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            } else {
                // Just place the argument tokens in a setter
                $setterName = $rpcParameter->getSetter();
                // Remove leading whitespace
                for ($i = 0; $argumentTokens[$i]->isGivenKind(T_WHITESPACE); $i++) {
                    unset($argumentTokens[$i]);
                }
                return [[$setterName, $argumentTokens]];
            }
        } else {
            // print('Could not find argument for ' . $clientFullName . '::' . $rpcName . ' at index ' . $argIndex);
        }

        return $setters;
    }

    private function getSettersFromInlineArray(Tokens $tokens, int $argumentStart, int $closeIndex)
    {
        $arrayEntries = $tokens->findGivenKind(T_DOUBLE_ARROW, $argumentStart, $closeIndex);
        $nestedArrays = $tokens->findGivenKind(CT::T_ARRAY_SQUARE_BRACE_OPEN, $argumentStart + 1, $closeIndex);

        // skip nested arrays
        foreach ($arrayEntries as $doubleArrowIndex => $doubleArrowIndexToken) {
            foreach ($nestedArrays as $nestedArrayIndex => $nestedArrayIndexToken) {
                $nestedArrayCloseIndex = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_ARRAY_SQUARE_BRACE, $nestedArrayIndex);
                if ($doubleArrowIndex > $nestedArrayIndex && $doubleArrowIndex < $nestedArrayCloseIndex) {
                    unset($arrayEntries[$doubleArrowIndex]);
                }
            }
        }

        return $arrayEntries;
    }

    private function getRpcCallArguments(Tokens $tokens, int $startIndex)
    {
        $arguments = [];
        $nextIndex = $tokens->getNextMeaningfulToken($startIndex);
        $lastIndex = null;
        if ($tokens[$nextIndex]->getContent() == '(') {
            $startIndex = $nextIndex;
            $lastIndex = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS_BRACE, $nextIndex);
            $nextArgumentEnd = $this->getNextArgumentEnd($tokens, $nextIndex);
            while ($nextArgumentEnd != $nextIndex) {
                $argumentTokens = [];
                for ($i = $nextIndex + 1; $i <= $nextArgumentEnd; $i++) {
                    $argumentTokens[] = $tokens[$i];
                }

                $arguments[$nextIndex] = $argumentTokens;
                $nextIndex = $tokens->getNextMeaningfulToken($nextArgumentEnd);
                $nextArgumentEnd = $this->getNextArgumentEnd($tokens, $nextIndex);
            }
        }

        return [$arguments, $startIndex, $lastIndex];
    }

    private function getNextArgumentEnd(Tokens $tokens, int $index): int
    {
        $nextIndex = $tokens->getNextMeaningfulToken($index);
        $nextToken = $tokens[$nextIndex];

        while ($nextToken->equalsAny([
            '$',
            '[',
            '(',
            [CT::T_ARRAY_INDEX_CURLY_BRACE_OPEN],
            [CT::T_ARRAY_SQUARE_BRACE_OPEN],
            [CT::T_DYNAMIC_PROP_BRACE_OPEN],
            [CT::T_DYNAMIC_VAR_BRACE_OPEN],
            [CT::T_NAMESPACE_OPERATOR],
            [T_NS_SEPARATOR],
            [T_STATIC],
            [T_STRING],
            [T_CONSTANT_ENCAPSED_STRING],
            [T_VARIABLE],
            [T_NEW],
        ])) {
            $blockType = Tokens::detectBlockType($nextToken);

            if (null !== $blockType) {
                $nextIndex = $tokens->findBlockEnd($blockType['type'], $nextIndex);
            }

            $index = $nextIndex;
            $nextIndex = $tokens->getNextMeaningfulToken($nextIndex);
            $nextToken = $tokens[$nextIndex];
        }

        if ($nextToken->isGivenKind(T_OBJECT_OPERATOR)) {
            return $this->getNextArgumentEnd($tokens, $nextIndex);
        }

        if ($nextToken->isGivenKind(T_PAAMAYIM_NEKUDOTAYIM)) {
            return $this->getNextArgumentEnd($tokens, $tokens->getNextMeaningfulToken($nextIndex));
        }

        if ('"' === $nextToken->getContent()) {
            if ($endIndex = $tokens->getNextTokenOfKind($nextIndex + 1, ['"'])) {
                return $endIndex;
            }
        }

        return $index;
    }

    private function getImportStart(Tokens $tokens)
    {
        $useDeclarations = UseStatement::getUseDeclarations($tokens);
        if (count($useDeclarations) > 0) {
            return $useDeclarations[count($useDeclarations) - 1]->getEndIndex() + 1;
        }

        // There will be no changes made if there are no imports, so this logic
        // should not matter

        return $tokens->getNextMeaningfulToken(0);
    }

    private function getTokensForSetter(array $setter, string $indent): array
    {
        list($method, $varTokens) = $setter;

        $tokens = [
            // whitespace (assume 4 spaces)
            new Token([T_WHITESPACE, PHP_EOL . $indent . '    ']),
            // setter method
            new Token([T_OBJECT_OPERATOR, '->']),
            new Token([T_STRING, $method]),
            // setter value
            new Token('('),
        ];
        // merge in var tokens
        $tokens = array_merge($tokens, $varTokens);

        // add closing parenthesis
        $tokens[] = new Token(')');

        return $tokens;
    }

    /**
     * Returns the definition of the fixer.
     */
    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition('Upgrade code to the new Google Cloud PHP client surface', []);
    }

    /**
     * Returns the name of the fixer.
     *
     * The name must be all lowercase and without any spaces.
     *
     * @return string The name of the fixer
     */
    public function getName(): string
    {
        return 'GoogleCloud/new_surface_fixer';
    }

    /**
     * {@inheritdoc}
     *
     * Must run before OrderedImportsFixer.
     */
    public function getPriority(): int
    {
        return 0;
    }
}

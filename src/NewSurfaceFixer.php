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
        $useDeclarations = UseStatement::getUseDeclarations($tokens);
        $clients = [];
        foreach (UseStatement::getImportedClients($useDeclarations) as $clientClass => $useDeclaration) {
            $newClientName = ClientVar::getNewClassFromClassname($clientClass);
            if (class_exists($newClientName)) {
                // Rename old clients to new namespaces
                $tokens->overrideRange(
                    $useDeclaration->getStartIndex(),
                    $useDeclaration->getEndIndex(),
                    UseStatement::getTokensFromClassName($newClientName)
                );
                // Save the client names so we know what we changed
                $parts = explode('\\', $clientClass);
                $shortName = array_pop($parts);
                $clients[$clientClass] = $shortName;
            }
        }

        // Get variable names for all clients
        $clientVars = ClientVar::getClientVarsFromTokens($tokens, $clients);

        // Find the RPC methods being called on the clients
        $requestClasses = [];
        $counter = new RequestVariableCounter();
        $lastInsertEnd = null; // only used when inserting $request vars after use statements (for inline HTML)
        for ($index = 0; $index < count($tokens); $index++) {
            $clientVar = $clientVars[$tokens[$index]->getContent()] ?? null;
            if ($clientVar && $clientVar->isDeclaredAt($tokens, $index)) {
                $clientStartIndex = $index;
                $nextIndex = $tokens->getNextMeaningfulToken($index);
                $nextToken = $tokens[$nextIndex];
                if ($nextToken->isGivenKind(T_OBJECT_OPERATOR)) {
                    // Get the method being called by the client variable
                    $nextIndex = $tokens->getNextMeaningfulToken($nextIndex);
                    $nextToken = $tokens[$nextIndex];
                    $rpcName = $nextToken->getContent();

                    // Get the Request class name
                    if (!$rpcMethod = $clientVar->getRpcMethod($rpcName)) {
                        // The method doesn't exist, or is not an RPC call
                        continue;
                    }
                    $requestClasses[] = $requestClass = $rpcMethod->getRequestClass();

                    // Get the arguments being passed to the RPC method
                    [$arguments, $firstIndex, $lastIndex] = $this->getRpcCallArguments($tokens, $nextIndex);

                    // determine the indent
                    $indent = '';
                    $lineStart = $clientStartIndex;
                    $i = 1;
                    while (
                        $clientStartIndex - $i >= 0
                        && false === strpos($tokens[$clientStartIndex - $i]->getContent(), "\n")
                        && $tokens[$clientStartIndex - $i]->getId() !== T_OPEN_TAG
                    ) {
                        $i++;
                    }
                    // Handle differently when we are dealing with inline PHP
                    $newlineIsStartTag = $tokens[$clientStartIndex - $i]->getId() === T_OPEN_TAG;

                    if ($clientStartIndex - $i >= 0) {
                        if ($newlineIsStartTag) {
                            if (is_null($lastInsertEnd)) {
                                $useDeclarationEnd = $useDeclarations[count($useDeclarations) - 1]->getEndIndex() + 1;
                                if ($lastInsertEnd = $tokens->getNextTokenOfKind($useDeclarationEnd, ['?>', [T_CLOSE_TAG]])) {
                                    $lastInsertEnd--;
                                } else {
                                    // Fallback to after use statements (shouldn't ever happen)
                                    $lastInsertEnd = $useDeclarationEnd;
                                }
                            }
                            $lineStart = $lastInsertEnd;
                        } else {
                            $lineStart = $clientStartIndex - $i;
                            $indent = str_replace("\n", '', $tokens[$clientStartIndex - $i]->getContent());
                        }
                    }

                    $argIndex = 0;
                    $numSetterCalls = 0;
                    $requestSetterTokens = [];
                    foreach ($arguments as $startIndex => $argument) {
                        foreach ($this->getSettersFromToken($tokens, $clientVar->className, $rpcName, $startIndex, $argIndex, $argument) as $setter) {
                            $numSetterCalls++;
                            list($method, $varTokens) = $setter;
                            // whitespace (assume 4 spaces)
                            $requestSetterTokens[] = new Token([T_WHITESPACE, PHP_EOL . $indent . '    ']);
                            // setter method
                            $requestSetterTokens[] = new Token([T_OBJECT_OPERATOR, '->' . $method]);
                            // setter value
                            $requestSetterTokens[] = new Token('(');
                            $requestSetterTokens = array_merge($requestSetterTokens, $varTokens);
                            $requestSetterTokens[] = new Token(')');
                        }
                        $argIndex++;
                    }
                    $requestSetterTokens[] = new Token(';');
                    $requestVarName = $counter->getNextVariableName($requestClass->getShortName());
                    // Tokens for the "$request" variable
                    $initRequestVarTokens = $this->getBuildRequestTokens(
                        $indent,
                        $requestVarName,
                        $requestClass->getShortName(),
                        $numSetterCalls > 0
                    );
                    if ($newlineIsStartTag && count($requestClasses) == 1) {
                        // add a newline before $request variable when adding just after use statements
                        // NOTE: This is done for inline HTML
                        array_unshift($initRequestVarTokens, new Token([T_WHITESPACE, PHP_EOL]));
                    }
                    $buildRequestTokens = array_merge($initRequestVarTokens, $requestSetterTokens);

                    $tokens->insertAt($lineStart, $buildRequestTokens);
                    if ($newlineIsStartTag) {
                        $lastInsertEnd = $lineStart + count($buildRequestTokens);
                    }

                    // Replace the arguments with $request
                    $tokens->overrideRange(
                        $firstIndex + 1 + count($buildRequestTokens),
                        $lastIndex - 1 + count($buildRequestTokens),
                        [new Token([T_VARIABLE, $requestVarName])]
                    );
                    $index = $firstIndex + 1 + count($buildRequestTokens);
                }
            }
        }

        // Add the request namespaces
        $requestClassImportTokens = [];
        foreach (array_unique($requestClasses) as $requestClass) {
            $requestClassImportTokens[] = new Token([T_WHITESPACE, PHP_EOL]);
            $requestClassImportTokens = array_merge(
                $requestClassImportTokens,
                UseStatement::getTokensFromClassName($requestClass->getName())
            );
        }
        if ($requestClassImportTokens) {
            if ($lastUse = array_pop($useDeclarations)) {
                $insertAt = $lastUse->getEndIndex() + 1;
            } else {
                // @TODO: Support adding new use statements when no imports exist
                return;
            }
            $tokens->insertAt($insertAt, $requestClassImportTokens);
            // Ensure new imports are in the correct order
            $orderFixer = new OrderedImportsFixer();
            $orderFixer->fix($file, $tokens);
        }
    }

    private function getBuildRequestTokens(
        string $indent,
        string $requestVarName,
        string $requestClassShortName,
        bool $parenthesis
    ) {
        // Add the code for creating the $request variable
        return array_filter([
            new Token([T_WHITESPACE,  PHP_EOL . $indent]),
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

    private function getSettersFromToken($tokens, string $clientFullName, string $rpcName, int $startIndex, int $argIndex, array $argumentTokens): array
    {
        $setters = [];
        $setterName = null;
        if (method_exists($clientFullName, $rpcName)) {
            $rpcMethod = new RpcMethod($clientFullName, $rpcName);
            if ($rpcParameter  = $rpcMethod->getParameterAtIndex($argIndex)) {
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
                            if ($tokens[$keyIndex]->isGivenKind(T_CONSTANT_ENCAPSED_STRING)) {
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
        } else {
            // print('Could not find method ' . $clientFullName . '::' . $rpcName);
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

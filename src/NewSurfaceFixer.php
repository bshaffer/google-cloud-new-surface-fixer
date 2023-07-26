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
        $useDeclarations = (new NamespaceUsesAnalyzer())->getDeclarationsFromTokens($tokens);

        $clients = [];
        // Change to new namespace
        foreach ($tokens->getNamespaceDeclarations() as $namespace) {
            foreach ($useDeclarations as $useDeclaration) {
                $clientClass = $useDeclaration->getFullName();
                $clientShortName = $useDeclaration->getShortName();
                if (
                    0 === strpos($clientClass, 'Google\\')
                    && 'Client' === substr($clientShortName, -6)
                    && false === strpos($clientClass, '\\Client\\')
                    && class_exists($clientClass)
                ) {
                    if (false !== strpos(get_parent_class($clientClass), '\Gapic\\')) {
                        $parts = explode('\\', $clientClass);
                        $shortName = array_pop($parts);
                        $newClientName = $this->getNewClientClass($clientClass);
                        if (class_exists($newClientName)) {
                            $clients[$clientClass] = $clientShortName;
                            $tokens->overrideRange(
                                $useDeclaration->getStartIndex(),
                                $useDeclaration->getEndIndex(),
                                $this->getUseStatementTokensFromClassName($newClientName)
                            );
                        }
                    }
                }
            }
        }

        // Get variable names for all clients
        $clientVars = [];
        foreach ($tokens as $index => $token) {
            if ($token->isGivenKind(T_NEW)) {
                $nextToken = $tokens[$tokens->getNextMeaningfulToken($index)];
                if (in_array($nextToken->getContent(), $clients)) {
                    if ($prevIndex = $tokens->getPrevMeaningfulToken($index)) {
                        if ($tokens[$prevIndex]->getContent() === '=') {
                            if ($prevIndex = $tokens->getPrevMeaningfulToken($prevIndex)) {
                                if ($tokens[$prevIndex]->isGivenKind(T_VARIABLE)) {
                                    $clientFullName = array_search($nextToken->getContent(), $clients);
                                    $clientVars[$clientFullName] = $tokens[$prevIndex]->getContent();
                                }
                            }
                        }
                    }
                }
            }
        }

        // Find the RPC methods being called on the clients
        $requestClasses = [];
        $rpcCallCount = 0;
        $lastInsertEnd = null; // only used when inserting $request vars after use statements (for inline HTML)
        for ($index = 0; $index < count($tokens); $index++) {
            $token = $tokens[$index];
            if ($token->isGivenKind(T_VARIABLE)) {
                if (in_array($token->getContent(), $clientVars)) {
                    $clientStartIndex = $index;
                    $nextIndex = $tokens->getNextMeaningfulToken($index);
                    $nextToken = $tokens[$nextIndex];
                    if ($nextToken->isGivenKind(T_OBJECT_OPERATOR)) {
                        // Get the method being called by the client variable
                        $nextIndex = $tokens->getNextMeaningfulToken($nextIndex);
                        $nextToken = $tokens[$nextIndex];
                        $clientFullName = array_search($token->getContent(), $clientVars);
                        [$arguments, $firstIndex, $lastIndex] = $this->getRpcCallArguments($tokens, $nextIndex);
                        $rpcName = $nextToken->getContent();

                        // Get the Request class name
                        $newClientClass = $this->getNewClientClass($clientFullName);
                        if (!method_exists($newClientClass, $rpcName)) {
                            // If the method doesn't exist, there's nothing we can do
                            continue;
                        }
                        $method = new ReflectionMethod($newClientClass, $rpcName);
                        $parameters = $method->getParameters();
                        if (!isset($parameters[0]) || !$type = $parameters[0]->getType()) {
                            continue;
                        }
                        if ($type->isBuiltin()) {
                            // If the first parameter is a primitive type, assume this is a helper method
                            continue;
                        }
                        $rpcCallCount++;
                        $requestClass = $type->getName();
                        $requestShortName = (new ReflectionClass($requestClass))->getShortName();
                        $requestClasses[] = $requestClass;

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

                        // Tokens for the "$request" variable
                        $buildRequestTokens = [];
                        if ($newlineIsStartTag && $rpcCallCount == 1) {
                            // add a newline when adding just after use statements
                            $buildRequestTokens[] = new Token([T_WHITESPACE, PHP_EOL]);
                        }
                        // determine $request variable name depending on call count
                        $requestVarName = '$request' . ($rpcCallCount == 1 ? '' : $rpcCallCount);

                        // Add the code for creating the $request variable and setting its properties
                        $buildRequestTokens[] = new Token([T_WHITESPACE,  PHP_EOL . $indent]);
                        $buildRequestTokens[] = new Token([T_VARIABLE, $requestVarName]);
                        $buildRequestTokens[] = new Token([T_WHITESPACE, ' ']);
                        $buildRequestTokens[] = new Token('=');
                        $buildRequestTokens[] = new Token([T_WHITESPACE, ' ']);
                        $buildRequestTokens[] = new Token('(');
                        $buildRequestTokens[] = new Token([T_NEW, 'new']);
                        $buildRequestTokens[] = new Token([T_WHITESPACE, ' ']);
                        $buildRequestTokens[] = new Token([T_STRING, $requestShortName]);
                        $buildRequestTokens[] = new Token('(');
                        $buildRequestTokens[] = new Token(')');
                        $buildRequestTokens[] = new Token(')');
                        $argIndex = 0;
                        foreach ($arguments as $startIndex => $argument) {
                            foreach ($this->getSettersFromToken($tokens, $clientFullName, $rpcName, $startIndex, $argIndex, $argument) as $setter) {
                                list($method, $varTokens) = $setter;
                                // whitespace (assume 4 spaces)
                                $buildRequestTokens[] = new Token([T_WHITESPACE, PHP_EOL . $indent . '    ']);
                                // setter method
                                $buildRequestTokens[] = new Token([T_OBJECT_OPERATOR, '->' . $method]);
                                // setter value
                                $buildRequestTokens[] = new Token('(');
                                $buildRequestTokens = array_merge($buildRequestTokens, $varTokens);
                                $buildRequestTokens[] = new Token(')');
                            }
                            $argIndex++;
                        }
                        $buildRequestTokens[] = new Token(';');

                        $tokens->insertAt($lineStart, $buildRequestTokens);
                        if ($newlineIsStartTag) {
                            $lastInsertEnd = $lineStart + count($buildRequestTokens);
                        }

                        // Replace the arguments with $request
                        $tokens->overrideRange(
                            $firstIndex + 1 + count($buildRequestTokens),
                            $lastIndex - 1 + count($buildRequestTokens),
                            [
                                new Token([T_VARIABLE, $requestVarName]),
                            ]
                        );
                        $index = $firstIndex + 1 + count($buildRequestTokens);
                    }
                }
            }
        }

        // Add the request namespaces
        $requestClassImports = [];
        foreach (array_unique($requestClasses) as $requestClass) {
            $requestClassImports[] = new Token([T_WHITESPACE, PHP_EOL]);
            $requestClassImports = array_merge(
                $requestClassImports,
                $this->getUseStatementTokensFromClassName($requestClass)
            );
        }
        if ($lastUse = array_pop($useDeclarations)) {
            $tokens->insertAt($lastUse->getEndIndex() + 1, $requestClassImports);
            // Ensure new imports are in the correct order
            $orderFixer = new OrderedImportsFixer();
            $orderFixer->fix($file, $tokens);
        }
    }

    private function getNewClientClass(string $legacyClientClass)
    {
        $parts = explode('\\', $legacyClientClass);
        $shortName = array_pop($parts);
        return implode('\\', $parts) . '\\Client\\' . $shortName;
    }

    private function getSettersFromToken($tokens, string $clientFullName, string $rpcName, int $startIndex, int $argIndex, array $argumentTokens): array
    {
        $setters = [];
        $setterName = null;
        if (method_exists($clientFullName, $rpcName)) {
            $method = new ReflectionMethod($clientFullName, $rpcName);
            $params = $method->getParameters();
            $reflectionArg = $params[$argIndex] ?? null;
            if ($reflectionArg) {
                // handle array of optional args!
                if ($reflectionArg->getName() == 'optionalArgs') {
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
                    }
                    elseif ($tokens[$argumentStart]->isGivenKind(T_VARIABLE)) {
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
                    $setterName = 'set' . ucfirst($reflectionArg->getName());
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

        return $index;
    }

    private function getUseStatementTokensFromClassName(string $className): array
    {
        $tokens = [
            new Token([T_USE, 'use']),
            new Token([T_WHITESPACE, ' ']),
        ];
        foreach (explode('\'', $className) as $part) {
            $tokens[] = new Token([T_STRING, $part]);
            $tokens[] = new Token([T_NS_SEPARATOR, '\\']);
        }
        array_pop($tokens); // remove last namespace separator
        $tokens[] = new Token(';');
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

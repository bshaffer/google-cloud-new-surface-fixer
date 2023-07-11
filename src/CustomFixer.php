<?php

namespace TestFixer;

use PhpCsFixer\AbstractFixer;
use PhpCsFixer\Fixer\FixerInterface;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Tokenizer\Analyzer\NamespaceUsesAnalyzer;
use PhpCsFixer\Tokenizer\Analyzer\Analysis\NamespaceUseAnalysis;
use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;

class CustomFixer extends AbstractFixer
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
        $useDeclarations = (new NamespaceUsesAnalyzer())->getDeclarationsFromTokens($tokens);

        $clients = [];
        // Change to new namespace
        foreach ($tokens->getNamespaceDeclarations() as $namespace) {
            foreach ($useDeclarations as $useDeclaration) {
                if (
                    0 === strpos($useDeclaration->getFullName(), 'Google\\')
                    && 'Client' === substr($useDeclaration->getShortName(), -6)
                    && false === strpos($useDeclaration->getFullName(), '\\Client\\')
                ) {
                    $clients[$useDeclaration->getFullName()] = $useDeclaration->getShortName();
                    $this->replaceOldClientNamespaceWithNewClientNamespace($tokens, $useDeclaration);
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
        $rpcCalls = [];
        $rpcCallCount = 0;
        for ($index = 0; $index < count($tokens); $index++) {
            $token = $tokens[$index];
            if ($token->isGivenKind(T_VARIABLE)) {
                if (in_array($token->getContent(), $clientVars)) {
                    $clientStartIndex = $index;
                    $nextIndex = $tokens->getNextMeaningfulToken($index);
                    $nextToken = $tokens[$nextIndex];
                    if ($nextToken->isGivenKind(T_OBJECT_OPERATOR)) {
                        $rpcCallCount++;
                        $nextIndex = $tokens->getNextMeaningfulToken($nextIndex);
                        $nextToken = $tokens[$nextIndex];
                        $clientFullName = array_search($token->getContent(), $clientVars);
                        [$arguments, $firstIndex, $lastIndex] = $this->getRpcCallArguments($tokens, $nextIndex);
                        $rpcName = $nextToken->getContent();
                        $requestShortName = ucfirst($rpcName) . 'Request';
                        $rpcCalls[] = [$clientFullName, $requestShortName];
                        $clientShortName = $clients[$clientFullName];

                        // determine the indent
                        $indent = '';
                        $lineStart = $clientStartIndex;
                        $i = 1;
                        while (
                            $clientStartIndex - $i >= 0
                            && false === strpos($tokens[$clientStartIndex - $i]->getContent(), "\n")
                        ) {
                            $i++;
                        }
                        if ($clientStartIndex - $i >= 0) {
                            $lineStart = $clientStartIndex - $i;
                            $indent = str_replace("\n", '', $tokens[$clientStartIndex - $i]->getContent());
                        }

                        // Tokens for the "$request" variable
                        $buildRequestTokens = [];
                        $requestVarName = '$request' . ($rpcCallCount == 1 ? '' : $rpcCallCount);

                        // initiate $request variable
                        // Add indent
                        $buildRequestTokens[] = new Token([T_STRING, PHP_EOL. PHP_EOL . $indent]);
                        $buildRequestTokens[] = new Token([T_STRING, $requestVarName . ' = (new ' . $requestShortName . '())']);
                        foreach ($arguments as $argIndex => $argument) {
                            foreach ($this->getSettersFromToken($clientFullName, $rpcName, $argIndex, $argument) as $setter) {
                                list($method, $var) = $setter;
                                $buildRequestTokens[] = new Token([T_STRING, PHP_EOL . $indent . $indent]); // whitespace
                                $buildRequestTokens[] = new Token([T_STRING, '->' . $method]);   // setter method
                                $buildRequestTokens[] = new Token([T_STRING, '(' . $var . ')']); // setter value
                            }
                        }
                        $buildRequestTokens[] = new Token([T_STRING, ';']);
                        $buildRequestTokens[] = new Token([T_STRING, PHP_EOL . $indent]);

                        $tokens->insertAt($lineStart, $buildRequestTokens);

                        // Replace the arguments with $request
                        if ($lastIndex - $firstIndex > 1) {
                            $tokens->overrideRange(
                                $firstIndex + 1 + count($buildRequestTokens),
                                $lastIndex - 1 + count($buildRequestTokens),
                                [
                                    new Token([T_STRING, $requestVarName]),
                                ]
                            );
                        }
                        $index = $firstIndex + 1 + count($buildRequestTokens);
                    }
                }
            }
        }

        // Find all request classes to import
        $requestClasses = [];
        foreach ($rpcCalls as $rpcCall) {
            [$clientFullName, $requestShortName] = $rpcCall;
            $parts = explode('\\', $clientFullName);
            array_pop($parts); // remove client name to get namespace
            $namespace = implode('\\', $parts);
            $requestClasses[] = sprintf('%s\\%s', $namespace, $requestShortName);
        }
        $requestClasses = array_unique($requestClasses);

        // Add the request namespaces
        $requestClassImports = [];
        foreach ($requestClasses as $requestClass) {
            $requestClassImports[] = new Token([T_WHITESPACE, PHP_EOL]);
            $requestClassImports[] = new Token([T_USE, 'use']);
            $requestClassImports[] = new Token([T_WHITESPACE, ' ']);
            $requestClassImports[] = new Token([T_STRING, $requestClass]);
            $requestClassImports[] = new Token(';');
        }
        $lastUse = array_pop($useDeclarations);
        $tokens->insertAt($lastUse->getEndIndex() + 1, $requestClassImports);
    }

    private function getSettersFromToken(string $clientFullName, string $rpcName, int $argIndex, array $argumentTokens): array
    {
        $setters = [];
        $method = new \ReflectionMethod($clientFullName, $rpcName);
        $params = $method->getParameters();
        $reflectionArg = $params[$argIndex] ?? null;
        if ($reflectionArg) {
            // handle array of optional args!
            if ($reflectionArg->getName() == 'optionalArgs') {
                // do nothing for now!
            }
        } else {
            // throw new \Exception('Could not find argument for ' . $clientFullName . '::' . $rpcName . ' at index ' . $argIndex);
        }

        foreach ($argumentTokens as $argToken) {
            if ($argToken->isGivenKind(T_VARIABLE)) {
                // Simple variable argument!
                $varName = $argToken->getContent();
                $setterName = 'set' . ucfirst(substr($varName, 1));
                // use reflection when applicable to be sure we have the right setter name
                if ($reflectionArg && $reflectionArg->getName() !== 'optionalArgs') {
                    $setterName = 'set' . ucfirst($reflectionArg->getName());
                }
                $setters[] = [$setterName, $varName];
            } elseif ($reflectionArg && $reflectionArg->getName() == 'optionalArgs') {
                //
            }
        }

        return $setters;
    }

    private function getRpcCallArguments(Tokens $tokens, int $startIndex)
    {
        $arguments = [];
        $nextIndex = $tokens->getNextMeaningfulToken($startIndex);
        $lastIndex = null;
        if ($tokens[$nextIndex]->getContent() == '(') {
            $startIndex = $nextIndex;
            $lastIndex = $tokens->findBlockEnd(TOKENS::BLOCK_TYPE_PARENTHESIS_BRACE, $nextIndex);
            $nextArgumentEnd = $this->getNextArgumentEnd($tokens, $nextIndex);
            while ($nextArgumentEnd != $nextIndex) {
                $argumentTokens = [];
                for ($i = $nextIndex + 1; $i <= $nextArgumentEnd; $i++) {
                    $argumentTokens[] = $tokens[$i];
                }

                $arguments[] = $argumentTokens;
                $nextIndex = $tokens->getNextMeaningfulToken($nextArgumentEnd);
                $nextArgumentEnd = $this->getNextArgumentEnd($tokens, $nextIndex);
            }
        }

        return [$arguments, $startIndex, $lastIndex];
    }

    private function replaceOldClientNamespaceWithNewClientNamespace(Tokens $tokens, NamespaceUseAnalysis $useDeclaration): void
    {
        $parts = explode('\\', $useDeclaration->getFullName());
        $shortName = array_pop($parts);
        $newFullName = implode('\\', $parts) . '\\Client\\' . $shortName;
        $tokens->overrideRange(
            $useDeclaration->getStartIndex(),
            $useDeclaration->getEndIndex(),
            [
                new Token([T_STRING, 'use ' . $newFullName . ';']),
            ]
        );
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

    /**
     * Returns the definition of the fixer.
     */
    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition('This is a custom fixer', []);
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
        return 'TestFixer/custom_fixer';
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
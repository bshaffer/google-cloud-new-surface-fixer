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

    /**
     * Check if fixer is risky or not.
     *
     * Risky fixer could change code behavior!
     */
    public function isRisky(): bool
    {
        return false;
    }

    protected function applyFix(\SplFileInfo $file, Tokens $tokens): void
    {
        $useDeclarations = (new NamespaceUsesAnalyzer())->getDeclarationsFromTokens($tokens);
        $clients = [];
        // Change to new namespace
        foreach ($tokens->getNamespaceDeclarations() as $namespace) {
            foreach ($useDeclarations as $useDeclaration) {
                if (
                    0 === strpos($useDeclaration->getFullName(), 'Google\\Cloud\\')
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
        for ($index = 0; $index < count($tokens); $index++) {
            $token = $tokens[$index];
            if ($token->isGivenKind(T_VARIABLE)) {
                if (in_array($token->getContent(), $clientVars)) {
                    $clientStartIndex = $index;
                    $nextIndex = $tokens->getNextMeaningfulToken($index);
                    $nextToken = $tokens[$nextIndex];
                    if ($nextToken->isGivenKind(T_OBJECT_OPERATOR)) {
                        $nextIndex = $tokens->getNextMeaningfulToken($nextIndex);
                        $nextToken = $tokens[$nextIndex];
                        $clientFullName = array_search($token->getContent(), $clientVars);
                        [$arguments, $firstIndex, $lastIndex] = $this->getRpcCallArguments($tokens, $nextIndex);
                        $rpcName = $nextToken->getContent();
                        $rpcCalls[] = [$clientFullName, $rpcName];
                        // Create the "request" variable
                        $clientShortName = $clients[$clientFullName];
                        $buildRequestTokens = [
                            new Token([T_STRING, '$request = (new ' . $clientShortName . '())']),
                        ];
                        foreach ($arguments as $argIndex => $argument) {
                            foreach ($this->getSettersFromToken($clientFullName, $rpcName, $argIndex, $argument) as $setter) {
                                list($method, $var) = $setter;
                                $buildRequestTokens[] = new Token([T_STRING, '->' . $method]);
                                $buildRequestTokens[] = new Token([T_STRING, '(' . $var . ')']);
                            }
                        }
                        $buildRequestTokens[] = new Token([T_STRING, ';' . PHP_EOL]);

                        $tokens->insertAt($clientStartIndex, $buildRequestTokens);

                        // Replace the arguments with $request
                        if ($lastIndex - $firstIndex > 1) {
                            $tokens->overrideRange(
                                $firstIndex + 1 + count($buildRequestTokens),
                                $lastIndex - 1 + count($buildRequestTokens),
                                [
                                    new Token([T_STRING, '$request']),
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
            [$clientFullName, $method] = $rpcCall;
            $parts = explode('\\', $clientFullName);
            array_pop($parts); // remove client name to get namespace
            $namespace = implode('\\', $parts);
            $requestClasses[] = sprintf('%s\\%sRequest', $namespace, ucfirst($method));
        }
        $requestClasses = array_unique($requestClasses);

        // Add the request namespaces
        $requestClassImports = [];
        foreach ($requestClasses as $requestClass) {
            $requestClassImports[] = new Token([T_STRING, PHP_EOL . 'use ' . $requestClass . ';']);
        }
        $lastUse = array_pop($useDeclarations);
        $tokens->insertAt($lastUse->getEndIndex() + 1, $requestClassImports);
    }

    private function getSettersFromToken(string $clientFullName, string $rpcName, int $argIndex, array $argument): array
    {
        $setters = [];
        $method = new \ReflectionMethod($clientFullName, $rpcName);
        $params = $method->getParameters();
        $reflectionArg = $params[$argIndex] ?? null;
        if ($reflectionArg) {
            // handle array of optional args!
            if ($reflectionArg->getName() == 'optionalArgs') {
                echo "TESTTTT";
                return [];
            }
        } else {
            // throw new \Exception('Could not find argument for ' . $clientFullName . '::' . $rpcName . ' at index ' . $argIndex);
        }

        foreach ($argument as $argTokens) {
            if ($argTokens->isGivenKind(T_VARIABLE)) {
                $varName = $argTokens->getContent();
                $setters[] = ['set' . ucfirst(substr($varName, 1)), $varName];
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
     * Returns the priority of the fixer.
     *
     * The default priority is 0 and higher priorities are executed first.
     */
    public function getPriority(): int
    {
        return 0;
    }

    /**
     * Returns true if the file is supported by this fixer.
     *
     * @return bool true if the file is supported by this fixer, false otherwise
     */
    public function supports(\SplFileInfo $file): bool
    {
        return true;
    }
}
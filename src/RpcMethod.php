<?php

namespace Google\Cloud\Tools;

use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;
use ReflectionMethod;
use ReflectionParameter;

class RpcMethod
{
    private ReflectionMethod $legacyReflection;
    private ReflectionMethod $newReflection;

    public function __construct(ClientVar $clientVar, string $methodName)
    {
        $this->legacyReflection = new ReflectionMethod($clientVar->getClassName(), $methodName);
        $this->newReflection = new ReflectionMethod($clientVar->getNewClassName(), $methodName);
    }

    public function getRequestClass(): RequestClass
    {
        $firstParameter = $this->newReflection->getParameters()[0];
        return new RequestClass($firstParameter->getType()->getName());
    }

    public function getRequestSetterTokens(Tokens $tokens, array $arguments, string $indent)
    {
        $argIndex = 0;
        $requestSetterTokens = [];
        foreach ($arguments as $startIndex => $argumentTokens) {
            $setters = $this->getSettersFromTokens($tokens, $startIndex, $argIndex, $argumentTokens);
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
        int $startIndex,
        int $argIndex,
        array $argumentTokens
    ): array {
        $setters = [];
        $setterName = null;
        if ($rpcParameter = $this->getParameterAtIndex($argIndex)) {
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

    private function getParameterAtIndex(int $index): ?RpcParameter
    {
        $params = $this->legacyReflection->getParameters();
        if (isset($params[$index])) {
            return new RpcParameter($params[$index]);
        }

        return null;
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
}

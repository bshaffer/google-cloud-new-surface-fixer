<?php

namespace Google\Cloud\Tools;

use PhpCsFixer\Tokenizer\Tokens;

class ClientVar
{
    public $varName;
    public $className;

    public function __construct(
        $varName,
        $className
    ) {
        $this->varName = $varName;
        $this->className = $className;
    }

    /**
     * @param Tokens $tokens
     * @param int $index
     * @return bool
     */
    public function isDeclaredAt(Tokens $tokens, int $index): bool
    {
        $token = $tokens[$index];
        return $token->isGivenKind(T_VARIABLE)
            || ($token->isGivenKind(T_STRING) && $tokens[$index-1]->isGivenKind(T_OBJECT_OPERATOR));
    }

    public function getNewClassName(): string
    {
        return static::getNewClassFromClassname($this->className);
    }

    public static function getNewClassFromClassname(string $className)
    {
        $parts = explode('\\', $className);
        $shortName = array_pop($parts);
        return implode('\\', $parts) . '\\Client\\' . $shortName;
    }

    public static function getClientVarsFromTokens(Tokens $tokens, array $clients): array
    {
        $clientVars = [];
        foreach ($tokens as $index => $token) {
            // get variables which are set directly
            if ($token->isGivenKind(T_NEW)) {
                $nextToken = $tokens[$tokens->getNextMeaningfulToken($index)];
                if (in_array($nextToken->getContent(), $clients)) {
                    if ($prevIndex = $tokens->getPrevMeaningfulToken($index)) {
                        if ($tokens[$prevIndex]->getContent() === '=') {
                            if ($prevIndex = $tokens->getPrevMeaningfulToken($prevIndex)) {
                                if (
                                    $tokens[$prevIndex]->isGivenKind(T_VARIABLE)
                                    || (
                                        $tokens[$prevIndex]->isGivenKind(T_STRING)
                                        && $tokens[$prevIndex-1]->isGivenKind(T_OBJECT_OPERATOR)
                                    )
                                 ) {
                                    // Handle clients set to $var
                                    $clientFullName = array_search($nextToken->getContent(), $clients);
                                    $varName = $tokens[$prevIndex]->getContent();
                                    $clientVars[$varName] = new ClientVar($varName, $clientFullName);
                                }
                            }
                        }
                    }
                }
            }
        }

        return $clientVars;
    }
}

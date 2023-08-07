<?php

namespace Google\Cloud\Tools;

use PhpCsFixer\Tokenizer\Tokens;

class ClientVar
{
    public $varName;
    public $clientClass;

    public function __construct(
        $varName,
        $clientClass
    ) {
        $this->varName = $varName;
        $this->clientClass = $clientClass;
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
}
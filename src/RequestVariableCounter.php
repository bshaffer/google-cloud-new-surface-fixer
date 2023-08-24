<?php

namespace Google\Cloud\Tools;

use PhpCsFixer\Tokenizer\Tokens;

class RequestVariableCounter
{
    private array $varCounts = [];

    public function isFirstVar()
    {
        return count($this->varCounts) == 1
            && array_values($this->varCounts)[0] == 1;
    }

    public function getNextVariableName(string $shortName): string
    {
        if (!isset($this->varCounts[$shortName])) {
            $this->varCounts[$shortName] = 0;
        }
        // determine $request variable name depending on call count
        return sprintf(
            '$%s%s',
            lcfirst($shortName),
            (string) $this->varCounts[$shortName]++ ?: ''
        );
    }
}

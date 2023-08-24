<?php

namespace Google\Cloud\Tools;

use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;
use ReflectionClass;

class RequestClass
{
    private ReflectionClass $reflection;

    public function __construct(string $className)
    {
        $this->reflection = new ReflectionClass($className);
    }

    public function getShortName(): string
    {
        return $this->reflection->getShortName();
    }

    public function getName(): string
    {
        return $this->reflection->getName();
    }

    public function getImportTokens(): array
    {
        return array_merge(
            [new Token([T_WHITESPACE, PHP_EOL])],
            UseStatement::getTokensFromClassName($this->getName())
        );
    }
}

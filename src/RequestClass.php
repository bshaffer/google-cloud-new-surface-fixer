<?php

namespace Google\Cloud\Tools;

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
}

<?php

namespace Google\Cloud\Tools;

use PhpCsFixer\Tokenizer\Tokens;
use ReflectionParameter;

class RpcParameter
{
    private ReflectionParameter $reflection;

    public function __construct(ReflectionParameter $reflection)
    {
        $this->reflection = $reflection;
    }

    public function isOptionalArgs(): bool
    {
        return $this->reflection->getName() === 'optionalArgs';
    }

    public function getSetter(): string
    {
        return 'set' . ucfirst($this->reflection->getName());
    }
}

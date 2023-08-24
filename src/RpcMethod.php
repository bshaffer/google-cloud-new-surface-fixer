<?php

namespace Google\Cloud\Tools;

use PhpCsFixer\Tokenizer\Tokens;
use ReflectionMethod;
use ReflectionParameter;

class RpcMethod
{
    private ReflectionMethod $reflection;

    public function __construct(string $className, string $methodName)
    {
        $this->reflection = new ReflectionMethod($className, $methodName);
    }

    public function getParameterAtIndex(int $index): ?RpcParameter
    {
        $params = $this->reflection->getParameters();
        if (isset($params[$index])) {
            return new RpcParameter($params[$index]);
        }

        return null;
    }
}

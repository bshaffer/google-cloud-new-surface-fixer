<?php

namespace Google\Cloud\Tools;

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

    public function getParameterAtIndex(int $index): ?RpcParameter
    {
        $params = $this->legacyReflection->getParameters();
        if (isset($params[$index])) {
            return new RpcParameter($params[$index]);
        }

        return null;
    }

    public function getRequestClass(): RequestClass
    {
        $firstParameter = $this->newReflection->getParameters()[0];
        return new RequestClass($firstParameter->getType()->getName());
    }
}

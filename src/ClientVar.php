<?php

namespace Google\Cloud\Tools;

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
}
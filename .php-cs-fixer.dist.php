<?php

return (new PhpCsFixer\Config())
    // ...
    ->registerCustomFixers([
        new Google\Cloud\Tools\NewSurfaceFixer(),
    ])
    ->setRules([
        // ...
        'TestFixer/custom_fixer' => true,
        'ordered_imports' => true,
    ])
;
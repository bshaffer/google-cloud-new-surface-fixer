<?php

return (new PhpCsFixer\Config())
    // ...
    ->registerCustomFixers([
        new TestFixer\CustomFixer(),
    ])
    ->setRules([
        // ...
        'TestFixer/custom_fixer' => true,
    ])
;
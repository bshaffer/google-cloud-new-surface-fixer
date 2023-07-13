<?php

return (new PhpCsFixer\Config())
    // ...
    ->registerCustomFixers([
        new Google\Cloud\Tools\NewSurfaceFixer(),
    ])
    ->setRules([
        // ...
        'GoogleCloud/new_surface_fixer' => true,
        'ordered_imports' => true,
    ])
;
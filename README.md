# google-cloud-php-v2-fixer
PHP CS Fixer for Google Cloud PHP V2

## Installation

If you haven't already, install the `php-cs-fixer` package:

```
composer require --dev "friendsofphp/php-cs-fixer:^3.21"
```

Next, install the fixer:

```
composer require --dev "bshaffer/google-cloud-new-surface-fixer"
```

## Running the fixer

First, create a `.php-cs-fixer.google.php` in your project which will be
configured to use the custom fixer:

```php
<?php
// .php-cs-fixer.google.php

// The fixer MUST autoload google/cloud classes. This line is only necessary if
// "php-cs-fixer" was installed in a different composer.json, e.g. with
// "composer global require".
require __DIR__ . '/vendor/autoload.php';

// configure the fixer to run with the new surface fixer
return (new PhpCsFixer\Config())
    ->registerCustomFixers([
        new Google\Cloud\Tools\NewSurfaceFixer(),
    ])
    ->setRules([
        'GoogleCloud/new_surface_fixer' => true,
        'ordered_imports' => true,
    ])
;
```

Run this fixer with the following command:

```
export DIR=examples
php-cs-fixer fix --config=.php-cs-fixer.google.php --dry-run --diff $DIR
```
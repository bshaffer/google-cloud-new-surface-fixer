# google-cloud-php-v2-fixer
PHP CS Fixer for Google Cloud PHP V2

## Installation

If you haven't already, install the `php-cs-fixer` package:

```sh
composer require --dev "friendsofphp/php-cs-fixer:^3.21"
```

Next, install the fixer:

```sh
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

```sh
export DIR=examples
php-cs-fixer fix --config=.php-cs-fixer.google.php --dry-run --diff $DIR
```

You should get an output similar to this

```diff
--- examples/legacy_optional_args.php
+++ examples/legacy_optional_args.php
@@ -2,20 +2,28 @@

 namespace Google\Cloud\Samples\Dlp;

-use Google\Cloud\Dlp\V2\DlpServiceClient;
+use Google\Cloud\Dlp\V2\Client\DlpServiceClient;
+use Google\Cloud\Dlp\V2\CreateDlpJobRequest;
+use Google\Cloud\Dlp\V2\ListInfoTypesRequest;

 // Instantiate a client.
 $dlp = new DlpServiceClient();

 // optional args array (variable)
-$infoTypes = $dlp->listInfoTypes($foo);
+$request = (new ListInfoTypesRequest());
+$infoTypes = $dlp->listInfoTypes($request);

 // optional args array (inline array)
-$job = $dlp->createDlpJob($foo, ['baz' => 1, 'qux' => 2]);
+$request2 = (new CreateDlpJobRequest())
+    ->setParent($foo)
+    ->setBaz(1)
+    ->setQux(2);
+$job = $dlp->createDlpJob($request2);

 // optional args array (inline with nested arrays)
-$job = $dlp->createDlpJob($foo, [
-    'inspectJob' => new InspectJobConfig([
+$request3 = (new CreateDlpJobRequest())
+    ->setParent($foo)
+    ->setInspectJob(new InspectJobConfig([
         'actions' => ['action1', 'action2'],
         'storage_config' => new StorageConfig([
             'foo' => 'foo',
@@ -24,5 +32,5 @@
         'datastore_options' => (new DatastoreOptions())
             ->setPartitionId(123)
             ->setKind('dlp'),
-    ])
-]);
+    ]));
+$job = $dlp->createDlpJob($request3);

      ----------- end diff -----------
```

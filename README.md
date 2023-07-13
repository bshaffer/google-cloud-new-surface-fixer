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
vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.google.php --dry-run --diff $DIR
```

You should get an output similar to this

```diff
--- examples/legacy_optional_args.php
+++ examples/legacy_optional_args.php
@@ -2,10 +2,12 @@

 namespace Google\Cloud\Samples\Dlp;

-use Google\Cloud\Dlp\V2\DlpServiceClient;
+use Google\Cloud\Dlp\V2\Client\DlpServiceClient;
+use Google\Cloud\Dlp\V2\CreateDlpJobRequest;
 use Google\Cloud\Dlp\V2\InspectConfig;
 use Google\Cloud\Dlp\V2\InspectJobConfig;
 use Google\Cloud\Dlp\V2\Likelihood;
+use Google\Cloud\Dlp\V2\ListInfoTypesRequest;
 use Google\Cloud\Dlp\V2\StorageConfig;

 // Instantiate a client.
@@ -12,14 +14,20 @@
 $dlp = new DlpServiceClient();

 // optional args array (variable)
-$infoTypes = $dlp->listInfoTypes($parent);
+$request = (new ListInfoTypesRequest());
+$infoTypes = $dlp->listInfoTypes($request);

 // optional args array (inline array)
-$job = $dlp->createDlpJob($parent, ['jobId' => 'abc', 'locationId' => 'def']);
+$request2 = (new CreateDlpJobRequest())
+    ->setParent($parent)
+    ->setJobId('abc')
+    ->setLocationId('def');
+$job = $dlp->createDlpJob($request2);

 // optional args array (inline with nested arrays)
-$job = $dlp->createDlpJob($parent, [
-    'inspectJob' => new InspectJobConfig([
+$request3 = (new CreateDlpJobRequest())
+    ->setParent($parent)
+    ->setInspectJob(new InspectJobConfig([
         'inspect_config' => (new InspectConfig())
             ->setMinLikelihood(likelihood::LIKELIHOOD_UNSPECIFIED)
             ->setLimits($limits)
@@ -28,5 +36,5 @@
         'storage_config' => (new StorageConfig())
             ->setCloudStorageOptions(($cloudStorageOptions))
             ->setTimespanConfig($timespanConfig),
-    ])
-]);
+    ]));
+$job = $dlp->createDlpJob($request3);

      ----------- end diff -----------
```

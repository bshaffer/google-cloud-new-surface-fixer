<?php

namespace Google\Cloud\Samples\Dlp;

use Google\Cloud\Dlp\V2\Client\DlpServiceClient;
use Google\Cloud\Dlp\V2\CreateDlpJobRequest;
use Google\Cloud\Dlp\V2\InspectConfig;
use Google\Cloud\Dlp\V2\InspectJobConfig;
use Google\Cloud\Dlp\V2\Likelihood;
use Google\Cloud\Dlp\V2\StorageConfig;

// Instantiate a client.
$dlp = new DlpServiceClient();

// required args variable and optional args variable
$request = (new CreateDlpJobRequest())
    ->setParent($parent);
$dlp->createDlpJob($request);

// required args variable and optional args array
$request2 = (new CreateDlpJobRequest())
    ->setParent($parent)
    ->setJobId('abc')
    ->setLocationId('def');
$dlp->createDlpJob($request2);

// required args string and optional variable
$request3 = (new CreateDlpJobRequest())
    ->setParent('path/to/parent')
    ->setJobId('abc')
    ->setLocationId('def');
$dlp->createDlpJob($request3);

// required args variable and optional args array with nested array
$request4 = (new CreateDlpJobRequest())
    ->setParent($parent)
    ->setInspectJob(new InspectJobConfig([
        'inspect_config' => (new InspectConfig())
            ->setMinLikelihood(likelihood::LIKELIHOOD_UNSPECIFIED)
            ->setLimits($limits)
            ->setInfoTypes($infoTypes)
            ->setIncludeQuote(true),
        'storage_config' => (new StorageConfig())
            ->setCloudStorageOptions(($cloudStorageOptions))
            ->setTimespanConfig($timespanConfig),
    ]));
$job = $dlp->createDlpJob($request4);

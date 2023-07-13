<?php

namespace Google\Cloud\Samples\Dlp;

use Google\Cloud\Dlp\V2\Client\DlpServiceClient;
use Google\Cloud\Dlp\V2\CreateDlpJobRequest;
use Google\Cloud\Dlp\V2\ListInfoTypesRequest;

// Instantiate a client.
$dlp = new DlpServiceClient();

// optional args array (variable)
$request = (new ListInfoTypesRequest());
$infoTypes = $dlp->listInfoTypes($request);

// optional args array (inline array)
$request2 = (new CreateDlpJobRequest())
    ->setParent($foo)
    ->setBaz(1)
    ->setQux(2);
$job = $dlp->createDlpJob($request2);

// optional args array (inline with nested arrays)
$request3 = (new CreateDlpJobRequest())
    ->setParent($foo)
    ->setInspectJob(new InspectJobConfig([
        'actions' => ['action1', 'action2'],
        'storage_config' => new StorageConfig([
            'foo' => 'foo',
            'bar' => 'bar',
        ]),
        'datastore_options' => (new DatastoreOptions())
            ->setPartitionId(123)
            ->setKind('dlp'),
    ]));
$job = $dlp->createDlpJob($request3);

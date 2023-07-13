<?php

namespace Google\Cloud\Samples\Dlp;

use Google\Cloud\Dlp\V2\Client\DlpServiceClient;
use Google\Cloud\Dlp\V2\CreateDlpJobRequest;

// Instantiate a client.
$dlp = new DlpServiceClient();

// required args variable and optional args variable
$request = (new CreateDlpJobRequest())
    ->setParent($foo);
$dlp->createDlpJob($request);

// required args variable and optional args array
$request2 = (new CreateDlpJobRequest())
    ->setParent($foo)
    ->setBaz(1)
    ->setQux(2);
$dlp->createDlpJob($request2);

// required args string and optional variable
$request3 = (new CreateDlpJobRequest())
    ->setParent('foo/bar/baz')
    ->setBaz(1)
    ->setQux(2);
$dlp->createDlpJob($request3);

// required args variable and optional args array with nested array
$request4 = (new CreateDlpJobRequest())
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
$dlp->createDlpJob($request4);

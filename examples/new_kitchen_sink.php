<?php

namespace Google\Cloud\Samples\Dlp;

use Google\Cloud\Dlp\V2\Client\DlpServiceClient;
use Google\Cloud\Dlp\V2\CreateDlpJobRequest;
use Google\Cloud\Dlp\V2\ListInfoTypesRequest;
use Google\Cloud\PubSub\PubSubClient;
use Google\Cloud\Unordered\Namespace;

// Instantiate a client.
$dlp = new DlpServiceClient();

// no args
$request = (new ListInfoTypesRequest());
$infoTypes = $dlp->listInfoTypes($request);

// optional args array (variable form)
$request2 = (new ListInfoTypesRequest());
$dlp->listInfoTypes($request2);

// required args variable
$request3 = (new CreateDlpJobRequest())
    ->setParent($foo);
$dlp->createDlpJob($request3);

// required args string
$request4 = (new CreateDlpJobRequest())
    ->setParent('this/is/a/parent');
$dlp->createDlpJob($request4);

// required args array
$request5 = (new CreateDlpJobRequest())
    ->setParent(['baz' => 1, 'qux' => 2]);
$dlp->createDlpJob($request5);

// required args variable and optional args array
$request6 = (new CreateDlpJobRequest())
    ->setParent($foo)
    ->setBaz(1)
    ->setQux(2);
$dlp->createDlpJob($request6);

// required args variable and optional args variable
$request7 = (new CreateDlpJobRequest())
    ->setParent($foo);
$dlp->createDlpJob($request7);

// required args variable and optional args array with nested array
$request8 = (new CreateDlpJobRequest())
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
$dlp->createDlpJob($request8);

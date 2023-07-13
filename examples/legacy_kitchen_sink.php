<?php

namespace Google\Cloud\Samples\Dlp;

use Google\Cloud\Dlp\V2\DlpServiceClient;
use Google\Cloud\PubSub\PubSubClient;
use Google\Cloud\Unordered\Namespace;

// Instantiate a client.
$dlp = new DlpServiceClient();

// no args
$infoTypes = $dlp->listInfoTypes();

// optional args array (variable form)
$dlp->listInfoTypes($foo);

// required args variable
$dlp->createDlpJob($foo);

// required args string
$dlp->createDlpJob('this/is/a/parent');

// required args array
$dlp->createDlpJob(['baz' => 1, 'qux' => 2]);

// required args variable and optional args array
$dlp->createDlpJob($foo, ['baz' => 1, 'qux' => 2]);

// required args variable and optional args variable
$dlp->createDlpJob($foo, $bar);

// required args variable and optional args array with nested array
$dlp->createDlpJob($foo, [
    'inspectJob' => new InspectJobConfig([
        'actions' => ['action1', 'action2'],
        'storage_config' => new StorageConfig([
            'foo' => 'foo',
            'bar' => 'bar',
        ]),
        'datastore_options' => (new DatastoreOptions())
            ->setPartitionId(123)
            ->setKind('dlp'),
    ])
]);

<?php

namespace Google\Cloud\Samples\Dlp;

use Google\Cloud\Dlp\V2\DlpServiceClient;

// Instantiate a client.
$dlp = new DlpServiceClient();

// required args variable and optional args variable
$dlp->createDlpJob($foo, $bar);

// required args variable and optional args array
$dlp->createDlpJob($foo, ['baz' => 1, 'qux' => 2]);

// required args string and optional variable
$dlp->createDlpJob('foo/bar/baz', ['baz' => 1, 'qux' => 2]);

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

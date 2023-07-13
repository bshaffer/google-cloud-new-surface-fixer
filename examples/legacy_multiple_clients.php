<?php

namespace Google\Cloud\Samples\Dlp;

use Google\Cloud\Dlp\V2\DlpServiceClient;
use Google\Cloud\Iam\V2\IamClient;

// Instantiate a client.
$dlp = new DlpServiceClient();
$iam = new IamClient();

// optional args array (variable)
$infoTypes = $dlp->listInfoTypes($foo);

// optional args array (inline array)
$job = $dlp->createDlpJob($foo, ['baz' => 1, 'qux' => 2]);

// optional args array (inline with nested arrays)
$job = $dlp->createDlpJob($foo, [
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

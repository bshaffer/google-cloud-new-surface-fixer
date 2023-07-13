<?php

namespace Google\Cloud\Samples\Dlp;

use Google\Cloud\Dlp\V2\DlpServiceClient;

// Instantiate a client.
$dlp = new DlpServiceClient();

// required args string
$dlp->createDlpJob('this/is/a/parent');

// required args inline array
$dlp->createDlpJob(['baz' => 1, 'qux' => 2]);

// required args variable
$dlp->createDlpJob($foo);

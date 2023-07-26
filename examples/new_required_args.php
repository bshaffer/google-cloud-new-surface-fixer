<?php

namespace Google\Cloud\Samples\Dlp;

use Google\Cloud\Dlp\V2\Client\DlpServiceClient;
use Google\Cloud\Dlp\V2\CreateDlpJobRequest;

// Instantiate a client.
$dlp = new DlpServiceClient();

// required args string
$request = (new CreateDlpJobRequest())
    ->setParent('this/is/a/parent');
$dlp->createDlpJob($request);

// required args string (double quotes)
$request2 = (new CreateDlpJobRequest())
    ->setParent("this/is/a/$variable");
$dlp->createDlpJob($request2);

// required args inline array
$request3 = (new CreateDlpJobRequest())
    ->setParent(['jobId' => 'abc', 'locationId' => 'def']);
$dlp->createDlpJob($request3);

// required args variable
$request4 = (new CreateDlpJobRequest())
    ->setParent($foo);
$dlp->createDlpJob($request4);

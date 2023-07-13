<?php

namespace Google\Cloud\Samples\Dlp;


use Google\Cloud\Dlp\V2\Client\DlpServiceClient;
use Google\Cloud\Dlp\V2\GetDlpJobRequest;

// Instantiate a client.
$dlp = new DlpServiceClient();

// Call a client method which is NOT an RPC
$jobName = $dlp->dlpJobName('my-project', 'my-job');

// Call an RPC method
$request2 = (new GetDlpJobRequest())
    ->setName($jobName);
$job = $dlp->getDlpJob($request2);

// Call a non-existant method!
$job = $dlp->getJob($jobName);

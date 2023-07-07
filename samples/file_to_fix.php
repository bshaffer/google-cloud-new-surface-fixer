<?php

/**
 * Copyright 2023 Google Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * For instructions on how to run the samples:
 *
 * @see https://github.com/GoogleCloudPlatform/php-docs-samples/tree/main/dlp/README.md
 */

namespace Google\Cloud\Samples\Dlp;

# [START dlp_deidentify_exception_list]
use Google\Cloud\Dlp\V2\DlpServiceClient;

/**
 * Create an exception list for de-identification
 * Create an exception list for a regular custom dictionary detector.
 *
 * @param string $callingProjectId  The project ID to run the API call under
 * @param string $textToDeIdentify  The String you want the service to DeIdentify
 */
function deidentify_exception_list(
    // TODO(developer): Replace sample parameters before running the code.
): void {
    // Instantiate a client.
    $dlp = new DlpServiceClient();
    $infoTypes = $dlp->listInfoTypes();     // no args
    $dlp->listInfoTypes($foo); // optional args array (variable form)
    $dlp->createDlpJob($foo);  // required args variable
    $dlp->createDlpJob('this/is/a/parent');  // required args string
    $dlp->createDlpJob($foo, ['baz' => 1, 'qux' => 2]); // required args variable and array
    $dlp->createDlpJob($foo, $bar); // required args variable and array variable

    // Send the request and receive response from the service
    // $response = $dlp->deidentifyContent($request);

    // Print the results
    // printf('Text after replace with infotype config: %s', $response->getItem()->getValue());
}
# [END dlp_deidentify_exception_list]


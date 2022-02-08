<?php
require_once __DIR__ . '/vendor/autoload.php';

try {
    $instance = \ExponentPhpSDK\Expo::normalSetup();
    echo 'Succeeded! We have created an Expo instance successfully';
} catch (Exception $e) {
    echo 'Test Failed';
}


// Test that the total_response array has the proper structure
// Test that it throws errors if interest in empty or invalid
// Test that postData contains the proper structure
// Verify that no sub-array of $postDataChunks has over 90 elements
// Test that everything works fine with perfect data
// Test that error is thrown when $response contains errors
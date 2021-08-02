<?php

require_once 'common.php';
use Scriptotek\Alma\Client as AlmaClient;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    exit;
}

try {
    // todo: verify using the signature key
    $body = file_get_contents('php://input');
    $notification = json_decode($body, true);

    file_put_contents('receipt_webhook.log', $body . "\n", FILE_APPEND);

    http_response_code(200);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    exit;
}

<?php

require_once 'common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

try {
    $body = file_get_contents('php://input');
    if (defined('WEBHOOK_LOG_PATH')) {
        file_put_contents(WEBHOOK_LOG_PATH, $body . "\n", FILE_APPEND);
    }
    $signatureHeader = $_SERVER["HTTP_X_ANET_SIGNATURE"];
    // signature header is formatted as "sha512=<signature>"
    $signature = substr($signatureHeader, 7);
    $hash = hash_hmac("sha512", $body, AUTHORIZE_SIGNATURE_KEY);
    if (strcasecmp($signature, $hash) !== 0) {
        // return unauthorized if signature does not match
        http_response_code(401);
        exit;
    }

    $notification = json_decode($body);
    switch ($notification->eventType) {
        case 'net.authorize.payment.authcapture.created':
            $transactionId = $notification->payload->id;
            shell_exec('php record_alma_transaction.php ' . $transactionId . ' 2>&1 | tee -a record_transaction.log 2>/dev/null >/dev/null &');
            break;
        
        default:
            break;
    }

    http_response_code(200);
    exit;
} catch (Throwable $e) {
    if (defined('WEBHOOK_ERROR_LOG_PATH')) {
        $error_message = $e . "\n";
        if (isset($notification)) {
            $error_message = $notification->notificationId . ': ' . $error_message;
        }
        file_put_contents(WEBHOOK_ERROR_LOG_PATH, date('[Y-m-d h:m:s] ') . $error_message, FILE_APPEND);
    }
    http_response_code(500);
    exit;
}

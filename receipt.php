<?php

require_once 'common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

try {
    $body = file_get_contents('php://input');
    if (defined('WEBHOOK_NOTIFICATION_LOG_PATH')) {
        file_put_contents(WEBHOOK_NOTIFICATION_LOG_PATH, $body . "\n", FILE_APPEND);
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
            $cmd = 'php record_alma_transaction.php ' . escapeshellarg($transactionId);
            $output = defined('WEBHOOK_OUTPUT_LOG_PATH') ? WEBHOOK_OUTPUT_LOG_PATH : '/dev/null';
            $descriptors = array(
                0 => array('file', '/dev/null', 'r'),
                1 => array('file', $output, 'a'),
                2 => array('pipe', 'w')
            );
            $process = proc_open($cmd, $descriptors, $pipes);
            if (!is_resource($process)) {
                throw new Exception('Failed to open process.');
            }
            // $pipes[2] is the stderr of the new process, set it to not block and check if there was immediately an error
            stream_set_blocking($pipes[2], false);
            if ($err = stream_get_contents($pipes[2])) {
                throw new Exception('Failed to open process: ' . $err);
            }
            break;
        
        default:
            break;
    }

    http_response_code(200);
    exit;
} catch (Throwable $e) {
    $error_message = $e . "\n";
    if (isset($notification)) {
        $error_message = 
            '[notificationId:' . $notification->notificationId . 
            '; transactionId:' . $notification->payload->id . '] ' . $error_message;
    }
    logWebhookError($error_message);
    mailWebhookError('Alma Payment Receipt Webhook Error 500', $error_message);
    http_response_code(500);
    exit;
}

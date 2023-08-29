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
    $body = file_get_contents('php://input');
    if (defined('WEBHOOK_LOG_PATH')) {
        file_put_contents(WEBHOOK_LOG_PATH, $body . "\n", FILE_APPEND);
    }
    $signatureHeader = $_SERVER["HTTP_X_ANET_SIGNATURE"];
    // signature header is formatted as "sha512=<signature>"
    $signature = substr($signatureHeader, 7);
    $hash = hash_hmac("sha512", $body, AUTHORIZE_SIGNATURE_KEY);
    if (strcasecmp($signature, $hash) !== 0) {
        // return bad request if signature does not match
        http_response_code(400);
        exit;
    }

    $notification = json_decode($body);
    switch ($notification->eventType) {
        case 'net.authorize.payment.authcapture.created':
            $transactionId = $notification->payload->id;

            shell_exec('php record_alma_transaction.php ' . $transactionId . ' 2>&1 | tee -a record_transaction.log 2>/dev/null >/dev/null &');
/*
            $request = new AnetApi\GetTransactionDetailsRequest();
            $request->setMerchantAuthentication(getMerchantAuthentication());
            $request->setTransId($transactionId);

            // get the transaction details so we can read the fee ids off of the line items
            $controller = new AnetController\GetTransactionDetailsController($request);
            $response = $controller->executeWithApiResponse(AUTHORIZE_ENVIRONMENT);

            if ($response == null) {
                throw new Exception("An error occurred when retrieving transaction details.");
            } else if ($response->getMessages()->getResultCode() != "Ok") {
                $errorMessages = $response->getMessages()->getMessage();
                throw new Exception($errorMessages[0]->getCode() . " " . $errorMessages[0]->getText());        
            }

            $transaction = $response->getTransaction();
            $userId = $transaction->getCustomer()->getId();
            $alma = new AlmaClient(ALMA_API_KEY, ALMA_REGION);
            foreach ($transaction->getLineItems() as $lineItem) {
                $feeId = $lineItem->getItemId();
                // mark each fee as paid
                $url = $alma->buildUrl('/users/' . $userId . '/fees/' . $feeId, [
                    'op' => 'pay',
                    'amount' => strval($lineItem->getUnitPrice()),
                    'method' => 'ONLINE',
                    'external_transaction_id' => $transactionId
                ]);
                $alma->post($url, null);
            }
*/
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

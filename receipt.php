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
    $signatureHeader = $_SERVER["HTTP_X_ANET_SIGNATURE"];
    // signature header is formatted as "sha512=<signature>"
    $signature = substr($signatureHeader, 7);
    $hash = hash_hmac("sha512", $body, AUTHORIZE_SIGNATURE_KEY);
    if (strcasecmp($signature, $hash) !== 0) {
        http_response_code(400);
        exit;
    }

    $notification = json_decode($body);
    switch ($notification->eventType) {
        case 'net.authorize.payment.authcapture.created':
            $transactionId = $notification->payload->id;

            $request = new AnetApi\GetTransactionDetailsRequest();
            $request->setMerchantAuthentication(getMerchantAuthentication());
            $request->setTransId($transactionId);

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
                $url = $alma->buildUrl('/users/' . $userId . '/fees/' . $feeId, [
                    'op' => 'pay',
                    'amount' => strval($lineItem->getUnitPrice()),
                    'method' => 'ONLINE',
                    'external_transaction_id' => $transactionId
                ]);
                $alma->post($url, null);
            }

            break;
        
        default:
            break;
    }

    http_response_code(200);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    exit;
}

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
    $notification = json_decode($body);

    switch ($notification->eventType) {
        case 'net.authorize.payment.authcapture.created':
            $transactionId = $notification->payload->id;

            $request = new AnetApi\GetTransactionDetailsRequest();
            $request->setMerchantAuthentication(getMerchantAuthentication());
            $request->setTransId($transactionId);

            $controller = AnetController\AnetController\GetTransactionDetailsController($request);
            $response = $controller->executeWithApiResponse(\net\authorize\api\constants\ANetEnvironment::SANDBOX);

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
                $alma->post($url);
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

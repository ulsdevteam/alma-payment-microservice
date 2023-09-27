<?php

require_once 'common.php';
use Scriptotek\Alma\Client as AlmaClient;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;

if (isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(403);
    exit;
}

try {
    $transactionId = $argv[1];

    $request = new AnetApi\GetTransactionDetailsRequest();
    $request->setMerchantAuthentication(getMerchantAuthentication());
    $request->setTransId($transactionId);

    // get the transaction details so we can read the fee ids off of the line items
    $controller = new AnetController\GetTransactionDetailsController($request);
    $response = $controller->executeWithApiResponse(AUTHORIZE_ENVIRONMENT);

    if ($response == null) {
        throw new Exception("An error occurred when retrieving transaction details.");
    } else if ($response->getMessages()->getResultCode() != "Ok") {
        $exceptionMessage = '';
        foreach ($response->getMessages()->getMessage() as $errorMessage) {
            $exceptionMessage .= $errorMessage->getCode() . " " . $errorMessage->getText() . "\n";
        }
        throw new Exception($exceptionMessage);        
    }

    $transaction = $response->getTransaction();
    if (substr($transaction->getOrder()->getInvoiceNumber(), 0, 4) != 'ALMA') {
        exit;
    }
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
} catch (Throwable $error) {
    logWebhookError($error);
    mailWebhookError('Alma Payment Receipt Webhook Error for Transaction ' . $transactionId, $error);
}

<?php

require_once 'common.php';

if (isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(404);
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
        $errorMessages = $response->getMessages()->getMessage();
        throw new Exception($errorMessages[0]->getCode() . " " . $errorMessages[0]->getText());        
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
} catch (Throwable $e) {
    if (defined('WEBHOOK_ERROR_LOG_PATH')) {
        $error_message = date('[Y-m-d h:m:s] ') . $e . "\n";
        file_put_contents(WEBHOOK_ERROR_LOG_PATH, $error_message, FILE_APPEND);
    }
    if (defined('WEBHOOK_ERROR_NOTIFICATION_EMAIL')) {
        mail(WEBHOOK_ERROR_NOTIFICATION_EMAIL, 'Alma Payment Receipt Webhook Error', $e);
    }
}

<?php

require_once 'common.php';
use Scriptotek\Alma\Client as AlmaClient;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $jwt_payload = validateJwt($_GET['jwt']);
    if (!$jwt_payload) {
        http_response_code(401);
        exit;
    }
    $user = getAlmaUser($jwt_payload);
    $token = getAuthorizeTransactionToken($user);
?>
<!DOCTYPE html>
<html>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0">
    <head></head>
    <body>
        <form method="post" action="<?php echo AUTHORIZE_HOSTED_PAYMENT; ?>" id="formAuthorizeNetTestPage" name="formAuthorizeNetTestPage">
            <input type="hidden" name="token" value="<?php echo $token; ?>" />
        </form>         
    </body>
    <script type="text/javascript">
        document.getElementById('formAuthorizeNetTestPage').submit();
    </script>
</html>
<?php
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo $e;
    exit;
}

/**
 * Given a user, read their fees and use Authorize.net API to create a token that can be used to show a payment form
 * 
 * @param Scriptotek\Alma\Users\User $user The alma user
 * @return string The token
 */
function getAuthorizeTransactionToken($user) {
    $transactionRequest = new AnetAPI\TransactionRequestType();
    $transactionRequest->setTransactionType("authCaptureTransaction");
    $transactionRequest->setAmount($user->fees->total_sum);
    $transactionRequest->setCurrencyCode($user->fees->currency);
    
    // invoice number includes 'A' to indicate an alma transaction, and the user id
    $order = new AnetAPI\OrderType();
    $order->setInvoiceNumber('A' . $user->getPrimaryId() . dechex(time()));
    $transactionRequest->setOrder($order);

    // set the alma user id as the customer id, will be retrieved in the receipt webhook
    $customer = new AnetAPI\CustomerDataType();
    $customer->setId($user->getPrimaryId());
    $transactionRequest->setCustomer($customer);

    // set line item id as the fee id, will also be retrieved in the receipt webhook
    foreach ($user->fees as $fee) {
        $lineItem = new AnetAPI\LineItemType();
        $lineItem->setItemId($fee->id);
        $lineItem->setName($fee->type->value);
        $lineItem->setDescription($fee->type->desc);
        $lineItem->setQuantity(1);
        $lineItem->setUnitPrice($fee->balance);
        $transactionRequest->addToLineItems($lineItem);
    }

    // visual and return url settings for the hosted payment page
    $buttonOptionsSetting = new AnetAPI\SettingType();
    $buttonOptionsSetting->setSettingName("hostedPaymentButtonOptions");
    $buttonOptionsSetting->setSettingValue("{\"text\":\"Pay\"}");
    $orderOptionsSetting = new AnetAPI\SettingType();
    $orderOptionsSetting->setSettingName("hostedPaymentOrderOptions");
    $orderOptionsSetting->setSettingValue("{\"show\":true}");
    $returnOptionsSetting = new AnetAPI\SettingType();
    $returnOptionsSetting->setSettingName("hostedPaymentReturnOptions");
    $returnOptionsSetting->setSettingValue("{\"showReceipt\":true, \"url\":\"" . RETURN_URL . "\", \"cancelUrl\":\"" . RETURN_URL . "\"}");

    $request = new AnetAPI\GetHostedPaymentPageRequest();
    $request->setMerchantAuthentication(getMerchantAuthentication());
    $request->setTransactionRequest($transactionRequest);
    $request->addToHostedPaymentSettings($buttonOptionsSetting);
    $request->addToHostedPaymentSettings($orderOptionsSetting);
    $request->addToHostedPaymentSettings($returnOptionsSetting);

    $controller = new AnetController\GetHostedPaymentPageController($request);
    $response = $controller->executeWithApiResponse(AUTHORIZE_ENVIRONMENT);
    if ($response != null && $response->getMessages()->getResultCode() == "Ok") {
        return $response->getToken();
    } else if ($response != null) {
        $errorMessages = $response->getMessages()->getMessage();
        throw new Exception($errorMessages[0]->getCode() . " " . $errorMessages[0]->getText());        
    } else {
        throw new Exception("An error occurred when requesting a hosted payment page token.");
    }
}
?>

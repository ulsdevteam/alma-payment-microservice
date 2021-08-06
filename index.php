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
        <form method="post" action="https://test.authorize.net/payment/payment" id="formAuthorizeNetTestPage" name="formAuthorizeNetTestPage">
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

function getAuthorizeTransactionToken($user) {
    $transactionRequest = new AnetAPI\TransactionRequestType();
    $transactionRequest->setTransactionType("authCaptureTransaction");
    $transactionRequest->setAmount($user->fees->total_sum);
    $transactionRequest->setCurrencyCode($user->fees->currency);
    
    $order = new AnetAPI\OrderType();
    $order->setInvoiceNumber('A' . $user->getPrimaryId() . dechex(time()));
    $transactionRequest->setOrder($order);

    $customer = new AnetAPI\CustomerDataType();
    $customer->setId($user->getPrimaryId());
    $transactionRequest->setCustomer($customer);

    foreach ($user->fees as $fee) {
        $lineItem = new AnetAPI\LineItemType();
        $lineItem->setItemId($fee->id);
        $lineItem->setName($fee->type->value);
        $lineItem->setDescription($fee->type->desc);
        $lineItem->setQuantity(1);
        $lineItem->setUnitPrice($fee->balance);
        $transactionRequest->addToLineItems($lineItem);
    }

    $buttonOptionsSetting = new AnetAPI\SettingType();
    $buttonOptionsSetting->setSettingName("hostedPaymentButtonOptions");
    $buttonOptionsSetting->setSettingValue("{\"text\":\"Pay\"}");
    $orderOptionsSetting = new AnetAPI\SettingType();
    $orderOptionsSetting->setSettingName("hostedPaymentOrderOptions");
    $orderOptionsSetting->setSettingValue("{\"show\":true}");
    $returnOptionsSetting = new AnetAPI\SettingType();
    $returnOptionsSetting->setSettingName("hostedPaymentReturnOptions");
    $returnOptionsSetting->setSettingValue("{\"showReceipt\":true}");

    $request = new AnetAPI\GetHostedPaymentPageRequest();
    $request->setMerchantAuthentication(getMerchantAuthentication());
    $request->setTransactionRequest($transactionRequest);
    $request->addToHostedPaymentSettings($buttonOptionsSetting);
    $request->addToHostedPaymentSettings($orderOptionsSetting);
    $request->addToHostedPaymentSettings($returnOptionsSetting);

    $controller = new AnetController\GetHostedPaymentPageController($request);
    $response = $controller->executeWithApiResponse(\net\authorize\api\constants\ANetEnvironment::SANDBOX);
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

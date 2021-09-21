<?php

require_once 'common.php';
use Scriptotek\Alma\Client as AlmaClient;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
} else if (!in_array($method, ['GET', 'POST'])) {    
    http_response_code(400);
    exit;
}

try {
    $jwt_payload = validateJwt($_GET['jwt']);
    if (!$jwt_payload) {
        http_response_code(401);
        exit;
    }    
    $alma = new AlmaClient(ALMA_API_KEY, ALMA_REGION);
    if ($method === 'GET') {        
        $userId = $jwt_payload->userName;
    } else if ($method === 'POST') {
        $userId = $_POST['userId'];
    }
    $user = $alma->users->get($userId);
    if ($user->fees->total_sum === 0) {
        http_response_code(200);
        echo "You currently have no fines or fees that need to be paid.";
        exit;
    }
    if ($method === 'GET') {
        $fees = [];
        foreach ($user->fees as $fee) {
            $fees[$fee->id] = $fee->balance;
        }
    } else if ($method === 'POST') {
        if (!$user->exists()) {
            http_response_code(400);
            echo 'User with id "' . $userId . '" was not found.';
            exit;
        }
        $fees = $_POST['fees'];
        $feeErrors = [];
        foreach ($fees as $feeId => $amount) {
            $fee = $user->fees->get($feeId);
            if ($amount > $fee->balance) {
                $feeErrors[] = 'Given amount for fee "' . $feeId . '" is higher than the current balance.';
            }
            // TODO: other checks for fee amounts not being valid based on fee type, i.e. a fee type that must be paid in full
        }
        if (!empty($feeErrors)) {
            http_response_code(400);
            echo join("\n", $feeErrors);
            exit;
        }
    }
    $token = getAuthorizeTransactionToken($user, $fees);
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
 * @param array $fees The fee amounts: those sent by the user if it's a POST, or the full amount for all fees if it's a GET
 * @return string The token
 */
function getAuthorizeTransactionToken($user, $fees) {
    $transactionRequest = new AnetAPI\TransactionRequestType();
    $transactionRequest->setTransactionType("authCaptureTransaction");
    $transactionRequest->setAmount(array_sum($fees));
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
    foreach ($fees as $feeId => $amount) {
        $fee = $user->fees->get($feeId);
        $lineItem = new AnetAPI\LineItemType();
        $lineItem->setItemId($feeId);
        $lineItem->setName($fee->type->value);
        $lineItem->setDescription($fee->type->desc);
        $lineItem->setQuantity(1);
        $lineItem->setUnitPrice($amount);
        $transactionRequest->addToLineItems($lineItem);
    }

    $request = new AnetAPI\GetHostedPaymentPageRequest();
    $request->setMerchantAuthentication(getMerchantAuthentication());
    $request->setTransactionRequest($transactionRequest);

    $settings = json_decode(file_get_contents(AUTHORIZE_HOSTED_PAYMENT_SETTINGS));
    foreach ($settings as $settingName => $settingValue) {
        $settingType = new AnetAPI\SettingType();
        $settingType->setSettingName($settingName);
        $settingType->setSettingValue(json_encode($settingValue));
        $request->addToHostedPaymentSettings($settingType);
    }

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

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
    // JWT for authentication is passed as a query parameter
    // TODO: may also want to support passing it in the Authorization header?
    $jwt_payload = validateJwt($_GET['jwt']);
    if (!$jwt_payload) {
        http_response_code(401);
        exit;
    }    
    $alma = new AlmaClient(ALMA_API_KEY, ALMA_REGION);
    if ($method === 'POST') {
        // in the case of a post, the body is expected to contain:
        // userId: string, optional
        // fees: array, mapping fee id to amount
        $contentType = $_SERVER['CONTENT_TYPE'];
        if ($contentType == 'application/json') {
            $body = file_get_contents('php://input');
            $body = json_decode($body, true);
        } else if ($contentType == 'application/x-www-form-urlencoded') {
            $body = $_POST;
        } else {
            http_response_code('415');
            exit;
        }
    }
    // if there's no body (i.e. this is a GET) or userId isn't present, get it from the jwt payload
    if (!empty($body) && array_key_exists('userId', $body)) {
        $userId = $body['userId'];
    } else {
        $userId = $jwt_payload->userName;
    }
    $user = $alma->users->get($userId);
    if (!$user->exists()) {
        http_response_code(400);
        echo 'User with id "' . $userId . '" was not found.';
        exit;
    }
    if ($user->fees->total_sum === 0) {
        http_response_code(200);
        echo "You currently have no fines or fees that need to be paid.";
        exit;
    }
    $hosted_payment_settings_key = 'default';
    // in the case of a get, pay the full balance for all allowed fees
    if ($method === 'GET') {
        $fees = [];
        foreach ($user->fees as $fee) {
            if (isLibraryAllowed($fee->owner->value)) {
                $fees[$fee->id] = $fee->balance;
            }
        }
        if (empty($fees)) {
            http_response_code(200);
            echo "You currently have no fines or fees that may be paid online.";
            exit;
        }
        if (array_key_exists('paymentSettings', $_GET)) {
            $hosted_payment_settings_key = $_GET['paymentSettings'];
        }
    } else if ($method === 'POST') {        
        $fees = $body['fees'];
        $feeErrors = [];
        foreach ($fees as $feeId => $amount) {
            $fee = $user->fees->get($feeId);
            if (!isLibraryAllowed($fee->owner->value)) {
                $feeErrors[$feeId] = 'Fees owned by ' . $fee->owner->desc . ' may not be paid using this service.';
            } else if ($amount < 0) {
                $feeErrors[$feeId] = 'Payment amount cannot be negative.';
            } else if ($amount > $fee->balance) {
                $feeErrors[$feeId] = 'Payment amount is higher than the current balance.';
            }
            // TODO (maybe?): other checks for fee amounts not being valid based on fee type, i.e. a fee type that must be paid in full
        }
        $total = array_sum($fees);
        if ($total == 0) {
            $feeErrors['other'] = 'Please enter an amount for at least one fee.';
        } else if (defined('MINIMUM_TOTAL_AMOUNT') && $total < MINIMUM_TOTAL_AMOUNT) {
            $feeErrors['other'] = 'The minimum total payment amount is ' . MINIMUM_TOTAL_AMOUNT . '.';
        }
        if (!empty($feeErrors)) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode($feeErrors);
            exit;
        }
        if (array_key_exists('paymentSettings', $body)) {
            $hosted_payment_settings_key = $body['paymentSettings'];
        }
    }
    $token = getAuthorizeTransactionToken($user, $fees, $hosted_payment_settings_key);

    if ($_SERVER['HTTP_ACCEPT'] == 'application/json') {
        http_response_code(200);
	    header('Content-Type: application/json');
        echo json_encode([
            'url' => AUTHORIZE_HOSTED_PAYMENT,
            'token' => $token
        ]);
        exit;
    }
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
 * @param string $hosted_payment_settings_key Key to reference in the payment settings json file, defaults to 'default', uses default if the key is not found
 * @return string The token
 */
function getAuthorizeTransactionToken($user, $fees, $hosted_payment_settings_key) {
    $transactionRequest = new AnetAPI\TransactionRequestType();
    $transactionRequest->setTransactionType("authCaptureTransaction");
    $transactionRequest->setAmount(array_sum($fees));
    $transactionRequest->setCurrencyCode($user->fees->currency);
    
    // invoice number based on the microsecond
    $order = new AnetAPI\OrderType();
    $order->setInvoiceNumber('A' . microtime(true) * 10000);
    $transactionRequest->setOrder($order);

    // set the alma user id as the customer id, will be retrieved in the receipt webhook
    $customer = new AnetAPI\CustomerDataType();
    $customer->setId($user->getPrimaryId());
    $transactionRequest->setCustomer($customer);

    // set line item id as the fee id, will also be retrieved in the receipt webhook
    foreach ($fees as $feeId => $amount) {
        if ($amount <= 0) continue;
        $fee = $user->fees->get($feeId);
        $lineItem = new AnetAPI\LineItemType();
        $lineItem->setItemId($feeId);
        $lineItem->setName($fee->type->value);
        $lineItem->setDescription($fee->type->desc);
        $lineItem->setQuantity(1);
        $lineItem->setUnitPrice($amount);
        $lineItem->setTotalAmount($amount);
        $transactionRequest->addToLineItems($lineItem);
    }

    $request = new AnetAPI\GetHostedPaymentPageRequest();
    $request->setMerchantAuthentication(getMerchantAuthentication());
    $request->setTransactionRequest($transactionRequest);

    $settings = json_decode(file_get_contents(AUTHORIZE_HOSTED_PAYMENT_SETTINGS), true);
    if (!array_key_exists($hosted_payment_settings_key, $settings)) {
        $hosted_payment_settings_key = 'default';
    }
    foreach ($settings[$hosted_payment_settings_key] as $settingName => $settingValue) {
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

<?php

require_once 'common.php';
use Scriptotek\Alma\Client as AlmaClient;

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
} else if (!in_array($method, ['GET', 'POST'])) {    
    http_response_code(400);
    exit;
}

try {
    $jwt = $_GET['jwt'];
    $jwt_payload = validateJwt($jwt);
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
?>
<!DOCTYPE html>
<html>
    <head></head>
    <body>
        <form method="post" action="index.php?jwt=<?php echo $jwt; ?>">
            <input type="hidden" name="userId" value="<?php echo $userId; ?>" />
            <?php foreach($user->fees as $fee): ?>
                <input type="text" name="fees[<?php echo $fee->id; ?>]" />
            <?php endforeach; ?>
            <input type="submit" value="Pay Fees">
        </form>
    </body>
</html>
<?php
} catch (Throwable $e) {
    http_response_code(500);
    echo $e;
    exit;
}

?>

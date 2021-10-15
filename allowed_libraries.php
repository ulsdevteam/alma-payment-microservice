<?php

require_once 'common.php';
use Scriptotek\Alma\Client as AlmaClient;

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
} else if ($method !== 'GET') {    
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
    $allowedLibraries = [];
    foreach ($alma->conf->libraries as $library) {
        if (isLibraryAllowed($library->code)) {
            $allowedLibraries[] = [
                'code' => $library->code,
                'name' => $library->name,
            ];
        }
    }
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode($allowedLibraries);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    exit;
}

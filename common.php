<?php

require_once 'vendor/autoload.php';
require_once 'config.php';

use Scriptotek\Alma\Client as AlmaClient;
use Scriptotek\PrimoSearch\Primo as PrimoClient;
use Scriptotek\Alma\Users\User;
use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;
use net\authorize\api\contract\v1 as AnetAPI;

/**
 * Get an Authorize.net MerchantAuthentication object with the api login and key
 * 
 * @return MerchantAuthenticationType
 */
function getMerchantAuthentication() {
    $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
    $merchantAuthentication->setName(AUTHORIZE_API_LOGIN);
    $merchantAuthentication->setTransactionKey(AUTHORIZE_API_KEY);
    return $merchantAuthentication;
}

/**
 * Get an Alma user object given a JWT payload from that user
 * 
 * @param object $jwt_payload The payload decoded from a JWT assigned to the desired user
 * @return User The Scriptotek\Alma\Users\User object
 */
function getAlmaUser(object $jwt_payload): User
{
    $alma = new AlmaClient(ALMA_API_KEY, ALMA_REGION);
    $user_id = $jwt_payload->userName;
    $user = $alma->users->get($user_id);
    return $user;
}

/**
 * Validate a base64 encoded JWT using the public key obtained from Primo
 * 
 * @param string $jwt The encoded JWT
 * @return object|null Returns the decoded payload, or null if the signature is invalid or the token is expired
 */
function validateJwt(string $jwt)
{
    $cache_file_path = PRIMO_PUBKEY_CACHE_DIR . '/public-key.pem';
    $log_file_path = PRIMO_PUBKEY_CACHE_DIR . '/public-key.log';
    if (file_exists($cache_file_path)) {
        $cached_key = file_get_contents($cache_file_path);
    } else {
        $cached_key = getPublicKeyFromPrimo();
        file_put_contents($cache_file_path, $cached_key);
        file_put_contents($log_file_path, 'public key retrieved at ' . date('c') . "\n", FILE_APPEND);
    }
    try {
        $jwt_payload = JWT::decode($jwt, $cached_key, ['ES256']);
        return $jwt_payload;
    } catch (SignatureInvalidException $e) {
        // public key might be outdated
        $public_key = getPublicKeyFromPrimo();
        if ($cached_key === $public_key) {
            // its not, this jwt is just invalid/expired
            return null;
        } else {
            file_put_contents($cache_file_path, $public_key);
            file_put_contents($log_file_path, 'public key updated at ' . date('c') . "\n", FILE_APPEND);
        }
        $jwt_payload = JWT::decode($jwt, $public_key, ['ES256']);
        return $jwt_payload;
    }
}

/**
 * Gets the public key from the Primo API
 * 
 * @return string The public key
 */
function getPublicKeyFromPrimo(): string {
    $primo = new PrimoClient([
        'apiKey' => ALMA_API_KEY,
        'region' => ALMA_REGION,
        'vid' => '',
        'scope' => 'default_scope'
    ]);
    return $primo->getPublicKey();
}

/**
 * Given a library code, determine if its fees may be paid using this service based on the allowed/excluded list
 * @param string $libraryCode
 * @return bool
 */
function isLibraryAllowed(string $libraryCode): bool {
    if (defined('ALLOWED_LIBRARY_CODES')) {
        return in_array($libraryCode, ALLOWED_LIBRARY_CODES);
    } else if (defined('EXCLUDED_LIBRARY_CODES')) {
        return !in_array($libraryCode, EXCLUDED_LIBRARY_CODES);
    } else {
        return true;
    }
}

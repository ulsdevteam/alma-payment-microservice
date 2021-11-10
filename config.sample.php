<?php
define('PRIMO_PUBKEY_CACHE_DIR', 'cache');
define('ALMA_API_KEY', 'alma api key');
define('ALMA_REGION', 'na');
define('ALMA_INSTITUTION', 'alma institution');
define('AUTHORIZE_ENVIRONMENT', 'https://apitest.authorize.net');
define('AUTHORIZE_API_LOGIN', 'authorize.net api login id');
define('AUTHORIZE_API_KEY', 'authorize.net api key');
define('AUTHORIZE_SIGNATURE_KEY', 'authorize.net signature key');
define('AUTHORIZE_HOSTED_PAYMENT', 'https://test.authorize.net/payment/payment');
define('AUTHORIZE_HOSTED_PAYMENT_SETTINGS', 'hosted-payment-settings.example.json');
// Either of the following may be defined. If neither are defined all libraries are considered allowed.
define('ALLOWED_LIBRARY_CODES', ['array of library codes', 'whose fees may be paid using this service']);
define('EXCLUDED_LIBRARY_CODES', ['array of library codes', 'whose fees may NOT be paid using this service']);
// Optional, for those cases where a minimum amount is desired due to payment processor fees, etc.
define('MINIMUM_TOTAL_AMOUNT', 5.00);
?>
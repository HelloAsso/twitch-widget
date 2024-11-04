<?php

require 'app/Config.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$repository = Config::getInstance()->repo;
$apiWrapper = Config::getInstance()->apiWrapper;

$tokens = $repository->getAccessTokensToRefresh();
if ($tokens == null) {
    echo "no token to refresh";
} else {
    echo count($tokens) . " tokens to refresh";

    foreach ($tokens as $token) {
        $decryptedOrganizationRefreshToken = Helpers::decryptToken(encryptedToken: $token['refresh_token']);
        $apiWrapper->refreshToken($decryptedOrganizationRefreshToken, $token['organization_slug']);
        echo "Token for " . $token['organization_slug'] . " refreshed";
    }
}
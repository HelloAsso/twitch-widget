<?php

use App\Repositories\AccessTokenRepository;
use App\Repositories\AuthorizationCodeRepository;
use App\Services\ApiWrapper;

require __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

$dsn = "mysql:host={$_SERVER['DBURL']};port={$_SERVER['DBPORT']};dbname={$_SERVER['DBNAME']};charset=utf8mb4";
$pdo = new PDO($dsn, $_SERVER['DBUSER'], $_SERVER['DBPASSWORD'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$accessTokenRepository = new AccessTokenRepository($pdo, $_SERVER['DBPREFIX']);
$authorizationCodeRepository = new AuthorizationCodeRepository($pdo, $_SERVER['DBPREFIX']);

$apiWrapper = new ApiWrapper(
    $accessTokenRepository,
    $authorizationCodeRepository,
    $_SERVER['HA_AUTH_URL'],
    $_SERVER['API_URL'],
    $_SERVER['API_AUTH_URL'],
    $_SERVER['CLIENT_ID'],
    $_SERVER['CLIENT_SECRET'],
    $_SERVER['WEBSITE_DOMAIN']
);

$tokens = $accessTokenRepository->getAccessTokensToRefresh();

echo count($tokens) . " tokens to refresh";

foreach ($tokens as $token) {
    $apiWrapper->getAccessTokensAndRefreshIfNecessary($token->organization_slug);
    echo "Token for " . $token->organization_slug . " refreshed";
}

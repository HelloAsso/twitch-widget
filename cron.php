<?php

use App\Repositories\AccessTokenRepository;
use App\Repositories\AuthorizationCodeRepository;
use App\Services\ApiWrapper;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

require __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

$dsn = "mysql:host={$_SERVER['DBURL']};port={$_SERVER['DBPORT']};dbname={$_SERVER['DBNAME']};charset=utf8mb4";
$pdo = new PDO($dsn, $_SERVER['DBUSER'], $_SERVER['DBPASSWORD'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$logger = new Logger('cron');
$logger->pushHandler(new StreamHandler(__DIR__ . '/logs/cron.log', Logger::DEBUG));

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
    $_SERVER['WEBSITE_DOMAIN'],
    $logger
);

$tokens = $accessTokenRepository->getAccessTokensToRefresh();

echo count($tokens) . " token(s) à rafraîchir\n";
foreach ($tokens as $token) {
    try {
        $apiWrapper->refreshToken($token->refresh_token, $token->organization_slug);
        echo "Token rafraîchi pour " . ($token->organization_slug ?? 'global') . "\n";
    } catch (Exception $e) {
        echo "Erreur pour " . ($token->organization_slug ?? 'global') . " : " . $e->getMessage() . "\n";
        $logger->error('Erreur refresh token pour ' . $token->organization_slug, ['exception' => $e]);
    }
}

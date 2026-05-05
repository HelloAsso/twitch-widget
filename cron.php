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
$env = $_SERVER['APP_ENV'] ?? 'production';
$logFile = __DIR__ . '/logs/cron-' . $env . '.log';
$logger->pushHandler(new StreamHandler($logFile, Logger::DEBUG));

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

$logger->info('Cron démarré. IP du serveur : ' . gethostbyname(gethostname()) . '. API_AUTH_URL : ' . $_SERVER['API_AUTH_URL']);
echo count($tokens) . " token(s) à rafraîchir\n";
foreach ($tokens as $token) {
    try {
        $apiWrapper->refreshToken($token->refresh_token, $token->organization_slug);
        echo "Token rafraîchi pour " . ($token->organization_slug ?? 'global') . "\n";
        $logger->info('Token rafraîchi avec succès pour ' . ($token->organization_slug ?? 'global'));
    } catch (Exception $e) {
        $isNetworkError = str_contains($e->getMessage(), 'cURL error 7') || str_contains($e->getMessage(), 'Connection refused');
        echo "Erreur pour " . ($token->organization_slug ?? 'global') . " : " . $e->getMessage() . "\n";
        if ($isNetworkError) {
            $logger->critical('Impossible de joindre l\'API (' . $_SERVER['API_AUTH_URL'] . '). Vérifiez que le serveur distant est accessible depuis cette machine.', [
                'server_ip' => gethostbyname(gethostname()),
                'organization_slug' => $token->organization_slug,
            ]);
        } else {
            $logger->error('Erreur refresh token pour ' . $token->organization_slug, ['exception' => $e]);
        }
    }
}

<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Controllers\HomeController;
use App\Controllers\LoginController;
use App\Controllers\StreamController;
use App\Controllers\WidgetController;
use App\Middlewares\AuthMiddleware;
use App\Repositories\AccessTokenRepository;
use App\Repositories\AuthorizationCodeRepository;
use App\Repositories\FileManager;
use App\Repositories\StreamRepository;
use App\Services\ApiWrapper;
use App\Twig\CustomTwigExtension;
use DI\Container;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Slim\Factory\AppFactory;
use Slim\Flash\Messages;
use Slim\Views\Twig;
use Twig\Extra\Intl\IntlExtension;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

$container = new Container();

$container->set(Logger::class, function () {
    $logger = new Logger('app');
    $streamHandler = new StreamHandler(__DIR__ . '/../log.txt', $_SERVER['LOGLEVEL']);
    $logger->pushHandler($streamHandler);
    return $logger;
});

$container->set(PDO::class, function () {
    $dsn = "mysql:host={$_SERVER['DBURL']};port={$_SERVER['DBPORT']};dbname={$_SERVER['DBNAME']};charset=utf8mb4";
    return new PDO($dsn, $_SERVER['DBUSER'], $_SERVER['DBPASSWORD'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
});

$container->set(AccessTokenRepository::class, function ($c) {
    return new AccessTokenRepository($c->get(PDO::class), $_SERVER['DBPREFIX']);
});

$container->set(AuthorizationCodeRepository::class, function ($c) {
    return new AuthorizationCodeRepository($c->get(PDO::class), $_SERVER['DBPREFIX']);
});

$container->set(StreamRepository::class, function ($c) {
    return new StreamRepository($c->get(PDO::class), $_SERVER['DBPREFIX']);
});

$container->set(ApiWrapper::class, function ($c) {
    return new ApiWrapper(
        $c->get(AccessTokenRepository::class),
        $c->get(AuthorizationCodeRepository::class),
        $c->get(Logger::class),
        $_SERVER['HA_AUTH_URL'],
        $_SERVER['API_URL'],
        $_SERVER['API_AUTH_URL'],
        $_SERVER['CLIENT_ID'],
        $_SERVER['CLIENT_SECRET'],
        $_SERVER['WEBSITE_DOMAIN']
    );
});

$container->set(FileManager::class, function ($c) {
    $storage = BlobRestProxy::createBlobService($_SERVER['BLOB_CONNECTION_STRING']);
    return new FileManager($storage, $_SERVER['BLOB_URL']);
});

$container->set(Twig::class, function (): Twig {
    $twig = Twig::create(__DIR__ . '/../src/views', ['cache' => false]);
    $twig->addExtension(new IntlExtension());
    $twig->addExtension(new CustomTwigExtension());
    return $twig;
});

$container->set(Messages::class, function () {
    return new Messages();
});

$app = AppFactory::createFromContainer($container);

if (!session_id())
    @session_start();

$errorMiddleware = $app->addErrorMiddleware(true, true, true, $container->get(Logger::class));

$app->get('/', [HomeController::class, 'index'])->setName('app_index');

$app->post('/login', [LoginController::class, 'login'])->setName('app_login');
$app->get('/logout', [LoginController::class, 'logout'])->setName('app_logout');
$app->get('/redirect_auth_page', [LoginController::class, 'redirectAuthPage'])->setName('app_redirect_auth_page');
$app->get('/refresh_token', [LoginController::class, 'refreshToken'])->setName('app_refresh_token');
$app->get('/validate_auth_page', [LoginController::class, 'validateAuthPage'])->setName('app_validate_auth_page');

$app->get('/admin', [StreamController::class, 'index'])->add(new AuthMiddleware())->setName('app_stream_index');
$app->post('/admin/new', [StreamController::class, 'new'])->add(new AuthMiddleware())->setName('app_stream_new');
$app->post('/admin/{id}/refresh', [StreamController::class, 'refreshPassword'])->add(new AuthMiddleware())->setName('app_stream_refresh');
$app->post('/admin/{id}/delete', [StreamController::class, 'delete'])->add(new AuthMiddleware())->setName('app_stream_delete');
$app->get('/admin/{id}/edit', [StreamController::class, 'edit'])->add(new AuthMiddleware())->setName('app_stream_edit');
$app->post('/admin/{id}/edit', [StreamController::class, 'editPost'])->add(new AuthMiddleware())->setName('app_stream_edit_post');

$app->get('/widget/{id}/fetchDonation', [WidgetController::class, 'widgetFetchDonation'])->setName('app_stream_widget_fetch_donation');
$app->get('/widget/{id}/alert', [WidgetController::class, 'widgetAlert'])->setName('app_stream_widget_alert');
$app->get('/widget/{id}/donation', [WidgetController::class, 'widgetDonation'])->setName('app_stream_widget_donation');

$app->run();

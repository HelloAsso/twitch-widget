<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Controllers\ApiController;
use App\Controllers\HomeController;
use App\Controllers\LoginController;
use App\Controllers\AdminController;
use App\Controllers\WidgetController;
use App\Middlewares\AuthAdminMiddleware;
use App\Middlewares\AuthApiMiddleware;
use App\Middlewares\AuthMiddleware;
use App\Repositories\AccessTokenRepository;
use App\Repositories\AuthorizationCodeRepository;
use App\Repositories\EventRepository;
use App\Repositories\FileManager;
use App\Repositories\StreamRepository;
use App\Repositories\UserRepository;
use App\Repositories\WidgetRepository;
use App\Services\ApiWrapper;
use DI\Container;
use MailchimpTransactional\ApiClient;
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

$container->set(EventRepository::class, function ($c) {
    return new EventRepository($c->get(PDO::class), $_SERVER['DBPREFIX']);
});

$container->set(StreamRepository::class, function ($c) {
    return new StreamRepository($c->get(PDO::class), $_SERVER['DBPREFIX']);
});

$container->set(UserRepository::class, function ($c) {
    return new UserRepository($c->get(PDO::class), $_SERVER['DBPREFIX']);
});

$container->set(WidgetRepository::class, function ($c) {
    return new WidgetRepository($c->get(PDO::class), $_SERVER['DBPREFIX']);
});

$container->set(ApiWrapper::class, function ($c) {
    return new ApiWrapper(
        $c->get(AccessTokenRepository::class),
        $c->get(AuthorizationCodeRepository::class),
        $_SERVER['HA_AUTH_URL'],
        $_SERVER['API_URL'],
        $_SERVER['API_AUTH_URL'],
        $_SERVER['CLIENT_ID'],
        $_SERVER['CLIENT_SECRET'],
        $_SERVER['WEBSITE_DOMAIN']
    );
});

$container->set(FileManager::class, function () {
    $storage = BlobRestProxy::createBlobService($_SERVER['BLOB_CONNECTION_STRING']);
    return new FileManager($storage, $_SERVER['BLOB_URL']);
});

$container->set(ApiClient::class, function () {
    $mailchimp = new \MailchimpTransactional\ApiClient();
    $mailchimp->setApiKey($_SERVER['MANDRILL_API']);
    return $mailchimp;
});

$container->set(Twig::class, function (): Twig {
    $twig = Twig::create(__DIR__ . '/../src/views', ['cache' => false]);
    $twig->addExtension(new IntlExtension());
    return $twig;
});

$container->set(Messages::class, function () {
    return new Messages();
});

$app = AppFactory::createFromContainer($container);

if (!session_id())
    @session_start();

$errorMiddleware = $app->addErrorMiddleware(false, true, true, $container->get(Logger::class));

$app->get('/', [HomeController::class, 'index'])->setName('app_index');
$app->get('/forgot_password', [HomeController::class, 'forgotPassword'])->setName('app_forgot_password');
$app->get('/reset_password/{token}', [HomeController::class, 'resetPassword'])->setName('app_reset_password');

$app->post('/login', [LoginController::class, 'login'])->setName('app_login');
$app->get('/logout', [LoginController::class, 'logout'])->add(new AuthMiddleware())->setName('app_logout');
$app->post('/forgot_password', [LoginController::class, 'forgotPassword'])->setName('app_forgot_password_post');
$app->post('/reset_password', [LoginController::class, 'resetPassword'])->setName('app_reset_password_post');
$app->get('/redirect_auth_page', [LoginController::class, 'redirectAuthPage'])->setName('app_redirect_auth_page');
$app->get('/validate_auth_page', [LoginController::class, 'validateAuthPage'])->setName('app_validate_auth_page');

$app->get('/admin', [AdminController::class, 'index'])->add(new AuthMiddleware())->setName('app_admin_index');
$app->post('/admin/event', [AdminController::class, 'newEvent'])->add(new AuthAdminMiddleware())->setName('app_event_new');
$app->post('/admin/event/{id}/delete', [AdminController::class, 'deleteEvent'])->add(new AuthAdminMiddleware())->setName('app_event_delete');
$app->get('/admin/event/{id}/edit', [AdminController::class, 'editEvent'])->add(new AuthMiddleware())->setName('app_event_edit');
$app->post('/admin/event/{id}/edit', [AdminController::class, 'editEventPost'])->add(new AuthMiddleware())->setName('app_event_edit_post');
$app->post('/admin/stream', [AdminController::class, 'newStream'])->add(new AuthMiddleware())->setName('app_stream_new');
$app->post('/admin/stream/{id}/delete', [AdminController::class, 'deleteStream'])->add(new AuthMiddleware())->setName('app_stream_delete');
$app->get('/admin/stream/{id}/edit', [AdminController::class, 'editStream'])->add(new AuthMiddleware())->setName('app_stream_edit');
$app->post('/admin/stream/{id}/edit', [AdminController::class, 'editStreamPost'])->add(new AuthMiddleware())->setName('app_stream_edit_post');

$app->post('/api/stream', [ApiController::class, 'new'])->add(new AuthApiMiddleware())->setName('app_stream_edit_post');

$app->get('/widget-stream-alert/{id}', [WidgetController::class, 'widgetAlert'])->setName('app_stream_widget_alert');
$app->get('/widget-stream-alert/{id}/fetch', [WidgetController::class, 'widgetAlertFetch'])->setName('app_stream_widget_alert_fetch');
$app->get('/widget-stream-donation/{id}', [WidgetController::class, 'widgetDonation'])->setName('app_stream_widget_donation');
$app->get('/widget-stream-donation/{id}/fetch', [WidgetController::class, 'widgetDonationFetch'])->setName('app_stream_widget_donation_fetch');
$app->get('/widget-event/{id}', [WidgetController::class, 'widgetEventDonation'])->setName('app_event_widget_donation');
$app->get('/widget-event/{id}/fetch', [WidgetController::class, 'widgetEventDonationFetch'])->setName('app_event_widget_donation_fetch');

$app->run();

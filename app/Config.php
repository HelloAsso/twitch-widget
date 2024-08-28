<?php

if (!session_id())
    @session_start();

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/ApiWrapper.php';
require __DIR__ . '/../app/Helpers.php';
require __DIR__ . '/../app/FileManager.php';
require __DIR__ . '/../app/Repository.php';

use MicrosoftAzure\Storage\Blob\BlobRestProxy;

class Config
{
    private static $_instance = null;
    public $db = null;
    public $dbPrefix = null;
    public $repo = null;
    public $apiWrapper = null;
    public $fileManager = null;
    public $encryptionKey = null;
    public $haUrl = null;
    public $haIps = null;
    public $webSiteDomain = null;

    private function getDb()
    {
        $options = array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        );

        $db = new PDO(
            "mysql:host=" . $_ENV['DBURL'] . ";dbname=" . $_ENV['DBNAME'] . ";charset=utf8mb4",
            $_ENV['DBUSER'],
            $_ENV['DBPASSWORD'],
            $options
        );

        return $db;
    }

    private function __construct()
    {
        date_default_timezone_set('Europe/Paris');

        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
        $dotenv->safeLoad();

        $this->db = $this->getDb();
        $this->dbPrefix = $_ENV['DBPREFIX'];

        $this->repo = new Repository($this->db, $this->dbPrefix);
        $this->apiWrapper = new ApiWrapper($this->repo, $_ENV['HA_AUTH_URL'], $_ENV['API_URL'], $_ENV['API_AUTH_URL'], $_ENV['CLIENT_ID'], $_ENV['CLIENT_SECRET'], $_ENV['WEBSITE_DOMAIN']);

        $storage = BlobRestProxy::createBlobService($_ENV['BLOB_CONNECTION_STRING']);
        $this->fileManager = new FileManager($storage, $_ENV['BLOB_URL']);

        $this->encryptionKey = $_ENV['ENCRYPTION_KEY'];

        $this->haUrl = $_ENV['HA_URL'];
        $this->haIps = isset($_ENV['HA_IPS']) ? explode(",", $_ENV['HA_IPS']) : [];
        $this->webSiteDomain = $_ENV['WEBSITE_DOMAIN'];
    }

    public static function getInstance()
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new Config();
        }

        return self::$_instance;
    }
}
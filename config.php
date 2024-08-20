<?php
session_start();

// Vérifier si la session 'environment' est définie, sinon définir une valeur par défaut
if (!isset($_SESSION['environment'])) {
    $_SESSION['environment'] = 'SANDBOX';
}

// Si la session d'environnement n'existe pas
$environment = $_SESSION['environment'];

require 'vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Déterminer l'environnement actuel en fonction de la session
$isLocal = $_ENV['IS_LOCAL'] == "TRUE";

// Configurer les paramètres de connexion en fonction de l'environnement sélectionné
$host = $isLocal ? $_ENV['DBURL_LOCAL'] : $_ENV['DBURL'];
$dbname = $isLocal ? $_ENV['DBNAME_LOCAL'] : $_ENV['DBNAME'];
$user = $isLocal ? $_ENV['DBUSER_LOCAL'] : $_ENV['DBUSER'];
$password = $isLocal ? $_ENV['DBPASSWORD_LOCAL'] : $_ENV['DBPASSWORD'];

$blob_url = $_ENV['BLOB_URL_' . $environment];
$blob_images_folder = $_ENV['IMAGES_FOLDER'];
$blob_sounds_folder = $_ENV['SOUNDS_FOLDER'];

$client_id = $_ENV['CLIENT_ID_' . $environment];
$client_secret = $_ENV['CLIENT_SECRET_' . $environment];

// Options de connexion
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $db = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $user,
        $password,
        $options
    );
} catch (PDOException $e) {
    echo "Erreur de connexion : " . $e->getMessage();
}
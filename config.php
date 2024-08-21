<?php
// Vérifier si une session est déjà active avant d'appeler session_start()
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Vérifier si la session 'environment' est définie, sinon définir une valeur par défaut
if (!isset($_SESSION['environment'])) {
    $_SESSION['environment'] = 'SANDBOX';
}

// Déterminer l'environnement actuel en fonction de la session
$environment = $_SESSION['environment'] ?? $_ENV['ENVIRONMENT'];

require 'vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Déterminer si nous sommes en local ou non
$isLocal = $_ENV['IS_LOCAL'] === 'TRUE';

// Configurer les paramètres de connexion en fonction de l'environnement sélectionné
$host = $isLocal ? $_ENV['DBURL_LOCAL'] : $_ENV['DBURL'];
$dbname = $isLocal ? $_ENV['DBNAME_LOCAL'] : $_ENV['DBNAME'];
$user = $isLocal ? $_ENV['DBUSER_LOCAL'] : $_ENV['DBUSER'];
$password = $isLocal ? $_ENV['DBPASSWORD_LOCAL'] : $_ENV['DBPASSWORD'];

$blob_url = $_ENV['BLOB_URL_' . $environment];
$blob_images_folder = $_ENV['IMAGES_FOLDER'];
$blob_sounds_folder = $_ENV['SOUNDS_FOLDER'];
$encryption_key = $_ENV['ENCRYPTION_KEY'];

// Initialiser les variables de session pour les clés d'API
$_SESSION['client_id'] = $_ENV['CLIENT_ID_' . $environment];
$_SESSION['client_secret'] = $_ENV['CLIENT_SECRET_' . $environment];
$_SESSION['api_url'] = $_ENV['API_URL_' . $environment];
$_SESSION['api_auth_url'] = $_ENV['API_AUTH_URL_' . $environment];

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

<?php
require 'vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Déterminer l'environnement actuel
$isLocal = $_ENV['DB_ENV'] === 'local';

$ADMIN_USER = $_ENV['ADMIN_USER'];
$ADMIN_PASSWORD = $_ENV['ADMIN_PASSWORD'];

// Configurer les paramètres de connexion en fonction de l'environnement
$host = $isLocal ? $_ENV['DBURL_LOCAL'] : $_ENV['DBURL'];
$dbname = $isLocal ? $_ENV['DBNAME_LOCAL'] : $_ENV['DBNAME'];
$user = $isLocal ? $_ENV['DBUSER_LOCAL'] : $_ENV['DBUSER'];
$password = $isLocal ? $_ENV['DBPASSWORD_LOCAL'] : $_ENV['DBPASSWORD'];

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



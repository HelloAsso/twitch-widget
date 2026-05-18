<?php

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

$requiredVars = ['DBURL', 'DBPORT', 'DBNAME', 'DBUSER', 'DBPASSWORD', 'DBPREFIX'];
foreach ($requiredVars as $var) {
    if (empty($_SERVER[$var]) && empty($_ENV[$var])) {
        echo "ERROR: Missing required environment variable: $var\n";
        exit(1);
    }
}

// Prefer $_ENV over $_SERVER for dotenv compatibility
$dbUrl = $_ENV['DBURL'] ?? $_SERVER['DBURL'];
$dbPort = $_ENV['DBPORT'] ?? $_SERVER['DBPORT'];
$dbName = $_ENV['DBNAME'] ?? $_SERVER['DBNAME'];
$dbUser = $_ENV['DBUSER'] ?? $_SERVER['DBUSER'];
$dbPassword = $_ENV['DBPASSWORD'] ?? $_SERVER['DBPASSWORD'];
$dbPrefix = $_ENV['DBPREFIX'] ?? $_SERVER['DBPREFIX'];

try {
    $dsn = "mysql:host={$dbUrl};port={$dbPort};dbname={$dbName};charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "ERROR: Database connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

function execute()
{
    global $pdo, $dbPrefix;

    $migrations = getMigrations();
    if (count($migrations) == 0) {
        echo "No migration files found.\n";
        return;
    }

    $executedMigrations = getExecutedMigrations();
    $pending = array_diff($migrations, $executedMigrations);

    if (count($pending) == 0) {
        echo "All migrations are up to date.\n";
        return;
    }

    foreach ($pending as $migration) {
        echo "Running migration: $migration ... ";
        executeFile($migration);
        echo "OK\n";
    }
}

function getMigrations()
{
    return array_map(function ($file) {
        return basename($file);
    }, glob(__DIR__ . '/*.sql'));
}

function getExecutedMigrations()
{
    global $pdo, $dbPrefix;

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS `' . $dbPrefix . 'migrations` (
            `name` varchar(255) NOT NULL,
            `date` datetime NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ');

    $stmt = $pdo->prepare('
        SELECT name
        FROM ' . $dbPrefix . 'migrations
    ');
    $stmt->execute();
    return $stmt->fetchAll(\PDO::FETCH_COLUMN);
}

function executeFile($fileName)
{
    global $pdo, $dbPrefix;

    $sql = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . $fileName);
    $sql = str_replace('{prefix}', $dbPrefix, $sql);

    $pdo->exec($sql);

    $stmt = $pdo->prepare('
        INSERT INTO ' . $dbPrefix . 'migrations VALUES
        (?, NOW());
    ');
    $stmt->execute([$fileName]);
}

try {
    execute();
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}

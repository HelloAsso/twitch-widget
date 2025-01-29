<?php

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

$dsn = "mysql:host={$_SERVER['DBURL']};port={$_SERVER['DBPORT']};dbname={$_SERVER['DBNAME']};charset=utf8mb4";
$pdo = new PDO($dsn, $_SERVER['DBUSER'], $_SERVER['DBPASSWORD'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

function execute()
{
    $migrations = getMigrations();
    if (count($migrations) == 0) {
        return;
    }

    $executedMigrations = getExecutedMigrations();

    foreach (array_diff($migrations, $executedMigrations) as $migration) {
        executeFile($migration);
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
    $stmt = $GLOBALS['pdo']->prepare('
        CREATE TABLE IF NOT EXISTS `' . $_SERVER['DBPREFIX'] . 'migrations` (
            `name` varchar(255) NOT NULL,
            `date` datetime NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ');
    $stmt->execute();

    $stmt = $GLOBALS['pdo']->prepare('
        SELECT name
        FROM ' . $_SERVER['DBPREFIX'] . 'migrations;
    ');
    $stmt->execute();
    return $stmt->fetchAll(\PDO::FETCH_COLUMN);
}

function executeFile($fileName)
{
    $sql = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . $fileName);
    $sql = str_replace('{prefix}', $_SERVER['DBPREFIX'], $sql);

    $stmt = $GLOBALS['pdo']->prepare($sql);
    $stmt->execute();

    $stmt = $GLOBALS['pdo']->prepare('
        INSERT INTO ' . $_SERVER['DBPREFIX'] . 'migrations VALUES
        (?, CURTIME());
    ');
    $stmt->execute([$fileName]);
}

execute();

<?php

require 'app/Config.php';

$config = Config::getInstance();

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
    $stmt = $GLOBALS['config']->db->prepare('
        CREATE TABLE IF NOT EXISTS `' . $GLOBALS['config']->dbPrefix . 'migrations` (
            `name` varchar(255) NOT NULL,
            `date` datetime NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ');
    $stmt->execute();

    $stmt = $GLOBALS['config']->db->prepare('
        SELECT name
        FROM ' . $GLOBALS['config']->dbPrefix . 'migrations;
    ');
    $stmt->execute();
    return $stmt->fetchAll(\PDO::FETCH_COLUMN);
}

function executeFile($fileName)
{
    $sql = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . $fileName);
    $sql = str_replace('{prefix}', $GLOBALS['config']->dbPrefix, $sql);

    $stmt = $GLOBALS['config']->db->prepare($sql);
    $stmt->execute();

    $stmt = $GLOBALS['config']->db->prepare('
        INSERT INTO ' . $GLOBALS['config']->dbPrefix . 'migrations VALUES
        (?, CURTIME());
    ');
    $stmt->execute([$fileName]);
}

execute();
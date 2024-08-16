<?php

require 'vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

$ADMIN_USER = $_ENV['ADMIN_USER'];
$ADMIN_PASSWORD = $_ENV['ADMIN_PASSWORD'];

$options = array(
	PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
	PDO::MYSQL_ATTR_SSL_CA => '',
	PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
);

$db = new PDO(
	'mysql:host=' . $_ENV['DBURL'] . ';dbname=' . $_ENV['DBNAME'] . ';charset=utf8mb4',
	$_ENV['DBUSER'],
	$_ENV['DBPASSWORD'] ?? null,
	$options
);

?>
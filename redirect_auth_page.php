<?php
require 'config.php';
require 'helpers/grant_authorization_helpers.php';
require 'helpers/db_helpers.php';

$organizationSlug = $_GET['organizationSlug'];

// Obtenir les valeurs nécessaires
$clientId = $_SESSION['client_id'];
$environment = $_SESSION['environment'];

// Générer l'URL d'autorisation
$authorizationUrl = generateAuthorizationUrl($clientId, $isLocal, $environment, $organizationSlug, $db);

// Rediriger vers l'URL générée
header('Location: ' . $authorizationUrl);
exit;


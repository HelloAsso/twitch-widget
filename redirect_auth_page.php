<?php
require 'config.php';
require 'helpers/grant_authorization_helpers.php';
require 'helpers/authentication_helpers.php';
require 'helpers/db_helpers.php';

$organizationSlug = $_GET['organizationSlug'];
$environment = $_SESSION['environment'];

$PartnerTokenData = GetAccessTokensAndRefreshIfNecessary($db, $environment, null);

// Vérifiez si $tokenData est un tableau, sinon gérez l'erreur
if (!is_array($PartnerTokenData)) {
    // Affichez l'erreur ou gérez-la de manière appropriée
    die('Erreur lors de la récupération du jeton d\'accès : ' . $PartnerTokenData);
}

$accessToken = $PartnerTokenData['access_token'];
$domain = $isLocal == true ? 'https://localhost' : 'https://twitch.helloasso.blog';

SetClientDomain($domain, $accessToken);

// Générer l'URL d'autorisation
$authorizationUrl = GenerateAuthorizationUrl($isLocal, $environment, $organizationSlug, $db);

// Rediriger vers l'URL générée
header('Location: ' . $authorizationUrl);
exit;


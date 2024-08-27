<?php
require 'app/Config.php';

$apiWrapper = Config::getInstance()->apiWrapper;

$organizationSlug = $_GET['organizationSlug'];

$PartnerTokenData = $apiWrapper->getAccessTokensAndRefreshIfNecessary(null);

// Vérifiez si $tokenData est un tableau, sinon gérez l'erreur
if (!is_array($PartnerTokenData)) {
    // Affichez l'erreur ou gérez-la de manière appropriée
    die('Erreur lors de la récupération du jeton d\'accès : ' . $PartnerTokenData);
}

$accessToken = $PartnerTokenData['access_token'];

$apiWrapper->setClientDomain(Config::getInstance()->webSiteDomain, $accessToken);

// Générer l'URL d'autorisation
$authorizationUrl = $apiWrapper->generateAuthorizationUrl($organizationSlug);

// Rediriger vers l'URL générée
header('Location: ' . $authorizationUrl);
exit;


<?php
require 'app/Config.php';

$apiWrapper = Config::getInstance()->apiWrapper;

$organizationSlug = $_GET['organizationSlug'];

$partnerTokenData = $apiWrapper->getAccessTokensAndRefreshIfNecessary(null);
$accessToken = $partnerTokenData['access_token'];

$apiWrapper->setClientDomain(Config::getInstance()->webSiteDomain, $accessToken);

// Générer l'URL d'autorisation
$authorizationUrl = $apiWrapper->generateAuthorizationUrl($organizationSlug);

// Rediriger vers l'URL générée
header('Location: ' . $authorizationUrl);
exit;


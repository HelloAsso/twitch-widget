<?php
require 'app/Config.php';

$repository = Config::getInstance()->repo;
$apiWrapper = Config::getInstance()->apiWrapper;

// Récupérer le paramètre 'state' depuis l'URL
$state = $_GET['state'];

// Récupérer la randomString depuis le cache avec l'id 'state'
$authorizationCodeData = $repository->getAuthorizationCodeByIdDB($state);
$redirect_uri = $authorizationCodeData['redirect_uri'];
$codeVerifier = $authorizationCodeData['code_verifier'];

// Le code est normalement renvoyé par la mire d'authentication
$code = $_GET['code'];

$tokenDataGrantAuthorization = $apiWrapper->exchangeAuthorizationCode($code, $redirect_uri, $codeVerifier);

// Vérifier que les tokens sont présents dans la réponse
if (
    !isset(
    $tokenDataGrantAuthorization['access_token'],
    $tokenDataGrantAuthorization['refresh_token'],
    $tokenDataGrantAuthorization['expires_in'],
    $tokenDataGrantAuthorization['organization_slug']
)
) {
    die("Erreur : Réponse API invalide, tokens manquants.");
}

// Calculer les dates d'expiration des tokens
$accessTokenExpiresAt = (new DateTime())->add(new DateInterval('PT28M'));
$refreshTokenExpiresAt = (new DateTime())->add(new DateInterval('P28D'));

$existingOrganizationToken = $apiWrapper->getAccessTokensAndRefreshIfNecessary($tokenDataGrantAuthorization['organization_slug']);

if ($existingOrganizationToken != null) {
    try {
        $repository->updateAccessTokenDB(
            Helpers::encryptToken($tokenDataGrantAuthorization['access_token']),
            Helpers::encryptToken($tokenDataGrantAuthorization['refresh_token']),
            $tokenDataGrantAuthorization['organization_slug'],
            $accessTokenExpiresAt,
            $refreshTokenExpiresAt
        );

        echo 'Votre compte ' . $tokenDataGrantAuthorization['organization_slug'] . ' été déjà lié à HelloAssoCharityStream, vous pouvez fermer cette page.';
    } catch (Exception $e) {
        die("Erreur de MAJ en base de données : " . $e->getMessage());
    }
} else {
    try {
        $repository->insertAccessTokenDB(
            Helpers::encryptToken($tokenDataGrantAuthorization['access_token']),
            Helpers::encryptToken($tokenDataGrantAuthorization['refresh_token']),
            $tokenDataGrantAuthorization['organization_slug'],
            $accessTokenExpiresAt,
            $refreshTokenExpiresAt
        );

        echo 'Votre compte ' . $tokenDataGrantAuthorization['organization_slug'] . ' à bien été lié à HelloAssoCharityStream, vous pouvez fermer cette page.';
    } catch (Exception $e) {
        die("Erreur lors de l'insertion en base de données : " . $e->getMessage());
    }
}
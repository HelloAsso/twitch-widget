<?php
require 'config.php';
require 'helpers/db_helpers.php';   
require 'helpers/session_helpers.php';   
require 'helpers/authentication_helpers.php';   
require 'helpers/grant_authorization_helpers.php';   

// Récupérer le paramètre 'state' depuis l'URL
$state =  $_GET['state'];
$env = $_GET['env'];

try {
    // Appeler la fonction pour mettre à jour les variables de session
    UpdatePhpSessionVariables($env);
} catch (Exception $e) {
    // Gérer l'erreur si nécessaire
    echo "Erreur: " . $e->getMessage();
}

// Récupérer la randomString depuis le cache avec l'id 'state'
$authorizationCodeData = GetAuthorizationCodeById($db, $state, $env);
$redirect_uri = $authorizationCodeData['redirect_uri'];
$codeVerifier = $authorizationCodeData['code_verifier'];

// Le code est normalement renvoyé par la mire d'authentication
$code = $_GET['code'];

// Appel API pour obtenir l'access_token et refresh_token
$client_id = $_SESSION['client_id'];
$client_secret = $_SESSION['client_secret'];

$tokenDataGrantAuthorization = ExchangeAuthorizationCode($client_id, $client_secret, $code, $redirect_uri, $codeVerifier);

// Vérifier que les tokens sont présents dans la réponse
if (!isset($tokenDataGrantAuthorization['access_token'],
 $tokenDataGrantAuthorization['refresh_token'],
  $tokenDataGrantAuthorization['expires_in'],
  $tokenDataGrantAuthorization['organization_slug'])) {
    die("Erreur : Réponse API invalide, tokens manquants.");
}

// Calculer les dates d'expiration des tokens
$accessTokenExpiresAt = (new DateTime())->add(new DateInterval('PT28M'))->format('Y-m-d H:i:s');
$refreshTokenExpiresAt = (new DateTime())->add(new DateInterval('P28D'))->format('Y-m-d H:i:s');

$existingOrganizationToken = GetAccessToken($db, $tokenDataGrantAuthorization['organization_slug'], $environment);

if($existingOrganizationToken != null)
{
    try 
    {
        UpdateAccessToken($db,
        $tokenDataGrantAuthorization['access_token'],
        $tokenDataGrantAuthorization['refresh_token'],
        $tokenDataGrantAuthorization['organization_slug'],
        $accessTokenExpiresAt,
        $refreshTokenExpiresAt,
        $env);

     echo 'Votre compte ' . $tokenDataGrantAuthorization['organization_slug'].' été déjà lié à HelloAssoCharityStream, vous pouvez fermer cette page.';
    } 
    catch (Exception $e) 
    {
        die("Erreur de MAJ en base de données : " . $e->getMessage());
    }
}
else
{
    try 
    {
        InsertAccessToken($db,
        EncryptToken($tokenDataGrantAuthorization['access_token']),
        EncryptToken($tokenDataGrantAuthorization['refresh_token']),
        $tokenDataGrantAuthorization['organization_slug'],
        $accessTokenExpiresAt,
        $refreshTokenExpiresAt,
        $env);
        
        echo 'Votre compte ' . $tokenDataGrantAuthorization['organization_slug'].' à bien été lié à HelloAssoCharityStream, vous pouvez fermer cette page.';
    } 
    catch (Exception $e) 
    {
        die("Erreur lors de l'insertion en base de données : " . $e->getMessage());
    }
}
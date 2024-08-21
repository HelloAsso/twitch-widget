<?php
require 'config.php';
require 'helpers/db_helpers.php';   
require 'helpers/session_helpers.php';   

// Récupérer le paramètre 'state' depuis l'URL
$state =  $_GET['state'];
$env = $_GET['env'];

try {
    // Appeler la fonction pour mettre à jour les variables de session
    updateSessionVariables($env);
} catch (Exception $e) {
    // Gérer l'erreur si nécessaire
    echo "Erreur: " . $e->getMessage();
}

// Récupérer la randomString depuis le cache avec l'id 'state'
$authorizationCodeData = GetAuthorizationCodeById($db, $state, $env);

// Le code est normalement renvoyé par la mire d'authentification
$code = $_GET['code'];

// Appel API pour obtenir l'access_token et refresh_token
$client_id = $_SESSION['client_id'];
$client_secret = $_SESSION['client_secret'];


$curl = curl_init();

curl_setopt_array($curl, array(
    CURLOPT_URL => 'https://api.helloasso-sandbox.com/v5/partners/me/api-clients',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => 'PUT',
    CURLOPT_POSTFIELDS =>'{
      "Domain" : "https://www.ffjudo.com"
  }',
    CURLOPT_HTTPHEADER => array(
      'Content-Type: application/json',
      'Authorization: '
    ),
  ));

$response = curl_exec($curl);
$http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

if ($http_code !== 200) {
    die("Erreur : L'appel API a échoué avec le code HTTP " . $http_code);
}

$curl = curl_init();

curl_setopt_array($curl, array(
    CURLOPT_URL => $_SESSION['api_auth_url'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_POSTFIELDS => http_build_query([
        'grant_type' => 'authorization_code',
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'code' => $code,
        'redirect_uri' => $authorizationCodeData['redirect_uri'],
        'code_verifier' => $authorizationCodeData['random_string']
    ]),
    CURLOPT_HTTPHEADER => array(
        'cache-control: no-cache',
        'content-type: application/x-www-form-urlencoded',
    ),
));

$response = curl_exec($curl);
$http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

if ($http_code !== 200) {
    die("Erreur : L'appel API a échoué avec le code HTTP " . $http_code);
}

$data = json_decode($response, true);

// Vérifier que les tokens sont présents dans la réponse
if (!isset($data['access_token'], $data['refresh_token'], $data['expires_in'])) {
    die("Erreur : Réponse API invalide, tokens manquants.");
}

// Calculer les dates d'expiration des tokens
$accessTokenExpiresAt = (new DateTime())->add(new DateInterval('PT' . $data['expires_in'] . 'S'));
$refreshTokenExpiresAt = (new DateTime())->add(new DateInterval('P30D')); // Supposons que le refresh_token expire dans 30 jours

// 7. Insérer les tokens dans la base de données
try {
    InsertAccessToken($db, $data['access_token'], $data['refresh_token'], $data['organization_slug'], $accessTokenExpiresAt, $refreshTokenExpiresAt, $env);
    echo "Les tokens ont été insérés avec succès.";
} catch (Exception $e) {
    die("Erreur lors de l'insertion en base de données : " . $e->getMessage());
}



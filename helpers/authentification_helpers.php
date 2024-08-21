<?php

require 'config.php';

// Clé de chiffrement secrète (ne la partagez pas publiquement et stockez-la de manière sécurisée)
define('ENCRYPTION_KEY', $encryption_key);

function encryptToken($token) {
    // Méthode de chiffrement AES-256-CBC
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $encryptedToken = openssl_encrypt($token, 'aes-256-cbc', ENCRYPTION_KEY, 0, $iv);
    // Stocker l'IV avec le token chiffré pour pouvoir le déchiffrer plus tard
    return base64_encode($encryptedToken . '::' . $iv);
}

function decryptToken($encryptedToken) {
    // Décodage base64
    list($encryptedData, $iv) = explode('::', base64_decode($encryptedToken), 2);
    return openssl_decrypt($encryptedData, 'aes-256-cbc', ENCRYPTION_KEY, 0, $iv);
}

function GenerateGlobalAccessToken($db, $env) {

    // Configurer la requête cURL
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
            'grant_type' => 'client_credentials',
            'client_id' => $_SESSION['client_id'],
            'client_secret' => $_SESSION['client_secret']
        ]),
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/x-www-form-urlencoded'
        ),
    ));

    // Exécuter la requête
    $response = curl_exec($curl);

    // Gérer les erreurs cURL
    if (curl_errno($curl)) {
        $error_msg = curl_error($curl);
        curl_close($curl);
        return 'Erreur cURL : ' . $error_msg;
    }

    // Fermer cURL
    curl_close($curl);

    $accessTokenExpiresAt = (new DateTime())->add(new DateInterval('PT1700S'));
    $refreshTokenExpiresAt = (new DateTime())->add(new DateInterval('P29D')); // Supposons que le refresh_token expire dans 30 jours

    InsertAccessToken($db, encryptToken($response['access_token']), encryptToken($response['refresh_token']), null, $accessTokenExpiresAt, $refreshTokenExpiresAt, $env);
    // Retourner la réponse
    return $response;
}


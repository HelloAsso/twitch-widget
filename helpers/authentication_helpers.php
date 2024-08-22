<?php

require 'config.php';

// Clé de chiffrement secrète (ne la partagez pas publiquement et stockez-la de manière sécurisée)
define('ENCRYPTION_KEY', $encryption_key);

function EncryptToken($token) {
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

function GetGlobalAccessToken($db, $env){
    $tokenData = GetAccessToken($db, null, $env);

    if($tokenData == null){
        $tokenData = GenerateGlobalAccessToken($db, $env);
        return $tokenData;
    }
    else 
    {
        $tokenData['refresh_token'] = decryptToken($tokenData['refresh_token']);
        if($tokenData['access_token_expires_at'] < date('Y-m-d H:i:s'))
        {
            $tokenData = RefreshToken($tokenData['refresh_token'], $env, null, $db);
            return $tokenData;
        }
        $tokenData['access_token'] = decryptToken($tokenData['access_token']);
        return $tokenData;
    }
}

function GenerateGlobalAccessToken($db, $env) {  
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

    // Décoder la réponse JSON
    $responseData = json_decode($response, true);

    // Vérifier si la réponse a bien été décodée
    if (json_last_error() !== JSON_ERROR_NONE) {
        return 'Erreur de décodage JSON : ' . json_last_error_msg();
    }

    // Vérifier que les tokens sont présents dans la réponse
    if (!isset($responseData['access_token']) || !isset($responseData['refresh_token'])) {
        return 'Erreur : Les tokens ne sont pas présents dans la réponse.';
    }

    $accessTokenExpiresAt = (new DateTime())->add(new DateInterval('PT1700S'));
    $refreshTokenExpiresAt = (new DateTime())->add(new DateInterval('P29D'));

    // Insérer les tokens en base de données
    InsertAccessToken(
        $db,
        EncryptToken($responseData['access_token']),
        EncryptToken($responseData['refresh_token']),
        null,
        $accessTokenExpiresAt,
        $refreshTokenExpiresAt,
        $env
    );

    return $responseData;
}

function RefreshToken($refreshToken, $env, $organization_slug, $db){
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
      CURLOPT_POSTFIELDS => 'grant_type=refresh_token&refresh_token='. $refreshToken,
      CURLOPT_HTTPHEADER => array(
        'Cache-Control: no-cache',
        'content-type: application/x-www-form-urlencoded',
      ),
    ));
    
    $response = curl_exec($curl);
    
    // Décoder la réponse JSON
    $responseData = json_decode($response, true);

    // Vérifier si la réponse a bien été décodée
    if (json_last_error() !== JSON_ERROR_NONE) {
        return 'Erreur de décodage JSON : ' . json_last_error_msg();
    }

    curl_close($curl);

    // Calculer les dates d'expiration des tokens
    $accessTokenExpiresAt = (new DateTime())->add(new DateInterval('PT28M'))->format('Y-m-d H:i:s');
    $refreshTokenExpiresAt = (new DateTime())->add(new DateInterval('P28D'))->format('Y-m-d H:i:s');

    UpdateAccessToken($db,
    EncryptToken($responseData['access_token']),
    EncryptToken($responseData['refresh_token']),
    $organization_slug,
    $accessTokenExpiresAt,
    $refreshTokenExpiresAt,
    $env);

    return $responseData;
}
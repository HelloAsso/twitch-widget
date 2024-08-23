<?php

require 'config.php';

function GenerateUUID() {
    return bin2hex(random_bytes(16));
}

function GeneratePKCEChallenge($plainText) {
    // Étape 1 : Hacher la chaîne avec SHA-256
    $hashed = hash('sha256', $plainText, true);

    // Étape 2 : Encoder en base64 (sans les =, +, /)
    $base64Encoded = rtrim(strtr(base64_encode($hashed), '+/', '-_'), '=');

    return $base64Encoded;
}

function GenerateRandomString($length = 80) {
    $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $charactersLength = strlen($characters);
    $randomString = '';

    for ($i = 0; $i < $length; $i++) {
        $randomIndex = random_int(0, $charactersLength - 1);
        $randomString .= $characters[$randomIndex];
    }

    return $randomString;
}

function GenerateAuthorizationUrl( $isLocal, $environment, $organization_slug, $db) {
    $env = strtolower($environment);
    error_log($env);
    $uniqueUUID = GenerateUUID();
    $codeVerifier = GenerateRandomString();
    $redirectUri = $isLocal 
    ? 'https://localhost/validate_grant_authorization.php?env=' . $environment
    : 'https://twitch.helloasso.blog/validate_grant_authorization.php?env=' . $environment;

    InsertAuthorizationCodeDB($db, $uniqueUUID, $codeVerifier, $redirectUri, $organization_slug, $environment);

    // Définir l'URL de base selon la valeur de $isLocal
    $baseUrl = $env == "prod" 
    ? 'https://auth.helloasso.com'
    : 'https://auth.helloasso-' . $environment . '.com';

    // Générer le code challenge
    $codeChallenge = GeneratePKCEChallenge($codeVerifier);

    // Construire l'URL finale
    $authorizationUrl = $baseUrl . "/authorize?" . http_build_query([
        'client_id' => $_SESSION['client_id'],
        'redirect_uri' => $redirectUri,
        'code_challenge' => $codeChallenge,
        'code_challenge_method' => 'S256',
        'state' => $uniqueUUID
    ]);

    return $authorizationUrl;
}

function SetClientDomain($domain, $accessToken){
    $curl = curl_init();

    curl_setopt_array($curl, array(
        CURLOPT_URL => $_SESSION['api_url'] . '/partners/me/api-clients',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => json_encode([
            'Domain' => $domain
        ]),
        CURLOPT_HTTPHEADER => array(
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken
        ),
    ));

    $response = curl_exec($curl);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($http_code !== 200) {
        die("Erreur Set Domain : L'appel API a échoué avec le code HTTP " . $http_code);
    }
}

function ExchangeAuthorizationCode($client_id, $client_secret, $code, $redirect_uri, $codeVerifier){
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
            'redirect_uri' => $redirect_uri,
            'code_verifier' => $codeVerifier
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

    // Décoder la réponse JSON
    $responseData = json_decode($response, true);

    // Vérifier si la réponse a bien été décodée
    if (json_last_error() !== JSON_ERROR_NONE) {
        return 'Erreur de décodage JSON : ' . json_last_error_msg();
    }

    return $responseData;
}
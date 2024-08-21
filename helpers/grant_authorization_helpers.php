<?php

require 'config.php';

function generateUUID() {
    return bin2hex(random_bytes(16));
}

function generatePKCEChallenge($plainText) {
    // Étape 1 : Hacher la chaîne avec SHA-256
    $hashed = hash('sha256', $plainText, true);

    // Étape 2 : Encoder en base64 (sans les =, +, /)
    $base64Encoded = rtrim(strtr(base64_encode($hashed), '+/', '-_'), '=');

    return $base64Encoded;
}

function generateRandomString($length = 80) {
    $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $charactersLength = strlen($characters);
    $randomString = '';

    for ($i = 0; $i < $length; $i++) {
        $randomIndex = random_int(0, $charactersLength - 1);
        $randomString .= $characters[$randomIndex];
    }

    return $randomString;
}

function generateAuthorizationUrl($clientId, $isLocal, $environment, $organization_slug, $db) {
    $env = strtolower($environment);
    error_log($env);
    $uniqueUUID = generateUUID();
    $random_string = generateRandomString();
    $redirectUri = $isLocal 
    ? 'https://localhost/validate_grant_authorization.php?env=' . $environment
    : 'https://twitch.helloasso.blog/validate_grant_authorization.php?env=' . $environment;

    InsertAuthorizationCode($db, $uniqueUUID, $random_string, $redirectUri, $organization_slug, $environment);

    // Définir l'URL de base selon la valeur de $isLocal
    $baseUrl = $env == "prod" 
    ? 'https://auth.helloasso.com'
    : 'https://auth.helloasso-' . $environment . '.com';

    // Générer le code challenge
    $codeChallenge = generatePKCEChallenge($random_string);

    // Construire l'URL finale
    $authorizationUrl = $baseUrl . "/authorize?" . http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'code_challenge' => $codeChallenge,
        'code_challenge_method' => 'S256',
        'state' => $uniqueUUID
    ]);

    return $authorizationUrl;
}

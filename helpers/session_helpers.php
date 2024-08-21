<?php

function updateSessionVariables($environment) {

    $environment = strtoupper($environment);

    // Mettre à jour la variable de session pour l'environnement
    $_SESSION['environment'] = $environment;
    $_SESSION['client_id'] = $_ENV['CLIENT_ID_' . $environment];
    $_SESSION['client_secret'] = $_ENV['CLIENT_SECRET_' . $environment];
    $_SESSION['blob_url'] = $_ENV['BLOB_URL_' . $environment];
    $_SESSION['api_url'] = $_ENV['API_URL_' . $environment];
    $_SESSION['api_auth_url'] = $_ENV['API_AUTH_URL_' . $environment];
}

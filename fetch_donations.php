<?php
// Inclusion des fichiers de configuration et des helpers
require 'config.php';
require 'helpers/db_helpers.php';
require 'helpers/organization_orders_helpers.php'; 
require 'helpers/authentication_helpers.php'; 

// Récupération de l'ID du Charity Stream depuis la query string
$charityStreamId = $_GET['charityStreamId'] ?? '';
if (!$charityStreamId) {
    http_response_code(400);
    echo json_encode(['error' => 'Charity Stream ID manquant ou incorrect.']);
    exit;
}

// Conversion de l'ID en format binaire
$guidBinary = hex2bin($charityStreamId);

// Récupération des données du Charity Stream correspondant à l'ID
$charityStream = GetCharityStreamByGuidDB($db, $environment, $guidBinary);
if (!$charityStream) {
    http_response_code(404);
    echo json_encode(['error' => 'Charity Stream non trouvé.']);
    exit;
}

// Initialisation du montant actuel des donations et des tokens pour la pagination
$currentAmount = 0;
$continuationToken = null;
$previousToken = ''; // Pour suivre les tokens et détecter la fin de la pagination

// Récupération du jeton d'accès pour l'API
$organizationAccessToken = GetAccessTokensAndRefreshIfNecessary($db,  $environment, $charityStream['organization_slug']);
if (!$organizationAccessToken || !isset($organizationAccessToken['access_token'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Jeton d\'accès API non trouvé ou expiré.']);
    exit;
}

// Boucle pour récupérer toutes les pages de données de l'API
do {
    // Appel de l'API pour récupérer les ordres de donations avec le continuationToken s'il existe
    $formOrdersData = GetDonationFormOrders(
        $charityStream['organization_slug'], 
        $charityStream['form_slug'], 
        $organizationAccessToken['access_token'], 
        $continuationToken
    );

    if (!isset($formOrdersData['data'])) {
        http_response_code(500);
        echo json_encode(['error' => 'Erreur lors de la récupération des données de l\'API.']);
        exit;
    }

    // Incrémentation du montant total avec les montants récupérés dans cette page
    foreach ($formOrdersData['data'] as $order) {
        if (isset($order['amount']['total']) && is_numeric($order['amount']['total'])) {
            $currentAmount += (float)$order['amount']['total'];  // Utiliser le montant total
        }
    }

    // Mise à jour du continuationToken pour récupérer la page suivante
    $previousToken = $continuationToken;
    $continuationToken = $formOrdersData['continuationToken'] ?? null;

    // Tant que le token actuel est différent de l'ancien, on continue la boucle
} while ($continuationToken && $continuationToken !== $previousToken);

// Retourne le montant total des donations sous forme de JSON
echo json_encode(['currentAmount' => $currentAmount]);

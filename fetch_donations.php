<?php
require 'app/Config.php';

$repository = Config::getInstance()->repo;
$apiWrapper = Config::getInstance()->apiWrapper;

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
$charityStream = $repository->getCharityStreamByGuidDB($guidBinary);
if (!$charityStream) {
    http_response_code(404);
    echo json_encode(['error' => 'Charity Stream non trouvé.']);
    exit;
}

// Initialisation du montant actuel et des tokens pour la pagination
$currentAmount = $_GET['currentAmount'] ?? 0;
$continuationToken = $_GET['continuationToken'] ?? null;
$from = $_GET['from'] ?? null;

try {
    $result = $apiWrapper->GetAllOrders(
        $charityStream['organization_slug'], 
        $charityStream['form_slug'], 
        $currentAmount,
        $continuationToken,
        $from);

    echo json_encode($result);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Impossible de récupérer les commandes.']);
}
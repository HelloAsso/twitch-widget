<?php
require 'config.php';
require 'helpers/db_helpers.php';

$charityStreamId = $_GET['charityStreamId'] ?? '';
if (!$charityStreamId) {
    die("Charity Stream ID manquant ou incorrect.");
}

// Récupérer le GUID correspondant au charity_stream_id
$guidBinary = hex2bin($charityStreamId);

// Récupérer les données du widget donation goal en fonction du GUID
$donationGoalWidget = GetDonationGoalWidgetByGuid($db, $environment, $guidBinary);
if (!$donationGoalWidget) {
    die("Aucun widget trouvé pour le Charity Stream ID fourni.");
}

// Calculer la progression actuelle
$goalAmount = (int)$donationGoalWidget['goal'];
$currentAmount = 1000;  // Assurez-vous que ce champ existe et est mis à jour dans votre base de données
$percentage = ($goalAmount > 0) ? min(100, ($currentAmount / $goalAmount) * 100) : 0;

// Appliquer les couleurs du widget
$textColor = htmlspecialchars($donationGoalWidget['text_color']);
$barColor = htmlspecialchars($donationGoalWidget['bar_color']);
$backgroundColor = htmlspecialchars($donationGoalWidget['background_color']);

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Donation Goal Widget</title>
    <link rel="stylesheet" href="styles/widget_donation_bar_goal.css">
</head>
<body>
    <div class='goal-cont'>
        <div style='position: relative'>
            <div id='goal-bar' style="background-color: <?php echo htmlspecialchars($donationGoalWidget['background_color']); ?>; border-color: <?php echo htmlspecialchars($donationGoalWidget['text_color']); ?>;">
                <p id='goal-current' style="color: <?php echo htmlspecialchars($donationGoalWidget['text_color']); ?>;">
                    <?php echo $currentAmount; ?> €
                </p>
                <p id='title' style="color: <?php echo htmlspecialchars($donationGoalWidget['text_color']); ?>;">
                    Donations
                </p>
                <p id='goal-total' style="color: <?php echo htmlspecialchars($donationGoalWidget['text_color']); ?>;">
                    <?php echo $goalAmount; ?> €
                </p>
                <div id='total-bar' style="background-color: <?php echo htmlspecialchars($donationGoalWidget['bar_color']); ?>; width: <?php echo ($currentAmount / $goalAmount) * 100; ?>%;">
                </div>
            </div>
        </div>
    </div>
</body>
</html>

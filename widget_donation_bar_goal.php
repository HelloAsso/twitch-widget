<?php
require 'config.php';
require 'db_helpers.php';

$charityStreamId = $_GET['charity_stream_id'] ?? '';
if (!$charityStreamId) {
    die("Charity Stream ID manquant ou incorrect.");
}

// Récupérer le GUID correspondant au charity_stream_id
$guidBinary = hex2bin($charityStreamId);

// Récupérer les données du widget donation goal en fonction du GUID
$donationGoalWidget = GetDonationGoalWidgetByGuid($db, $guidBinary);
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
    <style>
        /* Style fourni pour la barre de donation */
        .goal-cont {
            margin: 10px;
            color: <?php echo $textColor; ?>;
            -webkit-box-shadow: 0px 0px 10px 0px rgba(0,0,0,0.5);
            -moz-box-shadow: 0px 0px 10px 0px rgba(0,0,0,0.5);
            box-shadow: 0px 0px 10px 0px rgba(0,0,0,0.5);
        }

        #title {
            font-size: 28px;
            text-transform: uppercase;
            font-weight: bold;
            font-family: Roboto, sans-serif;
            text-align: center;
            text-shadow: 0px 0px 10px #000;
            margin: 0;
            width: 70%;
            z-index: 1;
        }

        #goal-bar {
            border: 4px solid <?php echo $textColor; ?>;
            background-color: <?php echo $backgroundColor; ?>;
            padding: 16px;
            display: flex;
            position: relative;
            overflow: hidden;
        }

        #goal-current,
        #goal-total {
            margin: 0;
            font-family: Roboto, sans-serif;
            font-weight: bold;
            font-size: 28px;
            text-shadow: 0px 0px 10px #000;
            width: 15%;
            z-index: 1;
        }

        #goal-total {
            text-align: right;
        }

        #total-bar {
            position: absolute;
            width: calc(<?php echo $percentage; ?>% - 8px);
            height: calc(100% - 8px);
            max-width: 100%;
            background: <?php echo $barColor; ?>;
            top: 4px;
            left: 4px;
            overflow: hidden;
        }
    </style>
</head>
<body>
    <div class="goal-cont">
        <div style="position: relative">
            <div id="goal-bar">
                <p id="goal-current"><?php echo $currentAmount; ?> €</p>
                <p id="title">Donations</p>
                <p id="goal-total"><?php echo $goalAmount; ?> €</p>
                <div id="total-bar"></div>
            </div>
        </div>
    </div>
</body>
</html>

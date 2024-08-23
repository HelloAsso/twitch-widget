<?php
require 'config.php';
require 'helpers/db_helpers.php';
require 'helpers/organization_orders_helpers.php';

$charityStreamId = $_GET['charityStreamId'] ?? '';
if (!$charityStreamId) {
    die("Charity Stream ID manquant ou incorrect.");
}

// Récupérer le GUID correspondant au charity_stream_id
$guidBinary = hex2bin($charityStreamId);

// Récupérer les données du widget donation goal en fonction du GUID
$donationGoalWidget = GetDonationGoalWidgetByGuidDB($db, $environment, $guidBinary);
if (!$donationGoalWidget) {
    die("Aucun widget trouvé pour le Charity Stream ID fourni.");
}

// Récupérer les données du charity stream en fonction du GUID
$charityStream = GetCharityStreamByGuidDB($db, $environment, $guidBinary);
if (!$charityStream) {
    die("Aucun charity stream trouvé pour le Charity Stream ID fourni.");
}

// Initialisation des valeurs de départ
$goalAmount = (int)$donationGoalWidget['goal'];
$currentAmount = 0;

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
            <div id='goal-bar' style="background-color: <?php echo $backgroundColor; ?>; border-color: <?php echo $textColor; ?>;">
                <p id='goal-current' style="color: <?php echo $textColor; ?>;">
                    <?php echo $currentAmount; ?> €
                </p>
                <p id='title' style="color: <?php echo $textColor; ?>;">
                    Donations
                </p>
                <p id='goal-total' style="color: <?php echo $textColor; ?>;">
                    <?php echo $goalAmount; ?> €
                </p>
                <div id='total-bar' style="background-color: <?php echo $barColor; ?>; width: 0%;">
                </div>
            </div>
        </div>
    </div>

    <script>
        const goalAmount = <?php echo $goalAmount; ?>;

        function updateDonationBar(currentAmount) {
            const currentAmountUnit = currentAmount / 100;
            const percentage = Math.min(100, (currentAmountUnit / goalAmount) * 100);
            document.getElementById('goal-current').textContent = currentAmountUnit + ' €';
            document.getElementById('total-bar').style.width = percentage + '%';
        }

        async function fetchDonationAmount() {
            try {
                // Appel AJAX vers un script PHP pour récupérer les données de l'API côté serveur
                const response = await fetch('fetch_donations.php?charityStreamId=<?php echo $charityStreamId; ?>');
                const data = await response.json();

                // Mettre à jour la barre avec le montant récupéré
                const currentAmount = data.currentAmount;
                updateDonationBar(currentAmount);

            } catch (error) {
                console.error('Erreur lors de la récupération des données de donation:', error);
            }
        }

        // Appel API toutes les 15 secondes
        setInterval(fetchDonationAmount, 2000);
    </script>
</body>
</html>

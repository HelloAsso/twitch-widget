<?php
require 'app/Config.php';

$repository = Config::getInstance()->repo;
$apiWrapper = Config::getInstance()->apiWrapper;

$charityStreamId = $_GET['charityStreamId'] ?? '';
if (!$charityStreamId) {
    die("Charity Stream ID manquant ou incorrect.");
}

// Récupérer le GUID correspondant au charity_stream_id
$guidBinary = hex2bin($charityStreamId);

// Récupérer les données du widget donation goal en fonction du GUID
$donationGoalWidget = $repository->getDonationGoalWidgetByGuidDB($guidBinary);
if (!$donationGoalWidget) {
    die("Aucun widget trouvé pour le Charity Stream ID fourni.");
}

$charityStream = $repository->getCharityStreamByGuidDB($guidBinary);
if (!$charityStream) {
    die("Charity Stream non trouvé.");
}

// Initialisation des valeurs de départ
$goalAmount = (int) $donationGoalWidget['goal'];

$result = $apiWrapper->GetAllOrders(
    $charityStream['organization_slug'], 
    $charityStream['form_slug']);

$currentAmount = $result['amount'];
$continuationToken = $result['continuationToken'];

// Appliquer les couleurs du widget
$textColor = htmlspecialchars($donationGoalWidget['text_color']);
$textContent = htmlspecialchars($donationGoalWidget['text_content']);
$barColor = htmlspecialchars($donationGoalWidget['bar_color']);
$backgroundColor = htmlspecialchars($donationGoalWidget['background_color']);

?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <title>Donation Goal Widget</title>
    <link rel="stylesheet" href="/css/main.min.css">
</head>

<body>
    <div class='widget-donation-goal'>
        <div id='goal-bar'
            style="background-color: <?php echo $backgroundColor; ?>; border-color: <?php echo $textColor; ?>;">
            <p id='goal-current' style="color: <?php echo $textColor; ?>;">
                <?php echo $currentAmount / 100; ?> €
            </p>
            <p id='title' style="color: <?php echo $textColor; ?>;">
                <?php echo $textContent; ?>
            </p>
            <p id='goal-total' style="color: <?php echo $textColor; ?>;">
                <?php echo $goalAmount; ?> €
            </p>
            <div id='total-bar' style="background-color: <?php echo $barColor; ?>; width: 0%;">
            </div>
        </div>
    </div>

    <script>
        var goalAmount = <?php echo $goalAmount; ?>;
        var charityStreamId = '<?php echo $charityStreamId; ?>';
        var currentAmount = <?php echo $currentAmount; ?>;
        var continuationToken = '<?php echo $continuationToken; ?>';
    </script>

    <script src="/js/main.min.js"></script>

    <script>
        updateDonationBar();
        setInterval(fetchDonation, 10000);
    </script>
</body>

</html>
<?php
require 'app/Config.php';

$repository = Config::getInstance()->repo;
$apiWrapper = Config::getInstance()->apiWrapper;

$charityStreamId = $_GET['charityStreamId'] ?? '';
if (!$charityStreamId) {
    throw new Exception("Charity Stream ID manquant ou incorrect.");
}

// Récupérer le GUID correspondant au charity_stream_id
$guidBinary = hex2bin($charityStreamId);

// Récupérer les données du widget donation goal en fonction du GUID
$donationGoalWidget = $repository->getDonationGoalWidgetByGuidDB($guidBinary);
if (!$donationGoalWidget) {
    throw new Exception("Aucun widget trouvé pour le Charity Stream ID fourni.");
}

$charityStream = $repository->getCharityStreamByGuidDB($guidBinary);
if (!$charityStream) {
    throw new Exception("Charity Stream non trouvé.");
}

// Initialisation des valeurs de départ
$goalAmount = (int) $donationGoalWidget['goal'];

$result = $apiWrapper->GetAllOrders(
    $charityStream['organization_slug'], 
    $charityStream['form_slug']);

$currentAmount = $result['amount'];
$continuationToken = $result['continuationToken'];

// Appliquer les couleurs du widget
$textColorMain = htmlspecialchars($donationGoalWidget['text_color_main']);
$textColorAlt = htmlspecialchars($donationGoalWidget['text_color_alt']);
$textContent = htmlspecialchars($donationGoalWidget['text_content']);
$barColor = htmlspecialchars($donationGoalWidget['bar_color']);
$backgroundColor = htmlspecialchars($donationGoalWidget['background_color']);

?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <title>Donation Goal Widget</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:ital,wght@0,300..800;1,300..800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="/css/main.min.css">
</head>

<body>
    <div class="progress">
      <div class="back" style="background:<?php echo $backgroundColor; ?>;color:<?php echo $textColorMain; ?>">
        <p id="back-goal-current"><?php echo $currentAmount / 100; ?> €</p>
        <p id="back-title"><?php echo $textContent; ?></p>
        <p id="back-goal-total"><?php echo $goalAmount; ?> €</p>
      </div>
      <div class="front" style="background:<?php echo $barColor; ?>;color:<?php echo $textColorAlt; ?>">
        <p id="front-goal-current"><?php echo $currentAmount / 100; ?> €</p>
        <p id="front-title"><?php echo $textContent; ?></p>
        <p id="front-goal-total"><?php echo $goalAmount; ?> €</p>
      </div>
    </div>

    <script>
        var goalAmount = <?php echo $goalAmount; ?>;
        var charityStreamId = '<?php echo $charityStreamId; ?>';
        var currentAmount = <?php echo $currentAmount; ?>;
        var continuationToken = '<?php echo $continuationToken; ?>';
    </script>

    <script src="/node_modules/countup.js/dist/countUp.umd.js"></script>
    <script src="/js/main.min.js"></script>

    <script>
        updateDonationBar();
        setInterval(fetchDonation, 10000);
    </script>
</body>

</html>
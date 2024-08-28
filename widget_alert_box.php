<?php
require 'app/Config.php';

$repository = Config::getInstance()->repo;
$fileManager = Config::getInstance()->fileManager;

$charityStreamId = $_GET['charityStreamId'] ?? '';
if (!$charityStreamId) {
    die("Charity Stream ID manquant ou incorrect.");
}

// Récupérer le GUID correspondant au charity_stream_id
$guidBinary = hex2bin($charityStreamId);

// Récupérer les données du widget donation goal en fonction du GUID
$alertBoxWidget = $repository->getAlertBoxWidgetByGuidDB($guidBinary);
if (!$alertBoxWidget) {
    die("Aucun widget trouvé pour le Charity Stream ID fourni.");
}

// Initialisation des valeurs de départ
$image = htmlspecialchars($alertBoxWidget['image']);
$alert_duration = (int) $alertBoxWidget['alert_duration'];
$message_template = htmlspecialchars($alertBoxWidget['message_template']);
$sound = htmlspecialchars($alertBoxWidget['sound']);
$sound_volume = (int) $alertBoxWidget['sound_volume'];

?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <title>Alert Box Widget</title>
    <link rel="stylesheet" href="/css/main.min.css">
</head>

<body>
    <div class='widget-alert-box'>
    </div>

    <script src="/node_modules/moment/min/moment.min.js"></script>

    <script>
        var image = '<?php echo $fileManager->getPictureUrl($alertBoxWidget['image']); ?>';
        var message_template = '<?php echo $alertBoxWidget['message_template']; ?>';
        var sound = '<?php echo $fileManager->getSoundUrl($alertBoxWidget['sound']); ?>';
        var sound_volume = <?php echo $alertBoxWidget['sound_volume'] / 100; ?>;
        var alert_duration = <?php echo $alertBoxWidget['alert_duration'] * 1000; ?>;
        var charityStreamId = '<?php echo $charityStreamId; ?>';
        var from = moment().format('YYYY-MM-DDTHH:mm:ss');
    </script>

    <script src="/js/main.min.js"></script>

    <script>
        // test purpose
        //displayAlertBox();

        setInterval(fetchDonation, 10000);
    </script>
</body>

</html>
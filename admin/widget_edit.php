<?php
require '../app/Config.php';

$canAccess = isset($_SESSION['user_email']) || 
    in_array($_SERVER['REMOTE_ADDR'], Config::getInstance()->haIps);

if(!$canAccess) {
    header("Location: /index.php");
}

$repository = Config::getInstance()->repo;
$fileManager = Config::getInstance()->fileManager;

if(isset($_SESSION['user_email'])) {
    $charityStreams = $repository->getCharityStreamByEmail($_SESSION['user_email']);
    $guidBinary = $charityStreams[0]['guid'];
    $guidHex = bin2hex($charityStreams[0]['guid']);
} else {
    $guidHex = $_GET['charityStreamId'] ?? '';
    if (!$guidHex) {
        throw new Exception("GUID manquant ou incorrect.");
    }
    $guidBinary = hex2bin($guidHex);
}

$charityStream = $repository->getCharityStreamByGuidDB($guidBinary);
$donationGoalWidget = $repository->getDonationGoalWidgetByGuidDB($guidBinary);
$alertBoxWidget = $repository->getAlertBoxWidgetByGuidDB($guidBinary);

$donationUrl = Config::getInstance()->haUrl . '/associations/' . $charityStream['organization_slug'] . '/formulaires/' . $charityStream['form_slug'];

$widgetDonationGoalUrl = Config::getInstance()->webSiteDomain . '/widget_donation_goal.php?charityStreamId=' . $guidHex;
$widgetAlertBoxUrl = Config::getInstance()->webSiteDomain . '/widget_alert_box.php?charityStreamId=' . $guidHex;

// Traitement du formulaire de mise à jour pour chaque widget
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_donation_goal'])) {
        $repository->updateDonationGoalWidgetDB($guidBinary, $_POST);
    }

    if (isset($_POST['save_alert_box'])) {
        if (isset($_FILES["image"]) && $_FILES["image"]['size'] > 0)
            $image = $fileManager->uploadPicture($_FILES["image"]);
        if (isset($_FILES["sound"]) && $_FILES["sound"]['size'] > 0)
            $sound = $fileManager->uploadSound($_FILES["sound"]);

        $repository->updateAlertBoxWidgetDB($guidBinary, $_POST, $image ?? null, $sound ?? null);
    }

    // Redirection pour éviter de renvoyer les formulaires
    header("Location: widget_edit.php?charityStreamId=" . $guidHex);
    exit();
}
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <title>Édition</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:ital,wght@0,300..800;1,300..800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="/node_modules/bootstrap/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="/css/main.min.css">
    <style>
        .front {
            clip-path: inset(0 50% 0 0 round 999px);
            -webkit-clip-path: inset(0 50% 0 0 round 999px);
        }
    </style>
</head>

<body class="bg-light">
    <div class="container">
        <h1 class="my-4 text-center">Édition</h1>

        <!-- Lien pour revenir à l'index -->
        <a href="index.php" class="btn btn-secondary mb-4">Retour</a>

        <!-- Formulaire pour widget_donation_goal_bar -->
        <h5>
            <?php echo 'Formulaire de don Helloasso: '; ?>
            <a href="<?php echo $donationUrl; ?>" target="_blank"><?php echo $donationUrl; ?></a>
        </h5>
        <hr class="my-4">
        <h2>Widget barre de don</h2>
        <form method="POST">
            <div class="mb-3">
                <label for="text_color_main" class="form-label">Couleur du texte primaire</label>
                <input type="color" class="form-control form-control-color" id="text_color_main" name="text_color_main"
                    value="<?php echo htmlspecialchars($donationGoalWidget['text_color_main']); ?>">
            </div>
            <div class="mb-3">
                <label for="text_color_alt" class="form-label">Couleur du texte secondaire</label>
                <input type="color" class="form-control form-control-color" id="text_color_alt" name="text_color_alt"
                    value="<?php echo htmlspecialchars($donationGoalWidget['text_color_alt']); ?>">
            </div>
            <div class="mb-3">
                <label for="text_content" class="form-label">Texte</label>
                <input type="text" class="form-control" id="text_content" name="text_content"
                    value="<?php echo htmlspecialchars($donationGoalWidget['text_content']); ?>">
            </div>
            <div class="mb-3">
                <label for="bar_color" class="form-label">Couleur de la barre</label>
                <input type="color" class="form-control form-control-color" id="bar_color" name="bar_color"
                    value="<?php echo htmlspecialchars($donationGoalWidget['bar_color']); ?>">
            </div>
            <div class="mb-3">
                <label for="background_color" class="form-label">Couleur du fond</label>
                <input type="color" class="form-control form-control-color" id="background_color"
                    name="background_color"
                    value="<?php echo htmlspecialchars($donationGoalWidget['background_color']); ?>">
            </div>
            <div class="mb-3">
                <label for="goal" class="form-label">Objectif</label>
                <input type="number" class="form-control" id="goal" name="goal"
                    value="<?php echo htmlspecialchars($donationGoalWidget['goal']); ?>">
            </div>
            <br />
            <!-- Prévisualisation de la barre de donation -->
            <div class="progress">
                <div class="back" style="background:<?php echo htmlspecialchars($donationGoalWidget['background_color']); ?>;color:<?php echo htmlspecialchars($donationGoalWidget['text_color_main']); ?>">
                    <p id="back-goal-current"><?php echo ($donationGoalWidget['goal'] / 2); ?> €</p>
                    <p id="back-title"><?php echo htmlspecialchars($donationGoalWidget['text_content']); ?></p>
                    <p id="back-goal-total"><?php echo $donationGoalWidget['goal']; ?> €</p>
                </div>
                <div class="front" style="background:<?php echo htmlspecialchars($donationGoalWidget['bar_color']); ?>;color:<?php echo htmlspecialchars($donationGoalWidget['text_color_alt']); ?>">
                    <p id="front-goal-current"><?php echo ($donationGoalWidget['goal'] / 2); ?> €</p>
                    <p id="front-title"><?php echo htmlspecialchars($donationGoalWidget['text_content']); ?></p>
                    <p id="front-goal-total"><?php echo $donationGoalWidget['goal']; ?> €</p>
                </div>
            </div>
            <br />
            <div class="align-items-center mt-3">
                <h5>
                    <?php echo 'URL du widget : '; ?>
                    <a href="<?php echo $widgetDonationGoalUrl; ?>"
                        target="_blank"><?php echo $widgetDonationGoalUrl; ?></a>
                </h5>
                <br />
                <button type="submit" class="btn btn-primary" name="save_donation_goal">💾</button>
            </div>
        </form>

        <hr class="my-5">

        <!-- Formulaire pour widget_alert_box -->
        <h2>Widget alerte</h2>
        <form method="POST" enctype="multipart/form-data">
            <div class="mb-3">
                <label for="image" class="form-label">Fichier image</label>
                <?php if(isset($alertBoxWidget['image'])) { ?>
                    <div class="form-text">
                        Déjà chargé: <a href="<?php echo $fileManager->getPictureUrl($alertBoxWidget['image']); ?>" target="_blank"><?php echo htmlspecialchars($alertBoxWidget['image']); ?></a>
                    </div>
                <?php } ?>
                <input type="file" class="form-control" id="image" name="image" />
            </div>
            <div class="mb-3">
                <label for="alert_duration" class="form-label">Durée de l'alerte (secondes)</label>
                <input type="number" class="form-control" id="alert_duration" name="alert_duration"
                    value="<?php echo htmlspecialchars($alertBoxWidget['alert_duration']); ?>">
            </div>
            <div class="mb-3">
                <label for="message_template" class="form-label">Template de message</label>
                <div id="passwordHelpBlock" class="form-text">
                    C'est le message qui s'affichera lors d'un don.<br />
                    Il existe 3 paramètres:
                    <ul>
                        <li>{pseudo} le pseudo du donateur (anonyme si non précisé)</li>
                        <li>{amount} le montant du don</li>
                        <li>{message} si le donateur a laissé un message</li>
                    </ul>
                    Vous pouvez ensuite formater le texte avec du html.
                    <br />
                    <br />
                </div>
                <textarea class="form-control" id="message_template"
                    name="message_template"><?php echo htmlspecialchars($alertBoxWidget['message_template']); ?></textarea>
            </div>
            <div class="mb-3">
                <label for="sound" class="form-label">Fichier de son</label>
                <?php if(isset($alertBoxWidget['sound'])) { ?>
                    <div class="form-text">
                        Déjà chargé: <a href="<?php echo $fileManager->getSoundUrl($alertBoxWidget['sound']); ?>" target="_blank"><?php echo htmlspecialchars($alertBoxWidget['sound']); ?></a>
                    </div>
                <?php } ?>
                <input type="file" class="form-control" id="sound" name="sound" />
            </div>
            <div class="mb-3">
                <label for="sound_volume" class="form-label">Volume du son (0-100)</label>
                <input type="number" class="form-control" id="sound_volume" name="sound_volume"
                    value="<?php echo htmlspecialchars($alertBoxWidget['sound_volume']); ?>">
            </div>

            <div class="align-items-center mt-3">
                <h5>
                    <?php echo 'URL du widget : '; ?>
                    <a href="<?php echo $widgetAlertBoxUrl; ?>" target="_blank"><?php echo $widgetAlertBoxUrl; ?></a>
                </h5>
                <br />
                <button type="submit" class="btn btn-primary" name="save_alert_box">💾</button>
                <button type="button" id="previewBtn" class="btn btn-info">Prévisualiser</button>
            </div>
        </form>

        <!-- Espace réservé pour la prévisualisation -->
        <div class="widget-alert-box mt-3 mb-5"
            style="min-height: 300px; border: 1px dashed #ccc; padding: 10px">
        </div>
    </div>

    <script src="/node_modules/bootstrap/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        var image = '<?php echo $fileManager->getPictureUrl($alertBoxWidget['image']); ?>';
        var message_template = '<?php echo $alertBoxWidget['message_template']; ?>';
        var sound = '<?php echo $fileManager->getSoundUrl($alertBoxWidget['sound']); ?>';
        var sound_volume = <?php echo $alertBoxWidget['sound_volume'] / 100; ?>;
        var alert_duration = <?php echo $alertBoxWidget['alert_duration'] * 1000; ?>;
    </script>

    <script src="/js/main.min.js"></script>
    <script src="/js/admin.min.js"></script>
</body>

</html>
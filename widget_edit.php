<?php
require 'config.php';
require 'db_helpers.php';

// Récupérer le GUID depuis l'URL et le convertir en binaire
$guidHex = $_GET['charity_stream_id'] ?? '';
if (!$guidHex) {
    die("GUID manquant ou incorrect.");
}
$guidBinary = hex2bin($guidHex);

// Récupérer les données actuelles des widgets depuis la base de données
$donationGoalWidget = GetDonationGoalWidgetByGuid($db, $guidBinary);
$alertBoxWidget = GetAlertBoxWidgetByGuid($db, $guidBinary);

$widgetUrl = "widget_donation_bar_goal.php?charity_stream_id=" . $guidHex;

// Traitement du formulaire de mise à jour pour chaque widget
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_donation_goal'])) {
        UpdateDonationGoalWidget($db, $guidBinary, $_POST);
    }

    if (isset($_POST['save_alert_box'])) {
        UpdateAlertBoxWidget($db, $guidBinary, $_POST);
    }

    // Redirection pour éviter de renvoyer les formulaires
    header("Location: widget_edit.php?charity_stream_id=" . $guidHex);
    exit();
}

// Générer les URLs complètes pour l'image et le son
$imageUrl = $blob_url . $blob_images_folder . $alertBoxWidget['image'];
$soundUrl = $blob_url . $blob_sounds_folder . $alertBoxWidget['sound'];
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Widgets</title>
    <link href="node_modules/bootstrap/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="node_modules/bootstrap/dist/js/bootstrap.bundle.min.js"></script>
    <style>
        /* Styles pour le fondu */
        .fade {
            opacity: 0;
            transition: opacity 1s ease-in-out;
        }

        .fade.show {
            opacity: 1;
        }
    </style>
</head>
<body class="bg-light">
    <div class="container">
        <h1 class="my-4 text-center">Edit Widgets</h1>
        
        <!-- Lien pour revenir à l'index -->
        <a href="index.php" class="btn btn-secondary mb-4">Back to Admin</a>

        <!-- Formulaire pour widget_donation_goal_bar -->
        <h2>Donation Goal Bar Widget</h2>
        <form method="POST">
            <div class="mb-3">
                <label for="text_color" class="form-label">Text Color</label>
                <input type="color" class="form-control" id="text_color" name="text_color" value="<?php echo htmlspecialchars($donationGoalWidget['text_color']); ?>">
            </div>
            <div class="mb-3">
                <label for="bar_color" class="form-label">Bar Color</label>
                <input type="color" class="form-control" id="bar_color" name="bar_color" value="<?php echo htmlspecialchars($donationGoalWidget['bar_color']); ?>">
            </div>
            <div class="mb-3">
                <label for="background_color" class="form-label">Background Color</label>
                <input type="color" class="form-control" id="background_color" name="background_color" value="<?php echo htmlspecialchars($donationGoalWidget['background_color']); ?>">
            </div>
            <div class="mb-3">
                <label for="goal" class="form-label">Goal Amount</label>
                <input type="number" class="form-control" id="goal" name="goal" value="<?php echo htmlspecialchars($donationGoalWidget['goal']); ?>">
            </div>
            <!-- Boutons Save et Open Widget -->
            <div class="d-flex justify-content-between align-items-center mt-3">
            <button type="submit" class="btn btn-primary" name="save_donation_goal">Save Donation Goal Widget</button>
            <a href="<?php echo $widgetUrl; ?>" class="btn btn-secondary" target="_blank">Open Widget</a>
            </div>
        </form>

        <!-- Prévisualisation de la barre de donation -->
        <div class="mt-4">
            <h4>Donation goal bar preview:</h4>
            <div id="donationGoalPreview" class="progress" style="background-color: <?php echo htmlspecialchars($donationGoalWidget['background_color']); ?>;">
                <div id="progressBar" class="progress-bar progress-bar-custom" role="progressbar" style="width: 50%; background-color: <?php echo htmlspecialchars($donationGoalWidget['bar_color']); ?>;">
                    50%
                </div>
            </div>
        </div>


        <hr class="my-5">

        <!-- Formulaire pour widget_alert_box -->
        <h2>Alert Box Widget</h2>
        <form method="POST">
            <div class="mb-3">
                <label for="image" class="form-label">Image File Name</label>
                <input type="text" class="form-control" id="image" name="image" value="<?php echo htmlspecialchars($alertBoxWidget['image']); ?>">
            </div>
            <div class="mb-3">
                <label for="alert_duration" class="form-label">Alert Duration (seconds)</label>
                <input type="number" class="form-control" id="alert_duration" name="alert_duration" value="<?php echo htmlspecialchars($alertBoxWidget['alert_duration']); ?>">
            </div>
            <div class="mb-3">
                <label for="message_template" class="form-label">Message Template</label>
                <textarea class="form-control" id="message_template" name="message_template"><?php echo htmlspecialchars($alertBoxWidget['message_template']); ?></textarea>
            </div>
            <div class="mb-3">
                <label for="sound" class="form-label">Sound File Name</label>
                <input type="text" class="form-control" id="sound" name="sound" value="<?php echo htmlspecialchars($alertBoxWidget['sound']); ?>">
            </div>
            <div class="mb-3">
                <label for="sound_volume" class="form-label">Sound Volume (0-100)</label>
                <input type="number" class="form-control" id="sound_volume" name="sound_volume" value="<?php echo htmlspecialchars($alertBoxWidget['sound_volume']); ?>">
            </div>
            <button type="submit" class="btn btn-primary" name="save_donation_goal">Save Donation Goal Widget</button>
        </form>

        <!-- Bouton Preview -->
        <hr class="my-5">
        <button id="previewBtn" class="btn btn-info mb-4">Preview Alert Box</button>

        <!-- Espace réservé pour la prévisualisation -->
        <div id="previewContainer" style="min-height: 300px; border: 1px dashed #ccc; padding: 10px; text-align: center;">
            <p class="text-muted">La prévisualisation apparaîtra ici</p>
        </div>
    </div>

    <script>
        // Mise à jour de la prévisualisation de la Donation Goal Bar
        document.getElementById('goal').addEventListener('input', updateDonationGoalPreview);
        document.getElementById('bar_color').addEventListener('input', updateDonationGoalPreview);
        document.getElementById('background_color').addEventListener('input', updateDonationGoalPreview);
        document.getElementById('text_color').addEventListener('input', updateDonationGoalPreview);

        function updateDonationGoalPreview() {
            var goal = parseInt(document.getElementById('goal').value) || 100;
            var current = goal / 2;  // Suppose that the current amount is half of the goal for preview
            var percentage = (current / goal) * 100;

            var progressBar = document.getElementById('progressBar');
            var donationGoalPreview = document.getElementById('donationGoalPreview');

            progressBar.style.width = percentage + '%';
            progressBar.style.backgroundColor = document.getElementById('bar_color').value;
            donationGoalPreview.style.backgroundColor = document.getElementById('background_color').value;
            progressBar.style.color = document.getElementById('text_color').value;
            progressBar.innerText = percentage.toFixed(0) + '%';
        }

        // Prévisualisation de l'Alert Box Widget (inchangé)
        document.getElementById('previewBtn').addEventListener('click', function() {
            var previewContainer = document.getElementById('previewContainer');
            previewContainer.innerHTML = ''; // Vider le contenu précédent

            // Afficher l'image
            var img = document.createElement('img');
            img.src = '<?php echo $imageUrl; ?>';
            img.style.maxWidth = '100%';
            img.classList.add('fade');
            previewContainer.appendChild(img);

            // Afficher le message template
            var messageTemplate = document.createElement('p');
            messageTemplate.innerText = '<?php echo htmlspecialchars($alertBoxWidget['message_template']); ?>';
            messageTemplate.style.marginTop = '10px';
            messageTemplate.classList.add('fade');
            previewContainer.appendChild(messageTemplate);

            // Appliquer l'effet de fondu
            setTimeout(function() {
                img.classList.add('show');
                messageTemplate.classList.add('show');
            }, 100); // Délai pour déclencher la transition

            // Jouer le son
            var audio = new Audio('<?php echo $soundUrl; ?>');
            audio.volume = <?php echo $alertBoxWidget['sound_volume'] / 100; ?>;
            audio.play();

            // Retirer l'image et le message après 3 secondes avec un effet de fondu
            setTimeout(function() {
                img.classList.remove('show');
                messageTemplate.classList.remove('show');

                setTimeout(function() {
                    previewContainer.innerHTML = '<p class="text-muted">La prévisualisation apparaîtra ici</p>';
                }, 1000); // Attendre que le fondu soit terminé avant de vider le conteneur
            }, 3000);
        });

        // Appeler la fonction pour initialiser la prévisualisation au chargement de la page
        updateDonationGoalPreview();
    </script>
</body>
</html>

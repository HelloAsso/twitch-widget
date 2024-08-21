<?php
require 'config.php';
require 'helpers/db_helpers.php';
require 'helpers/session_helpers.php';
require 'helpers/grant_authorization_helpers.php';

// Vérifier si l'utilisateur a sélectionné un environnement
if (isset($_POST['environment'])) {
    try {
        // Appeler la fonction pour mettre à jour les variables de session
        updateSessionVariables($_POST['environment']);
    } catch (Exception $e) {
        // Gérer l'erreur si nécessaire
        echo "Erreur: " . $e->getMessage();
    }
}

// Définir l'environnement par défaut si aucun n'est sélectionné
if (!isset($_SESSION['environment'])) {
    $_SESSION['environment'] = 'SANDBOX'; 
}

$environment = $_SESSION['environment'];
$clientId = $_SESSION['client_id'];
$clientSecret = $_SESSION['client_secret'];
$api_url = $_SESSION['api_url'];
$api_auth_url = $_SESSION['api_auth_url'];


// Traitement du formulaire de création de Charity Stream
if (isset($_POST['create_charity_stream'])) {
    $ownerEmail = $_POST['owner_email'];
    $formId = $_POST['form_id'];
    $organizationId = $_POST['organization_id'];
    $title = $_POST['title'];

    // Générer un GUID unique pour le nouveau Charity Stream
    $guid = bin2hex(random_bytes(16)); // Utilisation de bin2hex pour obtenir une chaîne hexadécimale

    $creationDate = date('Y-m-d H:i:s');
    $lastUpdate = $creationDate;

    // Appeler la fonction pour créer le Charity Stream
    CreateCharityStream($db, $environment, $guid, $ownerEmail, $formId, $organizationId, $title, $creationDate, $lastUpdate);
}

// Utilisation de la fonction GetCharityStreamsList pour récupérer les données mises à jour
$charityStreams = GetCharityStreamsList($db, $environment);

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administration des Charity Streams</title>
    <link href="node_modules/bootstrap/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="node_modules/bootstrap/dist/js/bootstrap.bundle.min.js"></script>
</head>
<body class="bg-light">
    <div class="container">
        <h1 class="my-4 text-center">Administration des Charity Streams</h1>

        <!-- Formulaire de sélection de l'environnement -->
        <form method="POST" action="">
            <label for="environment">Sélectionnez l'environnement :</label>
            <select name="environment" id="environment" onchange="this.form.submit()">
                <option value="SANDBOX" <?php if ($environment == 'SANDBOX') echo 'selected'; ?>>SANDBOX</option>
                <option value="PROD" <?php if ($environment == 'PROD') echo 'selected'; ?>>PROD</option>
            </select>
        </form>
        <!-- Formulaire de création de Charity Stream -->
        <div class="my-4 p-4 bg-white rounded shadow-sm">
            <h3>Créer un nouveau Charity Stream</h3>
            <form method="POST">
                <div class="mb-3">
                    <label for="owner_email" class="form-label">Owner Email</label>
                    <input type="email" class="form-control" id="owner_email" name="owner_email" required>
                </div>
                <div class="mb-3">
                    <label for="organization_id" class="form-label">Organization ID</label>
                    <input type="text" class="form-control" id="organization_id" name="organization_id" required>
                </div>
                <div class="mb-3">
                    <label for="form_id" class="form-label">Form ID</label>
                    <input type="text" class="form-control" id="form_id" name="form_id" required>
                </div>
                <div class="mb-3">
                    <label for="title" class="form-label">Title</label>
                    <input type="text" class="form-control" id="title" name="title" required>
                </div>
                <button type="submit" class="btn btn-success" name="create_charity_stream">Créer Charity Stream</button>
            </form>
        </div>


        <!-- Affichage des Charity Streams -->
        <table class="table table-bordered table-striped">
            <thead class="thead-dark">
                <tr>
                    <th>ID</th>
                    <th>GUID</th>
                    <th>Owner Email</th>
                    <th>Title</th>
                    <th>FormID</th>
                    <th>OrganizationSlug</th>
                    <th>Widgets</th>
                    <th>GrantAuthorizationLink</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($charityStreams as $stream): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($stream['id']); ?></td>
                        <td><?php echo htmlspecialchars(bin2hex($stream['guid'])); ?></td>
                        <td><?php echo htmlspecialchars($stream['owner_email']); ?></td>
                        <td><?php echo htmlspecialchars($stream['title']); ?></td>
                        <td><?php echo htmlspecialchars($stream['form_id']); ?></td>
                        <td><?php echo htmlspecialchars($stream['organization_slug']); ?></td>
                        <td>
                            <a href="widget_edit.php?env=<?php echo strtolower($environment); ?>&charityStreamId=<?php echo bin2hex($stream['guid']); ?>" class="btn btn-primary">Edit Widgets</a>
                        </td>
                        <td>
                        <a href="redirect_auth_page.php?env=<?php echo strtolower($environment); ?>&organizationSlug=<?php echo $stream['organization_slug']; ?>" class="btn btn-primary">Generate Link</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
</html>

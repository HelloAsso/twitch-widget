<?php
require '../app/Config.php';

$repository = Config::getInstance()->repo;

// Traitement du formulaire de création de Charity Stream
if (isset($_POST['create_charity_stream'])) {
    $ownerEmail = $_POST['owner_email'];
    $formSlug = $_POST['form_slug'];
    $organizationSlug = $_POST['organization_slug'];
    $title = $_POST['title'];

    // Générer un GUID unique pour le nouveau Charity Stream
    $guid = bin2hex(random_bytes(16)); // Utilisation de bin2hex pour obtenir une chaîne hexadécimale

    // Appeler la fonction pour créer le Charity Stream
    $repository->createCharityStreamDB($guid, $ownerEmail, $formSlug, $organizationSlug, $title);
}

// Utilisation de la fonction GetCharityStreamsList pour récupérer les données mises à jour
$charityStreams = $repository->getCharityStreamsListDB();

?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administration des Charity Streams</title>
    <link href="/node_modules/bootstrap/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="/node_modules/bootstrap/dist/js/bootstrap.bundle.min.js"></script>
</head>

<body class="bg-light">
    <div class="container">
        <h1 class="my-4 text-center">Administration des Charity Streams</h1>
        <!-- Formulaire de création de Charity Stream -->
        <div class="my-4 p-4 bg-white rounded shadow-sm">
            <h3>Créer un nouveau Charity Stream</h3>
            <form method="POST">
                <div class="mb-3">
                    <label for="owner_email" class="form-label">Email</label>
                    <input type="email" class="form-control" id="owner_email" name="owner_email" required>
                </div>
                <div class="mb-3">
                    <label for="organization_id" class="form-label">Slug association</label>
                    <input type="text" class="form-control" id="organization_slug" name="organization_slug" required>
                </div>
                <div class="mb-3">
                    <label for="form_slug" class="form-label">Slug formulaire</label>
                    <input type="text" class="form-control" id="form_id" name="form_slug" required>
                </div>
                <div class="mb-3">
                    <label for="title" class="form-label">Titre</label>
                    <input type="text" class="form-control" id="title" name="title" required>
                </div>
                <button type="submit" class="btn btn-success" name="create_charity_stream">Créer</button>
            </form>
        </div>


        <!-- Affichage des Charity Streams -->
        <table class="table table-bordered table-striped">
            <thead class="thead-dark">
                <tr>
                    <th>ID</th>
                    <th>GUID</th>
                    <th>Email</th>
                    <th>Titre</th>
                    <th>Slug formuaire</th>
                    <th>Slug association</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($charityStreams as $stream): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($stream['id']); ?></td>
                        <td><?php echo htmlspecialchars(bin2hex($stream['guid'])); ?></td>
                        <td><?php echo htmlspecialchars($stream['owner_email']); ?></td>
                        <td><?php echo htmlspecialchars($stream['title']); ?></td>
                        <td><?php echo htmlspecialchars($stream['form_slug']); ?></td>
                        <td><?php echo htmlspecialchars($stream['organization_slug']); ?></td>
                        <td>
                            <a href="/admin/widget_edit.php?charityStreamId=<?php echo bin2hex($stream['guid']); ?>"
                                class="btn btn-primary">Édition</a>
                            <a href="/redirect_auth_page.php?organizationSlug=<?php echo $stream['organization_slug']; ?>"
                                class="btn btn-primary" target="_blank">Mire d'authorisation</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>

</html>
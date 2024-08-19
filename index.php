<?php
require 'config.php';
require 'db_helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_charity_stream'])) {
    $owner_email = $_POST['owner_email'];
    $form_id = $_POST['form_id'];
    $title = $_POST['title'];
    
    $guid = bin2hex(random_bytes(16)); // Génère un GUID
    $creation_date = date('Y-m-d H:i:s');
    $last_update = $creation_date;

    CreateCharityStream($db, $guid, $owner_email, $form_id, $title, $creation_date, $last_update);

    // Redirection ou autre action après la création
    header("Location: index.php");
    exit();
}

// Utilisation de la fonction GetCharityStreamsList pour récupérer les données
$charityStreams = GetCharityStreamsList($db);
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

        <!-- Formulaire de création de Charity Stream -->
        <div class="my-4 p-4 bg-white rounded shadow-sm">
            <h3>Créer un nouveau Charity Stream</h3>
            <form method="POST">
                <div class="mb-3">
                    <label for="owner_email" class="form-label">Owner Email</label>
                    <input type="email" class="form-control" id="owner_email" name="owner_email" required>
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

        <table class="table table-bordered table-striped">
            <thead class="thead-dark">
                <tr>
                    <th>ID</th>
                    <th>Owner Email</th>
                    <th>Title</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($charityStreams as $stream): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($stream['id']); ?></td>
                        <td><?php echo htmlspecialchars($stream['owner_email']); ?></td>
                        <td><?php echo htmlspecialchars($stream['title']); ?></td>
                        <td>
                            <a href="widget_edit.php?charity_stream_id=<?php echo bin2hex($stream['guid']); ?>" class="btn btn-primary">Edit Widgets</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
</html>


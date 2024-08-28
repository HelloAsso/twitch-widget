<?php

require 'app/Config.php';

$repository = Config::getInstance()->repo;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    $user = $repository->getUser($username);

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_email'] = $user['email'];
        header('Location: /admin/widget_edit.php');
        exit;
    } else {
        echo '<div class="alert alert-danger" role="alert">Email ou mot de passe invalide 😞</div>';
    }
}
?>

<!DOCTYPE html>

<html lang="fr">

<head>
    <meta charset="UTF-8">
    <title>Connexion</title>
    <link rel="stylesheet" href="/node_modules/bootstrap/dist/css/bootstrap.min.css">
</head>

<body>
    <div class="container">
        <h2>Connexion</h2>
        <form action="index.php" method="POST">
            <div class="form-group">
                <label for="username">Email</label>
                <input type="text" class="form-control" id="username" name="username" required>
            </div>
            <div class="form-group">
                <label for="password">Mot de passe</label>
                <input type="password" class="form-control" id="password" name="password" required>
            </div>
            <button type="submit" class="btn btn-primary">🚀</button>
        </form>
    </div>
</body>

</html>

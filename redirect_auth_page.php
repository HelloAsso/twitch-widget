<?php
use GuzzleHttp\Psr7\Message;
require 'app/Config.php';

$repository = Config::getInstance()->repo;
$apiWrapper = Config::getInstance()->apiWrapper;

$organizationSlug = $_GET['organizationSlug'];
if($organizationSlug == null)
{
    throw new Exception("Erreur : OrganizationSlug introuvable");
}

//Vérification si l'association à déjà lié son compte
//Récupération du refresh_token de l'association en BDD pour voir si c'est nécessaire de générer une URL de mire
$organizationToken = $repository->getAccessTokensDB($organizationSlug);

if ($organizationToken != null)
{
    //Nous avons réussi à récupérer un token de l'association
    //Si on peut rafraichir ce token c'est qu'il est encore valide
    try
    {
        $decryptedOrganizationRefreshToken = Helpers::decryptToken($organizationToken['refresh_token']);
        $refreshToken = $apiWrapper->refreshToken( $decryptedOrganizationRefreshToken, $organizationSlug);
        echo 'Nous possédons déjà un token pour le compte ' . $organizationSlug . ' et nous l\'avons rafraichi, vous pouvez fermer cette page.';                
    }
    catch (Exception $e)
    {
        redirectionToAuthorizationUrl();
    }
}
else
{
    redirectionToAuthorizationUrl();
}

function redirectionToAuthorizationUrl()
{
    global $apiWrapper;
    global $organizationSlug;

    // Nous ne possédons pas de Refresh valide pour cette association, nous allons donc générer une Url pour la liaison
    // Récupération du token global HelloassoCharityStream pour set le domain (important pour la mire)
    $globalTokens = $apiWrapper->getGlobalTokensAndRefreshIfNecessary();
    $globalAccessToken = $globalTokens['access_token'];

    $apiWrapper->setClientDomain(Config::getInstance()->webSiteDomain, $globalAccessToken);

    // Générer l'URL d'autorisation
    $authorizationUrl = $apiWrapper->generateAuthorizationUrl($organizationSlug);

    // Rediriger vers l'URL générée
    header('Location: ' . $authorizationUrl);
}

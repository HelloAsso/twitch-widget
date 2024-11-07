<?php
require 'app/Config.php';

$repository = Config::getInstance()->repo;
$apiWrapper = Config::getInstance()->apiWrapper;

// Récupérer le paramètre 'state' depuis l'URL
// Nous y avons mit l'ID de l'authorizationCode en BDD 
// Cela nous permet de récupérer les informartions necessaire pour la mire
$state = $_GET['state'];
$authorizationCodeData = $repository->getAuthorizationCodeByIdDB($state);
if($authorizationCodeData == null)
{
    throw new Exception("Erreur : Nous n'avons pas trouvé d'authorizationCode correspondant.");
}

$redirect_uri = $authorizationCodeData['redirect_uri'];
$codeVerifier = $authorizationCodeData['code_verifier'];

// Le code est renvoyé par la mire d'autorisation
$code = $_GET['code'];

// Une fois que nous avons toutes les informations necessaire nous pouvons procéder à l'échange
$tokenDataGrantAuthorization = $apiWrapper->exchangeAuthorizationCode($code, $redirect_uri, $codeVerifier);

// Calculer les dates d'expiration des tokens
$accessTokenExpiresAt = (new DateTime())->add(new DateInterval('PT28M'));
$refreshTokenExpiresAt = (new DateTime())->add(new DateInterval('P28D'));

$existingOrganizationToken = $repository->getAccessTokensDB($tokenDataGrantAuthorization['organization_slug']);

if ($existingOrganizationToken != null) 
{
    try 
    {
        $repository->updateAccessTokenDB(
            Helpers::encryptToken($tokenDataGrantAuthorization['access_token']),
            Helpers::encryptToken($tokenDataGrantAuthorization['refresh_token']),
            $tokenDataGrantAuthorization['organization_slug'],
            $accessTokenExpiresAt,
            $refreshTokenExpiresAt
        );

        echo 'Votre compte ' . $tokenDataGrantAuthorization['organization_slug'] . ' été déjà lié à HelloAssoCharityStream, vous pouvez fermer cette page.';
    } 
    catch (Exception $e) 
    {
        throw new Exception("Erreur de MAJ en base de données : " . $e->getMessage());
    }
} 
else 
{
    try 
    {
        $repository->insertAccessTokenDB(
            Helpers::encryptToken($tokenDataGrantAuthorization['access_token']),
            Helpers::encryptToken($tokenDataGrantAuthorization['refresh_token']),
            $tokenDataGrantAuthorization['organization_slug'],
            $accessTokenExpiresAt,
            $refreshTokenExpiresAt
        );

        echo 'Votre compte ' . $tokenDataGrantAuthorization['organization_slug'] . ' à bien été lié à HelloAssoCharityStream, vous pouvez fermer cette page.';
    
        $mailchimp = new \MailchimpTransactional\ApiClient();
        $mailchimp->setApiKey(Config::getInstance()->mandrillApi);

        $mailchimp->messages->send([
            "message" => [
                "from_email" => "contact@helloasso.io",
                "from_name" => "HelloAsso",
                "subject" => "Une association vient de valider sa mire" ,
                "html" => "<p>L'association " . $tokenDataGrantAuthorization['organization_slug'] . " vient de valider sa mire d'authorisation sur l'environnement " . Config::getInstance()->webSiteDomain . "</p>",
                "to" => [
                    [
                        "email" => "helloasso.stream@helloasso.org"
                    ]
                ],
            ]
        ]);
    } 
    catch (Exception $e) 
    {
        throw new Exception("Erreur lors de l'insertion en base de données : " . $e->getMessage());
    }
}
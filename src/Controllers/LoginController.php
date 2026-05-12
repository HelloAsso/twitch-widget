<?php

namespace App\Controllers;

use App\Repositories\AccessTokenRepository;
use App\Repositories\AuthorizationCodeRepository;
use App\Repositories\StreamRepository;
use App\Repositories\UserRepository;
use App\Services\ApiWrapper;
use Exception;
use MailchimpTransactional\ApiClient;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Flash\Messages;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

class LoginController
{
    public function __construct(
        private Twig $view,
        private ApiWrapper $apiWrapper,
        private AccessTokenRepository $accessTokenRepository,
        private AuthorizationCodeRepository $authorizationCodeRepository,
        private StreamRepository $streamRepository,
        private UserRepository $userRepository,
        private ApiClient $mailchimp,
        private Messages $messages,
        private Logger $logger,
    ) {}

    private function redirectToRoute(Request $request, Response $response, string $routeName, array $params = []): Response
    {
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $url = $routeParser->urlFor($routeName, $params);
        return $response->withHeader('Location', $url)->withStatus(302);
    }

    /**
     * Valide la page de connexion après soumission du formulaire.
     */
    public function login(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';
        $user = $this->userRepository->select($email);

        if ($user && password_verify($password, $user->password)) {
            session_regenerate_id(true);
            $_SESSION['user'] = $user;
            return $this->redirectToRoute($request, $response, 'app_admin_index');
        }

        $this->messages->addMessage('login_failed', true);
        return $this->redirectToRoute($request, $response, 'app_index');
    }

    /**
     * Envoie un email de réinitialisation de mot de passe si l'adresse existe.
     */
    public function forgotPassword(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $email = $data['email'] ?? '';
        $user = $this->userRepository->select($email);

        if ($user) {
            $user = $this->userRepository->insertResetToken($user);
            $routeParser = RouteContext::fromRequest($request)->getRouteParser();
            $resetUrl = $_SERVER['WEBSITE_DOMAIN'] . $routeParser->urlFor('app_reset_password', ["token" => $user->reset_token]);

            $this->mailchimp->messages->send([
                "message" => [
                    "from_email" => "contact@helloasso.io",
                    "from_name" => "HelloAsso",
                    "subject" => "Mot de passe oublié",
                    "html" => "<p>Vous avez fait une demande de réinitialisation de mot de passe. Merci de le définir sur <a href=\"{$resetUrl}\">cette page</a><br/>Ou en suivant ce lien {$resetUrl}</p>",
                    "to" => [["email" => $user->email]],
                ],
            ]);
        }

        $this->messages->addMessage('mail_sent', true);
        return $this->redirectToRoute($request, $response, 'app_index');
    }

    /**
     * Réinitialise le mot de passe après soumission du formulaire.
     */
    public function resetPassword(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $password = $data['password'] ?? '';
        $passwordRepeat = $data['passwordRepeat'] ?? '';
        $token = $data['token'] ?? '';

        $user = $this->userRepository->selectByToken($token);

        if ($user && $password && $passwordRepeat && $password === $passwordRepeat) {
            $this->userRepository->updatePassword($user, $password);
            $this->messages->addMessage('password_reset', true);
            return $this->redirectToRoute($request, $response, 'app_index');
        }

        $this->messages->addMessage('password_reset_error', true);
        return $this->redirectToRoute($request, $response, 'app_reset_password', ["token" => $token]);
    }

    /**
     * Détruit la session de l'utilisateur et redirige vers la page d'accueil.
     */
    public function logout(Request $request, Response $response): Response
    {
        unset($_SESSION['user']);
        return $this->redirectToRoute($request, $response, 'app_index');
    }

    /**
     * Redirige vers l'URL d'autorisation pour l'organisation donnée.
     * Si un token existe déjà et est encore valide, le rafraîchit et affiche un message.
     */
    public function redirectAuthPage(Request $request, Response $response): Response
    {
        $organizationSlug = $request->getQueryParams()['organizationSlug'] ?? null;
        if (!$organizationSlug) {
            throw new Exception("Erreur : OrganizationSlug introuvable");
        }

        $existingToken = $this->accessTokenRepository->selectBySlug($organizationSlug);

        if ($existingToken) {
            try {
                $this->apiWrapper->getOrganizationAccessToken($organizationSlug);
                $response->getBody()->write(
                    'Nous possédons déjà un token pour le compte ' . $organizationSlug
                    . ' et nous l\'avons rafraichi, vous pouvez fermer cette page.'
                );
                return $response;
            } catch (Exception) {
                // Token invalide → on redirige vers la mire d'auth
            }
        }

        $authorizationUrl = $this->apiWrapper->generateAuthorizationUrl($organizationSlug);
        return $response->withHeader('Location', $authorizationUrl)->withStatus(302);
    }

    /**
     * Callback OAuth : échange le code d'autorisation, stocke les tokens et notifie par email si c'est un nouveau compte.
     */
    public function validateAuthPage(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $error = $queryParams['error'] ?? null;

        if ($error) {
            $response->getBody()->write($queryParams['error_description'] ?? 'Erreur inconnue');
            return $response;
        }

        $state = $queryParams['state'];
        $code = $queryParams['code'];

        $authorizationCodeData = $this->authorizationCodeRepository->selectById($state);
        $tokenData = $this->apiWrapper->exchangeAuthorizationCode(
            $code,
            $authorizationCodeData->redirect_uri,
            $authorizationCodeData->code_verifier,
        );

        if ($authorizationCodeData->organization_slug !== $tokenData['organization_slug']) {
            $this->logger->warning('Incohérence de slug lors de l\'échange du code d\'autorisation', [
                'slug_attendu' => $authorizationCodeData->organization_slug,
                'slug_reçu' => $tokenData['organization_slug'],
            ]);
            $response->getBody()->write('Erreur : le slug de l\'association ne correspond pas à celui attendu. L\'authentification a été annulée.');
            return $response->withStatus(400);
        }

        $isNewToken = $this->accessTokenRepository->selectBySlug($tokenData['organization_slug']) === null;
        $this->apiWrapper->storeOrUpdateToken($tokenData);

        if ($isNewToken) {
            $this->mailchimp->messages->send([
                "message" => [
                    "from_email" => "contact@helloasso.io",
                    "from_name" => "HelloAsso",
                    "subject" => "Une association vient de valider sa mire",
                    "html" => "<p>L'association {$tokenData['organization_slug']} vient de valider sa mire d'authorisation sur l'environnement {$_SERVER['WEBSITE_DOMAIN']}</p>",
                    "to" => [["email" => "helloasso.stream@helloasso.org"]],
                ],
            ]);
        }

        $response->getBody()->write('Votre compte ' . $tokenData['organization_slug'] . ' à bien été lié à HelloAssoCharityStream, vous pouvez fermer cette page.');
        return $response;
    }
}

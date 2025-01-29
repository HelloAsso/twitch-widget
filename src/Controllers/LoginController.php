<?php

namespace App\Controllers;

use App\Models\AccessToken;
use App\Repositories\AccessTokenRepository;
use App\Repositories\AuthorizationCodeRepository;
use App\Repositories\StreamRepository;
use App\Repositories\UserRepository;
use App\Services\ApiWrapper;
use DateTime;
use DateInterval;
use Exception;
use MailchimpTransactional\ApiClient;
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
    ) {}

    public function login(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';
        $user = $this->userRepository->selectUser($email);

        $routeParser = RouteContext::fromRequest($request)->getRouteParser();

        if ($user && password_verify($password, $user->password)) {
            $_SESSION['user'] = $user;

            $charityStreams = $this->streamRepository->getCharityStreamByEmail($user->email);

            $url = $routeParser->urlFor('app_stream_edit', ["id" => bin2hex($charityStreams[0]['guid'])]);
            return $response->withHeader('Location', $url)->withStatus(302);
        } else {
            $this->messages->addMessage('login_failed', true);
            $url = $routeParser->urlFor('app_index');
            return $response->withHeader('Location', $url)->withStatus(302);
        }
    }

    public function forgotPassword(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        $email = $data['email'] ?? '';
        $user = $this->userRepository->selectUser($email);

        if ($user) {
            $user = $this->userRepository->insertResetToken($user);

            $this->mailchimp->messages->send([
                "message" => [
                    "from_email" => "contact@helloasso.io",
                    "from_name" => "HelloAsso",
                    "subject" => "mot de passe oublié",
                    "html" => "<p>Vous avez fait une demande de réinitialisation de mot de passe. Merci de le définir sur <a href=\"" . $_SERVER['WEBSITE_DOMAIN'] . "/reset_password/$user->reset_token\">cette page</a><br/>Ou en suivant ce lien " . $_SERVER['WEBSITE_DOMAIN'] . "/reset_password/$user->reset_token</p>",
                    "to" => [
                        [
                            "email" => $user->email
                        ]
                    ],
                ]
            ]);
        }

        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $this->messages->addMessage('mail_sent', true);
        $url = $routeParser->urlFor('app_index');
        return $response->withHeader('Location', $url)->withStatus(302);
    }

    public function resetPassword(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        $password = $data['password'] ?? '';
        $passwordRepeat = $data['passwordRepeat'] ?? '';
        $token = $data['token'] ?? '';

        $user = $this->userRepository->selectUserByToken($token);

        $routeParser = RouteContext::fromRequest($request)->getRouteParser();

        if ($user && $password && $passwordRepeat && $password == $passwordRepeat) {
            $this->userRepository->updateUserPassword($user, $password);
            $this->messages->addMessage('password_reset', true);
            $url = $routeParser->urlFor('app_index');
        } else {
            $this->messages->addMessage('password_reset_error', true);
            $url = $routeParser->urlFor('app_reset_password', ["token" => $token]);
        }

        return $response->withHeader('Location', $url)->withStatus(302);
    }

    public function logout(Request $request, Response $response): Response
    {
        unset($_SESSION['user']);

        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $url = $routeParser->urlFor('app_index');

        return $response->withHeader('Location', $url)->withStatus(302);
    }

    private function redirectionToAuthorizationUrl(Response $response, $organizationSlug): Response
    {
        $globalTokens = $this->apiWrapper->getAccessTokensAndRefreshIfNecessary(null);
        $this->apiWrapper->setClientDomain($globalTokens->access_token);

        $authorizationUrl = $this->apiWrapper->generateAuthorizationUrl($organizationSlug);

        return $response->withHeader('Location', $authorizationUrl)->withStatus(302);
    }

    public function redirectAuthPage(Request $request, Response $response): Response
    {
        $organizationSlug = $request->getQueryParams()['organizationSlug'];
        if ($organizationSlug == null) {
            throw new Exception("Erreur : OrganizationSlug introuvable");
        }

        $organizationToken = $this->accessTokenRepository->selectBySlug($organizationSlug);

        if ($organizationToken != null) {
            try {
                $this->apiWrapper->getAccessTokensAndRefreshIfNecessary($organizationSlug);
                $response->getBody()->write('Nous possédons déjà un token pour le compte ' . $organizationSlug . ' et nous l\'avons rafraichi, vous pouvez fermer cette page.');
            } catch (Exception $e) {
                return $this->redirectionToAuthorizationUrl($response, $organizationSlug);
            }
        } else {
            return $this->redirectionToAuthorizationUrl($response, $organizationSlug);
        }

        return $response;
    }

    public function refreshToken(Request $request, Response $response): Response
    {
        $tokens = $this->accessTokenRepository->getAccessTokensToRefresh();

        $response->getBody()->write(count($tokens) . " tokens to refresh<br/>");

        foreach ($tokens as $token) {
            $this->apiWrapper->getAccessTokensAndRefreshIfNecessary($token->organization_slug);
            $response->getBody()->write("Token for " . $token->organization_slug . " refreshed<br/>");
        }

        return $response;
    }

    public function validateAuthPage(Request $request, Response $response): Response
    {
        $error = $request->getQueryParams()['error'] ?? null;
        $errorDescription = $request->getQueryParams()['error_description'] ?? null;

        if ($error) {
            $response->getBody()->write($errorDescription);
            return $response;
        }

        $state = $request->getQueryParams()['state'];
        $code = $request->getQueryParams()['code'];

        $authorizationCodeData = $this->authorizationCodeRepository->selectById($state);
        $redirect_uri = $authorizationCodeData->redirect_uri;
        $codeVerifier = $authorizationCodeData->code_verifier;

        $tokenDataGrantAuthorization = $this->apiWrapper->exchangeAuthorizationCode($code, $redirect_uri, $codeVerifier);
        $existingOrganizationToken = $this->apiWrapper->getAccessTokensAndRefreshIfNecessary($tokenDataGrantAuthorization['organization_slug']);

        $token = new AccessToken();
        $token->access_token = $tokenDataGrantAuthorization['access_token'];
        $token->refresh_token = $tokenDataGrantAuthorization['refresh_token'];
        $token->organization_slug = $tokenDataGrantAuthorization['organization_slug'];
        $token->access_token_expires_at = (new DateTime())->add(new DateInterval('PT28M'));
        $token->refresh_token_expires_at = (new DateTime())->add(new DateInterval('P28D'));

        if ($existingOrganizationToken == null) {
            $this->accessTokenRepository->insert($token);

            $response->getBody()->write('Votre compte ' . $tokenDataGrantAuthorization['organization_slug'] . ' à bien été lié à HelloAssoCharityStream, vous pouvez fermer cette page.');

            $this->mailchimp->messages->send([
                "message" => [
                    "from_email" => "contact@helloasso.io",
                    "from_name" => "HelloAsso",
                    "subject" => "Une association vient de valider sa mire",
                    "html" => "<p>L'association " . $tokenDataGrantAuthorization['organization_slug'] . " vient de valider sa mire d'authorisation sur l'environnement " . $_SERVER['WEBSITE_DOMAIN'] . "</p>",
                    "to" => [
                        [
                            //"email" => "helloasso.stream@helloasso.org"
                            "email" => "eddy@helloasso.org"
                        ]
                    ],
                ]
            ]);
        } else {
            $response->getBody()->write('Votre compte ' . $tokenDataGrantAuthorization['organization_slug'] . ' été déjà lié à HelloAssoCharityStream, vous pouvez fermer cette page.');
        }

        return $response;
    }
}

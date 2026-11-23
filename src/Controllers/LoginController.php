<?php

namespace App\Controllers;

use App\Repositories\AccessTokenRepository;
use App\Repositories\AuthorizationCodeRepository;
use App\Repositories\StreamRepository;
use App\Repositories\UserRepository;
use App\Models\User;
use App\Services\ApiWrapper;
use App\Services\BrandedEmail;
use App\Services\HelloAssoLinks;
use App\Services\Mailer;
use Exception;
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
        private Mailer $mailer,
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
     * Vérifie aussi que l'email est confirmé.
     */
    public function login(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';
        $user = $this->userRepository->select($email);

        if ($user && password_verify($password, $user->password)) {
            if (isset($user->email_verified) && !$user->email_verified) {
                $this->sendVerificationEmail($request, $user);
                $this->messages->addMessage('email_not_verified', true);
                return $this->redirectToRoute($request, $response, 'app_index');
            }
            session_regenerate_id(true);
            $_SESSION['user'] = $user;
            return $this->redirectToRoute($request, $response, 'app_admin_index');
        }

        $this->messages->addMessage('login_failed', true);
        return $this->redirectToRoute($request, $response, 'app_index');
    }

    /**
     * Inscrit un nouvel utilisateur avec vérification par email.
     */
    public function register(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';
        $passwordRepeat = $data['passwordRepeat'] ?? '';

        // Validation email
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->messages->addMessage('register_error', 'Adresse email invalide.');
            return $this->redirectToRoute($request, $response, 'app_register');
        }

        // Validation mot de passe
        $passwordErrors = $this->validatePassword($password, $passwordRepeat);
        if (!empty($passwordErrors)) {
            $this->messages->addMessage('register_error', implode(' ', $passwordErrors));
            return $this->redirectToRoute($request, $response, 'app_register');
        }

        // Vérifier si l'utilisateur existe déjà
        $existing = $this->userRepository->select($email);
        if ($existing) {
            $this->messages->addMessage('register_error', 'Un compte avec cet email existe déjà.');
            return $this->redirectToRoute($request, $response, 'app_register');
        }

        // Créer l'utilisateur (email non vérifié)
        $user = $this->userRepository->insertWithPassword($email, $password);

        // Envoyer l'email de vérification
        $this->sendVerificationEmail($request, $user);

        $this->messages->addMessage('register_success', true);
        return $this->redirectToRoute($request, $response, 'app_index');
    }

    /**
     * Vérifie l'email via le token reçu par mail.
     */
    public function verifyEmail(Request $request, Response $response, array $args): Response
    {
        $token = $args['token'] ?? '';
        $user = $this->userRepository->selectByToken($token);

        if ($user) {
            $this->userRepository->verifyEmail($user);
            $this->messages->addMessage('email_verified', true);
        } else {
            $this->messages->addMessage('email_verify_error', true);
        }

        return $this->redirectToRoute($request, $response, 'app_index');
    }

    /**
     * Valide les règles de mot de passe.
     */
    private function validatePassword(string $password, string $passwordRepeat): array
    {
        $errors = [];

        if ($password !== $passwordRepeat) {
            $errors[] = 'Les mots de passe ne correspondent pas.';
        }
        if (strlen($password) < 8) {
            $errors[] = 'Le mot de passe doit contenir au moins 8 caractères.';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Le mot de passe doit contenir au moins une majuscule.';
        }
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Le mot de passe doit contenir au moins une minuscule.';
        }
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Le mot de passe doit contenir au moins un chiffre.';
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = 'Le mot de passe doit contenir au moins un caractère spécial.';
        }

        return $errors;
    }

    /**
     * Envoie (ou renvoie) l'email de vérification à un utilisateur.
     * Génère un nouveau token de réinitialisation avant l'envoi.
     */
    private function sendVerificationEmail(Request $request, User $user): void
    {
        $user = $this->userRepository->insertResetToken($user);

        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $verifyUrl = $_SERVER['WEBSITE_DOMAIN'] . $routeParser->urlFor('app_verify_email', ["token" => $user->reset_token]);

        try {
            $this->mailer->send(
                $user->email,
                "Activez votre compte HelloAsso Stream",
                $this->buildVerificationEmail($verifyUrl),
            );
        } catch (Exception $e) {
            $this->logger->error('Échec de l\'envoi de l\'email de vérification', [
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Génère le contenu HTML de l'email de vérification.
     */
    private function buildVerificationEmail(string $verifyUrl): string
    {
        $e = BrandedEmail::e(...);

        return BrandedEmail::render(
            $_SERVER['WEBSITE_DOMAIN'] ?? '',
            'Activez votre compte',
            BrandedEmail::paragraph('Bonjour,')
            . BrandedEmail::paragraph('Merci d\'avoir créé votre compte <strong>HelloAsso Stream</strong>. Confirmez votre adresse email pour l\'activer :')
            . BrandedEmail::button('Activer mon compte', $verifyUrl)
            . BrandedEmail::note('Ce lien est valable 1 heure. S\'il ne fonctionne pas, copiez cette adresse dans votre navigateur : ' . $e($verifyUrl))
            . BrandedEmail::subtitle('Et ensuite ?')
            . BrandedEmail::paragraph('Créez votre premier stream, personnalisez vos widgets et ajoutez-les dans OBS. Le centre d\'aide vous guide pas à pas :')
            . BrandedEmail::paragraph(BrandedEmail::link('Découvrir comment fonctionne HelloAsso Stream', HelloAssoLinks::HELP_CENTER))
            . BrandedEmail::separator()
            . BrandedEmail::note('Vous n\'êtes pas à l\'origine de cette inscription ? Ignorez simplement cet email.')
            . BrandedEmail::paragraph('L\'équipe HelloAsso'),
        );
    }

    /**
     * Génère le contenu HTML de l'email de réinitialisation de mot de passe.
     */
    private function buildResetPasswordEmail(string $resetUrl): string
    {
        $e = BrandedEmail::e(...);

        return BrandedEmail::render(
            $_SERVER['WEBSITE_DOMAIN'] ?? '',
            'Nouveau mot de passe',
            BrandedEmail::paragraph('Bonjour,')
            . BrandedEmail::paragraph('Vous avez demandé à réinitialiser le mot de passe de votre compte <strong>HelloAsso Stream</strong>.')
            . BrandedEmail::button('Choisir un nouveau mot de passe', $resetUrl)
            . BrandedEmail::note('Ce lien est valable 1 heure. S\'il ne fonctionne pas, copiez cette adresse dans votre navigateur : ' . $e($resetUrl))
            . BrandedEmail::separator()
            . BrandedEmail::note('Vous n\'êtes pas à l\'origine de cette demande ? Ignorez cet email : votre mot de passe reste inchangé.')
            . BrandedEmail::paragraph('L\'équipe HelloAsso'),
        );
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

            try {
                $this->mailer->send(
                    $user->email,
                    "Choisissez un nouveau mot de passe",
                    $this->buildResetPasswordEmail($resetUrl),
                );
            } catch (Exception $e) {
                $this->logger->error('Échec de l\'envoi de l\'email de réinitialisation', [
                    'email' => $user->email,
                    'error' => $e->getMessage(),
                ]);
            }
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
            if (!$user->email_verified) {
                $this->userRepository->verifyEmail($user);
            }
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
            try {
                $this->mailer->send(
                    "helloasso.stream@helloasso.org",
                    "Une association vient de valider sa mire",
                    "<p>L'association {$tokenData['organization_slug']} vient de valider sa mire d'authorisation sur l'environnement {$_SERVER['WEBSITE_DOMAIN']}</p>",
                );
            } catch (Exception $e) {
                $this->logger->error('Échec de l\'envoi de la notification de nouvelle association', [
                    'slug' => $tokenData['organization_slug'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $response->getBody()->write('Votre compte ' . $tokenData['organization_slug'] . ' a bien été lié à HelloAsso Stream, vous pouvez fermer cette page.');
        return $response;
    }
}

<?php

namespace App\Controllers;

use App\Models\AccessToken;
use App\Repositories\AccessTokenRepository;
use App\Repositories\AuthorizationCodeRepository;
use App\Repositories\EventRepository;
use App\Repositories\FileManager;
use App\Repositories\StreamRepository;
use App\Repositories\UserRepository;
use App\Repositories\WidgetRepository;
use App\Services\ApiWrapper;
use DateInterval;
use DateTime;
use Exception;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Flash\Messages;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

class AdminController
{
    public function __construct(
        private Twig $view,
        private FileManager $fileManager,
        private EventRepository $eventRepository,
        private StreamRepository $streamRepository,
        private UserRepository $userRepository,
        private WidgetRepository $widgetRepository,
        private Messages $messages,
        private ApiWrapper $apiWrapper,
        private AccessTokenRepository $accessTokenRepository,
        private AuthorizationCodeRepository $authorizationCodeRepository,
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if ($user->role == "ADMIN") {
            $streams = $this->streamRepository->selectList();
            $events = $this->eventRepository->selectList();
        } else {
            $streams = $this->streamRepository->selectListByUser($user);
            $events = $this->eventRepository->selectListByUser($user);
        }

        $data = [
            "streams" => $streams,
            "events" => $events,
            "messages" => $this->messages->getMessages(),
            "currentUser" => $user,
        ];

        $template = $user->role == "ADMIN" ? 'stream/index-admin.html.twig' : 'stream/index.html.twig';
        return $this->view->render($response, $template, $data);
    }

    public function newEvent(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        $ownerEmail = $data['owner_email'];
        $title = $data['title'];

        $user = $this->userRepository->select($ownerEmail);
        if ($user == null) {
            $user = $this->userRepository->insert($ownerEmail);
        }
        $event = $this->eventRepository->insert($title);
        $this->userRepository->insertRight($user, null, $event);

        $this->messages->addMessage('success', 'Évènement ajouté');
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $url = $routeParser->urlFor('app_admin_index');

        return $response->withHeader('Location', $url)->withStatus(302);
    }

    public function deleteEvent(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $url = $routeParser->urlFor('app_admin_index');

        $event = $this->eventRepository->selectByUserAndGuid($user, $args['id']);
        if (!$event) {
            $this->messages->addMessage('error', 'Tu n\'as pas accès cet évènement');
            return $response->withHeader('Location', $url)->withStatus(302);
        }

        $this->eventRepository->delete($event);
        $this->messages->addMessage('success', 'Évènement supprimé');

        return $response->withHeader('Location', $url)->withStatus(302);
    }

    public function editEvent(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $event = $this->eventRepository->selectByUserAndGuid($user, $args['id']);
        $donationGoalWidget = $this->widgetRepository->selectDonationWidgetByGuid(null, $event->guid);
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();

        $data = [
            "logged" => true,
            "event" => $event,
            "donationGoalWidget" => $donationGoalWidget,
            "widgetDonationGoalUrl" => $_SERVER['WEBSITE_DOMAIN'] . $routeParser->urlFor('app_event_widget_donation', ["id" => $event->guid]),
        ];

        return $this->view->render($response, 'event/edit.html.twig', $data);
    }

    public function editEventPost(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $body = $request->getParsedBody();
        $event = $this->eventRepository->selectByUserAndGuid($user, $args['id']);

        if (isset($body['save_donation_goal'])) {
            $this->widgetRepository->updateDonationWidget(null, $event->guid, $body);
        }

        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $url = $routeParser->urlFor('app_event_edit', ["id" => $event->guid]);

        return $response->withHeader('Location', $url)->withStatus(302);
    }

    public function newStream(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = $request->getParsedBody();

        $parentEvent = $data['parent_event'] ?? null;
        $parentStyle = isset($data['parent_style']);
        $ownerEmail = $data['owner_email'];
        $formSlug = $data['form_slug'];
        $organizationSlug = $data['organization_slug'];
        $title = $data['title'];

        $owner = $this->userRepository->select($ownerEmail);
        if ($owner == null) {
            $owner = $this->userRepository->insert($ownerEmail);
        }

        $event = null;
        if (!empty($parentEvent)) {
            $event = $this->eventRepository->selectByUserAndId($user, $parentEvent);
        }

        $stream = $this->streamRepository->insert($formSlug, $organizationSlug, $title, $event->id ?? null);
        $this->userRepository->insertRight($owner, $stream, null);

        if ($event !== null && $parentStyle) {
            $donationGoalWidget = $this->widgetRepository->selectDonationWidgetByGuid(null, $event->guid);
            if ($donationGoalWidget) {
                $widgetData = [
                    'text_color_main' => $donationGoalWidget->text_color_main,
                    'text_color_alt' => $donationGoalWidget->text_color_alt,
                    'text_content' => $donationGoalWidget->text_content,
                    'bar_color' => $donationGoalWidget->bar_color,
                    'background_color' => $donationGoalWidget->background_color,
                    'goal' => $donationGoalWidget->goal,
                ];
                $this->widgetRepository->updateDonationWidget($stream->guid, null, $widgetData);
            }
        }

        $this->messages->addMessage('success', 'Stream ajouté');
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $url = $routeParser->urlFor('app_admin_index');

        return $response->withHeader('Location', $url)->withStatus(302);
    }

    public function deleteStream(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $url = $routeParser->urlFor('app_admin_index');

        $stream = $this->streamRepository->selectByUserAndGuid($user, $args['id']);
        if (!$stream) {
            $this->messages->addMessage('error', 'Tu n\'as pas accès ce stream');
            return $response->withHeader('Location', $url)->withStatus(302);
        }

        $this->streamRepository->delete($stream);
        $this->messages->addMessage('success', 'Stream supprimé');

        return $response->withHeader('Location', $url)->withStatus(302);
    }

    public function editStream(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $charityStream = $this->streamRepository->selectByUserAndGuid($user, $args['id']);
        $guid = $charityStream->guid;

        $donationGoalWidget = $this->widgetRepository->selectDonationWidgetByGuid($guid, null);
        $alertBoxWidget = $this->widgetRepository->selectAlertWidgetByGuid($guid);

        $donationUrl = $_SERVER['HA_URL'] . '/associations/' . $charityStream->organization_slug . '/formulaires/' . $charityStream->form_slug;
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();

        $data = [
            "logged" => true,
            "charityStream" => $charityStream,
            "donationGoalWidget" => $donationGoalWidget,
            "alertBoxWidget" => $alertBoxWidget,
            "alertBoxWidgetPictureUrl" => ($alertBoxWidget && $alertBoxWidget->image) ? $this->fileManager->getPictureUrl($alertBoxWidget->image) : null,
            "alertBoxWidgetSoundUrl" => ($alertBoxWidget && $alertBoxWidget->sound) ? $this->fileManager->getSoundUrl($alertBoxWidget->sound) : null,
            "donationUrl" => $donationUrl,
            "widgetDonationGoalUrl" => $_SERVER['WEBSITE_DOMAIN'] . $routeParser->urlFor('app_stream_widget_donation', ["id" => $guid]),
            "widgetAlertBoxUrl" => $_SERVER['WEBSITE_DOMAIN'] . $routeParser->urlFor('app_stream_widget_alert', ["id" => $guid]),
        ];

        return $this->view->render($response, 'stream/edit.html.twig', $data);
    }

    public function editStreamPost(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $body = $request->getParsedBody();
        $charityStream = $this->streamRepository->selectByUserAndGuid($user, $args['id']);
        $guid = $charityStream->guid;

        if (isset($body['save_donation_goal'])) {
            $this->widgetRepository->updateDonationWidget($guid, null, $body);
        }

        if (isset($body['save_alert_box'])) {
            $uploadedFiles = $request->getUploadedFiles();
            $image = isset($uploadedFiles['image']) && $uploadedFiles['image']->getSize() > 0
                ? $this->fileManager->uploadPicture($uploadedFiles['image'])
                : null;
            $sound = isset($uploadedFiles['sound']) && $uploadedFiles['sound']->getSize() > 0
                ? $this->fileManager->uploadSound($uploadedFiles['sound'])
                : null;

            $this->widgetRepository->updateAlertWidget($guid, $body, $image, $sound);
        }

        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $url = $routeParser->urlFor('app_stream_edit', ["id" => $guid]);

        return $response->withHeader('Location', $url)->withStatus(302);
    }

    /**
     * Génère l'URL d'autorisation OAuth HelloAsso pour connecter une association dans le cadre de la création d'un stream.
     * Retourne un JSON { "url": "..." } pour que le front puisse ouvrir la mire dans un nouvel onglet.
     *
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function initStreamAuth(Request $request, Response $response): Response
    {
        $streamCallbackUrl = $_SERVER['WEBSITE_DOMAIN'] . '/admin/stream/auth-callback';
        $authorizationUrl = $this->apiWrapper->generateAuthorizationUrl(null, $streamCallbackUrl);

        $response->getBody()->write(json_encode(['url' => $authorizationUrl]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Callback OAuth pour la connexion d'une association lors de la création d'un stream.
     * Échange le code d'autorisation, stocke les tokens et récupère la liste des formulaires de don.
     * Retourne une page HTML qui transmet les données à la fenêtre parente via postMessage.
     *
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function streamAuthCallback(Request $request, Response $response): Response
    {
        $error = $request->getQueryParams()['error'] ?? null;
        $errorDescription = $request->getQueryParams()['error_description'] ?? null;

        if ($error) {
            $response->getBody()->write($this->buildCallbackPage(null, [], $errorDescription));
            return $response;
        }

        $state = $request->getQueryParams()['state'] ?? null;
        $code = $request->getQueryParams()['code'] ?? null;

        if (!$state || !$code) {
            $response->getBody()->write($this->buildCallbackPage(null, [], 'Paramètres manquants dans la réponse.'));
            return $response;
        }

        try {
            $authorizationCodeData = $this->authorizationCodeRepository->selectById($state);
            if (!$authorizationCodeData) {
                throw new Exception("State invalide ou expiré.");
            }

            $tokenData = $this->apiWrapper->exchangeAuthorizationCode(
                $code,
                $authorizationCodeData->redirect_uri,
                $authorizationCodeData->code_verifier
            );

            $organizationSlug = $tokenData['organization_slug'];

            // Stocker / mettre à jour les tokens
            $existingToken = $this->accessTokenRepository->selectBySlug($organizationSlug);

            $token = new AccessToken();
            $token->access_token = $tokenData['access_token'];
            $token->refresh_token = $tokenData['refresh_token'];
            $token->organization_slug = $organizationSlug;
            $token->access_token_expires_at = (new DateTime())->add(new DateInterval('PT28M'));
            $token->refresh_token_expires_at = (new DateTime())->add(new DateInterval('P28D'));

            if ($existingToken === null) {
                $this->accessTokenRepository->insert($token);
            } else {
                $this->accessTokenRepository->update($token);
            }

            // Récupérer les formulaires de don
            $forms = $this->apiWrapper->getDonationForms($organizationSlug);
        } catch (Exception $e) {
            $response->getBody()->write($this->buildCallbackPage(null, [], $e->getMessage()));
            return $response;
        }

        $response->getBody()->write($this->buildCallbackPage($organizationSlug, $forms));
        return $response;
    }

    /**
     * Construit la page HTML de callback OAuth qui communique les données à la fenêtre parente via postMessage.
     */
    private function buildCallbackPage(?string $organizationSlug, array $forms, ?string $error = null): string
    {
        $organizationSlugJson = json_encode($organizationSlug);
        $formsJson = json_encode($forms);
        $errorJson = json_encode($error);

        return <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Connexion HelloAsso</title>
    <style>
        body { font-family: sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; background: #1a1a2e; color: #eee; }
        .card { background: #16213e; padding: 2rem; border-radius: 12px; text-align: center; max-width: 400px; }
        .success { color: #4ade80; font-size: 2rem; }
        .error { color: #f87171; }
    </style>
</head>
<body>
<div class="card">
    <div id="msg"><p>Connexion en cours, veuillez patienter...</p></div>
</div>
<script>
    var organizationSlug = {$organizationSlugJson};
    var forms = {$formsJson};
    var error = {$errorJson};

    if (error) {
        document.getElementById('msg').innerHTML = '<p class="error">❌ Erreur : ' + error + '</p><p>Vous pouvez fermer cet onglet.</p>';
    } else if (window.opener && !window.opener.closed) {
        window.opener.postMessage({
            type: 'ha_stream_auth_success',
            organizationSlug: organizationSlug,
            forms: forms
        }, window.location.origin);
        document.getElementById('msg').innerHTML = '<p class="success">✅</p><p>Association connectée ! Fermeture en cours...</p>';
        setTimeout(function() { window.close(); }, 1500);
    } else {
        document.getElementById('msg').innerHTML = '<p class="success">✅ Association connectée !</p><p>Vous pouvez fermer cet onglet et retourner à la page d\'administration.</p>';
    }
</script>
</body>
</html>
HTML;
    }
}

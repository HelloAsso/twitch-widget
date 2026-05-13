<?php

namespace App\Controllers;

use App\Repositories\AccessTokenRepository;
use App\Repositories\AuthorizationCodeRepository;
use App\Repositories\EventRepository;
use App\Repositories\FileManager;
use App\Repositories\StreamRepository;
use App\Repositories\UserRepository;
use App\Repositories\WidgetRepository;
use App\Services\ApiWrapper;
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

    /**
     * Retourne les slugs d'organisation dont le token est expiré parmi les streams donnés.
     */
    private function getInvalidTokenSlugs(array $streams): array
    {
        $validSlugs = $this->accessTokenRepository->getValidOrganizationSlugs();
        $invalidSlugs = [];
        foreach ($streams as $stream) {
            if ($stream->organization_slug && !in_array($stream->organization_slug, $validSlugs)) {
                $invalidSlugs[] = $stream->organization_slug;
            }
        }
        return array_unique($invalidSlugs);
    }

    private function redirectToRoute(Request $request, Response $response, string $routeName, array $params = []): Response
    {
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $url = $routeParser->urlFor($routeName, $params);
        return $response->withHeader('Location', $url)->withStatus(302);
    }

    public function index(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if ($user->role === "ADMIN") {
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
            "selectedEventId" => $request->getQueryParams()['eventId'] ?? null,
            "openCreateStream" => isset($request->getQueryParams()['createStream']),
            "openCreateEvent" => isset($request->getQueryParams()['createEvent']),
            "invalidTokenSlugs" => $this->getInvalidTokenSlugs($streams),
            "ownerEmail" => $user->email,
        ];

        if ($user->role === "ADMIN") {
            $data["users"] = $this->userRepository->selectAll();
        }

        $template = $user->role === "ADMIN" ? 'stream/index-admin.html.twig' : 'stream/index.html.twig';
        return $this->view->render($response, $template, $data);
    }

    public function newUser(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $email = trim($data['user_email'] ?? '');

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->messages->addMessage('error', 'Email invalide');
            return $this->redirectToRoute($request, $response, 'app_admin_index');
        }

        $existing = $this->userRepository->select($email);
        if ($existing) {
            $this->messages->addMessage('error', 'Un utilisateur avec cet email existe déjà');
            return $this->redirectToRoute($request, $response, 'app_admin_index');
        }

        $this->userRepository->insert($email);
        $this->messages->addMessage('success', 'Utilisateur créé : ' . $email);
        return $this->redirectToRoute($request, $response, 'app_admin_index');
    }

    public function newEvent(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = $request->getParsedBody();

        $ownerEmail = $data['owner_email'] ?? $user->email;

        // Si l'utilisateur n'est pas admin, il crée l'évènement pour lui-même
        if ($user->role !== 'ADMIN') {
            $ownerEmail = $user->email;
        }

        $owner = $this->userRepository->findOrCreate($ownerEmail);
        $event = $this->eventRepository->insert($data['title']);
        $this->userRepository->insertRight($owner, null, $event);

        $this->messages->addMessage('success', 'Évènement ajouté');
        return $this->redirectToRoute($request, $response, 'app_admin_index');
    }

    public function deleteEvent(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');

        $event = $this->eventRepository->selectByUserAndGuid($user, $args['id']);
        if (!$event) {
            $this->messages->addMessage('error', 'Tu n\'as pas accès cet évènement');
            return $this->redirectToRoute($request, $response, 'app_admin_index');
        }

        $this->eventRepository->delete($event);
        $this->messages->addMessage('success', 'Évènement supprimé');

        return $this->redirectToRoute($request, $response, 'app_admin_index');
    }

    public function editEvent(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $event = $this->eventRepository->selectByUserAndGuid($user, $args['id']);
        $donationGoalWidget = $this->widgetRepository->selectDonationWidgetByGuid(null, $event->guid);
        $cardWidget = $this->widgetRepository->selectCardWidgetByGuid(null, $event->guid);
        $streams = $this->streamRepository->selectListByEvent($event);
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();

        $data = [
            "logged" => true,
            "event" => $event,
            "streams" => $streams,
            "invalidTokenSlugs" => $this->getInvalidTokenSlugs($streams),
            "donationGoalWidget" => $donationGoalWidget,
            "cardWidget" => $cardWidget,
            "cardWidgetPictureUrl" => ($cardWidget && $cardWidget->image) ? $this->fileManager->getPictureUrl($cardWidget->image) : null,
            "widgetDonationGoalUrl" => $_SERVER['WEBSITE_DOMAIN'] . $routeParser->urlFor('app_event_widget_donation', ["id" => $event->guid]),
            "widgetCardUrl" => $_SERVER['WEBSITE_DOMAIN'] . $routeParser->urlFor('app_event_widget_card', ["id" => $event->guid]),
        ];

        return $this->view->render($response, 'event/edit.html.twig', $data);
    }

    public function editEventPost(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $event = $this->eventRepository->selectByUserAndGuid($user, $args['id']);

        $body = $request->getParsedBody();

        if (isset($body['save_event_info'])) {
            $updateData = [];
            if (isset($body['event_title'])) {
                $updateData['title'] = $body['event_title'];
            }
            if (isset($body['event_goal'])) {
                $updateData['goal'] = (int) $body['event_goal'];
            }
            $this->eventRepository->update($event, $updateData);
        }

        $this->handleWidgetFormSave($request, null, $event->guid);

        return $this->redirectToRoute($request, $response, 'app_event_edit', ["id" => $event->guid]);
    }

    public function newStream(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = $request->getParsedBody();

        $parentEvent = $data['parent_event'] ?? null;
        $parentStyle = isset($data['parent_style']);

        $ownerEmail = $data['owner_email'] ?? $user->email;
        if ($user->role !== 'ADMIN') {
            $ownerEmail = $user->email;
        }

        $owner = $this->userRepository->findOrCreate($ownerEmail);

        $event = null;
        if (!empty($parentEvent)) {
            $event = $this->eventRepository->selectByUserAndId($user, $parentEvent);
        }

        $stream = $this->streamRepository->insert($data['form_slug'], $data['organization_slug'], $data['title'], $event->id ?? null);
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
        return $this->redirectToRoute($request, $response, 'app_admin_index');
    }

    public function deleteStream(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');

        $stream = $this->streamRepository->selectByUserAndGuid($user, $args['id']);
        if (!$stream) {
            $this->messages->addMessage('error', 'Tu n\'as pas accès ce stream');
            return $this->redirectToRoute($request, $response, 'app_admin_index');
        }

        $this->streamRepository->delete($stream);
        $this->messages->addMessage('success', 'Stream supprimé');

        return $this->redirectToRoute($request, $response, 'app_admin_index');
    }

    public function editStream(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $charityStream = $this->streamRepository->selectByUserAndGuid($user, $args['id']);
        $guid = $charityStream->guid;

        $donationGoalWidget = $this->widgetRepository->selectDonationWidgetByGuid($guid, null);
        $alertBoxWidget = $this->widgetRepository->selectAlertWidgetByGuid($guid);
        $cardWidget = $this->widgetRepository->selectCardWidgetByGuid($guid, null);

        $parentEvent = null;
        if ($charityStream->charity_event_id) {
            $parentEvent = $this->eventRepository->selectByUserAndId($user, $charityStream->charity_event_id);
        }

        // Liste des events accessibles pour le lien stream ↔ event
        if ($user->role === 'ADMIN') {
            $availableEvents = $this->eventRepository->selectList();
        } else {
            $availableEvents = $this->eventRepository->selectListByUser($user);
        }

        $donationUrl = $_SERVER['HA_URL'] . '/associations/' . $charityStream->organization_slug . '/formulaires/' . $charityStream->form_slug;
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();

        $data = [
            "logged" => true,
            "charityStream" => $charityStream,
            "parentEvent" => $parentEvent,
            "availableEvents" => $availableEvents,
            "donationGoalWidget" => $donationGoalWidget,
            "alertBoxWidget" => $alertBoxWidget,
            "alertBoxWidgetPictureUrl" => ($alertBoxWidget && $alertBoxWidget->image) ? $this->fileManager->getPictureUrl($alertBoxWidget->image) : null,
            "alertBoxWidgetSoundUrl" => ($alertBoxWidget && $alertBoxWidget->sound) ? $this->fileManager->getSoundUrl($alertBoxWidget->sound) : null,
            "cardWidget" => $cardWidget,
            "cardWidgetPictureUrl" => ($cardWidget && $cardWidget->image) ? $this->fileManager->getPictureUrl($cardWidget->image) : null,
            "donationUrl" => $donationUrl,
            "widgetDonationGoalUrl" => $_SERVER['WEBSITE_DOMAIN'] . $routeParser->urlFor('app_stream_widget_donation', ["id" => $guid]),
            "widgetAlertBoxUrl" => $_SERVER['WEBSITE_DOMAIN'] . $routeParser->urlFor('app_stream_widget_alert', ["id" => $guid]),
            "widgetCardUrl" => $_SERVER['WEBSITE_DOMAIN'] . $routeParser->urlFor('app_stream_widget_card', ["id" => $guid]),
            "messages" => $this->messages->getMessages(),
        ];

        return $this->view->render($response, 'stream/edit.html.twig', $data);
    }

    public function editStreamPost(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $charityStream = $this->streamRepository->selectByUserAndGuid($user, $args['id']);
        $guid = $charityStream->guid;

        $body = $request->getParsedBody();

        if (isset($body['save_stream_info'])) {
            $updateData = [];
            if (isset($body['stream_title'])) {
                $updateData['title'] = $body['stream_title'];
            }
            if (isset($body['stream_goal'])) {
                $updateData['goal'] = (int) $body['stream_goal'];
            }
            $this->streamRepository->update($charityStream, $updateData);
        }

        if (isset($body['link_event'])) {
            $eventId = !empty($body['event_id']) ? (int) $body['event_id'] : null;
            if ($eventId) {
                $event = $this->eventRepository->selectByUserAndId($user, $eventId);
                if ($event) {
                    $this->streamRepository->updateEventLink($charityStream, $event->id);
                    $this->messages->addMessage('success', 'Stream lié à l\'événement « ' . $event->title . ' »');
                } else {
                    $this->messages->addMessage('error', 'Événement introuvable ou non autorisé');
                }
            }
        }

        if (isset($body['unlink_event'])) {
            $this->streamRepository->updateEventLink($charityStream, null);
            $this->messages->addMessage('success', 'Stream délié de son événement');
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

        $this->handleWidgetFormSave($request, $guid, null);

        return $this->redirectToRoute($request, $response, 'app_stream_edit', ["id" => $guid]);
    }

    /**
     * Traite les sauvegardes communes des widgets donation goal et card widget.
     */
    private function handleWidgetFormSave(Request $request, ?string $streamGuid, ?string $eventGuid): void
    {
        $body = $request->getParsedBody();

        if (isset($body['save_donation_goal'])) {
            $this->widgetRepository->updateDonationWidget($streamGuid, $eventGuid, $body);
        }

        if (isset($body['save_card_widget'])) {
            $uploadedFiles = $request->getUploadedFiles();
            $image = isset($uploadedFiles['card_image']) && $uploadedFiles['card_image']->getSize() > 0
                ? $this->fileManager->uploadPicture($uploadedFiles['card_image'])
                : null;
            $this->widgetRepository->updateCardWidget($streamGuid, $eventGuid, $body, $image);
        }
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
            $this->apiWrapper->storeOrUpdateToken($tokenData);

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

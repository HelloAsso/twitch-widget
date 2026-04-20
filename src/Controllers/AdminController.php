<?php

namespace App\Controllers;

use App\Repositories\EventRepository;
use App\Repositories\FileManager;
use App\Repositories\StreamRepository;
use App\Repositories\UserRepository;
use App\Repositories\WidgetRepository;
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
}

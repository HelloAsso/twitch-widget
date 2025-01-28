<?php

namespace App\Controllers;

use App\Repositories\FileManager;
use App\Repositories\StreamRepository;
use Exception;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Flash\Messages;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

class StreamController
{
    public function __construct(
        private Twig $view,
        private FileManager $fileManager,
        private StreamRepository $streamRepository,
        private Messages $messages,
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $streams = $this->streamRepository->getCharityStreamsListDB();
        $messages = $this->messages->getMessages();

        if (array_key_exists('password', $messages) && count(explode('_', $messages['password'][0])) == 2) {
            $passwordGuid =  explode('_', $messages['password'][0])[0];
            $password =  explode('_', $messages['password'][0])[1];
        }

        $data = [
            "streams" => $streams,
            "passwordGuid" => $passwordGuid ?? null,
            "password" => $password ?? null,
        ];

        return $this->view->render($response, 'stream/index.html.twig', $data);
    }

    public function new(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        $ownerEmail = $data['owner_email'];
        $formSlug = $data['form_slug'];
        $organizationSlug = $data['organization_slug'];
        $title = $data['title'];

        $guid = bin2hex(random_bytes(16));

        $password = $this->streamRepository->createCharityStreamDB($guid, $ownerEmail, $formSlug, $organizationSlug, $title);
        $this->messages->addMessage('password', $guid . '_' . $password);

        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $url = $routeParser->urlFor('app_stream_index');

        return $response->withHeader('Location', $url)->withStatus(302);
    }

    public function refreshPassword(Request $request, Response $response, array $args): Response
    {
        $data = $request->getParsedBody();
        $email = $data['email'];
        $guid = $args['id'];

        $password = $this->streamRepository->updateUserPassword($email);
        $this->messages->addMessage('password', $guid . '_' . $password);

        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $url = $routeParser->urlFor('app_stream_index');

        return $response->withHeader('Location', $url)->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $this->streamRepository->deleteCharityStream($args['id']);

        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $url = $routeParser->urlFor('app_stream_index');

        return $response->withHeader('Location', $url)->withStatus(302);
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        if (isset($_SESSION['user'])) {
            $charityStreams = $this->streamRepository->getCharityStreamByEmail($_SESSION['user']['email']);
            $guidBinary = $charityStreams[0]['guid'];
            $guidHex = bin2hex($charityStreams[0]['guid']);
        } else {
            $guidHex = $args['id'] ?? '';
            if (!$guidHex) {
                throw new Exception("GUID manquant ou incorrect.");
            }
            $guidBinary = hex2bin($guidHex);
        }

        $charityStream = $this->streamRepository->getCharityStreamByGuidDB($guidBinary);
        $donationGoalWidget = $this->streamRepository->getDonationGoalWidgetByGuidDB($guidBinary);
        $alertBoxWidget = $this->streamRepository->getAlertBoxWidgetByGuidDB($guidBinary);

        $donationUrl = $_SERVER['HA_URL'] . '/associations/' . $charityStream['organization_slug'] . '/formulaires/' . $charityStream['form_slug'];

        $widgetDonationGoalUrl = $_SERVER['WEBSITE_DOMAIN'] . '/widget/' . $guidHex . '/donation';
        $widgetAlertBoxUrl = $_SERVER['WEBSITE_DOMAIN'] . '/widget/' . $guidHex . '/alert';

        $data = [
            "logged" => isset($_SESSION['user']),
            "charityStream" => $charityStream,
            "donationGoalWidget" => $donationGoalWidget,
            "alertBoxWidget" => $alertBoxWidget,
            "alertBoxWidgetPictureUrl" => $this->fileManager->getPictureUrl($alertBoxWidget['image']),
            "alertBoxWidgetSoundUrl" => $this->fileManager->getSoundUrl($alertBoxWidget['sound']),
            "donationUrl" => $donationUrl,
            "widgetDonationGoalUrl" => $widgetDonationGoalUrl,
            "widgetAlertBoxUrl" => $widgetAlertBoxUrl,
        ];

        return $this->view->render($response, 'stream/edit.html.twig', $data);
    }

    public function editPost(Request $request, Response $response, array $args): Response
    {
        if (isset($_SESSION['user'])) {
            $charityStreams = $this->streamRepository->getCharityStreamByEmail($_SESSION['user']['email']);
            $guidBinary = $charityStreams[0]['guid'];
            $guidHex = bin2hex($charityStreams[0]['guid']);
        } else {
            $guidHex = $args['id'] ?? '';
            if (!$guidHex) {
                throw new Exception("GUID manquant ou incorrect.");
            }
            $guidBinary = hex2bin($guidHex);
        }

        if (isset($_POST['save_donation_goal'])) {
            $this->streamRepository->updateDonationGoalWidgetDB($guidBinary, $_POST);
        }

        if (isset($_POST['save_alert_box'])) {
            if (isset($_FILES["image"]) && $_FILES["image"]['size'] > 0)
                $image = $this->fileManager->uploadPicture($_FILES["image"]);
            if (isset($_FILES["sound"]) && $_FILES["sound"]['size'] > 0)
                $sound = $this->fileManager->uploadSound($_FILES["sound"]);

            $this->streamRepository->updateAlertBoxWidgetDB($guidBinary, $_POST, $image ?? null, $sound ?? null);
        }

        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $url = $routeParser->urlFor('app_stream_edit', ["id" => $guidHex]);

        return $response->withHeader('Location', $url)->withStatus(302);
    }
}

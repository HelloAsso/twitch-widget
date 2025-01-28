<?php

namespace App\Controllers;

use App\Repositories\FileManager;
use App\Repositories\StreamRepository;
use App\Services\ApiWrapper;
use Exception;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class WidgetController
{
    public function __construct(
        private Twig $view,
        private ApiWrapper $apiWrapper,
        private FileManager $fileManager,
        private StreamRepository $streamRepository,
    ) {}

    public function widgetFetchDonation(Request $request, Response $response, array $args): Response
    {
        $charityStreamId = $args['id'] ?? '';
        if (!$charityStreamId) {
            $response->getBody()->write(json_encode(['error' => 'Charity Stream ID manquant ou incorrect.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $guidBinary = hex2bin($charityStreamId);

        $charityStream = $this->streamRepository->getCharityStreamByGuidDB($guidBinary);
        if (!$charityStream) {
            $response->getBody()->write(json_encode(['error' => 'Charity Stream non trouvé.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $currentAmount = $request->getQueryParams()['currentAmount'] ?? 0;
        $continuationToken = $request->getQueryParams()['continuationToken'] ?? null;
        $from = $request->getQueryParams()['from'] ?? null;

        try {
            $result = $this->apiWrapper->getAllOrders(
                $charityStream['organization_slug'],
                $charityStream['form_slug'],
                $currentAmount,
                $continuationToken,
                $from
            );

            $response->getBody()->write(json_encode($result));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            $response->getBody()->write(json_encode(['error' => 'Impossible de récupérer les commandes.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    public function widgetAlert(Request $request, Response $response, array $args): Response
    {
        $charityStreamId = $args['id'] ?? '';
        if (!$charityStreamId) {
            throw new Exception("Charity Stream ID manquant ou incorrect.");
        }

        $guidBinary = hex2bin($charityStreamId);

        $alertBoxWidget = $this->streamRepository->getAlertBoxWidgetByGuidDB($guidBinary);
        if (!$alertBoxWidget) {
            throw new Exception("Aucun widget trouvé pour le Charity Stream ID fourni.");
        }

        $data = [
            "alertBoxWidget" => $alertBoxWidget,
            "alertBoxWidgetPictureUrl" => $this->fileManager->getPictureUrl($alertBoxWidget['image']),
            "alertBoxWidgetSoundUrl" => $this->fileManager->getSoundUrl($alertBoxWidget['sound']),
            "charityStreamId" => $charityStreamId
        ];

        return $this->view->render($response, 'widget/alert.html.twig', $data);
    }

    public function widgetDonation(Request $request, Response $response, array $args): Response
    {
        $charityStreamId = $args['id'] ?? '';
        if (!$charityStreamId) {
            throw new Exception("Charity Stream ID manquant ou incorrect.");
        }

        $guidBinary = hex2bin($charityStreamId);

        $donationGoalWidget = $this->streamRepository->getDonationGoalWidgetByGuidDB($guidBinary);
        if (!$donationGoalWidget) {
            throw new Exception("Aucun widget trouvé pour le Charity Stream ID fourni.");
        }

        $charityStream = $this->streamRepository->getCharityStreamByGuidDB($guidBinary);
        if (!$charityStream) {
            throw new Exception("Charity Stream non trouvé.");
        }

        $result = $this->apiWrapper->getAllOrders(
            $charityStream['organization_slug'],
            $charityStream['form_slug']
        );

        $currentAmount = $result['amount'];
        $continuationToken = $result['continuationToken'];

        $data = [
            "donationGoalWidget" => $donationGoalWidget,
            "currentAmount" => $currentAmount,
            "continuationToken" => $continuationToken,
            "charityStreamId" => $charityStreamId,
        ];

        return $this->view->render($response, 'widget/donation.html.twig', $data);
    }
}

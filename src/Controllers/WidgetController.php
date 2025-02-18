<?php

namespace App\Controllers;

use App\Repositories\EventRepository;
use App\Repositories\FileManager;
use App\Repositories\StreamRepository;
use App\Repositories\WidgetRepository;
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
        private EventRepository $eventRepository,
        private StreamRepository $streamRepository,
        private WidgetRepository $widgetRepository,
    ) {}

    public function widgetEventDonation(Request $request, Response $response, array $args): Response
    {
        $eventGuid = $args['id'] ?? '';
        if (!$eventGuid) {
            throw new Exception("Event ID manquant ou incorrect.");
        }

        $donationGoalWidget = $this->widgetRepository->selectDonationWidgetByGuid(null, $eventGuid);
        if (!$donationGoalWidget) {
            throw new Exception("Aucun widget trouvé pour le Event ID fourni.");
        }

        $event = $this->eventRepository->selectByGuid($eventGuid);
        if (!$event) {
            throw new Exception("Event non trouvé.");
        }

        $cacheData = $this->widgetRepository->selectEventDonationWidgetCacheData($event);
        if (!$cacheData) {
            $cacheData = [
                'amount' => 0,
                'streams' => []
            ];
        }

        $streams = $this->streamRepository->selectListByEvent($event);
        $totalAmount = 0;

        foreach ($streams as $stream) {
            $streamCache = null;

            foreach ($cacheData['streams'] as &$cachedStream) {
                if ($cachedStream['id'] === $stream->id) {
                    $streamCache = &$cachedStream;
                    break;
                }
            }

            if (!$streamCache) {
                $streamCache = [
                    'id' => $stream->id,
                    'amount' => 0,
                    'continuation_token' => null
                ];
                $cacheData['streams'][] = &$streamCache;
            }

            $result = $this->apiWrapper->getAllOrders(
                $stream->organization_slug,
                $stream->form_slug,
                $streamCache['amount'],
                $streamCache['continuation_token']
            );

            $streamCache['amount'] = $result['amount'];
            $streamCache['continuation_token'] = $result['continuationToken'];

            $totalAmount += $result['amount'];
        }

        if ($cacheData['amount'] !== $totalAmount) {
            $cacheData['amount'] = $totalAmount;

            $this->widgetRepository->updateEventDonationWidgetCacheData($event->guid, $cacheData);
        }

        $data = [
            "donationGoalWidget" => $donationGoalWidget,
            "currentAmount" => $totalAmount,
            "event" => 1
        ];

        return $this->view->render($response, 'widget/donation.html.twig', $data);
    }

    public function widgetEventDonationFetch(Request $request, Response $response, array $args): Response
    {
        $eventId = $args['id'] ?? '';
        if (!$eventId) {
            $response->getBody()->write(json_encode(['error' => 'Event ID manquant ou incorrect.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $event = $this->eventRepository->selectByGuid($eventId);
        if (!$event) {
            $response->getBody()->write(json_encode(['error' => 'Event non trouvé.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $cacheData = $this->widgetRepository->selectEventDonationWidgetCacheData($event);

        try {
            $streams = $this->streamRepository->selectListByEvent($event);
            $totalAmount = 0;

            foreach ($streams as $stream) {
                $streamCache = null;

                foreach ($cacheData['streams'] as &$cachedStream) {
                    if ($cachedStream['id'] === $stream->id) {
                        $streamCache = &$cachedStream;
                        break;
                    }
                }

                if (!$streamCache) {
                    $streamCache = [
                        'id' => $stream->id,
                        'amount' => 0,
                        'continuation_token' => null
                    ];
                    $cacheData['streams'][] = &$streamCache;
                }

                $result = $this->apiWrapper->getAllOrders(
                    $stream->organization_slug,
                    $stream->form_slug,
                    $streamCache['amount'],
                    $streamCache['continuation_token']
                );

                $streamCache['amount'] = $result['amount'];
                $streamCache['continuation_token'] = $result['continuationToken'];

                $totalAmount += $result['amount'];
            }

            if ($cacheData['amount'] !== $totalAmount) {
                $cacheData['amount'] = $totalAmount;

                $this->widgetRepository->updateEventDonationWidgetCacheData($event->guid, $cacheData);
            }

            $result = [
                'amount' => $totalAmount
            ];

            $response->getBody()->write(json_encode($result));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            $response->getBody()->write(json_encode(['error' => 'Impossible de récupérer le montant']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    public function widgetAlert(Request $request, Response $response, array $args): Response
    {
        $charityStreamId = $args['id'] ?? '';
        if (!$charityStreamId) {
            throw new Exception("Charity Stream ID manquant ou incorrect.");
        }

        $alertBoxWidget = $this->widgetRepository->selectAlertWidgetByGuid($charityStreamId);
        if (!$alertBoxWidget) {
            throw new Exception("Aucun widget trouvé pour le Charity Stream ID fourni.");
        }

        $charityStream = $this->streamRepository->selectByGuid($charityStreamId);
        if (!$charityStream) {
            throw new Exception("Charity Stream non trouvé.");
        }

        $cacheData = $this->widgetRepository->selectAlertWidgetCacheData($charityStream);
        if (!$cacheData) {
            $cacheData = [
                'continuation_token' => "",
            ];
        }

        $result = $this->apiWrapper->getAllOrders(
            $charityStream->organization_slug,
            $charityStream->form_slug,
            0,
            $cacheData->continuation_token,
        );

        if ($cacheData->continuation_token != $result['continuationToken']) {
            $this->widgetRepository->updateAlertWidgetCacheData($charityStream->guid, [
                "continuation_token" => $result['continuationToken']
            ]);
        }

        $data = [
            "alertBoxWidget" => $alertBoxWidget,
            "alertBoxWidgetPictureUrl" => $this->fileManager->getPictureUrl($alertBoxWidget->image),
            "alertBoxWidgetSoundUrl" => $this->fileManager->getSoundUrl($alertBoxWidget->sound),
        ];

        return $this->view->render($response, 'widget/alert.html.twig', $data);
    }

    public function widgetAlertFetch(Request $request, Response $response, array $args): Response
    {
        $charityStreamId = $args['id'] ?? '';
        if (!$charityStreamId) {
            $response->getBody()->write(json_encode(['error' => 'Charity Stream ID manquant ou incorrect.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $charityStream = $this->streamRepository->selectByGuid($charityStreamId);
        if (!$charityStream) {
            $response->getBody()->write(json_encode(['error' => 'Charity Stream non trouvé.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $cacheData = $this->widgetRepository->selectAlertWidgetCacheData($charityStream);

        try {
            $result = $this->apiWrapper->getAllOrders(
                $charityStream->organization_slug,
                $charityStream->form_slug,
                0,
                $cacheData->continuation_token
            );

            if ($cacheData->continuation_token != $result['continuationToken']) {
                $this->widgetRepository->updateAlertWidgetCacheData($charityStream->guid, [
                    "continuation_token" => $result['continuationToken']
                ]);
            }

            $response->getBody()->write(json_encode($result));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            $response->getBody()->write(json_encode(['error' => 'Impossible de récupérer les commandes.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    public function widgetDonation(Request $request, Response $response, array $args): Response
    {
        $streamGuid = $args['id'] ?? '';
        if (!$streamGuid) {
            throw new Exception("Charity Stream ID manquant ou incorrect.");
        }

        $donationGoalWidget = $this->widgetRepository->selectDonationWidgetByGuid($streamGuid, null);
        if (!$donationGoalWidget) {
            throw new Exception("Aucun widget trouvé pour le Charity Stream ID fourni.");
        }

        $charityStream = $this->streamRepository->selectByGuid($streamGuid);
        if (!$charityStream) {
            throw new Exception("Charity Stream non trouvé.");
        }

        $cacheData = $this->widgetRepository->selectStreamDonationWidgetCacheData($charityStream);
        if (!$cacheData) {
            $cacheData = [
                'amount' => 0,
                'continuation_token' => "",
            ];
        }

        $result = $this->apiWrapper->getAllOrders(
            $charityStream->organization_slug,
            $charityStream->form_slug,
            $cacheData->amount,
            $cacheData->continuation_token,
        );

        if ($cacheData->continuation_token != $result['continuationToken']) {
            $this->widgetRepository->updateStreamDonationWidgetCacheData($charityStream->guid, [
                "amount" => $result['amount'],
                "continuation_token" => $result['continuationToken']
            ]);
        }

        $data = [
            "donationGoalWidget" => $donationGoalWidget,
            "currentAmount" => $result['amount'],
            "stream" => 1
        ];

        return $this->view->render($response, 'widget/donation.html.twig', $data);
    }

    public function widgetDonationFetch(Request $request, Response $response, array $args): Response
    {
        $charityStreamId = $args['id'] ?? '';
        if (!$charityStreamId) {
            $response->getBody()->write(json_encode(['error' => 'Charity Stream ID manquant ou incorrect.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $charityStream = $this->streamRepository->selectByGuid($charityStreamId);
        if (!$charityStream) {
            $response->getBody()->write(json_encode(['error' => 'Charity Stream non trouvé.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $cacheData = $this->widgetRepository->selectStreamDonationWidgetCacheData($charityStream);

        try {
            $result = $this->apiWrapper->getAllOrders(
                $charityStream->organization_slug,
                $charityStream->form_slug,
                $cacheData->amount,
                $cacheData->continuation_token
            );

            if ($cacheData->continuation_token != $result['continuationToken']) {
                $this->widgetRepository->updateStreamDonationWidgetCacheData($charityStream->guid, [
                    "amount" => $result['amount'],
                    "continuation_token" => $result['continuationToken']
                ]);
            }

            $response->getBody()->write(json_encode($result));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            $response->getBody()->write(json_encode(['error' => 'Impossible de récupérer les commandes.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }
}

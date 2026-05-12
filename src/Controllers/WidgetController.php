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

    // ── Helpers ───────────────────────────────────────────────────

    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    private function jsonError(Response $response, string $message, int $status): Response
    {
        return $this->jsonResponse($response, ['error' => $message], $status);
    }

    /**
     * Agrège les montants de tous les streams d'un event via le cache par stream.
     * Retourne le cache mis à jour avec le total.
     */
    private function aggregateEventStreams(array $streams, array $cacheData, bool $trackDonors = false): array
    {
        $totalAmount = 0;
        $totalDonors = 0;

        foreach ($streams as $stream) {
            $streamCache = null;

            foreach ($cacheData['streams'] as &$cachedStream) {
                if ($cachedStream !== null && $cachedStream['id'] === $stream->id) {
                    $streamCache = &$cachedStream;
                    break;
                }
            }
            unset($cachedStream);

            if (!$streamCache) {
                $defaultCache = ['id' => $stream->id, 'amount' => 0, 'continuation_token' => null];
                if ($trackDonors) {
                    $defaultCache['donors'] = 0;
                }
                $streamCache = $defaultCache;
                $cacheData['streams'][] = &$streamCache;
            }

            $result = $this->apiWrapper->getAllOrders(
                $stream->organization_slug,
                $stream->form_slug,
                $streamCache['amount'],
                $streamCache['continuation_token']
            );

            $streamCache['amount'] = $result['amount'];
            $streamCache['continuation_token'] = $result['continuation_token'];

            if ($trackDonors) {
                $streamCache['donors'] = ($streamCache['donors'] ?? 0) + count($result['donations'] ?? []);
                $totalDonors += $streamCache['donors'];
            }

            $totalAmount += $result['amount'];
            unset($streamCache);
        }

        $cacheData['amount'] = $totalAmount;
        if ($trackDonors) {
            $cacheData['donors'] = $totalDonors;
        }

        return $cacheData;
    }

    // ── Event Donation ───────────────────────────────────────────

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

        $cacheData = $this->widgetRepository->selectEventDonationWidgetCacheData($event)
            ?? ['amount' => 0, 'streams' => []];

        $streams = $this->streamRepository->selectListByEvent($event);
        $oldAmount = $cacheData['amount'];
        $cacheData = $this->aggregateEventStreams($streams, $cacheData);

        if ($oldAmount !== $cacheData['amount']) {
            $this->widgetRepository->updateEventDonationWidgetCacheData($event->guid, $cacheData);
        }

        $data = [
            "donationGoalWidget" => $donationGoalWidget,
            "currentAmount" => $cacheData['amount'],
            "event" => 1
        ];

        return $this->view->render($response, 'widget/donation.html.twig', $data);
    }

    public function widgetEventDonationFetch(Request $request, Response $response, array $args): Response
    {
        $eventId = $args['id'] ?? '';
        if (!$eventId) {
            return $this->jsonError($response, 'Event ID manquant ou incorrect.', 400);
        }

        $event = $this->eventRepository->selectByGuid($eventId);
        if (!$event) {
            return $this->jsonError($response, 'Event non trouvé.', 404);
        }

        $cacheData = $this->widgetRepository->selectEventDonationWidgetCacheData($event)
            ?? ['amount' => 0, 'streams' => []];

        try {
            $streams = $this->streamRepository->selectListByEvent($event);
            $oldAmount = $cacheData['amount'];
            $cacheData = $this->aggregateEventStreams($streams, $cacheData);

            if ($oldAmount !== $cacheData['amount']) {
                $this->widgetRepository->updateEventDonationWidgetCacheData($event->guid, $cacheData);
            }

            return $this->jsonResponse($response, ['amount' => $cacheData['amount']]);
        } catch (Exception $e) {
            return $this->jsonError($response, 'Impossible de récupérer le montant', 500);
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
            $cacheData["continuation_token"],
        );

        if ($cacheData["continuation_token"] !== $result['continuation_token']) {
            $this->widgetRepository->updateAlertWidgetCacheData($charityStream->guid, [
                "continuation_token" => $result['continuation_token']
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
            return $this->jsonError($response, 'Charity Stream ID manquant ou incorrect.', 400);
        }

        $charityStream = $this->streamRepository->selectByGuid($charityStreamId);
        if (!$charityStream) {
            return $this->jsonError($response, 'Charity Stream non trouvé.', 404);
        }

        $cacheData = $this->widgetRepository->selectAlertWidgetCacheData($charityStream)
            ?? ['continuation_token' => ''];

        try {
            $result = $this->apiWrapper->getAllOrders(
                $charityStream->organization_slug,
                $charityStream->form_slug,
                0,
                $cacheData['continuation_token']
            );

            if ($cacheData['continuation_token'] !== $result['continuation_token']) {
                $this->widgetRepository->updateAlertWidgetCacheData($charityStream->guid, [
                    "continuation_token" => $result['continuation_token']
                ]);
            }

            return $this->jsonResponse($response, $result);
        } catch (Exception $e) {
            return $this->jsonError($response, 'Impossible de récupérer les commandes.', 500);
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

        $cacheData = $this->widgetRepository->selectStreamDonationWidgetCacheData($charityStream)
            ?? ['amount' => 0, 'continuation_token' => ''];

        $result = $this->apiWrapper->getAllOrders(
            $charityStream->organization_slug,
            $charityStream->form_slug,
            $cacheData['amount'],
            $cacheData['continuation_token'],
        );

        if ($cacheData['continuation_token'] !== $result['continuation_token'] || $cacheData['amount'] !== $result['amount']) {
            $this->widgetRepository->updateStreamDonationWidgetCacheData($charityStream->guid, [
                "amount" => $result['amount'],
                "continuation_token" => $result['continuation_token']
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
            return $this->jsonError($response, 'Charity Stream ID manquant ou incorrect.', 400);
        }

        $charityStream = $this->streamRepository->selectByGuid($charityStreamId);
        if (!$charityStream) {
            return $this->jsonError($response, 'Charity Stream non trouvé.', 404);
        }

        $cacheData = $this->widgetRepository->selectStreamDonationWidgetCacheData($charityStream)
            ?? ['amount' => 0, 'continuation_token' => ''];

        try {
            $result = $this->apiWrapper->getAllOrders(
                $charityStream->organization_slug,
                $charityStream->form_slug,
                $cacheData['amount'],
                $cacheData['continuation_token']
            );

            if ($cacheData['continuation_token'] !== $result['continuation_token']
                || $cacheData['amount'] !== $result['amount']) {
                $this->widgetRepository->updateStreamDonationWidgetCacheData($charityStream->guid, [
                    "amount" => $result['amount'],
                    "continuation_token" => $result['continuation_token']
                ]);
            }

            return $this->jsonResponse($response, $result);
        } catch (Exception $e) {
            return $this->jsonError($response, 'Impossible de récupérer les commandes.', 500);
        }
    }

    // ── Widget Card (Stream) ─────────────────────────────────────

    public function widgetStreamCard(Request $request, Response $response, array $args): Response
    {
        $streamGuid = $args['id'] ?? '';
        if (!$streamGuid) {
            throw new Exception("Charity Stream ID manquant ou incorrect.");
        }

        $cardWidget = $this->widgetRepository->selectCardWidgetByGuid($streamGuid, null);
        if (!$cardWidget) {
            throw new Exception("Aucun widget card trouvé pour le Charity Stream ID fourni.");
        }

        $charityStream = $this->streamRepository->selectByGuid($streamGuid);
        if (!$charityStream) {
            throw new Exception("Charity Stream non trouvé.");
        }

        $cacheData = $this->widgetRepository->selectStreamCardWidgetCacheData($charityStream)
            ?? ['amount' => 0, 'donors' => 0, 'continuation_token' => ''];

        $result = $this->apiWrapper->getAllOrders(
            $charityStream->organization_slug,
            $charityStream->form_slug,
            $cacheData['amount'],
            $cacheData['continuation_token'],
        );

        $newDonors = count($result['donations'] ?? []);
        $donors = ($cacheData['donors'] ?? 0) + $newDonors;

        if ($cacheData['continuation_token'] !== $result['continuation_token'] || $cacheData['amount'] !== $result['amount']) {
            $this->widgetRepository->updateStreamCardWidgetCacheData($charityStream->guid, [
                "amount" => $result['amount'],
                "donors" => $donors,
                "continuation_token" => $result['continuation_token']
            ]);
        }

        $currentAmount = $result['amount'];
        $goal = $cardWidget->goal ?: 1;
        $percentage = min(100, round(($currentAmount / 100) / $goal * 100));

        $data = [
            "cardWidget" => $cardWidget,
            "cardWidgetPictureUrl" => $cardWidget->image ? $this->fileManager->getPictureUrl($cardWidget->image) : null,
            "currentAmount" => $currentAmount,
            "donorCount" => $donors,
            "percentage" => $percentage,
            "stream" => 1
        ];

        return $this->view->render($response, 'widget/card.html.twig', $data);
    }

    public function widgetStreamCardFetch(Request $request, Response $response, array $args): Response
    {
        $charityStreamId = $args['id'] ?? '';
        if (!$charityStreamId) {
            return $this->jsonError($response, 'Charity Stream ID manquant ou incorrect.', 400);
        }

        $charityStream = $this->streamRepository->selectByGuid($charityStreamId);
        if (!$charityStream) {
            return $this->jsonError($response, 'Charity Stream non trouvé.', 404);
        }

        $cacheData = $this->widgetRepository->selectStreamCardWidgetCacheData($charityStream)
            ?? ['amount' => 0, 'donors' => 0, 'continuation_token' => ''];

        try {
            $result = $this->apiWrapper->getAllOrders(
                $charityStream->organization_slug,
                $charityStream->form_slug,
                $cacheData['amount'],
                $cacheData['continuation_token']
            );

            $newDonors = count($result['donations'] ?? []);
            $donors = ($cacheData['donors'] ?? 0) + $newDonors;

            if ($cacheData['continuation_token'] !== $result['continuation_token']
                || $cacheData['amount'] !== $result['amount']) {
                $this->widgetRepository->updateStreamCardWidgetCacheData($charityStream->guid, [
                    "amount" => $result['amount'],
                    "donors" => $donors,
                    "continuation_token" => $result['continuation_token']
                ]);
            }

            return $this->jsonResponse($response, ['amount' => $result['amount'], 'donors' => $donors]);
        } catch (Exception $e) {
            return $this->jsonError($response, 'Impossible de récupérer les données.', 500);
        }
    }

    // ── Widget Card (Event) ──────────────────────────────────────

    public function widgetEventCard(Request $request, Response $response, array $args): Response
    {
        $eventGuid = $args['id'] ?? '';
        if (!$eventGuid) {
            throw new Exception("Event ID manquant ou incorrect.");
        }

        $cardWidget = $this->widgetRepository->selectCardWidgetByGuid(null, $eventGuid);
        if (!$cardWidget) {
            throw new Exception("Aucun widget card trouvé pour le Event ID fourni.");
        }

        $event = $this->eventRepository->selectByGuid($eventGuid);
        if (!$event) {
            throw new Exception("Event non trouvé.");
        }

        $cacheData = $this->widgetRepository->selectEventCardWidgetCacheData($event)
            ?? ['amount' => 0, 'donors' => 0, 'streams' => []];

        $streams = $this->streamRepository->selectListByEvent($event);
        $oldAmount = $cacheData['amount'];
        $cacheData = $this->aggregateEventStreams($streams, $cacheData, true);

        if ($oldAmount !== $cacheData['amount']) {
            $this->widgetRepository->updateEventCardWidgetCacheData($event->guid, $cacheData);
        }

        $goal = $cardWidget->goal ?: 1;
        $percentage = min(100, round(($cacheData['amount'] / 100) / $goal * 100));

        $data = [
            "cardWidget" => $cardWidget,
            "cardWidgetPictureUrl" => $cardWidget->image ? $this->fileManager->getPictureUrl($cardWidget->image) : null,
            "currentAmount" => $cacheData['amount'],
            "donorCount" => $cacheData['donors'],
            "percentage" => $percentage,
            "event" => 1
        ];

        return $this->view->render($response, 'widget/card.html.twig', $data);
    }

    public function widgetEventCardFetch(Request $request, Response $response, array $args): Response
    {
        $eventId = $args['id'] ?? '';
        if (!$eventId) {
            return $this->jsonError($response, 'Event ID manquant ou incorrect.', 400);
        }

        $event = $this->eventRepository->selectByGuid($eventId);
        if (!$event) {
            return $this->jsonError($response, 'Event non trouvé.', 404);
        }

        $cacheData = $this->widgetRepository->selectEventCardWidgetCacheData($event)
            ?? ['amount' => 0, 'donors' => 0, 'streams' => []];

        try {
            $streams = $this->streamRepository->selectListByEvent($event);
            $oldAmount = $cacheData['amount'];
            $cacheData = $this->aggregateEventStreams($streams, $cacheData, true);

            if ($oldAmount !== $cacheData['amount']) {
                $this->widgetRepository->updateEventCardWidgetCacheData($event->guid, $cacheData);
            }

            return $this->jsonResponse($response, [
                'amount' => $cacheData['amount'],
                'donors' => $cacheData['donors']
            ]);
        } catch (Exception $e) {
            return $this->jsonError($response, 'Impossible de récupérer les données.', 500);
        }
    }
}

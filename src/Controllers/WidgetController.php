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

    private function calculatePercentage(int $amountInCents, int $goal): int
    {
        $safeGoal = $goal ?: 1;
        return min(100, (int) round(($amountInCents / 100) / $safeGoal * 100));
    }

    private function requireIdArg(array $args, string $label): string
    {
        $id = $args['id'] ?? '';
        if (!$id) {
            throw new Exception("$label ID manquant ou incorrect.");
        }
        return $id;
    }

    /**
     * Agrège les montants de tous les streams d'un event via le cache par stream.
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

    // ── Stream donation: shared logic ────────────────────────────

    private function fetchStreamDonationData(string $streamGuid): array
    {
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

        if ($cacheData['continuation_token'] !== $result['continuation_token']
            || $cacheData['amount'] !== $result['amount']) {
            $this->widgetRepository->updateStreamDonationWidgetCacheData($charityStream->guid, [
                'amount' => $result['amount'],
                'continuation_token' => $result['continuation_token'],
            ]);
        }

        return ['stream' => $charityStream, 'result' => $result];
    }

    // ── Event donation: shared logic ─────────────────────────────

    private function fetchEventDonationData(string $eventGuid): array
    {
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

        return ['event' => $event, 'cacheData' => $cacheData];
    }

    // ── Stream card: shared logic ────────────────────────────────

    private function fetchStreamCardData(string $streamGuid): array
    {
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

        if ($cacheData['continuation_token'] !== $result['continuation_token']
            || $cacheData['amount'] !== $result['amount']) {
            $this->widgetRepository->updateStreamCardWidgetCacheData($charityStream->guid, [
                'amount' => $result['amount'],
                'donors' => $donors,
                'continuation_token' => $result['continuation_token'],
            ]);
        }

        return ['stream' => $charityStream, 'amount' => $result['amount'], 'donors' => $donors];
    }

    // ── Event card: shared logic ─────────────────────────────────

    private function fetchEventCardData(string $eventGuid): array
    {
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

        return ['event' => $event, 'amount' => $cacheData['amount'], 'donors' => $cacheData['donors']];
    }

    // ── Event Donation ───────────────────────────────────────────

    public function widgetEventDonation(Request $request, Response $response, array $args): Response
    {
        $eventGuid = $this->requireIdArg($args, 'Event');

        $donationGoalWidget = $this->widgetRepository->selectDonationWidgetByGuid(null, $eventGuid);
        if (!$donationGoalWidget) {
            throw new Exception("Aucun widget trouvé pour le Event ID fourni.");
        }

        $data = $this->fetchEventDonationData($eventGuid);

        return $this->view->render($response, 'widget/donation.html.twig', [
            'donationGoalWidget' => $donationGoalWidget,
            'currentAmount' => $data['cacheData']['amount'],
            'goal' => $data['event']->goal,
            'event' => 1,
        ]);
    }

    public function widgetEventDonationFetch(Request $request, Response $response, array $args): Response
    {
        $eventGuid = $args['id'] ?? '';
        if (!$eventGuid) {
            return $this->jsonError($response, 'Event ID manquant ou incorrect.', 400);
        }

        $event = $this->eventRepository->selectByGuid($eventGuid);
        if (!$event) {
            return $this->jsonError($response, 'Event non trouvé.', 404);
        }

        try {
            $data = $this->fetchEventDonationData($eventGuid);
            return $this->jsonResponse($response, ['amount' => $data['cacheData']['amount']]);
        } catch (Exception $e) {
            return $this->jsonError($response, 'Impossible de récupérer le montant', 500);
        }
    }

    // ── Stream Alert ─────────────────────────────────────────────

    public function widgetAlert(Request $request, Response $response, array $args): Response
    {
        $charityStreamId = $this->requireIdArg($args, 'Charity Stream');

        $alertBoxWidget = $this->widgetRepository->selectAlertWidgetByGuid($charityStreamId);
        if (!$alertBoxWidget) {
            throw new Exception("Aucun widget trouvé pour le Charity Stream ID fourni.");
        }

        $charityStream = $this->streamRepository->selectByGuid($charityStreamId);
        if (!$charityStream) {
            throw new Exception("Charity Stream non trouvé.");
        }

        $cacheData = $this->widgetRepository->selectAlertWidgetCacheData($charityStream)
            ?? ['continuation_token' => ''];

        $result = $this->apiWrapper->getAllOrders(
            $charityStream->organization_slug,
            $charityStream->form_slug,
            0,
            $cacheData['continuation_token'],
        );

        if ($cacheData['continuation_token'] !== $result['continuation_token']) {
            $this->widgetRepository->updateAlertWidgetCacheData($charityStream->guid, [
                'continuation_token' => $result['continuation_token'],
            ]);
        }

        return $this->view->render($response, 'widget/alert.html.twig', [
            'alertBoxWidget' => $alertBoxWidget,
            'alertBoxWidgetPictureUrl' => $this->fileManager->getPictureUrl($alertBoxWidget->image),
            'alertBoxWidgetSoundUrl' => $this->fileManager->getSoundUrl($alertBoxWidget->sound),
        ]);
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
                    'continuation_token' => $result['continuation_token'],
                ]);
            }

            return $this->jsonResponse($response, $result);
        } catch (Exception $e) {
            return $this->jsonError($response, 'Impossible de récupérer les commandes.', 500);
        }
    }

    // ── Stream Donation ──────────────────────────────────────────

    public function widgetDonation(Request $request, Response $response, array $args): Response
    {
        $streamGuid = $this->requireIdArg($args, 'Charity Stream');

        $donationGoalWidget = $this->widgetRepository->selectDonationWidgetByGuid($streamGuid, null);
        if (!$donationGoalWidget) {
            throw new Exception("Aucun widget trouvé pour le Charity Stream ID fourni.");
        }

        $data = $this->fetchStreamDonationData($streamGuid);

        return $this->view->render($response, 'widget/donation.html.twig', [
            'donationGoalWidget' => $donationGoalWidget,
            'currentAmount' => $data['result']['amount'],
            'goal' => $data['stream']->goal,
            'stream' => 1,
        ]);
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

        try {
            $data = $this->fetchStreamDonationData($charityStreamId);
            return $this->jsonResponse($response, $data['result']);
        } catch (Exception $e) {
            return $this->jsonError($response, 'Impossible de récupérer les commandes.', 500);
        }
    }

    // ── Widget Card (Stream) ─────────────────────────────────────

    public function widgetStreamCard(Request $request, Response $response, array $args): Response
    {
        $streamGuid = $this->requireIdArg($args, 'Charity Stream');

        $cardWidget = $this->widgetRepository->selectCardWidgetByGuid($streamGuid, null);
        if (!$cardWidget) {
            throw new Exception("Aucun widget card trouvé pour le Charity Stream ID fourni.");
        }

        $data = $this->fetchStreamCardData($streamGuid);

        return $this->view->render($response, 'widget/card.html.twig', [
            'cardWidget' => $cardWidget,
            'cardWidgetPictureUrl' => $cardWidget->image ? $this->fileManager->getPictureUrl($cardWidget->image) : null,
            'currentAmount' => $data['amount'],
            'donorCount' => $data['donors'],
            'percentage' => $this->calculatePercentage($data['amount'], $data['stream']->goal),
            'goal' => $data['stream']->goal ?: 1,
            'stream' => 1,
        ]);
    }

    public function widgetStreamCardFetch(Request $request, Response $response, array $args): Response
    {
        $charityStreamId = $args['id'] ?? '';
        if (!$charityStreamId) {
            return $this->jsonError($response, 'Charity Stream ID manquant ou incorrect.', 400);
        }

        try {
            $data = $this->fetchStreamCardData($charityStreamId);
            return $this->jsonResponse($response, ['amount' => $data['amount'], 'donors' => $data['donors']]);
        } catch (Exception $e) {
            return $this->jsonError($response, 'Impossible de récupérer les données.', 500);
        }
    }

    // ── Widget Card (Event) ──────────────────────────────────────

    public function widgetEventCard(Request $request, Response $response, array $args): Response
    {
        $eventGuid = $this->requireIdArg($args, 'Event');

        $cardWidget = $this->widgetRepository->selectCardWidgetByGuid(null, $eventGuid);
        if (!$cardWidget) {
            throw new Exception("Aucun widget card trouvé pour le Event ID fourni.");
        }

        $data = $this->fetchEventCardData($eventGuid);

        return $this->view->render($response, 'widget/card.html.twig', [
            'cardWidget' => $cardWidget,
            'cardWidgetPictureUrl' => $cardWidget->image ? $this->fileManager->getPictureUrl($cardWidget->image) : null,
            'currentAmount' => $data['amount'],
            'donorCount' => $data['donors'],
            'percentage' => $this->calculatePercentage($data['amount'], $data['event']->goal),
            'goal' => $data['event']->goal ?: 1,
            'event' => 1,
        ]);
    }

    public function widgetEventCardFetch(Request $request, Response $response, array $args): Response
    {
        $eventId = $args['id'] ?? '';
        if (!$eventId) {
            return $this->jsonError($response, 'Event ID manquant ou incorrect.', 400);
        }

        try {
            $data = $this->fetchEventCardData($eventId);
            return $this->jsonResponse($response, ['amount' => $data['amount'], 'donors' => $data['donors']]);
        } catch (Exception $e) {
            return $this->jsonError($response, 'Impossible de récupérer les données.', 500);
        }
    }
}

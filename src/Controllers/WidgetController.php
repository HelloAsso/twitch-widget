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
    private int $cacheTtl;

    public function __construct(
        private Twig $view,
        private ApiWrapper $apiWrapper,
        private FileManager $fileManager,
        private EventRepository $eventRepository,
        private StreamRepository $streamRepository,
        private WidgetRepository $widgetRepository,
    ) {
        $this->cacheTtl = (int) ($_SERVER['WIDGET_CACHE_TTL'] ?? 30);
    }

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

    private function renderWidgetError(Response $response, string $message, Exception $e): Response
    {
        error_log('[Widget] ' . $message . ' : ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        return $this->view->render($response, 'widget/error.html.twig', [
            'message' => $message,
        ]);
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
                $streamCache['continuation_token'],
                $stream->form_type ?? 'Donation'
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

        // Mode test : retourner le montant simulé sans appeler l'API
        if ($charityStream->is_test_mode) {
            return ['stream' => $charityStream, 'result' => [
                'amount' => (int) $charityStream->test_amount,
                'donations' => [],
                'continuation_token' => '',
            ]];
        }

        $cacheData = $this->widgetRepository->selectStreamDonationWidgetCacheData($charityStream)
            ?? ['amount' => 0, 'continuation_token' => ''];

        // Si le cache est encore frais, on retourne les données en cache sans appeler l'API
        if ($this->widgetRepository->isCacheFresh($cacheData, $this->cacheTtl)) {
            return ['stream' => $charityStream, 'result' => [
                'amount' => $cacheData['amount'],
                'donations' => [],
                'continuation_token' => $cacheData['continuation_token'],
            ]];
        }

        $result = $this->apiWrapper->getAllOrders(
            $charityStream->organization_slug,
            $charityStream->form_slug,
            $cacheData['amount'],
            $cacheData['continuation_token'],
            $charityStream->form_type ?? 'Donation',
        );

        if ($cacheData['continuation_token'] !== $result['continuation_token']
            || $cacheData['amount'] !== $result['amount']) {
            $this->widgetRepository->updateStreamDonationWidgetCacheData($charityStream->guid, [
                'amount' => $result['amount'],
                'continuation_token' => $result['continuation_token'],
            ]);
        } else {
            // Même si rien n'a changé, on met à jour le timestamp du cache
            $this->widgetRepository->updateStreamDonationWidgetCacheData($charityStream->guid, [
                'amount' => $cacheData['amount'],
                'continuation_token' => $cacheData['continuation_token'],
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

        // Mode test : retourner le montant simulé sans appeler l'API
        if ($event->is_test_mode) {
            return ['event' => $event, 'cacheData' => [
                'amount' => (int) $event->test_amount,
                'streams' => [],
            ]];
        }

        $cacheData = $this->widgetRepository->selectEventDonationWidgetCacheData($event)
            ?? ['amount' => 0, 'streams' => []];

        // Si le cache est encore frais, on retourne les données en cache sans appeler l'API
        if ($this->widgetRepository->isCacheFresh($cacheData, $this->cacheTtl)) {
            return ['event' => $event, 'cacheData' => $cacheData];
        }

        $streams = $this->streamRepository->selectListByEvent($event);
        $oldAmount = $cacheData['amount'];
        $cacheData = $this->aggregateEventStreams($streams, $cacheData);

        if ($oldAmount !== $cacheData['amount']) {
            $this->widgetRepository->updateEventDonationWidgetCacheData($event->guid, $cacheData);
        } else {
            // Même si rien n'a changé, on met à jour le timestamp du cache
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

        // Mode test : retourner le montant simulé sans appeler l'API
        if ($charityStream->is_test_mode) {
            return [
                'stream' => $charityStream,
                'amount' => (int) $charityStream->test_amount,
                'donors' => 0,
            ];
        }

        $cacheData = $this->widgetRepository->selectStreamCardWidgetCacheData($charityStream)
            ?? ['amount' => 0, 'donors' => 0, 'continuation_token' => ''];

        // Si le cache est encore frais, on retourne les données en cache sans appeler l'API
        if ($this->widgetRepository->isCacheFresh($cacheData, $this->cacheTtl)) {
            return ['stream' => $charityStream, 'amount' => $cacheData['amount'], 'donors' => $cacheData['donors'] ?? 0];
        }

        $result = $this->apiWrapper->getAllOrders(
            $charityStream->organization_slug,
            $charityStream->form_slug,
            $cacheData['amount'],
            $cacheData['continuation_token'],
            $charityStream->form_type ?? 'Donation',
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
        } else {
            // Même si rien n'a changé, on met à jour le timestamp du cache
            $this->widgetRepository->updateStreamCardWidgetCacheData($charityStream->guid, [
                'amount' => $cacheData['amount'],
                'donors' => $donors,
                'continuation_token' => $cacheData['continuation_token'],
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

        // Mode test : retourner le montant simulé sans appeler l'API
        if ($event->is_test_mode) {
            return [
                'event' => $event,
                'amount' => (int) $event->test_amount,
                'donors' => 0,
            ];
        }

        $cacheData = $this->widgetRepository->selectEventCardWidgetCacheData($event)
            ?? ['amount' => 0, 'donors' => 0, 'streams' => []];

        // Si le cache est encore frais, on retourne les données en cache sans appeler l'API
        if ($this->widgetRepository->isCacheFresh($cacheData, $this->cacheTtl)) {
            return ['event' => $event, 'amount' => $cacheData['amount'], 'donors' => $cacheData['donors'] ?? 0];
        }

        $streams = $this->streamRepository->selectListByEvent($event);
        $oldAmount = $cacheData['amount'];
        $cacheData = $this->aggregateEventStreams($streams, $cacheData, true);

        if ($oldAmount !== $cacheData['amount']) {
            $this->widgetRepository->updateEventCardWidgetCacheData($event->guid, $cacheData);
        } else {
            // Même si rien n'a changé, on met à jour le timestamp du cache
            $this->widgetRepository->updateEventCardWidgetCacheData($event->guid, $cacheData);
        }

        return ['event' => $event, 'amount' => $cacheData['amount'], 'donors' => $cacheData['donors']];
    }

    // ── Event Donation ───────────────────────────────────────────

    public function widgetEventDonation(Request $request, Response $response, array $args): Response
    {
        try {
            $eventGuid = $this->requireIdArg($args, 'Event');

            $donationGoalWidget = $this->widgetRepository->selectDonationWidgetByGuid(null, $eventGuid);
            if (!$donationGoalWidget) {
                throw new Exception("Aucun widget trouvé pour le Event ID fourni.");
            }

            try {
                $data = $this->fetchEventDonationData($eventGuid);
                $currentAmount = $data['cacheData']['amount'];
                $event = $data['event'];
            } catch (Exception $e) {
                error_log('[WidgetEventDonation] Erreur API init pour event ' . $eventGuid . ' : ' . $e->getMessage());
                $event = $this->eventRepository->selectByGuid($eventGuid);
                $cacheData = $this->widgetRepository->selectEventDonationWidgetCacheData($event);
                $currentAmount = $cacheData['amount'] ?? 0;
            }

            return $this->view->render($response, 'widget/donation.html.twig', [
                'donationGoalWidget' => $donationGoalWidget,
                'currentAmount' => $currentAmount,
                'goal' => $event->goal,
                'event' => 1,
                'isTestMode' => (bool) $event->is_test_mode,
            ]);
        } catch (Exception $e) {
            return $this->renderWidgetError($response, 'Impossible de charger le widget de don.', $e);
        }
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
        try {
            $charityStreamId = $this->requireIdArg($args, 'Charity Stream');

            $alertBoxWidget = $this->widgetRepository->selectAlertWidgetByGuid($charityStreamId);
            if (!$alertBoxWidget) {
                throw new Exception("Aucun widget trouvé pour le Charity Stream ID fourni.");
            }

            $charityStream = $this->streamRepository->selectByGuid($charityStreamId);
            if (!$charityStream) {
                throw new Exception("Charity Stream non trouvé.");
            }

            // En mode test, on ne fait pas d'appel API pour l'init
            if (!$charityStream->is_test_mode) {
                $cacheData = $this->widgetRepository->selectAlertWidgetCacheData($charityStream)
                    ?? ['continuation_token' => ''];

                if (!$this->widgetRepository->isCacheFresh($cacheData, $this->cacheTtl)) {
                    try {
                        $result = $this->apiWrapper->getAllOrders(
                            $charityStream->organization_slug,
                            $charityStream->form_slug,
                            0,
                            $cacheData['continuation_token'],
                            $charityStream->form_type ?? 'Donation',
                        );

                        if ($cacheData['continuation_token'] !== $result['continuation_token']) {
                            $this->widgetRepository->updateAlertWidgetCacheData($charityStream->guid, [
                                'continuation_token' => $result['continuation_token'],
                            ]);
                        } else {
                            $this->widgetRepository->updateAlertWidgetCacheData($charityStream->guid, [
                                'continuation_token' => $cacheData['continuation_token'],
                            ]);
                        }
                    } catch (Exception $e) {
                        // Token invalide ou erreur API : on rend le widget avec le cache existant
                        // Le polling (fetch) réessaiera automatiquement
                        error_log('[WidgetAlert] Erreur API init pour stream ' . $charityStream->guid . ' : ' . $e->getMessage());
                    }
                }
            }

            return $this->view->render($response, 'widget/alert.html.twig', [
                'alertBoxWidget' => $alertBoxWidget,
                'alertBoxWidgetPictureUrl' => $this->fileManager->getPictureUrl($alertBoxWidget->image),
                'alertBoxWidgetSoundUrl' => $this->fileManager->getSoundUrl($alertBoxWidget->sound),
                'isTestMode' => (bool) $charityStream->is_test_mode,
            ]);
        } catch (Exception $e) {
            return $this->renderWidgetError($response, 'Impossible de charger le widget d\'alerte.', $e);
        }
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

        // En mode test, retourner un résultat vide (les alertes sont simulées via l'endpoint dédié)
        if ($charityStream->is_test_mode) {
            return $this->jsonResponse($response, [
                'amount' => 0,
                'donations' => [],
                'continuation_token' => '',
            ]);
        }

        $cacheData = $this->widgetRepository->selectAlertWidgetCacheData($charityStream)
            ?? ['continuation_token' => ''];

        try {
            // Si le cache est encore frais, on retourne un résultat vide (pas de nouvelles donations)
            if ($this->widgetRepository->isCacheFresh($cacheData, $this->cacheTtl)) {
                return $this->jsonResponse($response, [
                    'amount' => 0,
                    'donations' => [],
                    'continuation_token' => $cacheData['continuation_token'],
                ]);
            }

            $result = $this->apiWrapper->getAllOrders(
                $charityStream->organization_slug,
                $charityStream->form_slug,
                0,
                $cacheData['continuation_token'],
                $charityStream->form_type ?? 'Donation'
            );

            if ($cacheData['continuation_token'] !== $result['continuation_token']) {
                $this->widgetRepository->updateAlertWidgetCacheData($charityStream->guid, [
                    'continuation_token' => $result['continuation_token'],
                ]);
            } else {
                // Même si rien n'a changé, on met à jour le timestamp du cache
                $this->widgetRepository->updateAlertWidgetCacheData($charityStream->guid, [
                    'continuation_token' => $cacheData['continuation_token'],
                ]);
            }

            return $this->jsonResponse($response, $result);
        } catch (Exception $e) {
            $status = $e->getCode() === 401 ? 401 : 500;
            $message = $status === 401
                ? 'Token invalide ou expiré pour ce stream. Reconnectez l\'association.'
                : 'Impossible de récupérer les commandes.';
            error_log('[WidgetAlertFetch] Erreur pour stream ' . $charityStreamId . ' : ' . $e->getMessage());
            return $this->jsonError($response, $message, $status);
        }
    }

    // ── Stream Donation ──────────────────────────────────────────

    public function widgetDonation(Request $request, Response $response, array $args): Response
    {
        try {
            $streamGuid = $this->requireIdArg($args, 'Charity Stream');

            $donationGoalWidget = $this->widgetRepository->selectDonationWidgetByGuid($streamGuid, null);
            if (!$donationGoalWidget) {
                throw new Exception("Aucun widget trouvé pour le Charity Stream ID fourni.");
            }

            try {
                $data = $this->fetchStreamDonationData($streamGuid);
                $currentAmount = $data['result']['amount'];
                $stream = $data['stream'];
            } catch (Exception $e) {
                // Token invalide ou erreur API : on rend le widget avec le cache existant
                error_log('[WidgetDonation] Erreur API init pour stream ' . $streamGuid . ' : ' . $e->getMessage());
                $stream = $this->streamRepository->selectByGuid($streamGuid);
                $cacheData = $this->widgetRepository->selectStreamDonationWidgetCacheData($stream);
                $currentAmount = $cacheData['amount'] ?? 0;
            }

            return $this->view->render($response, 'widget/donation.html.twig', [
                'donationGoalWidget' => $donationGoalWidget,
                'currentAmount' => $currentAmount,
                'goal' => $stream->goal,
                'stream' => 1,
                'isTestMode' => (bool) $stream->is_test_mode,
            ]);
        } catch (Exception $e) {
            return $this->renderWidgetError($response, 'Impossible de charger le widget de don.', $e);
        }
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
            $status = $e->getCode() === 401 ? 401 : 500;
            $message = $status === 401
                ? 'Token invalide ou expiré pour ce stream. Reconnectez l\'association.'
                : 'Impossible de récupérer les commandes.';
            error_log('[WidgetDonationFetch] Erreur pour stream ' . $charityStreamId . ' : ' . $e->getMessage());
            return $this->jsonError($response, $message, $status);
        }
    }

    // ── Widget Card (Stream) ─────────────────────────────────────

    public function widgetStreamCard(Request $request, Response $response, array $args): Response
    {
        try {
            $streamGuid = $this->requireIdArg($args, 'Charity Stream');

            $cardWidget = $this->widgetRepository->selectCardWidgetByGuid($streamGuid, null);
            if (!$cardWidget) {
                throw new Exception("Aucun widget card trouvé pour le Charity Stream ID fourni.");
            }

            try {
                $data = $this->fetchStreamCardData($streamGuid);
                $currentAmount = $data['amount'];
                $donors = $data['donors'];
                $stream = $data['stream'];
            } catch (Exception $e) {
                // Token invalide ou erreur API : on rend le widget avec le cache existant
                error_log('[WidgetCard] Erreur API init pour stream ' . $streamGuid . ' : ' . $e->getMessage());
                $stream = $this->streamRepository->selectByGuid($streamGuid);
                $cacheData = $this->widgetRepository->selectStreamCardWidgetCacheData($stream);
                $currentAmount = $cacheData['amount'] ?? 0;
                $donors = $cacheData['donors'] ?? 0;
            }

            $formTypeUrlSegment = ($stream->form_type === 'CrowdFunding') ? 'collectes' : 'formulaires';
            $donationUrl = ($_SERVER['HA_URL'] ?? 'https://www.helloasso.com')
                . '/associations/' . $stream->organization_slug
                . '/' . $formTypeUrlSegment . '/' . $stream->form_slug;

            return $this->view->render($response, 'widget/card.html.twig', [
                'cardWidget' => $cardWidget,
                'cardWidgetPictureUrl' => $cardWidget->image ? $this->fileManager->getPictureUrl($cardWidget->image) : null,
                'currentAmount' => $currentAmount,
                'donorCount' => $donors,
                'percentage' => $this->calculatePercentage($currentAmount, $stream->goal),
                'goal' => $stream->goal ?: 1,
                'stream' => 1,
                'isTestMode' => (bool) $stream->is_test_mode,
                'donationUrl' => $donationUrl,
            ]);
        } catch (Exception $e) {
            return $this->renderWidgetError($response, 'Impossible de charger le widget carte.', $e);
        }
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
            $status = $e->getCode() === 401 ? 401 : 500;
            $message = $status === 401
                ? 'Token invalide ou expiré pour ce stream. Reconnectez l\'association.'
                : 'Impossible de récupérer les données.';
            error_log('[WidgetCardFetch] Erreur pour stream ' . $charityStreamId . ' : ' . $e->getMessage());
            return $this->jsonError($response, $message, $status);
        }
    }

    // ── Widget Card (Event) ──────────────────────────────────────

    public function widgetEventCard(Request $request, Response $response, array $args): Response
    {
        try {
            $eventGuid = $this->requireIdArg($args, 'Event');

            $cardWidget = $this->widgetRepository->selectCardWidgetByGuid(null, $eventGuid);
            if (!$cardWidget) {
                throw new Exception("Aucun widget card trouvé pour le Event ID fourni.");
            }

            try {
                $data = $this->fetchEventCardData($eventGuid);
                $currentAmount = $data['amount'];
                $donors = $data['donors'];
                $event = $data['event'];
            } catch (Exception $e) {
                error_log('[WidgetEventCard] Erreur API init pour event ' . $eventGuid . ' : ' . $e->getMessage());
                $event = $this->eventRepository->selectByGuid($eventGuid);
                $cacheData = $this->widgetRepository->selectEventCardWidgetCacheData($event);
                $currentAmount = $cacheData['amount'] ?? 0;
                $donors = $cacheData['donors'] ?? 0;
            }

            return $this->view->render($response, 'widget/card.html.twig', [
                'cardWidget' => $cardWidget,
                'cardWidgetPictureUrl' => $cardWidget->image ? $this->fileManager->getPictureUrl($cardWidget->image) : null,
                'currentAmount' => $currentAmount,
                'donorCount' => $donors,
                'percentage' => $this->calculatePercentage($currentAmount, $event->goal),
                'goal' => $event->goal ?: 1,
                'event' => 1,
                'isTestMode' => (bool) $event->is_test_mode,
            ]);
        } catch (Exception $e) {
            return $this->renderWidgetError($response, 'Impossible de charger le widget carte.', $e);
        }
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

    // ── Test mode: simulate donation ─────────────────────────────

    public function simulateStreamDonation(Request $request, Response $response, array $args): Response
    {
        $streamGuid = $args['id'] ?? '';
        if (!$streamGuid) {
            return $this->jsonError($response, 'Stream ID manquant.', 400);
        }

        $charityStream = $this->streamRepository->selectByGuid($streamGuid);
        if (!$charityStream) {
            return $this->jsonError($response, 'Stream non trouvé.', 404);
        }

        if (!$charityStream->is_test_mode) {
            return $this->jsonError($response, 'Le stream n\'est pas en mode test.', 400);
        }

        $body = $request->getParsedBody();
        if (empty($body)) {
            $body = json_decode((string) $request->getBody(), true) ?? [];
        }
        $amount = (int) ($body['amount'] ?? 1000);
        $pseudo = $body['pseudo'] ?? 'Testeur';
        $message = $body['message'] ?? 'Don de test';

        $newAmount = (int) $charityStream->test_amount + $amount;
        $this->streamRepository->update($charityStream, ['test_amount' => $newAmount]);

        return $this->jsonResponse($response, [
            'amount' => $newAmount,
            'donation' => [
                'pseudo' => $pseudo,
                'message' => $message,
                'amount' => $amount,
            ],
        ]);
    }

    public function simulateEventDonation(Request $request, Response $response, array $args): Response
    {
        $eventGuid = $args['id'] ?? '';
        if (!$eventGuid) {
            return $this->jsonError($response, 'Event ID manquant.', 400);
        }

        $event = $this->eventRepository->selectByGuid($eventGuid);
        if (!$event) {
            return $this->jsonError($response, 'Event non trouvé.', 404);
        }

        if (!$event->is_test_mode) {
            return $this->jsonError($response, 'L\'événement n\'est pas en mode test.', 400);
        }

        $body = $request->getParsedBody();
        if (empty($body)) {
            $body = json_decode((string) $request->getBody(), true) ?? [];
        }
        $amount = (int) ($body['amount'] ?? 1000);
        $pseudo = $body['pseudo'] ?? 'Testeur';
        $message = $body['message'] ?? 'Don de test';

        $newAmount = (int) $event->test_amount + $amount;
        $this->eventRepository->update($event, ['test_amount' => $newAmount]);

        return $this->jsonResponse($response, [
            'amount' => $newAmount,
            'donation' => [
                'pseudo' => $pseudo,
                'message' => $message,
                'amount' => $amount,
            ],
        ]);
    }
}

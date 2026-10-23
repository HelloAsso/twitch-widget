<?php

namespace App\Controllers;

use App\Models\Stream;
use App\Models\WidgetCard;
use App\Repositories\EventRepository;
use App\Repositories\FileManager;
use App\Repositories\GoalRepository;
use App\Repositories\StreamDonationHistoryRepository;
use App\Repositories\StreamRepository;
use App\Repositories\WidgetRepository;
use App\Services\ApiWrapper;
use Exception;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class WidgetController
{
    use RespondsWithJson;

    private int $cacheTtl;

    public function __construct(
        private Twig $view,
        private ApiWrapper $apiWrapper,
        private FileManager $fileManager,
        private EventRepository $eventRepository,
        private StreamRepository $streamRepository,
        private WidgetRepository $widgetRepository,
        private GoalRepository $goalRepository,
        private StreamDonationHistoryRepository $donationHistoryRepository,
    ) {
        $this->cacheTtl = (int) ($_SERVER['WIDGET_CACHE_TTL'] ?? 30);
    }

    private function resolveActiveGoal(array $goals, int $amountInCents): int
    {
        if (empty($goals)) {
            return 1;
        }
        $amountInEuros = $amountInCents / 100;
        foreach ($goals as $goal) {
            if ($goal > $amountInEuros) {
                return $goal;
            }
        }
        return end($goals);
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function calculatePercentage(int $amountInCents, int $goal): int
    {
        $safeGoal = $goal ?: 1;
        return min(100, (int) round(($amountInCents / 100) / $safeGoal * 100));
    }

    /**
     * Ajoute le montant / nombre de donateurs de départ configurés sur la carte.
     */
    private function withCardStartOffset(?WidgetCard $card, int $amount, int $donors): array
    {
        if (!$card) {
            return ['amount' => $amount, 'donors' => $donors];
        }

        return [
            'amount' => $amount + max(0, (int) $card->start_amount),
            'donors' => $donors + max(0, (int) $card->start_donors),
        ];
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
     * Cumule le montant encaissé d'un stream (paiements autorisés, mensualités comprises) à partir de son cache.
     * Le montant ne vient pas des commandes : celui d'une commande ne compte que sa première échéance.
     *
     * @param array $cache Cache du stream, avec `amount` et `payments_continuation_token`
     * @return array{amount: int, payments_continuation_token: ?string}
     */
    private function fetchStreamPaymentsAmount(Stream $stream, array $cache): array
    {
        // Cache d'avant le passage aux paiements : son montant venait des commandes, on repart de zéro
        if (!array_key_exists('payments_continuation_token', $cache)) {
            $cache['amount'] = 0;
            $cache['payments_continuation_token'] = null;
        }

        $result = $this->apiWrapper->getAllPaymentsAmount(
            $stream->organization_slug,
            $stream->form_slug,
            (int) $cache['amount'],
            $cache['payments_continuation_token'],
            $stream->form_type ?? 'Donation',
            $stream->getStartDateTime()
        );

        return ['amount' => $result['amount'], 'payments_continuation_token' => $result['continuation_token']];
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
                $defaultCache = ['id' => $stream->id, 'amount' => 0, 'payments_continuation_token' => null];
                if ($trackDonors) {
                    $defaultCache['donors'] = 0;
                    $defaultCache['continuation_token'] = null;
                }
                $streamCache = $defaultCache;
                $cacheData['streams'][] = &$streamCache;
            }

            $payments = $this->fetchStreamPaymentsAmount($stream, $streamCache);
            $streamCache['amount'] = $payments['amount'];
            $streamCache['payments_continuation_token'] = $payments['payments_continuation_token'];

            if ($trackDonors) {
                // Les donateurs restent comptés à partir des commandes
                $result = $this->apiWrapper->getAllOrders(
                    $stream->organization_slug,
                    $stream->form_slug,
                    0,
                    $streamCache['continuation_token'] ?? null,
                    $stream->form_type ?? 'Donation',
                    $stream->getStartDateTime()
                );

                $streamCache['continuation_token'] = $result['continuation_token'];
                $streamCache['donors'] = ($streamCache['donors'] ?? 0) + count($result['donations'] ?? []);
                $totalDonors += $streamCache['donors'];
            }

            $totalAmount += $streamCache['amount'];
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
            ?? ['amount' => 0, 'payments_continuation_token' => null];

        // Si le cache est encore frais, on retourne les données en cache sans appeler l'API
        if ($this->widgetRepository->isCacheFresh($cacheData, $this->cacheTtl)) {
            return ['stream' => $charityStream, 'result' => [
                'amount' => $cacheData['amount'],
                'donations' => [],
                'continuation_token' => $cacheData['payments_continuation_token'] ?? '',
            ]];
        }

        $payments = $this->fetchStreamPaymentsAmount($charityStream, $cacheData);

        // Met aussi à jour le timestamp du cache quand rien n'a changé
        $this->widgetRepository->updateStreamDonationWidgetCacheData($charityStream->guid, $payments);

        return ['stream' => $charityStream, 'result' => [
            'amount' => $payments['amount'],
            'donations' => [],
            'continuation_token' => $payments['payments_continuation_token'],
        ]];
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
            ?? ['amount' => 0, 'donors' => 0, 'continuation_token' => '', 'payments_continuation_token' => null];

        // Si le cache est encore frais, on retourne les données en cache sans appeler l'API
        if ($this->widgetRepository->isCacheFresh($cacheData, $this->cacheTtl)) {
            return ['stream' => $charityStream, 'amount' => $cacheData['amount'], 'donors' => $cacheData['donors'] ?? 0];
        }

        $payments = $this->fetchStreamPaymentsAmount($charityStream, $cacheData);

        // Les donateurs restent comptés à partir des commandes
        $result = $this->apiWrapper->getAllOrders(
            $charityStream->organization_slug,
            $charityStream->form_slug,
            0,
            $cacheData['continuation_token'],
            $charityStream->form_type ?? 'Donation',
            $charityStream->getStartDateTime(),
        );

        $newDonors = count($result['donations'] ?? []);
        $donors = ($cacheData['donors'] ?? 0) + $newDonors;

        // Met aussi à jour le timestamp du cache quand rien n'a changé
        $this->widgetRepository->updateStreamCardWidgetCacheData($charityStream->guid, [
            'amount' => $payments['amount'],
            'donors' => $donors,
            'continuation_token' => $result['continuation_token'],
            'payments_continuation_token' => $payments['payments_continuation_token'],
        ]);

        return ['stream' => $charityStream, 'amount' => $payments['amount'], 'donors' => $donors];
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

            $goals = $this->goalRepository->selectAmountsByEventGuid($eventGuid);
            $goal = $this->resolveActiveGoal($goals, $currentAmount);

            return $this->view->render($response, 'widget/donation.html.twig', [
                'donationGoalWidget' => $donationGoalWidget,
                'currentAmount' => $currentAmount,
                'goal' => $goal,
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
            $goals = $this->goalRepository->selectAmountsByEventGuid($eventGuid);
            $goal = $this->resolveActiveGoal($goals, $data['cacheData']['amount']);
            $allGoalsReached = !empty($goals) && ($data['cacheData']['amount'] / 100) >= end($goals);
            return $this->jsonResponse($response, ['amount' => $data['cacheData']['amount'], 'goal' => $goal, 'allGoalsReached' => $allGoalsReached]);
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
                            $charityStream->getStartDateTime(),
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
                $charityStream->form_type ?? 'Donation',
                $charityStream->getStartDateTime()
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

            $goals = $this->goalRepository->selectAmountsByStreamGuid($streamGuid);
            $goal = $this->resolveActiveGoal($goals, $currentAmount);

            return $this->view->render($response, 'widget/donation.html.twig', [
                'donationGoalWidget' => $donationGoalWidget,
                'currentAmount' => $currentAmount,
                'goal' => $goal,
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
            $goals = $this->goalRepository->selectAmountsByStreamGuid($charityStreamId);
            $goal = $this->resolveActiveGoal($goals, $data['result']['amount']);
            $allGoalsReached = !empty($goals) && ($data['result']['amount'] / 100) >= end($goals);
            return $this->jsonResponse($response, array_merge($data['result'], ['goal' => $goal, 'allGoalsReached' => $allGoalsReached]));
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

            $offset = $this->withCardStartOffset($cardWidget, $currentAmount, $donors);
            $currentAmount = $offset['amount'];
            $donors = $offset['donors'];

            $formTypeUrlSegment = ($stream->form_type === 'CrowdFunding') ? 'collectes' : 'formulaires';
            $donationUrl = ($_SERVER['HA_URL'] ?? 'https://www.helloasso.com')
                . '/associations/' . $stream->organization_slug
                . '/' . $formTypeUrlSegment . '/' . $stream->form_slug;

            $goals = $this->goalRepository->selectAmountsByStreamGuid($streamGuid);
            $goal = $this->resolveActiveGoal($goals, $currentAmount);

            return $this->view->render($response, 'widget/card.html.twig', [
                'cardWidget' => $cardWidget,
                'cardWidgetPictureUrl' => $cardWidget->image ? $this->fileManager->getPictureUrl($cardWidget->image) : null,
                'currentAmount' => $currentAmount,
                'donorCount' => $donors,
                'percentage' => $this->calculatePercentage($currentAmount, $goal),
                'goal' => $goal,
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
            $cardWidget = $this->widgetRepository->selectCardWidgetByGuid($charityStreamId, null);
            $offset = $this->withCardStartOffset($cardWidget, $data['amount'], $data['donors']);
            $goals = $this->goalRepository->selectAmountsByStreamGuid($charityStreamId);
            $goal = $this->resolveActiveGoal($goals, $offset['amount']);
            $allGoalsReached = !empty($goals) && ($offset['amount'] / 100) >= end($goals);
            return $this->jsonResponse($response, ['amount' => $offset['amount'], 'donors' => $offset['donors'], 'goal' => $goal, 'allGoalsReached' => $allGoalsReached]);
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

            $offset = $this->withCardStartOffset($cardWidget, $currentAmount, $donors);
            $currentAmount = $offset['amount'];
            $donors = $offset['donors'];

            $goals = $this->goalRepository->selectAmountsByEventGuid($eventGuid);
            $goal = $this->resolveActiveGoal($goals, $currentAmount);

            return $this->view->render($response, 'widget/card.html.twig', [
                'cardWidget' => $cardWidget,
                'cardWidgetPictureUrl' => $cardWidget->image ? $this->fileManager->getPictureUrl($cardWidget->image) : null,
                'currentAmount' => $currentAmount,
                'donorCount' => $donors,
                'percentage' => $this->calculatePercentage($currentAmount, $goal),
                'goal' => $goal,
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
            $cardWidget = $this->widgetRepository->selectCardWidgetByGuid(null, $eventId);
            $offset = $this->withCardStartOffset($cardWidget, $data['amount'], $data['donors']);
            $goals = $this->goalRepository->selectAmountsByEventGuid($eventId);
            $goal = $this->resolveActiveGoal($goals, $offset['amount']);
            $allGoalsReached = !empty($goals) && ($offset['amount'] / 100) >= end($goals);
            return $this->jsonResponse($response, ['amount' => $offset['amount'], 'donors' => $offset['donors'], 'goal' => $goal, 'allGoalsReached' => $allGoalsReached]);
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

        // Si un live est en cours, le don simulé alimente aussi la vue "Live"
        if ($charityStream->live_started_at !== null) {
            $this->donationHistoryRepository->insertMany($charityStream->guid, [[
                'pseudo' => $pseudo,
                'message' => $message,
                'amount' => $amount,
            ]]);
        }

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

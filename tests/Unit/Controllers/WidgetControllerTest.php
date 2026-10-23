<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\WidgetController;
use App\Models\Event;
use App\Models\Stream;
use App\Models\WidgetAlert;
use App\Models\WidgetCard;
use App\Models\WidgetDonation;
use App\Repositories\EventRepository;
use App\Repositories\FileManager;
use App\Repositories\GoalRepository;
use App\Repositories\StreamDonationHistoryRepository;
use App\Repositories\StreamRepository;
use App\Repositories\WidgetRepository;
use App\Services\ApiWrapper;
use Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;

/**
 * Tests d'affichage des widgets (alert et donation) et de leurs endpoints JSON.
 */
class WidgetControllerTest extends TestCase
{
    private Twig&MockObject $view;
    private ApiWrapper&MockObject $apiWrapper;
    private FileManager&MockObject $fileManager;
    private EventRepository&MockObject $eventRepository;
    private StreamRepository&MockObject $streamRepository;
    private WidgetRepository&MockObject $widgetRepository;
    private GoalRepository&MockObject $goalRepository;
    private StreamDonationHistoryRepository&MockObject $donationHistoryRepository;
    private WidgetController $controller;

    protected function setUp(): void
    {
        $this->view = $this->createMock(Twig::class);
        $this->apiWrapper = $this->createMock(ApiWrapper::class);
        $this->fileManager = $this->createMock(FileManager::class);
        $this->eventRepository = $this->createMock(EventRepository::class);
        $this->streamRepository = $this->createMock(StreamRepository::class);
        $this->widgetRepository = $this->createMock(WidgetRepository::class);
        $this->goalRepository = $this->createMock(GoalRepository::class);
        $this->donationHistoryRepository = $this->createMock(StreamDonationHistoryRepository::class);

        $this->goalRepository->method('selectAmountsByStreamGuid')->willReturn([1000]);
        $this->goalRepository->method('selectAmountsByEventGuid')->willReturn([1000]);

        $this->controller = new WidgetController(
            $this->view,
            $this->apiWrapper,
            $this->fileManager,
            $this->eventRepository,
            $this->streamRepository,
            $this->widgetRepository,
            $this->goalRepository,
            $this->donationHistoryRepository,
        );
    }

    // =====================================================================
    // Widget Alert — rendu (affichage)
    // =====================================================================

    public function testWidgetAlertRendersWithoutError(): void
    {
        $streamGuid = 'alert-stream-guid';
        $stream = $this->buildStream($streamGuid);
        $alertWidget = $this->buildAlertWidget($streamGuid);

        $this->widgetRepository->method('selectAlertWidgetByGuid')->willReturn($alertWidget);
        $this->streamRepository->method('selectByGuid')->willReturn($stream);
        $this->widgetRepository->method('selectAlertWidgetCacheData')->willReturn(null);
        $this->apiWrapper->method('getAllOrders')->willReturn([
            'amount' => 150,
            'donations' => [],
            'continuation_token' => 'token123',
        ]);
        $this->fileManager->method('getPictureUrl')->willReturn('https://cdn.example.com/img.png');
        $this->fileManager->method('getSoundUrl')->willReturn('https://cdn.example.com/sound.mp3');

        $capturedTemplate = null;
        $capturedData = null;
        $this->view->method('render')
            ->willReturnCallback(
                function (ResponseInterface $response, string $template, array $data) use (&$capturedTemplate, &$capturedData) {
                    $capturedTemplate = $template;
                    $capturedData = $data;
                    $response->getBody()->write('<html>alert widget</html>');
                    return $response;
                }
            );

        $request = (new ServerRequestFactory())->createServerRequest('GET', "/widget-stream-alert/{$streamGuid}");
        $result = $this->controller->widgetAlert($request, new Response(), ['id' => $streamGuid]);

        $this->assertEquals(200, $result->getStatusCode());
        $this->assertEquals('widget/alert.html.twig', $capturedTemplate);
        $this->assertArrayHasKey('alertBoxWidget', $capturedData);
        $this->assertArrayHasKey('alertBoxWidgetPictureUrl', $capturedData);
        $this->assertArrayHasKey('alertBoxWidgetSoundUrl', $capturedData);
    }

    public function testWidgetAlertRendersErrorWhenNoIdProvided(): void
    {
        $capturedTemplate = null;
        $this->view->method('render')
            ->willReturnCallback(
                function (ResponseInterface $response, string $template, array $data) use (&$capturedTemplate) {
                    $capturedTemplate = $template;
                    $response->getBody()->write('<html>error</html>');
                    return $response;
                }
            );

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/widget-stream-alert/');
        $this->controller->widgetAlert($request, new Response(), ['id' => '']);

        $this->assertEquals('widget/error.html.twig', $capturedTemplate);
    }

    public function testWidgetAlertRendersErrorWhenWidgetNotFound(): void
    {
        $this->widgetRepository->method('selectAlertWidgetByGuid')->willReturn(null);

        $capturedTemplate = null;
        $this->view->method('render')
            ->willReturnCallback(
                function (ResponseInterface $response, string $template, array $data) use (&$capturedTemplate) {
                    $capturedTemplate = $template;
                    $response->getBody()->write('<html>error</html>');
                    return $response;
                }
            );

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/widget-stream-alert/unknown');
        $this->controller->widgetAlert($request, new Response(), ['id' => 'unknown-guid']);

        $this->assertEquals('widget/error.html.twig', $capturedTemplate);
    }

    public function testWidgetAlertRendersErrorWhenStreamNotFound(): void
    {
        $this->widgetRepository->method('selectAlertWidgetByGuid')->willReturn($this->buildAlertWidget('g'));
        $this->streamRepository->method('selectByGuid')->willReturn(null);

        $capturedTemplate = null;
        $this->view->method('render')
            ->willReturnCallback(
                function (ResponseInterface $response, string $template, array $data) use (&$capturedTemplate) {
                    $capturedTemplate = $template;
                    $response->getBody()->write('<html>error</html>');
                    return $response;
                }
            );

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/widget-stream-alert/g');
        $this->controller->widgetAlert($request, new Response(), ['id' => 'g']);

        $this->assertEquals('widget/error.html.twig', $capturedTemplate);
    }

    // =====================================================================
    // Widget Alert — endpoint JSON (fetch)
    // =====================================================================

    public function testWidgetAlertFetchReturnsJsonOnSuccess(): void
    {
        $streamGuid = 'fetch-stream-guid';
        $stream = $this->buildStream($streamGuid);
        $ordersResult = [
            'amount' => 0,
            'donations' => [['pseudo' => 'Alice', 'message' => 'Bravo!', 'amount' => 500]],
            'continuation_token' => 'newToken123',
        ];

        $this->streamRepository->method('selectByGuid')->willReturn($stream);
        $this->widgetRepository->method('selectAlertWidgetCacheData')->willReturn(['continuation_token' => '']);
        $this->apiWrapper->method('getAllOrders')->willReturn($ordersResult);
        $this->widgetRepository->method('updateAlertWidgetCacheData');

        $request = (new ServerRequestFactory())->createServerRequest('GET', "/widget-stream-alert/{$streamGuid}/fetch");
        $result = $this->controller->widgetAlertFetch($request, new Response(), ['id' => $streamGuid]);

        $this->assertEquals(200, $result->getStatusCode());
        $this->assertStringContainsString('application/json', $result->getHeaderLine('Content-Type'));

        $body = json_decode((string) $result->getBody(), true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('donations', $body);
    }

    public function testWidgetAlertFetchReturns400WhenIdMissing(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/widget-stream-alert//fetch');
        $result = $this->controller->widgetAlertFetch($request, new Response(), ['id' => '']);

        $this->assertEquals(400, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    public function testWidgetAlertFetchReturns404WhenStreamNotFound(): void
    {
        $this->streamRepository->method('selectByGuid')->willReturn(null);

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/widget-stream-alert/ghost/fetch');
        $result = $this->controller->widgetAlertFetch($request, new Response(), ['id' => 'ghost-guid']);

        $this->assertEquals(404, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    public function testWidgetAlertFetchReturns500OnApiException(): void
    {
        $stream = $this->buildStream('error-stream');
        $this->streamRepository->method('selectByGuid')->willReturn($stream);
        $this->widgetRepository->method('selectAlertWidgetCacheData')->willReturn(null);
        $this->apiWrapper->method('getAllOrders')->willThrowException(new Exception('API error'));

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/widget-stream-alert/error-stream/fetch');
        $result = $this->controller->widgetAlertFetch($request, new Response(), ['id' => 'error-stream']);

        $this->assertEquals(500, $result->getStatusCode());
    }

    // =====================================================================
    // Widget Donation (stream) — rendu (affichage)
    // =====================================================================

    public function testWidgetDonationRendersWithoutError(): void
    {
        $streamGuid = 'donation-stream-guid';
        $stream = $this->buildStream($streamGuid);
        $donationWidget = $this->buildDonationWidget($streamGuid, null);

        $this->widgetRepository->method('selectDonationWidgetByGuid')->willReturn($donationWidget);
        $this->streamRepository->method('selectByGuid')->willReturn($stream);
        $this->widgetRepository->method('selectStreamDonationWidgetCacheData')->willReturn(null);
        $this->apiWrapper->method('getAllPaymentsAmount')->willReturn([
            'amount' => 3000,
            'continuation_token' => null,
        ]);

        $capturedTemplate = null;
        $capturedData = null;
        $this->view->method('render')
            ->willReturnCallback(
                function (ResponseInterface $response, string $template, array $data) use (&$capturedTemplate, &$capturedData) {
                    $capturedTemplate = $template;
                    $capturedData = $data;
                    $response->getBody()->write('<html>donation widget</html>');
                    return $response;
                }
            );

        $request = (new ServerRequestFactory())->createServerRequest('GET', "/widget-stream-donation/{$streamGuid}");
        $result = $this->controller->widgetDonation($request, new Response(), ['id' => $streamGuid]);

        $this->assertEquals(200, $result->getStatusCode());
        $this->assertEquals('widget/donation.html.twig', $capturedTemplate);
        $this->assertEquals(3000, $capturedData['currentAmount']);
        $this->assertArrayHasKey('donationGoalWidget', $capturedData);
    }

    public function testWidgetDonationRendersErrorWhenNoIdProvided(): void
    {
        $capturedTemplate = null;
        $this->view->method('render')
            ->willReturnCallback(
                function (ResponseInterface $response, string $template, array $data) use (&$capturedTemplate) {
                    $capturedTemplate = $template;
                    $response->getBody()->write('<html>error</html>');
                    return $response;
                }
            );

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/widget-stream-donation/');
        $this->controller->widgetDonation($request, new Response(), ['id' => '']);

        $this->assertEquals('widget/error.html.twig', $capturedTemplate);
    }

    public function testWidgetDonationRendersErrorWhenWidgetNotFound(): void
    {
        $this->widgetRepository->method('selectDonationWidgetByGuid')->willReturn(null);

        $capturedTemplate = null;
        $this->view->method('render')
            ->willReturnCallback(
                function (ResponseInterface $response, string $template, array $data) use (&$capturedTemplate) {
                    $capturedTemplate = $template;
                    $response->getBody()->write('<html>error</html>');
                    return $response;
                }
            );

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/widget-stream-donation/x');
        $this->controller->widgetDonation($request, new Response(), ['id' => 'x']);

        $this->assertEquals('widget/error.html.twig', $capturedTemplate);
    }

    // =====================================================================
    // Widget Donation (stream) — endpoint JSON (fetch)
    // =====================================================================

    public function testWidgetDonationFetchReturnsJsonOnSuccess(): void
    {
        $streamGuid = 'fetch-donation-guid';
        $stream = $this->buildStream($streamGuid);

        $this->streamRepository->method('selectByGuid')->willReturn($stream);
        $this->widgetRepository->method('selectStreamDonationWidgetCacheData')
            ->willReturn(['amount' => 1000, 'payments_continuation_token' => 'prev_token']);
        $this->apiWrapper->method('getAllPaymentsAmount')->willReturn([
            'amount' => 1500,
            'continuation_token' => 'new_token',
        ]);
        $this->widgetRepository->method('updateStreamDonationWidgetCacheData');

        $request = (new ServerRequestFactory())->createServerRequest('GET', "/widget-stream-donation/{$streamGuid}/fetch");
        $result = $this->controller->widgetDonationFetch($request, new Response(), ['id' => $streamGuid]);

        $this->assertEquals(200, $result->getStatusCode());
        $this->assertStringContainsString('application/json', $result->getHeaderLine('Content-Type'));

        $body = json_decode((string) $result->getBody(), true);
        $this->assertEquals(1500, $body['amount']);
    }

    public function testWidgetDonationFetchPassesStartDateToApi(): void
    {
        $streamGuid = 'start-date-guid';
        $stream = $this->buildStream($streamGuid);
        $stream->start_date = '2026-10-21 20:00:00';

        $this->streamRepository->method('selectByGuid')->willReturn($stream);
        $this->widgetRepository->method('selectStreamDonationWidgetCacheData')->willReturn(null);
        $this->apiWrapper->expects($this->once())
            ->method('getAllPaymentsAmount')
            ->with(
                'test-org',
                'test-form',
                0,
                null,
                'Donation',
                $this->callback(fn($from) => $from instanceof \DateTimeImmutable
                    && $from->format('Y-m-d H:i:s T') === '2026-10-21 20:00:00 CEST')
            )
            ->willReturn(['amount' => 500, 'continuation_token' => 'tk']);

        $request = (new ServerRequestFactory())->createServerRequest('GET', "/widget-stream-donation/{$streamGuid}/fetch");
        $result = $this->controller->widgetDonationFetch($request, new Response(), ['id' => $streamGuid]);

        $this->assertEquals(200, $result->getStatusCode());
    }

    public function testWidgetDonationFetchWithoutStartDateTakesAllPayments(): void
    {
        $streamGuid = 'no-start-date-guid';
        $stream = $this->buildStream($streamGuid);

        $this->streamRepository->method('selectByGuid')->willReturn($stream);
        $this->widgetRepository->method('selectStreamDonationWidgetCacheData')->willReturn(null);
        $this->apiWrapper->expects($this->once())
            ->method('getAllPaymentsAmount')
            ->with('test-org', 'test-form', 0, null, 'Donation', null)
            ->willReturn(['amount' => 500, 'continuation_token' => 'tk']);

        $request = (new ServerRequestFactory())->createServerRequest('GET', "/widget-stream-donation/{$streamGuid}/fetch");
        $result = $this->controller->widgetDonationFetch($request, new Response(), ['id' => $streamGuid]);

        $this->assertEquals(200, $result->getStatusCode());
    }

    public function testWidgetDonationFetchReturns400WhenIdMissing(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/widget-stream-donation//fetch');
        $result = $this->controller->widgetDonationFetch($request, new Response(), ['id' => '']);

        $this->assertEquals(400, $result->getStatusCode());
    }

    public function testWidgetDonationFetchReturns404WhenStreamNotFound(): void
    {
        $this->streamRepository->method('selectByGuid')->willReturn(null);

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/widget-stream-donation/ghost/fetch');
        $result = $this->controller->widgetDonationFetch($request, new Response(), ['id' => 'ghost-guid']);

        $this->assertEquals(404, $result->getStatusCode());
    }

    public function testWidgetDonationFetchUpdatesCacheWhenAmountChanged(): void
    {
        $streamGuid = 'cache-update-guid';
        $stream = $this->buildStream($streamGuid);

        $this->streamRepository->method('selectByGuid')->willReturn($stream);
        $this->widgetRepository->method('selectStreamDonationWidgetCacheData')
            ->willReturn(['amount' => 500, 'payments_continuation_token' => null]);
        $this->apiWrapper->method('getAllPaymentsAmount')->willReturn([
            'amount' => 800,
            'continuation_token' => 'ct_new',
        ]);

        // Vérifie que le cache est mis à jour
        $this->widgetRepository->expects($this->once())
            ->method('updateStreamDonationWidgetCacheData')
            ->with($streamGuid, ['amount' => 800, 'payments_continuation_token' => 'ct_new']);

        $request = (new ServerRequestFactory())->createServerRequest('GET', "/widget-stream-donation/{$streamGuid}/fetch");
        $this->controller->widgetDonationFetch($request, new Response(), ['id' => $streamGuid]);
    }

    public function testWidgetDonationFetchResumesFromPaymentsContinuationToken(): void
    {
        $streamGuid = 'resume-payments-guid';
        $stream = $this->buildStream($streamGuid);

        $this->streamRepository->method('selectByGuid')->willReturn($stream);
        $this->widgetRepository->method('selectStreamDonationWidgetCacheData')
            ->willReturn(['amount' => 1000, 'payments_continuation_token' => 'prev_token']);
        $this->apiWrapper->expects($this->once())
            ->method('getAllPaymentsAmount')
            ->with('test-org', 'test-form', 1000, 'prev_token', 'Donation', null)
            ->willReturn(['amount' => 1500, 'continuation_token' => 'new_token']);
        $this->apiWrapper->expects($this->never())->method('getAllOrders');

        $request = (new ServerRequestFactory())->createServerRequest('GET', "/widget-stream-donation/{$streamGuid}/fetch");
        $this->controller->widgetDonationFetch($request, new Response(), ['id' => $streamGuid]);
    }

    public function testWidgetDonationFetchRestartsAmountFromZeroWhenCacheComesFromOrders(): void
    {
        $streamGuid = 'legacy-cache-guid';
        $stream = $this->buildStream($streamGuid);

        $this->streamRepository->method('selectByGuid')->willReturn($stream);
        // Cache d'avant le passage aux paiements : montant des commandes, jeton de continuation des commandes
        $this->widgetRepository->method('selectStreamDonationWidgetCacheData')
            ->willReturn(['amount' => 17168797, 'continuation_token' => 'orders_token']);
        $this->apiWrapper->expects($this->once())
            ->method('getAllPaymentsAmount')
            ->with('test-org', 'test-form', 0, null, 'Donation', null)
            ->willReturn(['amount' => 17934097, 'continuation_token' => 'payments_token']);

        $this->widgetRepository->expects($this->once())
            ->method('updateStreamDonationWidgetCacheData')
            ->with($streamGuid, ['amount' => 17934097, 'payments_continuation_token' => 'payments_token']);

        $request = (new ServerRequestFactory())->createServerRequest('GET', "/widget-stream-donation/{$streamGuid}/fetch");
        $result = $this->controller->widgetDonationFetch($request, new Response(), ['id' => $streamGuid]);

        $body = json_decode((string) $result->getBody(), true);
        $this->assertEquals(17934097, $body['amount']);
    }

    // =====================================================================
    // Widget Donation (event) — endpoint JSON (fetch)
    // =====================================================================

    public function testWidgetEventDonationFetchReturnsJsonOnSuccess(): void
    {
        $eventGuid = 'event-donation-guid';
        $event = $this->buildEvent($eventGuid);
        $streams = [$this->buildStream('s1'), $this->buildStream('s2')];

        $this->eventRepository->method('selectByGuid')->willReturn($event);
        $this->widgetRepository->method('selectEventDonationWidgetCacheData')->willReturn(null);
        $this->streamRepository->method('selectListByEvent')->willReturn($streams);
        $this->apiWrapper->method('getAllPaymentsAmount')->willReturn([
            'amount' => 2000,
            'continuation_token' => null,
        ]);

        $request = (new ServerRequestFactory())->createServerRequest('GET', "/widget-event-donation/{$eventGuid}/fetch");
        $result = $this->controller->widgetEventDonationFetch($request, new Response(), ['id' => $eventGuid]);

        $this->assertEquals(200, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertArrayHasKey('amount', $body);
    }

    public function testWidgetEventDonationFetchReturns404WhenEventNotFound(): void
    {
        $this->eventRepository->method('selectByGuid')->willReturn(null);

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/widget-event-donation/ghost/fetch');
        $result = $this->controller->widgetEventDonationFetch($request, new Response(), ['id' => 'ghost-event']);

        $this->assertEquals(404, $result->getStatusCode());
    }

    // =====================================================================
    // Mode test : don simulé et vue Live
    // =====================================================================

    public function testSimulateStreamDonationFeedsLiveHistoryWhenLiveStarted(): void
    {
        $stream = $this->buildStream('stream-test');
        $stream->is_test_mode = 1;
        $stream->live_started_at = '2026-10-23 14:00:00.000000';
        $this->streamRepository->method('selectByGuid')->willReturn($stream);
        $this->streamRepository->expects($this->once())->method('update')->with($stream, ['test_amount' => 1500]);
        $this->donationHistoryRepository->expects($this->once())
            ->method('insertMany')
            ->with('stream-test', [['pseudo' => 'Alice', 'message' => 'Bravo', 'amount' => 1500]]);

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/widget-stream/stream-test/simulate')
            ->withParsedBody(['amount' => 1500, 'pseudo' => 'Alice', 'message' => 'Bravo']);
        $result = $this->controller->simulateStreamDonation($request, new Response(), ['id' => 'stream-test']);

        $this->assertEquals(200, $result->getStatusCode());
    }

    public function testSimulateStreamDonationSkipsLiveHistoryWhenLiveNotStarted(): void
    {
        $stream = $this->buildStream('stream-test');
        $stream->is_test_mode = 1;
        $this->streamRepository->method('selectByGuid')->willReturn($stream);
        $this->donationHistoryRepository->expects($this->never())->method('insertMany');

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/widget-stream/stream-test/simulate')
            ->withParsedBody(['amount' => 1000]);
        $result = $this->controller->simulateStreamDonation($request, new Response(), ['id' => 'stream-test']);

        $this->assertEquals(200, $result->getStatusCode());
    }

    public function testSimulateStreamDonationRejectsStreamOutsideTestMode(): void
    {
        $stream = $this->buildStream('stream-live');
        $stream->live_started_at = '2026-10-23 14:00:00.000000';
        $this->streamRepository->method('selectByGuid')->willReturn($stream);
        $this->donationHistoryRepository->expects($this->never())->method('insertMany');

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/widget-stream/stream-live/simulate')
            ->withParsedBody(['amount' => 1000]);
        $result = $this->controller->simulateStreamDonation($request, new Response(), ['id' => 'stream-live']);

        $this->assertEquals(400, $result->getStatusCode());
    }

    // =====================================================================
    // Widget Card — start amount / donors
    // =====================================================================

    public function testWidgetEventCardAddsStartOffsetToAmountAndDonors(): void
    {
        $eventGuid = 'event-card-guid';
        $event = $this->buildEvent($eventGuid);
        $event->is_test_mode = 1;
        $event->test_amount = 0;

        $card = $this->buildCardWidget(null, $eventGuid);
        $card->start_amount = 275000;
        $card->start_donors = 35;

        $this->widgetRepository->method('selectCardWidgetByGuid')->willReturn($card);
        $this->eventRepository->method('selectByGuid')->willReturn($event);

        $capturedTemplate = null;
        $capturedData = null;
        $this->view->method('render')
            ->willReturnCallback(
                function (ResponseInterface $response, string $template, array $data) use (&$capturedTemplate, &$capturedData) {
                    $capturedTemplate = $template;
                    $capturedData = $data;
                    $response->getBody()->write('<html>card widget</html>');
                    return $response;
                }
            );

        $request = (new ServerRequestFactory())->createServerRequest('GET', "/widget-event-card/{$eventGuid}");
        $result = $this->controller->widgetEventCard($request, new Response(), ['id' => $eventGuid]);

        $this->assertEquals(200, $result->getStatusCode());
        $this->assertEquals('widget/card.html.twig', $capturedTemplate);
        $this->assertEquals(275000, $capturedData['currentAmount']);
        $this->assertEquals(35, $capturedData['donorCount']);
        $this->assertEquals(100, $capturedData['percentage']);
    }

    public function testWidgetEventCardFetchAddsStartOffset(): void
    {
        $eventGuid = 'event-card-fetch-guid';
        $event = $this->buildEvent($eventGuid);
        $event->is_test_mode = 1;
        $event->test_amount = 5000;

        $card = $this->buildCardWidget(null, $eventGuid);
        $card->start_amount = 275000;
        $card->start_donors = 35;

        $this->widgetRepository->method('selectCardWidgetByGuid')->willReturn($card);
        $this->eventRepository->method('selectByGuid')->willReturn($event);

        $request = (new ServerRequestFactory())->createServerRequest('GET', "/widget-event-card/{$eventGuid}/fetch");
        $result = $this->controller->widgetEventCardFetch($request, new Response(), ['id' => $eventGuid]);

        $this->assertEquals(200, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertEquals(280000, $body['amount']);
        $this->assertEquals(35, $body['donors']);
    }

    // =====================================================================
    // Widget Card — montant depuis les paiements, donateurs depuis les commandes
    // =====================================================================

    public function testWidgetStreamCardFetchTakesAmountFromPaymentsAndDonorsFromOrders(): void
    {
        $streamGuid = 'stream-card-guid';
        $stream = $this->buildStream($streamGuid);

        $this->widgetRepository->method('selectCardWidgetByGuid')->willReturn($this->buildCardWidget($streamGuid, null));
        $this->streamRepository->method('selectByGuid')->willReturn($stream);
        $this->widgetRepository->method('selectStreamCardWidgetCacheData')->willReturn([
            'amount' => 1000,
            'donors' => 5,
            'continuation_token' => 'orders_token',
            'payments_continuation_token' => 'payments_token',
        ]);
        $this->apiWrapper->expects($this->once())
            ->method('getAllPaymentsAmount')
            ->with('test-org', 'test-form', 1000, 'payments_token', 'Donation', null)
            ->willReturn(['amount' => 1800, 'continuation_token' => 'payments_token_2']);
        // Le montant des commandes n'est plus utilisé : on ne leur demande que les nouveaux donateurs
        $this->apiWrapper->expects($this->once())
            ->method('getAllOrders')
            ->with('test-org', 'test-form', 0, 'orders_token', 'Donation', null)
            ->willReturn([
                'amount' => 999999,
                'donations' => [['pseudo' => 'a', 'message' => '', 'amount' => 500], ['pseudo' => 'b', 'message' => '', 'amount' => 300]],
                'continuation_token' => 'orders_token_2',
            ]);

        $this->widgetRepository->expects($this->once())
            ->method('updateStreamCardWidgetCacheData')
            ->with($streamGuid, [
                'amount' => 1800,
                'donors' => 7,
                'continuation_token' => 'orders_token_2',
                'payments_continuation_token' => 'payments_token_2',
            ]);

        $request = (new ServerRequestFactory())->createServerRequest('GET', "/widget-stream-card/{$streamGuid}/fetch");
        $result = $this->controller->widgetStreamCardFetch($request, new Response(), ['id' => $streamGuid]);

        $this->assertEquals(200, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertEquals(1800, $body['amount']);
        $this->assertEquals(7, $body['donors']);
    }

    public function testWidgetStreamCardFetchRestartsAmountButKeepsDonorsWhenCacheComesFromOrders(): void
    {
        $streamGuid = 'stream-card-legacy-guid';
        $stream = $this->buildStream($streamGuid);

        $this->widgetRepository->method('selectCardWidgetByGuid')->willReturn($this->buildCardWidget($streamGuid, null));
        $this->streamRepository->method('selectByGuid')->willReturn($stream);
        // Cache d'avant le passage aux paiements : pas de jeton de continuation des paiements
        $this->widgetRepository->method('selectStreamCardWidgetCacheData')->willReturn([
            'amount' => 17168797,
            'donors' => 2249,
            'continuation_token' => 'orders_token',
        ]);
        $this->apiWrapper->expects($this->once())
            ->method('getAllPaymentsAmount')
            ->with('test-org', 'test-form', 0, null, 'Donation', null)
            ->willReturn(['amount' => 17934097, 'continuation_token' => 'payments_token']);
        $this->apiWrapper->expects($this->once())
            ->method('getAllOrders')
            ->with('test-org', 'test-form', 0, 'orders_token', 'Donation', null)
            ->willReturn(['amount' => 0, 'donations' => [], 'continuation_token' => 'orders_token']);

        $this->widgetRepository->expects($this->once())
            ->method('updateStreamCardWidgetCacheData')
            ->with($streamGuid, [
                'amount' => 17934097,
                'donors' => 2249,
                'continuation_token' => 'orders_token',
                'payments_continuation_token' => 'payments_token',
            ]);

        $request = (new ServerRequestFactory())->createServerRequest('GET', "/widget-stream-card/{$streamGuid}/fetch");
        $result = $this->controller->widgetStreamCardFetch($request, new Response(), ['id' => $streamGuid]);

        $body = json_decode((string) $result->getBody(), true);
        $this->assertEquals(17934097, $body['amount']);
        $this->assertEquals(2249, $body['donors']);
    }

    public function testWidgetEventCardFetchRestartsStreamAmountButKeepsDonorsWhenCacheComesFromOrders(): void
    {
        $eventGuid = 'event-card-legacy-guid';
        $event = $this->buildEvent($eventGuid);
        $stream = $this->buildStream('event-stream');

        $this->widgetRepository->method('selectCardWidgetByGuid')->willReturn($this->buildCardWidget(null, $eventGuid));
        $this->eventRepository->method('selectByGuid')->willReturn($event);
        $this->streamRepository->method('selectListByEvent')->willReturn([$stream]);
        $this->widgetRepository->method('selectEventCardWidgetCacheData')->willReturn([
            'amount' => 999,
            'donors' => 10,
            'streams' => [['id' => $stream->id, 'amount' => 999, 'continuation_token' => 'orders_token', 'donors' => 10]],
        ]);
        $this->apiWrapper->expects($this->once())
            ->method('getAllPaymentsAmount')
            ->with('test-org', 'test-form', 0, null, 'Donation', null)
            ->willReturn(['amount' => 1800, 'continuation_token' => 'payments_token']);
        $this->apiWrapper->expects($this->once())
            ->method('getAllOrders')
            ->with('test-org', 'test-form', 0, 'orders_token', 'Donation', null)
            ->willReturn([
                'amount' => 0,
                'donations' => [['pseudo' => 'a', 'message' => '', 'amount' => 500]],
                'continuation_token' => 'orders_token_2',
            ]);

        $this->widgetRepository->expects($this->once())
            ->method('updateEventCardWidgetCacheData')
            ->with($eventGuid, [
                'amount' => 1800,
                'donors' => 11,
                'streams' => [[
                    'id' => $stream->id,
                    'amount' => 1800,
                    'continuation_token' => 'orders_token_2',
                    'donors' => 11,
                    'payments_continuation_token' => 'payments_token',
                ]],
            ]);

        $request = (new ServerRequestFactory())->createServerRequest('GET', "/widget-event-card/{$eventGuid}/fetch");
        $result = $this->controller->widgetEventCardFetch($request, new Response(), ['id' => $eventGuid]);

        $body = json_decode((string) $result->getBody(), true);
        $this->assertEquals(1800, $body['amount']);
        $this->assertEquals(11, $body['donors']);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function buildStream(string $guid): Stream
    {
        $stream = new Stream();
        $stream->id = rand(1, 9999);
        $stream->guid = $guid;
        $stream->title = 'Stream ' . $guid;
        $stream->organization_slug = 'test-org';
        $stream->form_slug = 'test-form';
        return $stream;
    }

    private function buildEvent(string $guid): Event
    {
        $event = new Event();
        $event->id = rand(1, 9999);
        $event->guid = $guid;
        $event->title = 'Event ' . $guid;
        return $event;
    }

    private function buildAlertWidget(string $streamGuid): WidgetAlert
    {
        $widget = new WidgetAlert();
        $widget->id = 1;
        $widget->charity_stream_guid = $streamGuid;
        $widget->alert_duration = 5;
        $widget->message_template = '{pseudo} a donné {amount}';
        $widget->sound_volume = 50;
        $widget->image = 'image.png';
        $widget->sound = 'sound.mp3';
        return $widget;
    }

    private function buildDonationWidget(?string $streamGuid, ?string $eventGuid): WidgetDonation
    {
        $widget = new WidgetDonation();
        $widget->id = 1;
        $widget->charity_stream_guid = $streamGuid;
        $widget->charity_event_guid = $eventGuid;
        $widget->text_content = 'Objectif : {goal}€';
        $widget->bar_color = '#00ff00';
        $widget->background_color = '#000000';
        $widget->text_color_main = '#ffffff';
        $widget->text_color_alt = '#cccccc';
        return $widget;
    }

    private function buildCardWidget(?string $streamGuid, ?string $eventGuid): WidgetCard
    {
        $widget = new WidgetCard();
        $widget->id = 1;
        $widget->charity_stream_guid = $streamGuid;
        $widget->charity_event_guid = $eventGuid;
        $widget->title = 'Campagne test';
        $widget->description = 'Description';
        $widget->start_amount = 0;
        $widget->start_donors = 0;
        return $widget;
    }
}


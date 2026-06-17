<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\WidgetController;
use App\Models\Event;
use App\Models\Stream;
use App\Models\WidgetAlert;
use App\Models\WidgetDonation;
use App\Repositories\EventRepository;
use App\Repositories\FileManager;
use App\Repositories\GoalRepository;
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
        $this->apiWrapper->method('getAllOrders')->willReturn([
            'amount' => 3000,
            'donations' => [],
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
            ->willReturn(['amount' => 1000, 'continuation_token' => 'prev_token']);
        $this->apiWrapper->method('getAllOrders')->willReturn([
            'amount' => 1500,
            'donations' => [],
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
            ->willReturn(['amount' => 500, 'continuation_token' => null]);
        $this->apiWrapper->method('getAllOrders')->willReturn([
            'amount' => 800,
            'donations' => [],
            'continuation_token' => 'ct_new',
        ]);

        // Vérifie que le cache est mis à jour
        $this->widgetRepository->expects($this->once())
            ->method('updateStreamDonationWidgetCacheData')
            ->with($streamGuid, ['amount' => 800, 'continuation_token' => 'ct_new']);

        $request = (new ServerRequestFactory())->createServerRequest('GET', "/widget-stream-donation/{$streamGuid}/fetch");
        $this->controller->widgetDonationFetch($request, new Response(), ['id' => $streamGuid]);
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
        $this->apiWrapper->method('getAllOrders')->willReturn([
            'amount' => 2000,
            'donations' => [],
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
}


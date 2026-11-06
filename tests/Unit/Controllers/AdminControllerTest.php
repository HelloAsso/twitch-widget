<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\AdminController;
use App\Models\Event;
use App\Models\Stream;
use App\Models\StreamDonationEntry;
use App\Models\User;
use App\Repositories\AccessTokenRepository;
use App\Repositories\AuthorizationCodeRepository;
use App\Repositories\EventRepository;
use App\Repositories\FileManager;
use App\Repositories\GoalRepository;
use App\Repositories\StreamDonationHistoryRepository;
use App\Repositories\StreamRepository;
use App\Repositories\UserRepository;
use App\Repositories\WidgetRepository;
use App\Services\ApiWrapper;
use MailchimpTransactional\ApiClient;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Factory\AppFactory;
use Slim\Flash\Messages;
use Slim\Interfaces\DispatcherInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Routing\RouteContext;
use Slim\Routing\RoutingResults;
use Slim\Views\Twig;

/**
 * Tests d'affichage du dashboard administrateur et de la vue Live d'un stream.
 */
class AdminControllerTest extends TestCase
{
    private Twig&MockObject $view;
    private FileManager&MockObject $fileManager;
    private EventRepository&MockObject $eventRepository;
    private StreamRepository&MockObject $streamRepository;
    private UserRepository&MockObject $userRepository;
    private WidgetRepository&MockObject $widgetRepository;
    private GoalRepository&MockObject $goalRepository;
    private Messages&MockObject $messages;
    private ApiWrapper&MockObject $apiWrapper;
    private AccessTokenRepository&MockObject $accessTokenRepository;
    private AuthorizationCodeRepository&MockObject $authorizationCodeRepository;
    private ApiClient&MockObject $mailchimp;
    private StreamDonationHistoryRepository&MockObject $donationHistoryRepository;
    private AdminController $controller;

    protected function setUp(): void
    {
        $this->view = $this->createMock(Twig::class);
        $this->fileManager = $this->createMock(FileManager::class);
        $this->eventRepository = $this->createMock(EventRepository::class);
        $this->streamRepository = $this->createMock(StreamRepository::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->widgetRepository = $this->createMock(WidgetRepository::class);
        $this->goalRepository = $this->createMock(GoalRepository::class);
        $this->messages = $this->createMock(Messages::class);
        $this->apiWrapper = $this->createMock(ApiWrapper::class);
        $this->accessTokenRepository = $this->createMock(AccessTokenRepository::class);
        $this->authorizationCodeRepository = $this->createMock(AuthorizationCodeRepository::class);
        $this->mailchimp = $this->createMock(ApiClient::class);
        $this->donationHistoryRepository = $this->createMock(StreamDonationHistoryRepository::class);

        $this->controller = new AdminController(
            $this->view,
            $this->fileManager,
            $this->eventRepository,
            $this->streamRepository,
            $this->userRepository,
            $this->widgetRepository,
            $this->goalRepository,
            $this->messages,
            $this->apiWrapper,
            $this->accessTokenRepository,
            $this->authorizationCodeRepository,
            $this->mailchimp,
            $this->donationHistoryRepository,
        );
    }

    // =====================================================================
    // Tests d'affichage du dashboard (index)
    // =====================================================================

    public function testIndexRendersAdminTemplateForAdminUser(): void
    {
        $adminUser = $this->buildUser('ADMIN');
        $streams = [$this->buildStream('stream-1'), $this->buildStream('stream-2')];
        $events = [$this->buildEvent('event-1')];

        $this->streamRepository->expects($this->once())->method('selectList')->willReturn($streams);
        $this->eventRepository->expects($this->once())->method('selectList')->willReturn($events);
        $this->messages->method('getMessages')->willReturn([]);

        $capturedTemplate = null;
        $capturedData = null;

        $this->view->expects($this->once())
            ->method('render')
            ->willReturnCallback(
                function (ResponseInterface $response, string $template, array $data) use (&$capturedTemplate, &$capturedData) {
                    $capturedTemplate = $template;
                    $capturedData = $data;
                    $response->getBody()->write('<html>admin</html>');
                    return $response;
                }
            );

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin')
            ->withAttribute('user', $adminUser);
        $response = new Response();

        $result = $this->controller->index($request, $response);

        $this->assertEquals('stream/index-admin.html.twig', $capturedTemplate);
        $this->assertCount(2, $capturedData['streams']);
        $this->assertCount(1, $capturedData['events']);
        $this->assertEquals(200, $result->getStatusCode());
    }

    public function testIndexRendersUserTemplateForRegularUser(): void
    {
        $regularUser = $this->buildUser('USER');

        $this->streamRepository->expects($this->once())
            ->method('selectListByUser')
            ->with($regularUser)
            ->willReturn([$this->buildStream('my-stream')]);

        $this->eventRepository->expects($this->once())
            ->method('selectListByUser')
            ->with($regularUser)
            ->willReturn([]);

        $this->messages->method('getMessages')->willReturn([]);

        $capturedTemplate = null;
        $this->view->expects($this->once())
            ->method('render')
            ->willReturnCallback(
                function (ResponseInterface $response, string $template, array $data) use (&$capturedTemplate) {
                    $capturedTemplate = $template;
                    $response->getBody()->write('<html>user</html>');
                    return $response;
                }
            );

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin')
            ->withAttribute('user', $regularUser);
        $response = new Response();

        $result = $this->controller->index($request, $response);

        $this->assertEquals('stream/index.html.twig', $capturedTemplate);
        $this->assertEquals(200, $result->getStatusCode());
    }

    public function testIndexPassesFlashMessagesToTemplate(): void
    {
        $adminUser = $this->buildUser('ADMIN');
        $flashMessages = ['success' => ['Opération réussie']];

        $this->streamRepository->method('selectList')->willReturn([]);
        $this->eventRepository->method('selectList')->willReturn([]);
        $this->messages->method('getMessages')->willReturn($flashMessages);

        $capturedData = null;
        $this->view->method('render')
            ->willReturnCallback(
                function (ResponseInterface $response, string $template, array $data) use (&$capturedData) {
                    $capturedData = $data;
                    $response->getBody()->write('<html></html>');
                    return $response;
                }
            );

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin')
            ->withAttribute('user', $adminUser);
        $response = new Response();

        $this->controller->index($request, $response);

        $this->assertEquals($flashMessages, $capturedData['messages']);
    }

    public function testIndexAdminDoesNotCallSelectListByUser(): void
    {
        $adminUser = $this->buildUser('ADMIN');

        $this->streamRepository->expects($this->once())->method('selectList')->willReturn([]);
        $this->streamRepository->expects($this->never())->method('selectListByUser');
        $this->eventRepository->expects($this->once())->method('selectList')->willReturn([]);
        $this->eventRepository->expects($this->never())->method('selectListByUser');
        $this->messages->method('getMessages')->willReturn([]);

        $this->view->method('render')->willReturnCallback(
            fn(ResponseInterface $r) => $r
        );

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin')
            ->withAttribute('user', $adminUser);

        $this->controller->index($request, new Response());
    }

    // =====================================================================
    // Vue Live : affichage
    // =====================================================================

    public function testLiveViewRendersEmptyFeedWhenLiveNotStarted(): void
    {
        $this->streamRepository->method('selectByUserAndGuid')->willReturn($this->liveStream(null));
        $this->donationHistoryRepository->expects($this->never())->method('selectPage');
        $this->messages->method('getMessages')->willReturn([]);

        $capturedTemplate = null;
        $capturedData = null;
        $this->view->expects($this->once())->method('render')->willReturnCallback(
            function (ResponseInterface $response, string $template, array $data) use (&$capturedTemplate, &$capturedData) {
                $capturedTemplate = $template;
                $capturedData = $data;
                return $response;
            }
        );

        $this->controller->liveView($this->liveRequest('GET', '/admin/stream/guid-live/live'), new Response(), ['id' => 'guid-live']);

        $this->assertSame('stream/live.html.twig', $capturedTemplate);
        $this->assertSame([], $capturedData['entries']);
        $this->assertSame(StreamDonationHistoryRepository::PAGE_SIZE, $capturedData['pageSize']);
        $this->assertSame(StreamDonationHistoryRepository::POLL_LIMIT, $capturedData['pollLimit']);
    }

    public function testLiveViewLoadsFirstPageWhenLiveStarted(): void
    {
        $this->streamRepository->method('selectByUserAndGuid')->willReturn($this->liveStream());
        $this->donationHistoryRepository->expects($this->once())
            ->method('selectPage')
            ->with('guid-live', null)
            ->willReturn([$this->donationEntry(2), $this->donationEntry(1)]);
        $this->messages->method('getMessages')->willReturn([]);

        $capturedData = null;
        $this->view->method('render')->willReturnCallback(
            function (ResponseInterface $response, string $template, array $data) use (&$capturedData) {
                $capturedData = $data;
                return $response;
            }
        );

        $this->controller->liveView($this->liveRequest('GET', '/admin/stream/guid-live/live'), new Response(), ['id' => 'guid-live']);

        $this->assertCount(2, $capturedData['entries']);
    }

    public function testLiveViewRedirectsToDashboardWhenNoAccess(): void
    {
        $this->streamRepository->method('selectByUserAndGuid')->willReturn(null);
        $this->view->expects($this->never())->method('render');
        $this->messages->expects($this->once())->method('addMessage')->with('error', $this->anything());

        $result = $this->controller->liveView($this->liveRequest('GET', '/admin/stream/guid-live/live'), new Response(), ['id' => 'guid-live']);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertStringStartsWith('/admin', $result->getHeaderLine('Location'));
    }

    // =====================================================================
    // Vue Live : démarrage / arrêt
    // =====================================================================

    public function testStartLiveAnchorsCursorOnApiAndStartsSession(): void
    {
        $stream = $this->liveStream(null);
        $this->streamRepository->method('selectByUserAndGuid')->willReturn($stream);
        $this->apiWrapper->expects($this->once())
            ->method('getAllOrders')
            ->with('test-org', 'test-form', 0, null, 'Donation')
            ->willReturn(['amount' => 1000, 'donations' => [['pseudo' => 'Old', 'message' => '', 'amount' => 1000]], 'continuation_token' => 'cursor-1']);
        $this->donationHistoryRepository->expects($this->once())->method('deleteByStreamGuid')->with('guid-live');
        $this->streamRepository->expects($this->once())->method('update')->with(
            $stream,
            $this->callback(fn(array $data) => $data['live_continuation_token'] === 'cursor-1'
                && !empty($data['live_started_at'])
                && !empty($data['live_cache_updated_at']))
        );
        $this->messages->expects($this->once())->method('addMessage')->with('success', $this->anything());

        $result = $this->controller->startLive($this->liveRequest('POST', '/admin/stream/guid-live/live/start'), new Response(), ['id' => 'guid-live']);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame('/admin/stream/guid-live/live', $result->getHeaderLine('Location'));
    }

    public function testStartLiveStartsWithoutCursorOnEmptyForm(): void
    {
        $this->streamRepository->method('selectByUserAndGuid')->willReturn($this->liveStream(null));
        $this->apiWrapper->method('getAllOrders')->willReturn(['amount' => 0, 'donations' => [], 'continuation_token' => null]);
        $this->streamRepository->expects($this->once())->method('update')->with(
            $this->anything(),
            $this->callback(fn(array $data) => $data['live_continuation_token'] === null && !empty($data['live_started_at']))
        );

        $this->controller->startLive($this->liveRequest('POST', '/admin/stream/guid-live/live/start'), new Response(), ['id' => 'guid-live']);
    }

    public function testStartLiveIsIdempotentWhenAlreadyStarted(): void
    {
        $this->streamRepository->method('selectByUserAndGuid')->willReturn($this->liveStream());
        $this->apiWrapper->expects($this->never())->method('getAllOrders');
        $this->streamRepository->expects($this->never())->method('update');
        $this->donationHistoryRepository->expects($this->never())->method('deleteByStreamGuid');

        $result = $this->controller->startLive($this->liveRequest('POST', '/admin/stream/guid-live/live/start'), new Response(), ['id' => 'guid-live']);

        $this->assertSame(302, $result->getStatusCode());
    }

    public function testStartLiveDoesNotStartWhenApiFails(): void
    {
        $this->streamRepository->method('selectByUserAndGuid')->willReturn($this->liveStream(null));
        $this->apiWrapper->method('getAllOrders')->willThrowException(new \Exception('timeout'));
        $this->streamRepository->expects($this->never())->method('update');
        $this->donationHistoryRepository->expects($this->never())->method('deleteByStreamGuid');
        $this->messages->expects($this->once())->method('addMessage')
            ->with('error', $this->stringContains('Impossible de démarrer le live'));

        $result = $this->controller->startLive($this->liveRequest('POST', '/admin/stream/guid-live/live/start'), new Response(), ['id' => 'guid-live']);

        $this->assertSame('/admin/stream/guid-live/live', $result->getHeaderLine('Location'));
    }

    public function testStartLiveShowsTokenErrorMessageWhenOrganizationTokenExpired(): void
    {
        $this->streamRepository->method('selectByUserAndGuid')->willReturn($this->liveStream(null));
        $this->apiWrapper->method('getAllOrders')->willThrowException(new \Exception('Votre token d\'accès est expiré', 401));
        $this->messages->expects($this->once())->method('addMessage')
            ->with('error', $this->stringContains('token d\'accès est expiré'));

        $this->controller->startLive($this->liveRequest('POST', '/admin/stream/guid-live/live/start'), new Response(), ['id' => 'guid-live']);
    }

    public function testStartLiveDoesNotStartWhenApiReturnsDonationsWithoutCursor(): void
    {
        $this->streamRepository->method('selectByUserAndGuid')->willReturn($this->liveStream(null));
        $this->apiWrapper->method('getAllOrders')->willReturn([
            'amount' => 500,
            'donations' => [['pseudo' => 'Old', 'message' => '', 'amount' => 500]],
            'continuation_token' => null,
        ]);
        $this->streamRepository->expects($this->never())->method('update');
        $this->messages->expects($this->once())->method('addMessage')->with('error', $this->anything());

        $this->controller->startLive($this->liveRequest('POST', '/admin/stream/guid-live/live/start'), new Response(), ['id' => 'guid-live']);
    }

    public function testStartLiveInTestModeSkipsApi(): void
    {
        $stream = $this->liveStream(null);
        $stream->is_test_mode = 1;
        $this->streamRepository->method('selectByUserAndGuid')->willReturn($stream);
        $this->apiWrapper->expects($this->never())->method('getAllOrders');
        $this->streamRepository->expects($this->once())->method('update')->with(
            $stream,
            $this->callback(fn(array $data) => $data['live_continuation_token'] === null && !empty($data['live_started_at']))
        );

        $this->controller->startLive($this->liveRequest('POST', '/admin/stream/guid-live/live/start'), new Response(), ['id' => 'guid-live']);
    }

    public function testStopLiveClearsHistoryAndSession(): void
    {
        $stream = $this->liveStream();
        $this->streamRepository->method('selectByUserAndGuid')->willReturn($stream);
        $this->donationHistoryRepository->expects($this->once())->method('deleteByStreamGuid')->with('guid-live');
        $this->streamRepository->expects($this->once())->method('update')->with($stream, [
            'live_started_at' => null,
            'live_continuation_token' => null,
            'live_cache_updated_at' => null,
        ]);

        $result = $this->controller->stopLive($this->liveRequest('POST', '/admin/stream/guid-live/live/stop'), new Response(), ['id' => 'guid-live']);

        $this->assertSame(302, $result->getStatusCode());
    }

    public function testToggleTestModeEndsRunningLive(): void
    {
        $this->streamRepository->method('selectByUserAndGuid')->willReturn($this->liveStream());
        $updates = [];
        $this->streamRepository->method('update')->willReturnCallback(
            function (Stream $stream, array $data) use (&$updates) {
                $updates[] = $data;
            }
        );
        $this->donationHistoryRepository->expects($this->once())->method('deleteByStreamGuid')->with('guid-live');

        $this->controller->editStreamPost(
            $this->liveRequest('POST', '/admin/stream/guid-live/edit', ['toggle_test_mode' => '1']),
            new Response(),
            ['id' => 'guid-live']
        );

        $this->assertSame(1, $updates[0]['is_test_mode']);
        $this->assertArrayHasKey('live_started_at', $updates[1]);
        $this->assertNull($updates[1]['live_started_at']);
        $this->assertNull($updates[1]['live_continuation_token']);
    }

    public function testToggleTestModeLeavesNonStartedLiveUntouched(): void
    {
        $this->streamRepository->method('selectByUserAndGuid')->willReturn($this->liveStream(null));
        $this->streamRepository->expects($this->once())->method('update');
        $this->donationHistoryRepository->expects($this->never())->method('deleteByStreamGuid');

        $this->controller->editStreamPost(
            $this->liveRequest('POST', '/admin/stream/guid-live/edit', ['toggle_test_mode' => '1']),
            new Response(),
            ['id' => 'guid-live']
        );
    }

    // =====================================================================
    // Vue Live : polling (liveFetch)
    // =====================================================================

    public function testLiveFetchReturns404WhenNoAccess(): void
    {
        $this->streamRepository->method('selectByUserAndGuid')->willReturn(null);

        $result = $this->controller->liveFetch($this->liveRequest('GET', '/x'), new Response(), ['id' => 'guid-live']);

        $this->assertSame(404, $result->getStatusCode());
        $this->assertArrayHasKey('error', $this->decode($result));
    }

    public function testLiveFetchReturnsEmptyListWhenLiveNotStarted(): void
    {
        $this->streamRepository->method('selectByUserAndGuid')->willReturn($this->liveStream(null));
        $this->streamRepository->expects($this->never())->method('claimLiveRefresh');
        $this->donationHistoryRepository->expects($this->never())->method('selectSince');

        $result = $this->controller->liveFetch($this->liveRequest('GET', '/x'), new Response(), ['id' => 'guid-live']);

        $this->assertSame(200, $result->getStatusCode());
        $this->assertSame([], $this->decode($result));
    }

    public function testLiveFetchStoresNewDonationsAndAdvancesCursor(): void
    {
        $stream = $this->liveStream();
        $stream->live_continuation_token = 'cursor-1';
        $donations = [['pseudo' => 'Alice', 'message' => 'Bravo', 'amount' => 500]];

        $this->streamRepository->method('selectByUserAndGuid')->willReturn($stream);
        $this->streamRepository->expects($this->once())->method('claimLiveRefresh')->with($stream, 30)->willReturn(true);
        $this->apiWrapper->expects($this->once())
            ->method('getAllOrders')
            ->with('test-org', 'test-form', 0, 'cursor-1', 'Donation')
            ->willReturn(['amount' => 500, 'donations' => $donations, 'continuation_token' => 'cursor-2']);
        $this->donationHistoryRepository->expects($this->once())->method('insertMany')->with('guid-live', $donations);
        $this->streamRepository->expects($this->once())->method('update')->with($stream, ['live_continuation_token' => 'cursor-2']);
        $this->donationHistoryRepository->method('selectSince')->with('guid-live', 4)->willReturn([$this->donationEntry(5)]);

        $result = $this->controller->liveFetch($this->liveRequest('GET', '/x', [], ['since_id' => '4']), new Response(), ['id' => 'guid-live']);

        $payload = $this->decode($result);
        $this->assertCount(1, $payload);
        $this->assertSame(5, $payload[0]['id']);
        $this->assertSame('Alice', $payload[0]['pseudo']);
        $this->assertSame(500, $payload[0]['amount']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $payload[0]['received_at']);
    }

    public function testLiveFetchSkipsApiWhenAnotherPollHoldsTheRefresh(): void
    {
        $this->streamRepository->method('selectByUserAndGuid')->willReturn($this->liveStream());
        $this->streamRepository->method('claimLiveRefresh')->willReturn(false);
        $this->apiWrapper->expects($this->never())->method('getAllOrders');
        $this->donationHistoryRepository->expects($this->never())->method('insertMany');
        $this->donationHistoryRepository->method('selectSince')->willReturn([$this->donationEntry(3)]);

        $result = $this->controller->liveFetch($this->liveRequest('GET', '/x'), new Response(), ['id' => 'guid-live']);

        $this->assertCount(1, $this->decode($result));
    }

    public function testLiveFetchNeverCallsApiInTestMode(): void
    {
        $stream = $this->liveStream();
        $stream->is_test_mode = 1;
        $this->streamRepository->method('selectByUserAndGuid')->willReturn($stream);
        $this->streamRepository->expects($this->never())->method('claimLiveRefresh');
        $this->apiWrapper->expects($this->never())->method('getAllOrders');
        $this->donationHistoryRepository->method('selectSince')->willReturn([$this->donationEntry(1)]);

        $result = $this->controller->liveFetch($this->liveRequest('GET', '/x'), new Response(), ['id' => 'guid-live']);

        $this->assertCount(1, $this->decode($result));
    }

    public function testLiveFetchNeverRegressesCursorToNull(): void
    {
        $this->streamRepository->method('selectByUserAndGuid')->willReturn($this->liveStream());
        $this->streamRepository->method('claimLiveRefresh')->willReturn(true);
        $this->apiWrapper->method('getAllOrders')->willReturn(['amount' => 0, 'donations' => [], 'continuation_token' => null]);
        $this->streamRepository->expects($this->never())->method('update');
        $this->donationHistoryRepository->method('selectSince')->willReturn([]);

        $this->controller->liveFetch($this->liveRequest('GET', '/x'), new Response(), ['id' => 'guid-live']);
    }

    public function testLiveFetchDoesNotAdvanceCursorWhenInsertFails(): void
    {
        $this->streamRepository->method('selectByUserAndGuid')->willReturn($this->liveStream());
        $this->streamRepository->method('claimLiveRefresh')->willReturn(true);
        $this->apiWrapper->method('getAllOrders')->willReturn([
            'amount' => 500,
            'donations' => [['pseudo' => 'Alice', 'message' => '', 'amount' => 500]],
            'continuation_token' => 'cursor-2',
        ]);
        $this->donationHistoryRepository->method('insertMany')->willThrowException(new \Exception('db down'));
        // Le curseur reste sur la page non insérée : le prochain rafraîchissement la rejoue
        $this->streamRepository->expects($this->never())->method('update');
        $this->donationHistoryRepository->method('selectSince')->willReturn([$this->donationEntry(2)]);

        $result = $this->controller->liveFetch($this->liveRequest('GET', '/x'), new Response(), ['id' => 'guid-live']);

        $this->assertSame(200, $result->getStatusCode());
        $this->assertCount(1, $this->decode($result));
    }

    public function testLiveFetchStillServesStoredDonationsWhenApiFails(): void
    {
        $this->streamRepository->method('selectByUserAndGuid')->willReturn($this->liveStream());
        $this->streamRepository->method('claimLiveRefresh')->willReturn(true);
        $this->apiWrapper->method('getAllOrders')->willThrowException(new \Exception('timeout'));
        $this->streamRepository->expects($this->never())->method('update');
        $this->donationHistoryRepository->method('selectSince')->willReturn([$this->donationEntry(2)]);

        $result = $this->controller->liveFetch($this->liveRequest('GET', '/x'), new Response(), ['id' => 'guid-live']);

        $this->assertSame(200, $result->getStatusCode());
        $this->assertCount(1, $this->decode($result));
    }

    // =====================================================================
    // Vue Live : historique (liveHistory)
    // =====================================================================

    public function testLiveHistoryReturns404WhenNoAccess(): void
    {
        $this->streamRepository->method('selectByUserAndGuid')->willReturn(null);

        $result = $this->controller->liveHistory($this->liveRequest('GET', '/x'), new Response(), ['id' => 'guid-live']);

        $this->assertSame(404, $result->getStatusCode());
    }

    public function testLiveHistoryReturnsPageBeforeGivenId(): void
    {
        $this->streamRepository->method('selectByUserAndGuid')->willReturn($this->liveStream());
        $this->donationHistoryRepository->expects($this->once())
            ->method('selectPage')
            ->with('guid-live', 42)
            ->willReturn([$this->donationEntry(41), $this->donationEntry(40)]);

        $result = $this->controller->liveHistory($this->liveRequest('GET', '/x', [], ['before_id' => '42']), new Response(), ['id' => 'guid-live']);

        $this->assertSame([41, 40], array_column($this->decode($result), 'id'));
    }

    public function testLiveHistoryWithoutCursorStartsFromNewest(): void
    {
        $this->streamRepository->method('selectByUserAndGuid')->willReturn($this->liveStream());
        $this->donationHistoryRepository->expects($this->once())->method('selectPage')->with('guid-live', null)->willReturn([]);

        $result = $this->controller->liveHistory($this->liveRequest('GET', '/x'), new Response(), ['id' => 'guid-live']);

        $this->assertSame([], $this->decode($result));
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function liveStream(?string $startedAt = '2026-10-23 14:00:00.000000'): Stream
    {
        $stream = $this->buildStream('guid-live');
        $stream->live_started_at = $startedAt;
        return $stream;
    }

    private function donationEntry(int $id, string $pseudo = 'Alice'): StreamDonationEntry
    {
        $entry = new StreamDonationEntry();
        $entry->id = (string) $id;
        $entry->charity_stream_guid = 'guid-live';
        $entry->pseudo = $pseudo;
        $entry->message = 'Bravo';
        $entry->amount = '500';
        $entry->received_at = '2026-10-23 14:05:03.123456';
        return $entry;
    }

    /**
     * Requête avec le contexte de routage Slim, nécessaire aux redirections par nom de route.
     */
    private function liveRequest(string $method, string $uri, array $body = [], array $query = []): ServerRequestInterface
    {
        $app = AppFactory::create();
        foreach ([
            'app_admin_index' => '/admin',
            'app_stream_live' => '/admin/stream/{id}/live',
            'app_stream_edit' => '/admin/stream/{id}/edit',
        ] as $name => $pattern) {
            $app->get($pattern, fn() => null)->setName($name);
        }

        $request = (new ServerRequestFactory())->createServerRequest($method, $uri)
            ->withAttribute('user', $this->buildUser('USER'))
            ->withAttribute(RouteContext::ROUTE_PARSER, $app->getRouteCollector()->getRouteParser())
            ->withAttribute(RouteContext::ROUTING_RESULTS, new RoutingResults($this->createMock(DispatcherInterface::class), $method, $uri, 0))
            ->withQueryParams($query);

        return $body ? $request->withParsedBody($body) : $request;
    }

    private function decode(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true);
    }

    private function buildUser(string $role): User
    {
        $user = new User();
        $user->id = 1;
        $user->email = 'test@example.com';
        $user->role = $role;
        return $user;
    }

    private function buildStream(string $guid): Stream
    {
        $stream = new Stream();
        $stream->id = rand(1, 1000);
        $stream->guid = $guid;
        $stream->title = 'Test Stream ' . $guid;
        $stream->organization_slug = 'test-org';
        $stream->form_slug = 'test-form';
        return $stream;
    }

    private function buildEvent(string $guid): Event
    {
        $event = new Event();
        $event->id = rand(1, 1000);
        $event->guid = $guid;
        $event->title = 'Test Event ' . $guid;
        return $event;
    }
}


<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\AdminController;
use App\Models\Event;
use App\Models\Stream;
use App\Models\User;
use App\Repositories\AccessTokenRepository;
use App\Repositories\AuthorizationCodeRepository;
use App\Repositories\EventRepository;
use App\Repositories\FileManager;
use App\Repositories\StreamRepository;
use App\Repositories\UserRepository;
use App\Repositories\WidgetRepository;
use App\Services\ApiWrapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Flash\Messages;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;

/**
 * Tests d'affichage du dashboard administrateur.
 */
class AdminControllerTest extends TestCase
{
    private Twig&MockObject $view;
    private FileManager&MockObject $fileManager;
    private EventRepository&MockObject $eventRepository;
    private StreamRepository&MockObject $streamRepository;
    private UserRepository&MockObject $userRepository;
    private WidgetRepository&MockObject $widgetRepository;
    private Messages&MockObject $messages;
    private ApiWrapper&MockObject $apiWrapper;
    private AccessTokenRepository&MockObject $accessTokenRepository;
    private AuthorizationCodeRepository&MockObject $authorizationCodeRepository;
    private AdminController $controller;

    protected function setUp(): void
    {
        $this->view = $this->createMock(Twig::class);
        $this->fileManager = $this->createMock(FileManager::class);
        $this->eventRepository = $this->createMock(EventRepository::class);
        $this->streamRepository = $this->createMock(StreamRepository::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->widgetRepository = $this->createMock(WidgetRepository::class);
        $this->messages = $this->createMock(Messages::class);
        $this->apiWrapper = $this->createMock(ApiWrapper::class);
        $this->accessTokenRepository = $this->createMock(AccessTokenRepository::class);
        $this->authorizationCodeRepository = $this->createMock(AuthorizationCodeRepository::class);

        $this->controller = new AdminController(
            $this->view,
            $this->fileManager,
            $this->eventRepository,
            $this->streamRepository,
            $this->userRepository,
            $this->widgetRepository,
            $this->messages,
            $this->apiWrapper,
            $this->accessTokenRepository,
            $this->authorizationCodeRepository,
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
    // Helpers
    // =====================================================================

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


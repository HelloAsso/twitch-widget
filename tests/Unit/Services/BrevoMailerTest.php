<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\BrevoMailer;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests de l'envoi d'emails via l'API Brevo (client HTTP simulé).
 */
class BrevoMailerTest extends TestCase
{
    /** @var array<int, array{request: Request, response: ?Response}> */
    private array $history = [];

    private function mailer(MockHandler $handler, string $apiKey = 'xkeysib-test'): BrevoMailer
    {
        $this->history = [];
        $stack = HandlerStack::create($handler);
        $stack->push(Middleware::history($this->history));

        return new BrevoMailer(new Client(['handler' => $stack]), $apiKey, 'contact@helloasso.com', 'HelloAsso');
    }

    public function testSendPostsTransactionalEmailToBrevo(): void
    {
        $mailer = $this->mailer(new MockHandler([
            new Response(201, ['Content-Type' => 'application/json'], '{"messageId":"<abc@smtp-relay.mailin.fr>"}'),
        ]));

        $mailer->send('jane@example.com', 'Bonjour', '<p>Salut</p>');

        $this->assertCount(1, $this->history);
        /** @var Request $request */
        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.brevo.com/v3/smtp/email', (string) $request->getUri());
        $this->assertSame('xkeysib-test', $request->getHeaderLine('api-key'));
        $this->assertStringContainsString('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame([
            'sender' => ['email' => 'contact@helloasso.com', 'name' => 'HelloAsso'],
            'to' => [['email' => 'jane@example.com']],
            'subject' => 'Bonjour',
            'htmlContent' => '<p>Salut</p>',
        ], json_decode((string) $request->getBody(), true));
    }

    public function testSendThrowsWithBrevoMessageWhenApiRejectsTheRequest(): void
    {
        $mailer = $this->mailer(new MockHandler([
            new Response(401, ['Content-Type' => 'application/json'], '{"code":"unauthorized","message":"Key not found"}'),
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/HTTP 401.*Key not found/');

        $mailer->send('jane@example.com', 'Bonjour', '<p>Salut</p>');
    }

    public function testSendThrowsOnNonJsonErrorBody(): void
    {
        $mailer = $this->mailer(new MockHandler([new Response(502, [], '<html>Bad gateway</html>')]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 502');

        $mailer->send('jane@example.com', 'Bonjour', '<p>Salut</p>');
    }

    public function testSendWrapsNetworkErrors(): void
    {
        $mailer = $this->mailer(new MockHandler([
            new ConnectException('Connection timed out', new Request('POST', BrevoMailer::API_URL)),
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Connection timed out');

        $mailer->send('jane@example.com', 'Bonjour', '<p>Salut</p>');
    }

    public function testSendThrowsWithoutCallingApiWhenKeyIsMissing(): void
    {
        $mailer = $this->mailer(new MockHandler([new Response(201)]), apiKey: '');

        try {
            $mailer->send('jane@example.com', 'Bonjour', '<p>Salut</p>');
            $this->fail('Une RuntimeException était attendue.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('BREVO_API_KEY', $e->getMessage());
        }

        $this->assertCount(0, $this->history);
    }
}

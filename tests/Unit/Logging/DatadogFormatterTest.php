<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use App\Logging\DatadogFormatter;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DatadogFormatterTest extends TestCase
{
    private function record(
        Level $level = Level::Error,
        string $message = 'Quelque chose a cassé',
        array $context = [],
        array $extra = [],
    ): LogRecord {
        return new LogRecord(
            new DateTimeImmutable('2026-11-23 14:05:09.123456', new \DateTimeZone('Europe/Paris')),
            'app',
            $level,
            $message,
            $context,
            $extra,
        );
    }

    private function decode(string $line): array
    {
        $this->assertStringNotContainsString("\n", $line, 'Un log doit tenir sur une seule ligne');
        $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);

        return $data;
    }

    public function testSendsDatadogReservedAttributes(): void
    {
        $formatter = new DatadogFormatter('twitch-widget', 'prod', 'abc123');

        $data = $this->decode($formatter->format($this->record()));

        $this->assertSame('2026-11-23T14:05:09.123+01:00', $data['date']);
        $this->assertSame('error', $data['status']);
        $this->assertSame('Quelque chose a cassé', $data['message']);
        $this->assertSame(['name' => 'app'], $data['logger']);
        $this->assertSame('twitch-widget', $data['service']);
        $this->assertSame('prod', $data['env']);
        $this->assertSame('abc123', $data['version']);
    }

    public function testStatusIsTheLowercaseLevelName(): void
    {
        $formatter = new DatadogFormatter('twitch-widget');

        foreach ([
            [Level::Debug, 'debug'],
            [Level::Info, 'info'],
            [Level::Notice, 'notice'],
            [Level::Warning, 'warning'],
            [Level::Error, 'error'],
            [Level::Critical, 'critical'],
            [Level::Alert, 'alert'],
            [Level::Emergency, 'emergency'],
        ] as [$level, $status]) {
            $this->assertSame($status, $this->decode($formatter->format($this->record($level)))['status']);
        }
    }

    public function testOmitsEnvVersionContextAndExtraWhenEmpty(): void
    {
        $formatter = new DatadogFormatter('twitch-widget', '', null);

        $data = $this->decode($formatter->format($this->record()));

        $this->assertArrayNotHasKey('env', $data);
        $this->assertArrayNotHasKey('version', $data);
        $this->assertArrayNotHasKey('context', $data);
        $this->assertArrayNotHasKey('extra', $data);
        $this->assertArrayNotHasKey('error', $data);
    }

    public function testKeepsContextAndExtraUnderTheirOwnKeys(): void
    {
        $formatter = new DatadogFormatter('twitch-widget');

        // Un champ de contexte nommé comme un attribut réservé ne doit pas l'écraser
        $data = $this->decode($formatter->format($this->record(
            Level::Warning,
            'Rate limit',
            ['status' => 429, 'stream_guid' => 'abc'],
            ['request_id' => 'r-1'],
        )));

        $this->assertSame('warning', $data['status']);
        $this->assertSame(['status' => 429, 'stream_guid' => 'abc'], $data['context']);
        $this->assertSame(['request_id' => 'r-1'], $data['extra']);
    }

    public function testMapsExceptionToErrorAttributes(): void
    {
        $formatter = new DatadogFormatter('twitch-widget');
        $exception = new RuntimeException('API HelloAsso injoignable', 502, new \LogicException('cause'));

        $data = $this->decode($formatter->format($this->record(
            Level::Error,
            'Erreur lors du fetch du widget',
            ['widget' => 'stream_alert', 'exception' => $exception],
        )));

        $this->assertSame(RuntimeException::class, $data['error']['kind']);
        $this->assertSame('API HelloAsso injoignable', $data['error']['message']);
        $this->assertStringContainsString('RuntimeException: API HelloAsso injoignable', $data['error']['stack']);
        $this->assertStringContainsString('LogicException: cause', $data['error']['stack'], 'La cause doit apparaître dans la pile');
        $this->assertSame(['widget' => 'stream_alert'], $data['context'], "L'exception ne doit pas être dupliquée dans le contexte");
    }

    public function testTruncatesVeryLongStack(): void
    {
        $formatter = new DatadogFormatter('twitch-widget');
        $exception = new RuntimeException(str_repeat('x', 10000));

        $data = $this->decode($formatter->format($this->record(
            Level::Error,
            'Boom',
            ['exception' => $exception],
        )));

        $this->assertLessThan(4100, strlen($data['error']['stack']));
        $this->assertStringEndsWith('(tronqué)', $data['error']['stack']);
        // Le message complet reste disponible dans son propre attribut
        $this->assertSame(10000, strlen($data['error']['message']));
    }

    public function testKeepsAccentsReadable(): void
    {
        $formatter = new DatadogFormatter('twitch-widget');

        $line = $formatter->format($this->record(Level::Info, 'Token rafraîchi'));

        $this->assertStringContainsString('Token rafraîchi', $line);
    }

    public function testSurvivesTruncationInTheMiddleOfAMultibyteCharacter(): void
    {
        $formatter = new DatadogFormatter('twitch-widget');
        // La coupe à 4000 octets tombe au milieu d'un « é » (2 octets) si le message est assez long
        $exception = new RuntimeException(str_repeat('é', 5000));

        $line = $formatter->format($this->record(Level::Error, 'Boom', ['exception' => $exception]));

        $this->assertNotSame('', $line);
        $this->assertIsArray(json_decode($line, true), 'La ligne doit rester du JSON valide');
    }

    public function testHasNoLineLimitByDefault(): void
    {
        $formatter = new DatadogFormatter('twitch-widget');

        $data = $this->decode($formatter->format($this->record(Level::Error, str_repeat('a', 5000))));

        $this->assertSame(5000, strlen($data['message']));
    }

    public function testShrinksTheStackFirstToStayUnderTheLimit(): void
    {
        $formatter = new DatadogFormatter('twitch-widget', 'prod', 'abc123', maxLineBytes: 600);
        $exception = new RuntimeException('API HelloAsso injoignable');

        $line = $formatter->format($this->record(
            Level::Error,
            'Erreur lors du fetch du widget',
            ['widget' => 'stream_alert', 'exception' => $exception],
        ));
        $data = $this->decode($line);

        $this->assertLessThanOrEqual(600, strlen($line));
        $this->assertSame('Erreur lors du fetch du widget', $data['message'], 'Le message du log doit rester entier');
        $this->assertSame(RuntimeException::class, $data['error']['kind']);
        $this->assertSame('API HelloAsso injoignable', $data['error']['message']);
        $this->assertSame(['widget' => 'stream_alert'], $data['context']);
        $this->assertStringEndsWith('(tronqué)', $data['error']['stack']);
    }

    public function testShrinksALongMessageWhenThereIsNoStack(): void
    {
        // Cas réel : Slim met toute la pile dans le message du log
        $formatter = new DatadogFormatter('twitch-widget', null, null, maxLineBytes: 500);

        $line = $formatter->format($this->record(Level::Error, "405 Method Not Allowed\n" . str_repeat('#0 /app/x.php ', 400), ['widget' => 'w']));
        $data = $this->decode($line);

        $this->assertLessThanOrEqual(500, strlen($line));
        $this->assertStringStartsWith("405 Method Not Allowed\n#0 /app/x.php", $data['message']);
        $this->assertStringEndsWith('(tronqué)', $data['message']);
        $this->assertSame(['widget' => 'w'], $data['context']);
    }

    public function testLimitCountsBytesAndKeepsUtf8Valid(): void
    {
        $formatter = new DatadogFormatter('twitch-widget', maxLineBytes: 400);

        $line = $formatter->format($this->record(Level::Error, str_repeat('é', 3000)));

        $this->assertLessThanOrEqual(400, strlen($line));
        $this->assertTrue(mb_check_encoding($line, 'UTF-8'));
        $this->assertStringContainsString('éééé', $this->decode($line)['message']);
    }

    public function testDropsContextAndExtraWhenNothingElseCanShrink(): void
    {
        $formatter = new DatadogFormatter('twitch-widget', maxLineBytes: 300);

        $line = $formatter->format($this->record(
            Level::Error,
            'Boom',
            ['payload' => str_repeat('x', 2000)],
            ['trace' => str_repeat('y', 2000)],
        ));
        $data = $this->decode($line);

        $this->assertLessThanOrEqual(300, strlen($line));
        $this->assertSame('Boom', $data['message']);
        $this->assertArrayNotHasKey('context', $data);
        $this->assertArrayNotHasKey('extra', $data);
    }

    public function testStaysUnderTheLimitWhateverIsTooLong(): void
    {
        $formatter = new DatadogFormatter('twitch-widget', 'prod', 'abc123', maxLineBytes: 400);

        $line = $formatter->format($this->record(
            Level::Error,
            str_repeat('m', 5000),
            ['payload' => str_repeat('x', 5000), 'exception' => new RuntimeException(str_repeat('e', 5000))],
            ['trace' => str_repeat('y', 5000)],
        ));
        $data = $this->decode($line);

        $this->assertLessThanOrEqual(400, strlen($line));
        $this->assertSame('error', $data['status']);
        $this->assertSame(RuntimeException::class, $data['error']['kind']);
    }
}

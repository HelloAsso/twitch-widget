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
}

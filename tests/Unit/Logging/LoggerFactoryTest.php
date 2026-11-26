<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use App\Logging\DatadogFormatter;
use App\Logging\LoggerFactory;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use PHPUnit\Framework\TestCase;

class LoggerFactoryTest extends TestCase
{
    public function testErrorlogOutputUsesErrorLogHandlerWithDatadogFormatter(): void
    {
        $logger = LoggerFactory::create('app', 'app', null, ['LOG_OUTPUT' => 'errorlog']);

        $handlers = $logger->getHandlers();
        $this->assertCount(1, $handlers);
        $this->assertInstanceOf(ErrorLogHandler::class, $handlers[0]);
        $this->assertInstanceOf(DatadogFormatter::class, $handlers[0]->getFormatter());
        $this->assertSame('app', $logger->getName());
    }

    public function testErrorlogOutputIsCaseInsensitive(): void
    {
        $logger = LoggerFactory::create('app', 'app', null, ['LOG_OUTPUT' => 'ErrorLog']);

        $this->assertInstanceOf(ErrorLogHandler::class, $logger->getHandlers()[0]);
    }

    public function testErrorlogOutputTagsLogsWithServiceEnvAndVersion(): void
    {
        $logger = LoggerFactory::create('app', 'app', null, [
            'LOG_OUTPUT' => 'errorlog',
            'APP_ENV' => 'sandbox',
            'APP_VERSION' => 'deadbeef',
        ]);

        $line = $this->format($logger->getHandlers()[0]->getFormatter());

        $this->assertSame('twitch-widget', $line['service']);
        $this->assertSame('sandbox', $line['env']);
        $this->assertSame('deadbeef', $line['version']);
    }

    public function testDatadogVariablesTakePrecedence(): void
    {
        $logger = LoggerFactory::create('app', 'app', null, [
            'LOG_OUTPUT' => 'errorlog',
            'DD_SERVICE' => 'ha-stream',
            'DD_ENV' => 'prod',
            'DD_VERSION' => '1.2.3',
            'APP_ENV' => 'main',
            'APP_VERSION' => 'deadbeef',
        ]);

        $line = $this->format($logger->getHandlers()[0]->getFormatter());

        $this->assertSame('ha-stream', $line['service']);
        $this->assertSame('prod', $line['env']);
        $this->assertSame('1.2.3', $line['version']);
    }

    public function testEmptyDatadogVariablesFallBackToDefaults(): void
    {
        $logger = LoggerFactory::create('app', 'app', null, [
            'LOG_OUTPUT' => 'errorlog',
            'DD_SERVICE' => '',
            'DD_ENV' => '',
            'APP_ENV' => 'sandbox',
        ]);

        $line = $this->format($logger->getHandlers()[0]->getFormatter());

        $this->assertSame('twitch-widget', $line['service']);
        $this->assertSame('sandbox', $line['env']);
    }

    public function testLogLevelFromEnvironmentApplies(): void
    {
        $logger = LoggerFactory::create('app', 'app', null, ['LOG_OUTPUT' => 'errorlog', 'LOGLEVEL' => 'ERROR']);

        $this->assertSame(Level::Error, $logger->getHandlers()[0]->getLevel());
    }

    public function testExplicitLevelOverridesLogLevel(): void
    {
        $logger = LoggerFactory::create('cron', 'cron', Level::Debug, ['LOG_OUTPUT' => 'errorlog', 'LOGLEVEL' => 'ERROR']);

        $this->assertSame(Level::Debug, $logger->getHandlers()[0]->getLevel());
    }

    public function testDefaultsToDebugWhenNoLevelConfigured(): void
    {
        $logger = LoggerFactory::create('app', 'app', null, ['LOG_OUTPUT' => 'errorlog']);

        $this->assertSame(Level::Debug, $logger->getHandlers()[0]->getLevel());
    }

    public function testFileOutputIsTheDefaultAndCreatesTheLogDirectory(): void
    {
        $env = 'phpunit-' . bin2hex(random_bytes(4));
        $logDir = dirname(__DIR__, 3) . '/logs/' . $env;
        $this->assertDirectoryDoesNotExist($logDir);

        try {
            $logger = LoggerFactory::create('app', 'app', null, ['APP_ENV' => $env]);

            $handlers = $logger->getHandlers();
            $this->assertCount(1, $handlers);
            $this->assertInstanceOf(RotatingFileHandler::class, $handlers[0]);
            $this->assertDirectoryExists($logDir);
        } finally {
            if (is_dir($logDir)) {
                rmdir($logDir);
                @rmdir(dirname($logDir));
            }
        }
    }

    public function testErrorlogOutputDoesNotTouchTheFilesystem(): void
    {
        $env = 'phpunit-' . bin2hex(random_bytes(4));
        $logDir = dirname(__DIR__, 3) . '/logs/' . $env;

        LoggerFactory::create('app', 'app', null, ['LOG_OUTPUT' => 'errorlog', 'APP_ENV' => $env]);

        $this->assertDirectoryDoesNotExist($logDir);
    }

    public function testLogMaxLineFromEnvironmentLimitsTheLineSize(): void
    {
        $logger = LoggerFactory::create('app', 'app', null, ['LOG_OUTPUT' => 'errorlog', 'LOG_MAX_LINE' => '300']);

        $line = $this->formatLine($logger->getHandlers()[0]->getFormatter(), str_repeat('a', 5000));

        $this->assertLessThanOrEqual(300, strlen($line));
        $this->assertIsArray(json_decode($line, true), 'La ligne doit rester du JSON valide');
    }

    public function testLogMaxLineZeroDisablesTheLimit(): void
    {
        $logger = LoggerFactory::create('app', 'app', null, ['LOG_OUTPUT' => 'errorlog', 'LOG_MAX_LINE' => '0']);

        $line = $this->formatLine($logger->getHandlers()[0]->getFormatter(), str_repeat('a', 5000));

        $this->assertGreaterThan(5000, strlen($line));
    }

    public function testNoLineLimitInCliByDefault(): void
    {
        // Le cron tourne en CLI : rien n'y coupe la sortie, on ne tronque donc pas
        $this->assertSame('cli', PHP_SAPI);
        $logger = LoggerFactory::create('cron', 'cron', null, ['LOG_OUTPUT' => 'errorlog']);

        $line = $this->formatLine($logger->getHandlers()[0]->getFormatter(), str_repeat('a', 5000));

        $this->assertGreaterThan(5000, strlen($line));
    }

    private function formatLine(\Monolog\Formatter\FormatterInterface $formatter, string $message): string
    {
        return $formatter->format(new \Monolog\LogRecord(new \DateTimeImmutable(), 'app', Level::Error, $message));
    }

    /**
     * @return array<string, mixed>
     */
    private function format(\Monolog\Formatter\FormatterInterface $formatter): array
    {
        $record = new \Monolog\LogRecord(new \DateTimeImmutable(), 'app', Level::Error, 'm');

        return json_decode($formatter->format($record), true, 512, JSON_THROW_ON_ERROR);
    }
}

<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger;

/**
 * Construit les loggers de l'application (web, API HelloAsso, cron).
 *
 * Deux sorties, choisies par la variable d'environnement LOG_OUTPUT :
 *  - `file` (défaut) : fichiers tournants dans logs/<APP_ENV>/<nom>.log, pour le dev local ;
 *  - `errorlog` : une ligne JSON par log via error_log(), que Clever Cloud collecte puis
 *    transmet à Datadog par un drain (le disque y est éphémère, les fichiers ne servent à rien).
 */
final class LoggerFactory
{
    public const OUTPUT_FILE = 'file';
    public const OUTPUT_ERRORLOG = 'errorlog';

    public const DEFAULT_SERVICE = 'twitch-widget';

    /**
     * @param string                $channel Nom du canal Monolog (champ `logger.name` dans Datadog)
     * @param string                $name    Nom du fichier de log en sortie `file` (sans extension)
     * @param Level|int|string|null $level   Niveau minimal ; à défaut, LOGLEVEL puis DEBUG
     * @param array<string, mixed>|null $server Variables d'environnement ; à défaut, $_SERVER puis getenv()
     */
    public static function create(
        string $channel,
        string $name,
        Level|int|string|null $level = null,
        ?array $server = null,
    ): Logger {
        // $_SERVER d'abord, puis l'environnement du processus (comme CleverCloudEnv)
        $server ??= $_SERVER + getenv();
        $level ??= $server['LOGLEVEL'] ?? Level::Debug;

        $logger = new Logger($channel);

        if (strtolower((string) ($server['LOG_OUTPUT'] ?? self::OUTPUT_FILE)) === self::OUTPUT_ERRORLOG) {
            $handler = new ErrorLogHandler(ErrorLogHandler::OPERATING_SYSTEM, $level);
            $handler->setFormatter(new DatadogFormatter(
                service: self::nonEmpty($server['DD_SERVICE'] ?? null) ?? self::DEFAULT_SERVICE,
                env: self::nonEmpty($server['DD_ENV'] ?? null) ?? self::nonEmpty($server['APP_ENV'] ?? null),
                version: self::nonEmpty($server['DD_VERSION'] ?? null) ?? self::nonEmpty($server['APP_VERSION'] ?? null),
            ));
            $logger->pushHandler($handler);

            return $logger;
        }

        $logDir = dirname(__DIR__, 2) . '/logs/' . ($server['APP_ENV'] ?? 'production');
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $logger->pushHandler(new RotatingFileHandler($logDir . '/' . $name . '.log', 7, $level));

        return $logger;
    }

    private static function nonEmpty(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}

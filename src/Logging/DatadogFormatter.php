<?php

declare(strict_types=1);

namespace App\Logging;

use DateTimeInterface;
use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;
use Throwable;

/**
 * Formate chaque log en une ligne JSON que Datadog interprète sans pipeline dédié :
 * attributs réservés (date, status, message, service), `logger.name`, et `error.*`
 * pour l'exception passée dans le contexte sous la clé `exception`.
 *
 * Le niveau Monolog (int) n'est pas repris tel quel : Datadog ne comprend un niveau
 * numérique que pour les sévérités syslog (0-7), on envoie donc `status` en texte.
 */
class DatadogFormatter extends JsonFormatter
{
    /** Évite les lignes démesurées (les piles d'appels PHP sont longues). */
    private const MAX_STACK_LENGTH = 4000;

    public function __construct(
        private readonly string $service,
        private readonly ?string $env = null,
        private readonly ?string $version = null,
    ) {
        // Pas de saut de ligne final : error_log() ajoute le sien
        parent::__construct(self::BATCH_MODE_JSON, false);
    }

    public function format(LogRecord $record): string
    {
        $context = $record->context;

        $error = null;
        if (($context['exception'] ?? null) instanceof Throwable) {
            $error = $this->describeThrowable($context['exception']);
            unset($context['exception']);
        }

        $log = [
            'date' => $record->datetime->format(DateTimeInterface::RFC3339_EXTENDED),
            'status' => strtolower($record->level->getName()),
            'message' => $record->message,
            'logger' => ['name' => $record->channel],
            'service' => $this->service,
        ];

        if ($this->env !== null && $this->env !== '') {
            $log['env'] = $this->env;
        }
        if ($this->version !== null && $this->version !== '') {
            $log['version'] = $this->version;
        }
        if ($error !== null) {
            $log['error'] = $error;
        }
        if ($context !== []) {
            $log['context'] = $context;
        }
        if ($record->extra !== []) {
            $log['extra'] = $record->extra;
        }

        return $this->toJson($this->normalize($log), true) . ($this->appendNewline ? "\n" : '');
    }

    /**
     * @return array{kind: string, message: string, stack: string}
     */
    private function describeThrowable(Throwable $e): array
    {
        // (string) $e inclut le message, le fichier, la pile et les exceptions précédentes
        $stack = (string) $e;
        if (strlen($stack) > self::MAX_STACK_LENGTH) {
            $stack = substr($stack, 0, self::MAX_STACK_LENGTH) . '... (tronqué)';
        }

        return [
            'kind' => $e::class,
            'message' => $e->getMessage(),
            'stack' => $stack,
        ];
    }
}

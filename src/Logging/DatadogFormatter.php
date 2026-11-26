<?php

declare(strict_types=1);

namespace App\Logging;

use DateTimeInterface;
use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;
use Throwable;

/**
 * Formate chaque log en une ligne JSON : attributs réservés de Datadog (date, status,
 * message, service), `logger.name`, et `error.*` pour l'exception passée dans le contexte
 * sous la clé `exception`.
 *
 * Sur Clever Cloud, la plateforme enveloppe la ligne (Apache pour le web, crond pour le cron) :
 * il faut un pipeline Datadog pour en extraire le JSON avant que ces attributs soient lus.
 *
 * Le niveau Monolog (int) n'est pas repris tel quel : Datadog ne comprend un niveau
 * numérique que pour les sévérités syslog (0-7), on envoie donc `status` en texte.
 */
class DatadogFormatter extends JsonFormatter
{
    /** Évite les lignes démesurées (les piles d'appels PHP sont longues). */
    private const MAX_STACK_LENGTH = 4000;

    /** ASCII pur : ce marqueur est ajouté après le passage en texte simple. */
    private const TRUNCATION_MARKER = '... [truncated]';

    /** Translittération des caractères courants en français (le reste devient « ? »). */
    private const TRANSLITERATION = [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ú' => 'u', 'ÿ' => 'y', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae',
        'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Á' => 'A', 'Ç' => 'C', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Î' => 'I', 'Ï' => 'I', 'Í' => 'I', 'Ô' => 'O', 'Ö' => 'O', 'Ó' => 'O', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
        'Ú' => 'U', 'Ÿ' => 'Y', 'Ñ' => 'N', 'Œ' => 'OE', 'Æ' => 'AE',
        '’' => "'", '‘' => "'", '“' => "'", '”' => "'", '«' => "'", '»' => "'", '–' => '-', '—' => '-',
        '…' => '...', '€' => 'EUR', '°' => 'o', "\u{a0}" => ' ',
    ];

    /** Plancher en octets avant de sacrifier le message du log lui-même. */
    private const MIN_MESSAGE_BYTES = 200;

    /**
     * @param int|null $maxLineBytes Taille maximale d'une ligne, en octets ; null = pas de limite.
     *                               Sous PHP-FPM, le runtime coupe les messages d'error_log vers 1 Ko :
     *                               une ligne JSON plus longue arrive tronquée, donc invalide.
     * @param bool     $plain        N'émet que de l'ASCII imprimable, sans guillemet ni antislash dans les
     *                               valeurs. Sous Apache + PHP-FPM, la plateforme réécrit les messages
     *                               d'error_log (antislash doublés, octets non ASCII en \xHH) : un échappement
     *                               JSON (\n, \", é) n'y survit pas et la ligne devient illisible.
     */
    public function __construct(
        private readonly string $service,
        private readonly ?string $env = null,
        private readonly ?string $version = null,
        private readonly ?int $maxLineBytes = null,
        private readonly bool $plain = false,
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

        $log = $this->normalize($log);
        if ($this->plain) {
            $log = $this->toPlain($log);
        }
        $line = $this->toJson($log, true);
        if ($this->maxLineBytes !== null && strlen($line) > $this->maxLineBytes) {
            $line = $this->fit($log, $this->maxLineBytes);
        }

        return $line . ($this->appendNewline ? "\n" : '');
    }

    /**
     * Réduit toutes les chaînes (valeurs et clés) à de l'ASCII imprimable sans « " » ni « \ » :
     * accents translittérés, retours à la ligne remplacés par « | », guillemets par « ' »,
     * antislash par « / », tout autre caractère par « ? ».
     */
    private function toPlain(mixed $value): mixed
    {
        if (is_array($value)) {
            $plain = [];
            foreach ($value as $key => $item) {
                $plain[is_string($key) ? $this->plainString($key) : $key] = $this->toPlain($item);
            }

            return $plain;
        }

        return is_string($value) ? $this->plainString($value) : $value;
    }

    private function plainString(string $value): string
    {
        $value = strtr($value, self::TRANSLITERATION);
        $value = preg_replace('/\r\n|\r|\n/', ' | ', $value) ?? '';
        $value = strtr($value, ['"' => "'", '\\' => '/']);

        return preg_replace('/[^\x20-\x7E]+/', '?', $value) ?? '';
    }

    /**
     * Ramène la ligne sous la limite en sacrifiant d'abord ce qui sert le moins :
     * la pile, puis le message du log, puis celui de l'exception, puis `extra` et `context`.
     * Le JSON reste toujours valide (on raccourcit des valeurs, jamais la ligne encodée).
     *
     * @param array<string, mixed> $log
     */
    private function fit(array $log, int $max): string
    {
        $line = $this->toJson($log, true);

        if (isset($log['error']['stack']) && is_string($log['error']['stack']) && strlen($line) > $max) {
            $log['error']['stack'] = $this->shrink($log['error']['stack'], strlen($line) - $max, 0);
            $line = $this->toJson($log, true);
        }
        if (is_string($log['message']) && strlen($line) > $max) {
            $log['message'] = $this->shrink($log['message'], strlen($line) - $max, self::MIN_MESSAGE_BYTES);
            $line = $this->toJson($log, true);
        }
        if (isset($log['error']['message']) && is_string($log['error']['message']) && strlen($line) > $max) {
            $log['error']['message'] = $this->shrink($log['error']['message'], strlen($line) - $max, intdiv(self::MIN_MESSAGE_BYTES, 2));
            $line = $this->toJson($log, true);
        }
        foreach (['extra', 'context'] as $key) {
            if (isset($log[$key]) && strlen($line) > $max) {
                unset($log[$key]);
                $line = $this->toJson($log, true);
            }
        }
        if (is_string($log['message']) && strlen($line) > $max) {
            // Dernier recours : même le message passe sous le plancher
            $log['message'] = $this->shrink($log['message'], strlen($line) - $max, 0);
            $line = $this->toJson($log, true);
        }

        return $line;
    }

    /**
     * Retire au moins $excess octets à $value (sans couper un caractère UTF-8 en deux),
     * en en gardant au moins $min, et ajoute le marqueur de troncature.
     */
    private function shrink(string $value, int $excess, int $min): string
    {
        $keep = max($min, strlen($value) - $excess - strlen(self::TRUNCATION_MARKER));
        if ($keep >= strlen($value)) {
            return $value;
        }

        return mb_strcut($value, 0, $keep, 'UTF-8') . self::TRUNCATION_MARKER;
    }

    /**
     * @return array{kind: string, message: string, stack: string}
     */
    private function describeThrowable(Throwable $e): array
    {
        // (string) $e inclut le message, le fichier, la pile et les exceptions précédentes
        $stack = (string) $e;
        if (strlen($stack) > self::MAX_STACK_LENGTH) {
            $stack = substr($stack, 0, self::MAX_STACK_LENGTH) . self::TRUNCATION_MARKER;
        }

        return [
            'kind' => $e::class,
            'message' => $e->getMessage(),
            'stack' => $stack,
        ];
    }
}

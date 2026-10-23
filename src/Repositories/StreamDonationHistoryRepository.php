<?php

namespace App\Repositories;

use App\Models\StreamDonationEntry;
use DateTime;
use Exception;
use PDO;

class StreamDonationHistoryRepository
{
    // Taille d'une page de l'historique (scroll infini), transmise au JS par la vue
    public const PAGE_SIZE = 30;
    // Nombre max de dons renvoyés par un poll : le client relance tant que la réponse est pleine
    public const POLL_LIMIT = 100;

    // Bornes des colonnes pseudo (VARCHAR(255)) et message (TEXT)
    private const MAX_PSEUDO_LENGTH = 255;
    private const MAX_MESSAGE_LENGTH = 5000;

    public function __construct(
        private PDO $pdo,
        private string $prefix
    ) {}

    /**
     * Insère un lot de dons capturés pendant une session de live.
     * Chaque don est un tableau ['pseudo' => ..., 'message' => ..., 'amount' => ...].
     * Pseudo et message sont tronqués à la taille des colonnes : une valeur trop longue
     * ferait échouer tout le lot et bloquerait le curseur du live.
     */
    public function insertMany(string $streamGuid, array $donations): void
    {
        if (empty($donations)) {
            return;
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO ' . $this->prefix . 'stream_donation_history (charity_stream_guid, pseudo, message, amount, received_at)
            VALUES (?, ?, ?, ?, ?)
        ');

        // received_at est posé côté PHP (et non par le DEFAULT MySQL) pour rester dans
        // le même fuseau que live_started_at / live_cache_updated_at
        $receivedAt = (new DateTime())->format('Y-m-d H:i:s.u');

        $this->pdo->beginTransaction();

        try {
            foreach ($donations as $donation) {
                $pseudo = $this->toText($donation['pseudo'] ?? null, self::MAX_PSEUDO_LENGTH);
                $message = $this->toText($donation['message'] ?? null, self::MAX_MESSAGE_LENGTH);

                $stmt->execute([
                    $streamGuid,
                    $pseudo !== '' ? $pseudo : 'anonyme',
                    $message !== '' ? $message : null,
                    (int) ($donation['amount'] ?? 0),
                    $receivedAt,
                ]);
            }
            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function toText(mixed $value, int $maxLength): string
    {
        return is_scalar($value) ? mb_substr(trim((string) $value), 0, $maxLength) : '';
    }

    /**
     * Page de l'historique en pagination par curseur (id décroissant, du plus récent au plus ancien).
     */
    public function selectPage(string $streamGuid, ?int $beforeId, int $limit = self::PAGE_SIZE): array
    {
        $sql = '
            SELECT *
            FROM ' . $this->prefix . 'stream_donation_history
            WHERE charity_stream_guid = ?
        ';
        $params = [$streamGuid];

        if ($beforeId !== null) {
            $sql .= ' AND id < ?';
            $params[] = $beforeId;
        }

        $sql .= ' ORDER BY id DESC LIMIT ' . (int) $limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->setFetchMode(PDO::FETCH_CLASS, StreamDonationEntry::class);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Dons arrivés après un id donné (id croissant), pour le polling temps réel.
     * Borné à $limit : si le client a du retard, il rattrape par lots successifs.
     */
    public function selectSince(string $streamGuid, int $afterId, int $limit = self::POLL_LIMIT): array
    {
        $stmt = $this->pdo->prepare('
            SELECT *
            FROM ' . $this->prefix . 'stream_donation_history
            WHERE charity_stream_guid = ?
            AND id > ?
            ORDER BY id ASC
            LIMIT ' . (int) $limit . '
        ');
        $stmt->setFetchMode(PDO::FETCH_CLASS, StreamDonationEntry::class);
        $stmt->execute([$streamGuid, $afterId]);
        return $stmt->fetchAll();
    }

    public function deleteByStreamGuid(string $streamGuid): void
    {
        $stmt = $this->pdo->prepare('
            DELETE FROM ' . $this->prefix . 'stream_donation_history
            WHERE charity_stream_guid = ?
        ');
        $stmt->execute([$streamGuid]);
    }
}

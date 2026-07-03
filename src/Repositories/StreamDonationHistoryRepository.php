<?php

namespace App\Repositories;

use App\Models\StreamDonationEntry;
use Exception;
use PDO;

class StreamDonationHistoryRepository
{
    public function __construct(
        private PDO $pdo,
        private string $prefix
    ) {}

    /**
     * Insère un lot de dons capturés pendant une session de live.
     * Chaque don est un tableau ['pseudo' => ..., 'message' => ..., 'amount' => ...].
     */
    public function insertMany(string $streamGuid, array $donations): void
    {
        if (empty($donations)) {
            return;
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO ' . $this->prefix . 'stream_donation_history (charity_stream_guid, pseudo, message, amount)
            VALUES (?, ?, ?, ?)
        ');

        $this->pdo->beginTransaction();

        try {
            foreach ($donations as $donation) {
                $stmt->execute([
                    $streamGuid,
                    $donation['pseudo'] ?? 'anonyme',
                    $donation['message'] ?? null,
                    (int) ($donation['amount'] ?? 0),
                ]);
            }
            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Page de l'historique en pagination par curseur (id décroissant, du plus récent au plus ancien).
     */
    public function selectPage(string $streamGuid, ?int $beforeId, int $limit): array
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
     */
    public function selectSince(string $streamGuid, int $afterId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT *
            FROM ' . $this->prefix . 'stream_donation_history
            WHERE charity_stream_guid = ?
            AND id > ?
            ORDER BY id ASC
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

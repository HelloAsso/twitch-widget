<?php

namespace App\Repositories;

use Exception;
use PDO;

class GoalRepository
{
    public function __construct(
        private PDO $pdo,
        private string $prefix
    ) {}

    public function selectAmountsByStreamGuid(string $guid): array
    {
        $stmt = $this->pdo->prepare('
            SELECT amount FROM ' . $this->prefix . 'goals
            WHERE charity_stream_guid = ?
            ORDER BY amount ASC
        ');
        $stmt->execute([$guid]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function selectAmountsByEventGuid(string $guid): array
    {
        $stmt = $this->pdo->prepare('
            SELECT amount FROM ' . $this->prefix . 'goals
            WHERE charity_event_guid = ?
            ORDER BY amount ASC
        ');
        $stmt->execute([$guid]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function replaceForStream(string $streamGuid, array $amounts): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('DELETE FROM ' . $this->prefix . 'goals WHERE charity_stream_guid = ?');
            $stmt->execute([$streamGuid]);

            $stmt = $this->pdo->prepare('INSERT INTO ' . $this->prefix . 'goals (charity_stream_guid, amount) VALUES (?, ?)');
            foreach ($amounts as $amount) {
                $stmt->execute([$streamGuid, $amount]);
            }
            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function replaceForEvent(string $eventGuid, array $amounts): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('DELETE FROM ' . $this->prefix . 'goals WHERE charity_event_guid = ?');
            $stmt->execute([$eventGuid]);

            $stmt = $this->pdo->prepare('INSERT INTO ' . $this->prefix . 'goals (charity_event_guid, amount) VALUES (?, ?)');
            foreach ($amounts as $amount) {
                $stmt->execute([$eventGuid, $amount]);
            }
            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function deleteByStreamGuid(string $guid): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . $this->prefix . 'goals WHERE charity_stream_guid = ?');
        $stmt->execute([$guid]);
    }

    public function deleteByEventGuid(string $guid): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . $this->prefix . 'goals WHERE charity_event_guid = ?');
        $stmt->execute([$guid]);
    }
}

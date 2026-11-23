<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Models\Stream;
use App\Repositories\StreamRepository;
use DateTime;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests des opérations du live sur les streams (verrou de rafraîchissement, suppression avec historique).
 * Utilise SQLite en mémoire pour éviter toute dépendance à MySQL.
 */
class StreamRepositoryLiveTest extends TestCase
{
    private PDO $pdo;
    private StreamRepository $repository;
    private string $prefix = 'test_';

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec("CREATE TABLE `{$this->prefix}charity_stream` (
            id                      INTEGER PRIMARY KEY AUTOINCREMENT,
            guid                    TEXT NOT NULL,
            live_started_at         TEXT NULL,
            live_continuation_token TEXT NULL,
            live_cache_updated_at   TEXT NULL
        )");
        foreach (['goals', 'widget_donation_goal_bar', 'widget_alert_box', 'widget_card', 'stream_donation_history'] as $table) {
            $this->pdo->exec("CREATE TABLE `{$this->prefix}{$table}` (id INTEGER PRIMARY KEY AUTOINCREMENT, charity_stream_guid TEXT NOT NULL)");
        }
        $this->pdo->exec("CREATE TABLE `{$this->prefix}user_right` (id INTEGER PRIMARY KEY AUTOINCREMENT, id_charity_stream INTEGER NULL)");

        $this->repository = new StreamRepository($this->pdo, $this->prefix);
    }

    // =====================================================================
    // Verrou de rafraîchissement du live
    // =====================================================================

    public function testClaimLiveRefreshSucceedsWhenNeverRefreshed(): void
    {
        $stream = $this->insertStream('guid-a', liveStartedAt: '2026-01-01 10:00:00.000000', cacheUpdatedAt: null);

        $this->assertTrue($this->repository->claimLiveRefresh($stream, 30));
    }

    public function testClaimLiveRefreshSucceedsWhenCacheIsStale(): void
    {
        $stale = (new DateTime())->modify('-60 seconds')->format('Y-m-d H:i:s.u');
        $stream = $this->insertStream('guid-a', liveStartedAt: '2026-01-01 10:00:00.000000', cacheUpdatedAt: $stale);

        $this->assertTrue($this->repository->claimLiveRefresh($stream, 30));
    }

    public function testClaimLiveRefreshFailsWhenCacheIsFresh(): void
    {
        $fresh = (new DateTime())->modify('-5 seconds')->format('Y-m-d H:i:s.u');
        $stream = $this->insertStream('guid-a', liveStartedAt: '2026-01-01 10:00:00.000000', cacheUpdatedAt: $fresh);

        $this->assertFalse($this->repository->claimLiveRefresh($stream, 30));
    }

    public function testClaimLiveRefreshIsGrantedToOnlyOneOfTwoConcurrentPolls(): void
    {
        $stream = $this->insertStream('guid-a', liveStartedAt: '2026-01-01 10:00:00.000000', cacheUpdatedAt: null);

        // Deux requêtes ont lu le même état périmé : seule la première réserve le rafraîchissement
        $first = $this->repository->claimLiveRefresh($stream, 30);
        $second = $this->repository->claimLiveRefresh($stream, 30);

        $this->assertTrue($first);
        $this->assertFalse($second);
    }

    public function testClaimLiveRefreshFailsWhenLiveIsNotStarted(): void
    {
        $stream = $this->insertStream('guid-a', liveStartedAt: null, cacheUpdatedAt: null);

        $this->assertFalse($this->repository->claimLiveRefresh($stream, 30));
    }

    // =====================================================================
    // Suppression d'un stream
    // =====================================================================

    public function testDeleteRemovesLiveDonationHistory(): void
    {
        $stream = $this->insertStream('guid-a', liveStartedAt: '2026-01-01 10:00:00.000000', cacheUpdatedAt: null);
        $other = $this->insertStream('guid-b', liveStartedAt: '2026-01-01 10:00:00.000000', cacheUpdatedAt: null);
        $this->pdo->exec("INSERT INTO `{$this->prefix}stream_donation_history` (charity_stream_guid) VALUES ('guid-a'), ('guid-a'), ('guid-b')");

        $this->repository->delete($stream);

        $remaining = $this->pdo->query("SELECT charity_stream_guid FROM `{$this->prefix}stream_donation_history`")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['guid-b'], $remaining);
        $this->assertSame(
            [(string) $other->id],
            array_map('strval', $this->pdo->query("SELECT id FROM `{$this->prefix}charity_stream`")->fetchAll(PDO::FETCH_COLUMN))
        );
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function insertStream(string $guid, ?string $liveStartedAt, ?string $cacheUpdatedAt): Stream
    {
        $stmt = $this->pdo->prepare("INSERT INTO `{$this->prefix}charity_stream` (guid, live_started_at, live_cache_updated_at) VALUES (?, ?, ?)");
        $stmt->execute([$guid, $liveStartedAt, $cacheUpdatedAt]);

        $stream = new Stream();
        $stream->id = (int) $this->pdo->lastInsertId();
        $stream->guid = $guid;
        return $stream;
    }
}

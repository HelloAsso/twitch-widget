<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Repositories\StreamDonationHistoryRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests de l'historique des dons d'une session de live.
 * Utilise SQLite en mémoire pour éviter toute dépendance à MySQL.
 */
class StreamDonationHistoryRepositoryTest extends TestCase
{
    private PDO $pdo;
    private StreamDonationHistoryRepository $repository;
    private string $prefix = 'test_';

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec("CREATE TABLE `{$this->prefix}stream_donation_history` (
            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
            charity_stream_guid TEXT    NOT NULL,
            pseudo              TEXT    NOT NULL,
            message             TEXT    NULL,
            amount              INTEGER NOT NULL,
            received_at         TEXT    NOT NULL
        )");

        $this->repository = new StreamDonationHistoryRepository($this->pdo, $this->prefix);
    }

    // =====================================================================
    // Insertion
    // =====================================================================

    public function testInsertManyStoresDonationsInOrder(): void
    {
        $this->repository->insertMany('guid-a', [
            ['pseudo' => 'Alice', 'message' => 'Bravo', 'amount' => 500],
            ['pseudo' => 'Bob', 'message' => '', 'amount' => 1000],
        ]);

        $entries = $this->repository->selectSince('guid-a', 0);

        $this->assertCount(2, $entries);
        $this->assertSame('Alice', $entries[0]->pseudo);
        $this->assertSame('Bravo', $entries[0]->message);
        $this->assertSame(500, (int) $entries[0]->amount);
        $this->assertSame('Bob', $entries[1]->pseudo);
    }

    public function testInsertManyStoresEmptyMessageAsNull(): void
    {
        $this->repository->insertMany('guid-a', [['pseudo' => 'Bob', 'message' => '', 'amount' => 100]]);

        $this->assertNull($this->repository->selectSince('guid-a', 0)[0]->message);
    }

    public function testInsertManyFallsBackToAnonymousPseudo(): void
    {
        $this->repository->insertMany('guid-a', [
            ['amount' => 100],
            ['pseudo' => '   ', 'amount' => 200],
            ['pseudo' => ['not', 'a', 'string'], 'amount' => 300],
        ]);

        foreach ($this->repository->selectSince('guid-a', 0) as $entry) {
            $this->assertSame('anonyme', $entry->pseudo);
        }
    }

    public function testInsertManyTruncatesOversizedPseudoAndMessage(): void
    {
        $this->repository->insertMany('guid-a', [[
            'pseudo' => str_repeat('é', 400),
            'message' => str_repeat('x', 9000),
            'amount' => 100,
        ]]);

        $entry = $this->repository->selectSince('guid-a', 0)[0];

        $this->assertSame(255, mb_strlen($entry->pseudo));
        $this->assertSame(5000, mb_strlen($entry->message));
    }

    public function testInsertManySetsReceivedAtWithTimezoneAwareFormat(): void
    {
        $this->repository->insertMany('guid-a', [['pseudo' => 'Alice', 'amount' => 100]]);

        $receivedAt = $this->repository->selectSince('guid-a', 0)[0]->received_at;

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/', $receivedAt);
    }

    public function testInsertManyWithNoDonationsDoesNothing(): void
    {
        $this->repository->insertMany('guid-a', []);

        $this->assertSame([], $this->repository->selectSince('guid-a', 0));
    }

    public function testInsertManyIsAllOrNothing(): void
    {
        // Un trigger fait échouer l'insertion de la 2e ligne : la 1re ne doit pas rester en base
        $this->pdo->exec("CREATE TRIGGER fail_on_bob BEFORE INSERT ON `{$this->prefix}stream_donation_history`
            WHEN NEW.pseudo = 'Bob' BEGIN SELECT RAISE(ABORT, 'boom'); END");

        try {
            $this->repository->insertMany('guid-a', [
                ['pseudo' => 'Alice', 'amount' => 100],
                ['pseudo' => 'Bob', 'amount' => 200],
            ]);
            $this->fail('Une exception était attendue');
        } catch (\Exception $e) {
            $this->assertSame([], $this->repository->selectSince('guid-a', 0));
        }
    }

    // =====================================================================
    // Pagination par curseur (scroll infini)
    // =====================================================================

    public function testSelectPageReturnsNewestFirstAndRespectsLimit(): void
    {
        $this->insertDonations('guid-a', 5);

        $page = $this->repository->selectPage('guid-a', null, 3);

        $this->assertSame(['d5', 'd4', 'd3'], array_column($this->toArray($page), 'pseudo'));
    }

    public function testSelectPageWithCursorReturnsOlderEntriesOnly(): void
    {
        $this->insertDonations('guid-a', 5);
        $newest = $this->repository->selectPage('guid-a', null, 5);
        $cursor = (int) $newest[2]->id; // d3

        $page = $this->repository->selectPage('guid-a', $cursor, 10);

        $this->assertSame(['d2', 'd1'], array_column($this->toArray($page), 'pseudo'));
    }

    public function testSelectPageDefaultsToSharedPageSize(): void
    {
        $this->insertDonations('guid-a', StreamDonationHistoryRepository::PAGE_SIZE + 5);

        $this->assertCount(StreamDonationHistoryRepository::PAGE_SIZE, $this->repository->selectPage('guid-a', null));
    }

    public function testSelectPageIsScopedToStream(): void
    {
        $this->insertDonations('guid-a', 2);
        $this->insertDonations('guid-b', 3);

        $this->assertCount(2, $this->repository->selectPage('guid-a', null));
    }

    // =====================================================================
    // Polling des nouveaux dons
    // =====================================================================

    public function testSelectSinceReturnsOnlyNewerEntriesInAscendingOrder(): void
    {
        $this->insertDonations('guid-a', 4);
        $afterId = (int) $this->repository->selectSince('guid-a', 0)[1]->id; // d2

        $entries = $this->repository->selectSince('guid-a', $afterId);

        $this->assertSame(['d3', 'd4'], array_column($this->toArray($entries), 'pseudo'));
    }

    public function testSelectSinceIsBoundedByLimit(): void
    {
        $this->insertDonations('guid-a', 10);

        $entries = $this->repository->selectSince('guid-a', 0, 4);

        // Les plus anciens d'abord : le client rattrape son retard lot après lot sans trou
        $this->assertSame(['d1', 'd2', 'd3', 'd4'], array_column($this->toArray($entries), 'pseudo'));
    }

    public function testSelectSinceDefaultsToSharedPollLimit(): void
    {
        $this->insertDonations('guid-a', StreamDonationHistoryRepository::POLL_LIMIT + 5);

        $this->assertCount(StreamDonationHistoryRepository::POLL_LIMIT, $this->repository->selectSince('guid-a', 0));
    }

    // =====================================================================
    // Purge
    // =====================================================================

    public function testDeleteByStreamGuidOnlyRemovesThatStream(): void
    {
        $this->insertDonations('guid-a', 2);
        $this->insertDonations('guid-b', 3);

        $this->repository->deleteByStreamGuid('guid-a');

        $this->assertCount(0, $this->repository->selectSince('guid-a', 0));
        $this->assertCount(3, $this->repository->selectSince('guid-b', 0));
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function insertDonations(string $guid, int $count): void
    {
        $donations = [];
        for ($i = 1; $i <= $count; $i++) {
            $donations[] = ['pseudo' => 'd' . $i, 'message' => null, 'amount' => $i * 100];
        }
        $this->repository->insertMany($guid, $donations);
    }

    private function toArray(array $entries): array
    {
        return array_map(fn($entry) => (array) $entry, $entries);
    }
}

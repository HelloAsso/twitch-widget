<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Models\User;
use App\Repositories\StreamRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests des listes de streams du dashboard.
 * Utilise SQLite en mémoire pour éviter toute dépendance à MySQL.
 */
class StreamRepositoryTest extends TestCase
{
    private PDO $pdo;
    private StreamRepository $repository;
    private string $prefix = 'test_';

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec("CREATE TABLE {$this->prefix}users (
            id    INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL
        )");
        $this->pdo->exec("CREATE TABLE {$this->prefix}charity_event (
            id INTEGER PRIMARY KEY AUTOINCREMENT
        )");
        $this->pdo->exec("CREATE TABLE {$this->prefix}charity_stream (
            id                INTEGER PRIMARY KEY AUTOINCREMENT,
            charity_event_id  INTEGER DEFAULT NULL,
            guid              TEXT,
            title             TEXT,
            form_slug         TEXT,
            form_type         TEXT DEFAULT 'Donation',
            organization_slug TEXT,
            creation_date     DATETIME,
            last_update       DATETIME,
            is_test_mode      INTEGER DEFAULT 0,
            test_amount       INTEGER DEFAULT 0
        )");
        $this->pdo->exec("CREATE TABLE {$this->prefix}user_right (
            id                INTEGER PRIMARY KEY AUTOINCREMENT,
            id_user           INTEGER NOT NULL,
            id_charity_event  INTEGER DEFAULT NULL,
            id_charity_stream INTEGER DEFAULT NULL,
            is_owner          INTEGER NOT NULL DEFAULT 0
        )");

        $this->repository = new StreamRepository($this->pdo, $this->prefix);
    }

    // =====================================================================
    // selectList() : une ligne par stream, quel que soit le nombre d'admins
    // =====================================================================

    public function testSelectListReturnsOneRowForStreamWithSeveralAdmins(): void
    {
        $streamId = $this->insertStream('2026-10-21 18:23:00');
        $owner = $this->insertUser('zoe@example.org');
        $other = $this->insertUser('alice@example.org');
        $this->insertStreamRight($owner, $streamId, true);
        $this->insertStreamRight($other, $streamId, false);

        $streams = $this->repository->selectList();

        $this->assertCount(1, $streams);
        $this->assertSame($streamId, (int) $streams[0]->id);
    }

    public function testSelectListConcatenatesAdminsWithOwnerFirst(): void
    {
        $streamId = $this->insertStream('2026-10-21 18:23:00');
        $this->insertStreamRight($this->insertUser('alice@example.org'), $streamId, false);
        $this->insertStreamRight($this->insertUser('zoe@example.org'), $streamId, true);
        $this->insertStreamRight($this->insertUser('bob@example.org'), $streamId, false);

        $streams = $this->repository->selectList();

        $this->assertSame('zoe@example.org, alice@example.org, bob@example.org', $streams[0]->admin);
    }

    public function testSelectListKeepsAdminOfStreamWithSingleAdmin(): void
    {
        $streamId = $this->insertStream('2026-10-21 18:23:00');
        $this->insertStreamRight($this->insertUser('solo@example.org'), $streamId, true);

        $streams = $this->repository->selectList();

        $this->assertCount(1, $streams);
        $this->assertSame('solo@example.org', $streams[0]->admin);
    }

    public function testSelectListKeepsStreamWithoutAnyRight(): void
    {
        $streamId = $this->insertStream('2026-10-21 18:23:00');

        $streams = $this->repository->selectList();

        $this->assertCount(1, $streams);
        $this->assertSame($streamId, (int) $streams[0]->id);
        $this->assertNull($streams[0]->admin);
    }

    public function testSelectListIgnoresRightsOnEvents(): void
    {
        $streamId = $this->insertStream('2026-10-21 18:23:00');
        $this->insertStreamRight($this->insertUser('stream-admin@example.org'), $streamId, true);
        $this->pdo->exec("INSERT INTO {$this->prefix}charity_event (id) VALUES (1)");
        $this->insertEventRight($this->insertUser('event-admin@example.org'), 1);

        $streams = $this->repository->selectList();

        $this->assertCount(1, $streams);
        $this->assertSame('stream-admin@example.org', $streams[0]->admin);
    }

    public function testSelectListOrdersByCreationDateDescWithoutDuplicates(): void
    {
        $oldest = $this->insertStream('2026-01-01 10:00:00');
        $middle = $this->insertStream('2026-05-01 10:00:00');
        $newest = $this->insertStream('2026-10-01 10:00:00');
        foreach ([$oldest => 2, $middle => 1, $newest => 3] as $streamId => $adminCount) {
            for ($i = 1; $i <= $adminCount; $i++) {
                $this->insertStreamRight($this->insertUser("admin{$streamId}_{$i}@example.org"), $streamId, $i === 1);
            }
        }

        $streams = $this->repository->selectList();

        $this->assertSame([$newest, $middle, $oldest], array_map(fn($s) => (int) $s->id, $streams));
    }

    // =====================================================================
    // selectListByUser() : non régression
    // =====================================================================

    public function testSelectListByUserReturnsStreamOnceEvenWithOtherAdmins(): void
    {
        $streamId = $this->insertStream('2026-10-21 18:23:00');
        $alice = $this->insertUser('alice@example.org');
        $this->insertStreamRight($alice, $streamId, false);
        $this->insertStreamRight($this->insertUser('bob@example.org'), $streamId, true);

        $streams = $this->repository->selectListByUser($this->userWithId($alice));

        $this->assertCount(1, $streams);
        $this->assertSame($streamId, (int) $streams[0]->id);
    }

    public function testSelectListByUserReturnsStreamOnceWhenRightComesFromStreamAndEvent(): void
    {
        $eventId = 1;
        $this->pdo->exec("INSERT INTO {$this->prefix}charity_event (id) VALUES ({$eventId})");
        $streamId = $this->insertStream('2026-10-21 18:23:00', $eventId);
        $alice = $this->insertUser('alice@example.org');
        $this->insertStreamRight($alice, $streamId, false);
        $this->insertEventRight($alice, $eventId);

        $streams = $this->repository->selectListByUser($this->userWithId($alice));

        $this->assertCount(1, $streams);
    }

    public function testSelectListByUserDoesNotReturnStreamsOfOthers(): void
    {
        $mine = $this->insertStream('2026-10-21 18:23:00');
        $theirs = $this->insertStream('2026-10-20 18:23:00');
        $alice = $this->insertUser('alice@example.org');
        $this->insertStreamRight($alice, $mine, true);
        $this->insertStreamRight($this->insertUser('bob@example.org'), $theirs, true);

        $streams = $this->repository->selectListByUser($this->userWithId($alice));

        $this->assertSame([$mine], array_map(fn($s) => (int) $s->id, $streams));
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function insertUser(string $email): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO {$this->prefix}users (email) VALUES (?)");
        $stmt->execute([$email]);
        return (int) $this->pdo->lastInsertId();
    }

    private function insertStream(string $creationDate, ?int $eventId = null): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO {$this->prefix}charity_stream (guid, title, form_slug, organization_slug, charity_event_id, creation_date)
            VALUES (?, 'Stream', 'form', 'org', ?, ?)");
        $stmt->execute([bin2hex(random_bytes(8)), $eventId, $creationDate]);
        return (int) $this->pdo->lastInsertId();
    }

    private function insertStreamRight(int $userId, int $streamId, bool $isOwner): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO {$this->prefix}user_right (id_user, id_charity_stream, is_owner) VALUES (?, ?, ?)");
        $stmt->execute([$userId, $streamId, (int) $isOwner]);
    }

    private function insertEventRight(int $userId, int $eventId): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO {$this->prefix}user_right (id_user, id_charity_event, is_owner) VALUES (?, ?, 0)");
        $stmt->execute([$userId, $eventId]);
    }

    private function userWithId(int $id): User
    {
        $user = new User();
        $user->id = $id;
        return $user;
    }
}

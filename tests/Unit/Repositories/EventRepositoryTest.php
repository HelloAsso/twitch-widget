<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Models\User;
use App\Repositories\EventRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests des listes d'évènements du dashboard.
 * Utilise SQLite en mémoire pour éviter toute dépendance à MySQL.
 */
class EventRepositoryTest extends TestCase
{
    private PDO $pdo;
    private EventRepository $repository;
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
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            guid          TEXT,
            title         TEXT,
            creation_date DATETIME,
            last_update   DATETIME,
            is_test_mode  INTEGER DEFAULT 0,
            test_amount   INTEGER DEFAULT 0
        )");
        $this->pdo->exec("CREATE TABLE {$this->prefix}user_right (
            id                INTEGER PRIMARY KEY AUTOINCREMENT,
            id_user           INTEGER NOT NULL,
            id_charity_event  INTEGER DEFAULT NULL,
            id_charity_stream INTEGER DEFAULT NULL,
            is_owner          INTEGER NOT NULL DEFAULT 0
        )");

        $this->repository = new EventRepository($this->pdo, $this->prefix);
    }

    // =====================================================================
    // selectList() : une ligne par évènement
    // =====================================================================

    public function testSelectListReturnsOneRowForEventWithOwnerAndOtherAdmins(): void
    {
        $eventId = $this->insertEvent('2026-10-21 18:23:00');
        $this->insertEventRight($this->insertUser('owner@example.org'), $eventId, true);
        $this->insertEventRight($this->insertUser('admin1@example.org'), $eventId, false);
        $this->insertEventRight($this->insertUser('admin2@example.org'), $eventId, false);

        $events = $this->repository->selectList();

        $this->assertCount(1, $events);
        $this->assertSame('owner@example.org', $events[0]->admin);
    }

    public function testSelectListReturnsOneRowForEventWithSeveralOwners(): void
    {
        $eventId = $this->insertEvent('2026-10-21 18:23:00');
        $this->insertEventRight($this->insertUser('zoe@example.org'), $eventId, true);
        $this->insertEventRight($this->insertUser('alice@example.org'), $eventId, true);

        $events = $this->repository->selectList();

        $this->assertCount(1, $events);
        $this->assertSame('alice@example.org, zoe@example.org', $events[0]->admin);
    }

    public function testSelectListKeepsEventWithoutOwner(): void
    {
        $eventId = $this->insertEvent('2026-10-21 18:23:00');
        $this->insertEventRight($this->insertUser('admin@example.org'), $eventId, false);

        $events = $this->repository->selectList();

        $this->assertCount(1, $events);
        $this->assertSame($eventId, (int) $events[0]->id);
        $this->assertNull($events[0]->admin);
    }

    public function testSelectListOrdersByCreationDateDesc(): void
    {
        $oldest = $this->insertEvent('2026-01-01 10:00:00');
        $newest = $this->insertEvent('2026-10-01 10:00:00');
        $this->insertEventRight($this->insertUser('a@example.org'), $oldest, true);
        $this->insertEventRight($this->insertUser('b@example.org'), $newest, true);
        $this->insertEventRight($this->insertUser('c@example.org'), $newest, true);

        $events = $this->repository->selectList();

        $this->assertSame([$newest, $oldest], array_map(fn($e) => (int) $e->id, $events));
    }

    // =====================================================================
    // selectListByUser() : non régression
    // =====================================================================

    public function testSelectListByUserReturnsOnlyEventsOfTheUser(): void
    {
        $mine = $this->insertEvent('2026-10-21 18:23:00');
        $theirs = $this->insertEvent('2026-10-20 18:23:00');
        $alice = $this->insertUser('alice@example.org');
        $this->insertEventRight($alice, $mine, false);
        $this->insertEventRight($this->insertUser('bob@example.org'), $mine, true);
        $this->insertEventRight($this->insertUser('carol@example.org'), $theirs, true);

        $user = new User();
        $user->id = $alice;
        $events = $this->repository->selectListByUser($user);

        $this->assertSame([$mine], array_map(fn($e) => (int) $e->id, $events));
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

    private function insertEvent(string $creationDate): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO {$this->prefix}charity_event (guid, title, creation_date) VALUES (?, 'Event', ?)");
        $stmt->execute([bin2hex(random_bytes(8)), $creationDate]);
        return (int) $this->pdo->lastInsertId();
    }

    private function insertEventRight(int $userId, int $eventId, bool $isOwner): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO {$this->prefix}user_right (id_user, id_charity_event, is_owner) VALUES (?, ?, ?)");
        $stmt->execute([$userId, $eventId, (int) $isOwner]);
    }
}

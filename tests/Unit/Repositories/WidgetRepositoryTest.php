<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Repositories\WidgetRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests des dons simulés (mode test) en attente pour le widget d'alerte.
 * Utilise SQLite en mémoire pour éviter toute dépendance à MySQL.
 */
class WidgetRepositoryTest extends TestCase
{
    private PDO $pdo;
    private WidgetRepository $repository;
    private string $prefix = 'test_';

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec("CREATE TABLE {$this->prefix}widget_alert_test_donation (
            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
            charity_stream_guid TEXT NOT NULL,
            pseudo              TEXT NOT NULL,
            message             TEXT NOT NULL DEFAULT '',
            amount              INTEGER NOT NULL,
            creation_date       TEXT NOT NULL
        )");

        $this->repository = new WidgetRepository($this->pdo, $this->prefix);
    }

    public function testSelectTestAlertDonationsReturnsDonationsOfStreamAfterCursor(): void
    {
        $alice = $this->repository->insertTestAlertDonation('stream-a', 'Alice', 'Bravo !', 1000);
        $bob = $this->repository->insertTestAlertDonation('stream-a', 'Bob', '', 250);
        $this->repository->insertTestAlertDonation('stream-b', 'Eve', 'Autre stream', 500);

        $this->assertSame([
            ['id' => $alice, 'pseudo' => 'Alice', 'message' => 'Bravo !', 'amount' => 1000],
            ['id' => $bob, 'pseudo' => 'Bob', 'message' => '', 'amount' => 250],
        ], $this->repository->selectTestAlertDonations('stream-a', 0, 120));

        $this->assertSame([
            ['id' => $bob, 'pseudo' => 'Bob', 'message' => '', 'amount' => 250],
        ], $this->repository->selectTestAlertDonations('stream-a', $alice, 120));

        $this->assertSame([], $this->repository->selectTestAlertDonations('stream-a', $bob, 120));
    }

    public function testSelectTestAlertDonationsIgnoresDonationsOlderThanMaxAge(): void
    {
        $this->insertRawDonation('stream-a', 'Ancien', 5);
        $recent = $this->repository->insertTestAlertDonation('stream-a', 'Récent', '', 100);

        $donations = $this->repository->selectTestAlertDonations('stream-a', 0, 120);

        $this->assertSame([$recent], array_column($donations, 'id'));
    }

    public function testSelectTestAlertDonationsIsLimited(): void
    {
        $first = $this->repository->insertTestAlertDonation('stream-a', 'A', '', 100);
        $second = $this->repository->insertTestAlertDonation('stream-a', 'B', '', 100);
        $this->repository->insertTestAlertDonation('stream-a', 'C', '', 100);

        $donations = $this->repository->selectTestAlertDonations('stream-a', 0, 120, 2);

        $this->assertSame([$first, $second], array_column($donations, 'id'));
    }

    public function testSelectLastTestAlertDonationIdIsScopedToStream(): void
    {
        $this->assertSame(0, $this->repository->selectLastTestAlertDonationId('stream-a'));

        $this->repository->insertTestAlertDonation('stream-a', 'Alice', '', 100);
        $last = $this->repository->insertTestAlertDonation('stream-a', 'Bob', '', 100);
        $this->repository->insertTestAlertDonation('stream-b', 'Eve', '', 100);

        $this->assertSame($last, $this->repository->selectLastTestAlertDonationId('stream-a'));
        $this->assertSame(0, $this->repository->selectLastTestAlertDonationId('stream-c'));
    }

    public function testInsertTestAlertDonationPurgesDonationsOlderThanOneHour(): void
    {
        $this->insertRawDonation('stream-a', 'Très ancien', 120);
        $this->insertRawDonation('stream-b', 'Très ancien autre stream', 61);
        $this->insertRawDonation('stream-a', 'Récent', 30);

        $this->repository->insertTestAlertDonation('stream-a', 'Nouveau', '', 100);

        $pseudos = $this->pdo
            ->query("SELECT pseudo FROM {$this->prefix}widget_alert_test_donation ORDER BY id")
            ->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['Récent', 'Nouveau'], $pseudos);
    }

    private function insertRawDonation(string $streamGuid, string $pseudo, int $minutesAgo): void
    {
        $createdAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify("-{$minutesAgo} minutes")
            ->format('Y-m-d H:i:s.u');

        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->prefix}widget_alert_test_donation (charity_stream_guid, pseudo, message, amount, creation_date)
             VALUES (?, ?, '', 100, ?)"
        );
        $stmt->execute([$streamGuid, $pseudo, $createdAt]);
    }
}

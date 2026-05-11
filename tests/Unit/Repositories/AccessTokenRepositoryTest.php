<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Models\AccessToken;
use App\Repositories\AccessTokenRepository;
use DateInterval;
use DateTime;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests du stockage et de la récupération des tokens en base de données.
 * Utilise SQLite en mémoire pour éviter toute dépendance à MySQL.
 */
class AccessTokenRepositoryTest extends TestCase
{
    private PDO $pdo;
    private AccessTokenRepository $repository;
    private string $prefix = 'test_';

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS `{$this->prefix}access_token_partner_organization` (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            access_token    TEXT    NOT NULL,
            refresh_token   TEXT    NOT NULL,
            organization_slug TEXT  DEFAULT NULL,
            access_token_expires_at  DATETIME,
            refresh_token_expires_at DATETIME,
            creation_date   DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_update     DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $this->repository = new AccessTokenRepository($this->pdo, $this->prefix);
    }

    // =====================================================================
    // Insertion d'un nouveau token
    // =====================================================================

    public function testInsertTokenAssignsId(): void
    {
        $token = $this->buildToken('access_1', 'refresh_1', 'org-a');

        $result = $this->repository->insert($token);

        $this->assertNotNull($result->id);
        $this->assertGreaterThan(0, (int) $result->id);
    }

    public function testInsertTokenPersistsAllFields(): void
    {
        $token = $this->buildToken('access_abc', 'refresh_abc', 'org-b');

        $this->repository->insert($token);
        $fetched = $this->repository->selectBySlug('org-b');

        $this->assertNotNull($fetched);
        $this->assertEquals('access_abc', $fetched->access_token);
        $this->assertEquals('refresh_abc', $fetched->refresh_token);
        $this->assertEquals('org-b', $fetched->organization_slug);
    }

    public function testInsertGlobalTokenWithNullSlug(): void
    {
        $token = $this->buildToken('global_access', 'global_refresh', null);

        $this->repository->insert($token);
        $fetched = $this->repository->selectBySlug(null);

        $this->assertNotNull($fetched);
        $this->assertEquals('global_access', $fetched->access_token);
        $this->assertNull($fetched->organization_slug);
    }

    public function testInsertMultipleTokensAreSeparated(): void
    {
        $this->repository->insert($this->buildToken('token_org1', 'refresh_org1', 'org-1'));
        $this->repository->insert($this->buildToken('token_org2', 'refresh_org2', 'org-2'));

        $r1 = $this->repository->selectBySlug('org-1');
        $r2 = $this->repository->selectBySlug('org-2');

        $this->assertEquals('token_org1', $r1->access_token);
        $this->assertEquals('token_org2', $r2->access_token);
    }

    // =====================================================================
    // Sélection par slug
    // =====================================================================

    public function testSelectBySlugReturnsNullForUnknownOrg(): void
    {
        $result = $this->repository->selectBySlug('unknown-org');
        $this->assertNull($result);
    }

    public function testSelectBySlugReturnsCorrectModel(): void
    {
        $this->repository->insert($this->buildToken('found_token', 'found_refresh', 'found-org'));

        $result = $this->repository->selectBySlug('found-org');

        $this->assertInstanceOf(AccessToken::class, $result);
        $this->assertEquals('found_token', $result->access_token);
    }

    // =====================================================================
    // Mise à jour d'un token
    // =====================================================================

    public function testUpdateTokenReplacesValues(): void
    {
        $this->repository->insert($this->buildToken('old_access', 'old_refresh', 'update-org'));

        $updated = $this->buildToken('new_access', 'new_refresh', 'update-org');
        $result = $this->repository->update($updated);

        $this->assertNotNull($result);
        $this->assertEquals('new_access', $result->access_token);
        $this->assertEquals('new_refresh', $result->refresh_token);

        // Vérification en base
        $fetched = $this->repository->selectBySlug('update-org');
        $this->assertEquals('new_access', $fetched->access_token);
        $this->assertEquals('new_refresh', $fetched->refresh_token);
    }

    public function testUpdateTokenAssignsId(): void
    {
        $this->repository->insert($this->buildToken('old', 'old_r', 'id-org'));
        $updated = $this->buildToken('new', 'new_r', 'id-org');

        $result = $this->repository->update($updated);

        $this->assertNotNull($result->id);
        $this->assertGreaterThan(0, (int) $result->id);
    }

    public function testUpdateNonExistentTokenReturnsNull(): void
    {
        $token = $this->buildToken('some_access', 'some_refresh', 'does-not-exist');

        $result = $this->repository->update($token);

        $this->assertNull($result);
    }

    public function testUpdateGlobalToken(): void
    {
        $this->repository->insert($this->buildToken('global_old', 'global_r_old', null));

        $updated = $this->buildToken('global_new', 'global_r_new', null);
        $result = $this->repository->update($updated);

        $this->assertNotNull($result);
        $this->assertEquals('global_new', $result->access_token);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function buildToken(
        string $access,
        string $refresh,
        ?string $slug,
        int $accessMinutes = 28,
        int $refreshDays = 28
    ): AccessToken {
        $token = new AccessToken();
        $token->access_token = $access;
        $token->refresh_token = $refresh;
        $token->organization_slug = $slug;
        $token->access_token_expires_at = (new DateTime())->add(new DateInterval("PT{$accessMinutes}M"));
        $token->refresh_token_expires_at = (new DateTime())->add(new DateInterval("P{$refreshDays}D"));
        return $token;
    }
}


<?php

namespace App\Repositories;

use App\Models\AccessToken;
use PDO;

class AccessTokenRepository
{
    public function __construct(
        private PDO $pdo,
        private string $prefix
    ) {}

    function selectBySlug($organization_slug): ?AccessToken
    {
        if (is_null($organization_slug)) {
            $query = "SELECT * FROM `{$this->prefix}access_token_partner_organization`
                    WHERE organization_slug IS NULL";
            $stmt = $this->pdo->prepare($query);
            $stmt->execute();
        } else {
            $query = "SELECT * FROM `{$this->prefix}access_token_partner_organization`
                    WHERE organization_slug = :organization_slug";
            $stmt = $this->pdo->prepare($query);
            $stmt->execute([
                ':organization_slug' => $organization_slug
            ]);
        }

        $stmt->setFetchMode(PDO::FETCH_CLASS, AccessToken::class);
        $token = $stmt->fetch();

        return $token ?: null;
    }

    function getAccessTokensToRefresh(): array
    {
        $stmt = $this->pdo->prepare('SELECT * 
            FROM ' . $this->prefix . 'access_token_partner_organization 
            WHERE refresh_token_expires_at > now()
            AND refresh_token_expires_at <= DATE_ADD(NOW(), INTERVAL 24 HOUR);');
        $stmt->execute();

        $stmt->setFetchMode(PDO::FETCH_CLASS, AccessToken::class);
        return $stmt->fetchAll();
    }

    public function insert(AccessToken $token): AccessToken
    {
        $stmt = $this->pdo->prepare("INSERT INTO `{$this->prefix}access_token_partner_organization` (access_token, refresh_token, organization_slug, access_token_expires_at, refresh_token_expires_at) 
        VALUES (:access_token, :refresh_token, :organization_slug, :access_token_expires_at, :refresh_token_expires_at)");
        $stmt->execute([
            'access_token' => $token->access_token,
            'refresh_token' => $token->refresh_token,
            'organization_slug' => $token->organization_slug,
            'access_token_expires_at' => $token->access_token_expires_at->format('Y-m-d H:i:s'),
            'refresh_token_expires_at' => $token->refresh_token_expires_at->format('Y-m-d H:i:s')
        ]);

        $token->id = $this->pdo->lastInsertId();

        return $token;
    }

    public function update(AccessToken $accessToken): ?AccessToken
    {
        $obj = $this->selectBySlug($accessToken->organization_slug);

        if (!$obj)
            return null;

        $stmt = $this->pdo->prepare("UPDATE `{$this->prefix}access_token_partner_organization` SET 
            access_token = :access_token,
            refresh_token = :refresh_token,
            organization_slug = :organization_slug,
            access_token_expires_at = :access_token_expires_at,
            refresh_token_expires_at = :refresh_token_expires_at
            WHERE id = :id");
        $stmt->execute([
            'access_token' => $accessToken->access_token,
            'refresh_token' => $accessToken->refresh_token,
            'organization_slug' => $accessToken->organization_slug,
            'access_token_expires_at' => $accessToken->access_token_expires_at->format('Y-m-d H:i:s'),
            'refresh_token_expires_at' => $accessToken->refresh_token_expires_at->format('Y-m-d H:i:s'),
            'id' => $obj->id
        ]);

        $accessToken->id = $obj->id;

        return $accessToken;
    }
}

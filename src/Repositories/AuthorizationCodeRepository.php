<?php

namespace App\Repositories;

use App\Models\AuthorizationCode;
use PDO;

class AuthorizationCodeRepository
{
    public function __construct(
        private PDO $pdo,
        private string $prefix
    ) {}

    function selectById($id): ?AuthorizationCode
    {
        $stmt = $this->pdo->prepare("SELECT * FROM `{$this->prefix}authorization_code`
                    WHERE id = :id");
        $stmt->execute([
            ':id' => $id
        ]);

        $stmt->setFetchMode(PDO::FETCH_CLASS, AuthorizationCode::class);
        $authorizationCode = $stmt->fetch();

        return $authorizationCode ?: null;
    }

    public function insert(AuthorizationCode $authorizationCode): AuthorizationCode
    {
        $stmt = $this->pdo->prepare("INSERT INTO `{$this->prefix}authorization_code` (id, code_verifier, organization_slug, redirect_uri) 
        VALUES (:id, :code_verifier, :organization_slug, :redirect_uri)");
        $stmt->execute([
            'id' => $authorizationCode->id,
            'code_verifier' => $authorizationCode->code_verifier,
            'organization_slug' => $authorizationCode->organization_slug,
            'redirect_uri' => $authorizationCode->redirect_uri
        ]);

        return $authorizationCode;
    }
}

<?php

namespace App\Repositories;

use App\Models\Event;
use App\Models\Stream;
use App\Models\User;
use DateTime;
use PDO;

class UserRepository
{
    public function __construct(
        private PDO $pdo,
        private string $prefix
    ) {}

    public function insert(string $email): User
    {
        $password = bin2hex(random_bytes(15));

        $stmt = $this->pdo->prepare('INSERT INTO ' . $this->prefix . 'users (email, password) VALUES (:email, :password)');
        $stmt->execute([
            ':email' => $email,
            ':password' => password_hash($password, PASSWORD_DEFAULT),
        ]);

        $user = new User();
        $user->id = $this->pdo->lastInsertId();
        $user->email = $email;

        return $user;
    }

    public function insertRight(User $user, ?Stream $stream, ?Event $event): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO ' . $this->prefix . 'user_right (id_user, id_charity_event, id_charity_stream) VALUES (:id_user, :id_charity_event, :id_charity_stream)');
        $stmt->execute([
            ':id_user' => $user->id,
            ':id_charity_event' => $event?->id,
            ':id_charity_stream' => $stream?->id,
        ]);
    }

    public function select(string $email): ?User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . $this->prefix . 'users WHERE email = ?');
        $stmt->execute([$email]);
        $stmt->setFetchMode(PDO::FETCH_CLASS, User::class);
        return $stmt->fetch() ?: null;
    }

    /**
     * Recherche un utilisateur par son token de réinitialisation, uniquement si le token n'est pas expiré.
     */
    public function selectByToken(string $token): ?User
    {
        $stmt = $this->pdo->prepare('
            SELECT * FROM ' . $this->prefix . 'users
            WHERE reset_token = ?
            AND reset_token_expires_at > NOW()
        ');
        $stmt->execute([$token]);
        $stmt->setFetchMode(PDO::FETCH_CLASS, User::class);
        return $stmt->fetch() ?: null;
    }

    /**
     * Génère un token de réinitialisation valable 1 heure.
     */
    public function insertResetToken(User $user): User
    {
        $token = bin2hex(random_bytes(30));
        $expiresAt = (new DateTime())->modify('+1 hour')->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare('UPDATE ' . $this->prefix . 'users SET reset_token = :token, reset_token_expires_at = :expires WHERE id = :id');
        $stmt->execute([
            ':id' => $user->id,
            ':token' => $token,
            ':expires' => $expiresAt,
        ]);

        $user->reset_token = $token;
        return $user;
    }

    public function updatePassword(User $user, ?string $password = null): User
    {
        if (!$password) {
            $password = bin2hex(random_bytes(15));
        }

        $stmt = $this->pdo->prepare('UPDATE ' . $this->prefix . 'users SET password = :password, reset_token = null, reset_token_expires_at = null WHERE email = :email');
        $stmt->execute([
            ':email' => $user->email,
            ':password' => password_hash($password, PASSWORD_DEFAULT),
        ]);

        return $user;
    }

    public function delete(string $email): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . $this->prefix . 'users WHERE email = ?');
        $stmt->execute([$email]);
    }
}

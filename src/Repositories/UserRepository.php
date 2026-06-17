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

    /**
     * Crée un utilisateur avec un mot de passe choisi et l'email non vérifié.
     */
    public function insertWithPassword(string $email, string $password): User
    {
        $stmt = $this->pdo->prepare('INSERT INTO ' . $this->prefix . 'users (email, password, email_verified) VALUES (:email, :password, 0)');
        $stmt->execute([
            ':email' => $email,
            ':password' => password_hash($password, PASSWORD_DEFAULT),
        ]);

        $user = new User();
        $user->id = $this->pdo->lastInsertId();
        $user->email = $email;
        $user->email_verified = 0;

        return $user;
    }

    /**
     * Marque l'email d'un utilisateur comme vérifié.
     */
    public function verifyEmail(User $user): void
    {
        $stmt = $this->pdo->prepare('UPDATE ' . $this->prefix . 'users SET email_verified = 1, reset_token = NULL, reset_token_expires_at = NULL WHERE id = :id');
        $stmt->execute([':id' => $user->id]);
        $user->email_verified = 1;
        $user->reset_token = null;
        $user->reset_token_expires_at = null;
    }

    public function insertRight(User $user, ?Stream $stream, ?Event $event, bool $isOwner = false): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO ' . $this->prefix . 'user_right (id_user, id_charity_event, id_charity_stream, is_owner) VALUES (:id_user, :id_charity_event, :id_charity_stream, :is_owner)');
        $stmt->execute([
            ':id_user' => $user->id,
            ':id_charity_event' => $event?->id,
            ':id_charity_stream' => $stream?->id,
            ':is_owner' => (int) $isOwner,
        ]);
    }

    public function selectEventAdmins(Event $event): array
    {
        $stmt = $this->pdo->prepare('
            SELECT u.id, u.email, ur.is_owner
            FROM ' . $this->prefix . 'user_right ur
            INNER JOIN ' . $this->prefix . 'users u ON u.id = ur.id_user
            WHERE ur.id_charity_event = ?
            ORDER BY ur.is_owner DESC, u.email ASC
        ');
        $stmt->execute([$event->id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function isEventOwner(User $user, Event $event): bool
    {
        $stmt = $this->pdo->prepare('
            SELECT 1 FROM ' . $this->prefix . 'user_right
            WHERE id_user = ? AND id_charity_event = ? AND is_owner = 1
            LIMIT 1
        ');
        $stmt->execute([$user->id, $event->id]);
        return (bool) $stmt->fetch();
    }

    public function deleteEventRight(int $userId, Event $event): void
    {
        $stmt = $this->pdo->prepare('
            DELETE FROM ' . $this->prefix . 'user_right
            WHERE id_user = ? AND id_charity_event = ?
        ');
        $stmt->execute([$userId, $event->id]);
    }

    public function select(string $email): ?User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . $this->prefix . 'users WHERE email = ?');
        $stmt->execute([$email]);
        $stmt->setFetchMode(PDO::FETCH_CLASS, User::class);
        return $stmt->fetch() ?: null;
    }

    public function selectAll(): array
    {
        $stmt = $this->pdo->query('SELECT id, email, role, email_verified, creation_date FROM ' . $this->prefix . 'users ORDER BY creation_date DESC');
        $stmt->setFetchMode(PDO::FETCH_CLASS, User::class);
        return $stmt->fetchAll();
    }

    public function selectById(int $id): ?User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . $this->prefix . 'users WHERE id = ?');
        $stmt->execute([$id]);
        $stmt->setFetchMode(PDO::FETCH_CLASS, User::class);
        return $stmt->fetch() ?: null;
    }

    public function findOrCreate(string $email): User
    {
        return $this->select($email) ?? $this->insert($email);
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

    public function deleteById(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . $this->prefix . 'user_right WHERE id_user = ?');
        $stmt->execute([$id]);

        $stmt = $this->pdo->prepare('DELETE FROM ' . $this->prefix . 'users WHERE id = ?');
        $stmt->execute([$id]);
    }
}

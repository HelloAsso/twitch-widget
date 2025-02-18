<?php

namespace App\Repositories;

use App\Models\Event;
use App\Models\Stream;
use App\Models\User;
use PDO;

class UserRepository
{
    public function __construct(
        private PDO $pdo,
        private string $prefix
    ) {}

    function insert($email): User
    {
        $password = bin2hex(random_bytes(15));

        $query = 'INSERT INTO ' . $this->prefix . 'users (email, password) 
                VALUES (:email, :password)';
        $stmt = $this->pdo->prepare($query);
        $stmt->execute([
            ':email' => $email,
            ':password' => password_hash($password, PASSWORD_DEFAULT)
        ]);

        $user = new User();
        $user->id = $this->pdo->lastInsertId();
        $user->email = $email;

        return $user;
    }

    function insertRight(User $user, ?Stream $stream, ?Event $event)
    {
        $query = 'INSERT INTO ' . $this->prefix . 'user_right (id_user, id_charity_event, id_charity_stream) 
                VALUES (:id_user, :id_charity_event, :id_charity_stream)';
        $stmt = $this->pdo->prepare($query);
        $stmt->execute([
            ':id_user' => $user->id,
            ':id_charity_event' => $event ? $event->id : null,
            ':id_charity_stream' => $stream ? $stream->id : null
        ]);
    }

    function select($email): ?User
    {
        $stmt = $this->pdo->prepare('
            SELECT *
            FROM ' . $this->prefix . 'users
            WHERE email = ?');
        $stmt->execute([$email]);

        $stmt->setFetchMode(PDO::FETCH_CLASS, User::class);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    function selectByToken($token): ?User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . $this->prefix . 'users WHERE reset_token = ?');
        $stmt->execute([$token]);

        $stmt->setFetchMode(PDO::FETCH_CLASS, User::class);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    function insertResetToken(User $user): User
    {
        $token = bin2hex(random_bytes(30));

        $query = 'UPDATE ' . $this->prefix . 'users
            SET reset_token = :token
            WHERE id = :id';
        $stmt = $this->pdo->prepare($query);
        $stmt->execute([
            ':id' => $user->id,
            ':token' => $token
        ]);

        $user->reset_token = $token;

        return $user;
    }

    function updatePassword(User $user, $password = null): User
    {
        if (!$password)
            $password = bin2hex(random_bytes(15));

        $query = 'UPDATE ' . $this->prefix . 'users
                SET password = :password,
                reset_token = null
                WHERE email = :email';
        $stmt = $this->pdo->prepare($query);
        $stmt->execute([
            ':email' => $user->email,
            ':password' => password_hash($password, PASSWORD_DEFAULT)
        ]);

        return $user;
    }

    function delete($email)
    {
        $query = 'DELETE FROM ' . $this->prefix . 'users
                WHERE email = ?';
        $stmt = $this->pdo->prepare($query);
        $stmt->execute([$email]);
    }
}

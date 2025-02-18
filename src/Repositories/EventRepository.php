<?php

namespace App\Repositories;

use App\Models\Event;
use App\Models\User;
use Exception;
use PDO;

class EventRepository
{
    public function __construct(
        private PDO $pdo,
        private string $prefix
    ) {}

    public function selectList(): array
    {
        $stmt = $this->pdo->query('
            SELECT c.*, u.email as admin
            FROM ' . $this->prefix . 'charity_event c
            INNER JOIN ' . $this->prefix . 'user_right r ON r.id_charity_event = c.id
            INNER JOIN ' . $this->prefix . 'users u ON u.id = r.id_user
        ');

        $stmt->setFetchMode(PDO::FETCH_CLASS, Event::class);
        return $stmt->fetchAll();
    }

    function selectListByUser(User $user): array
    {
        $stmt = $this->pdo->prepare('
            SELECT c.* 
            FROM ' . $this->prefix . 'charity_event c
            INNER JOIN ' . $this->prefix . 'user_right ur on ur.id_charity_event = c.id
            WHERE ur.id_user = ? 
        ');
        $stmt->setFetchMode(PDO::FETCH_CLASS, Event::class);
        $stmt->execute([$user->id]);
        return $stmt->fetchAll();
    }

    function selectByUserAndId(User $user, $id): ?Event
    {
        if ($user->role == "ADMIN") {
            $stmt = $this->pdo->prepare('
            SELECT c.* 
            FROM ' . $this->prefix . 'charity_event c
            WHERE c.id = ?
        ');
            $stmt->setFetchMode(PDO::FETCH_CLASS, Event::class);
            $stmt->execute([$id]);
        } else {
            $stmt = $this->pdo->prepare('
                SELECT c.* 
                FROM ' . $this->prefix . 'charity_event c
                INNER JOIN ' . $this->prefix . 'user_right ur on ur.id_charity_event = c.id
                WHERE ur.id_user = ? 
                AND c.id = ?
            ');
            $stmt->setFetchMode(PDO::FETCH_CLASS, Event::class);
            $stmt->execute([$user->id, $id]);
        }

        $event = $stmt->fetch();
        return $event ?: null;
    }

    function selectByUserAndGuid(User $user, $guid): ?Event
    {
        if ($user->role == "ADMIN") {
            $stmt = $this->pdo->prepare('
            SELECT c.* 
            FROM ' . $this->prefix . 'charity_event c
            WHERE c.guid = ?
        ');
            $stmt->setFetchMode(PDO::FETCH_CLASS, Event::class);
            $stmt->execute([$guid]);
        } else {
            $stmt = $this->pdo->prepare('
                SELECT c.* 
                FROM ' . $this->prefix . 'charity_event c
                INNER JOIN ' . $this->prefix . 'user_right ur on ur.id_charity_event = c.id
                WHERE ur.id_user = ? 
                AND c.guid = ?
            ');
            $stmt->setFetchMode(PDO::FETCH_CLASS, Event::class);
            $stmt->execute([$user->id, $guid]);
        }

        $event = $stmt->fetch();
        return $event ?: null;
    }

    function selectByGuid($guid): ?Event
    {
        $stmt = $this->pdo->prepare('
            SELECT * 
            FROM ' . $this->prefix . 'charity_event 
            WHERE guid = ?
        ');
        $stmt->setFetchMode(PDO::FETCH_CLASS, Event::class);
        $stmt->execute([$guid]);
        $event = $stmt->fetch();
        return $event ?: null;
    }

    public function insert($title): Event
    {
        $this->pdo->beginTransaction();

        try {
            $guid = bin2hex(random_bytes(16));

            $query = 'INSERT INTO ' . $this->prefix . 'charity_event (guid, title) 
        VALUES (:guid, :title)';
            $stmt = $this->pdo->prepare($query);
            $stmt->execute([
                ':guid' => $guid,
                ':title' => $title
            ]);

            $id = $this->pdo->lastInsertId();

            $query = 'INSERT INTO ' . $this->prefix . 'widget_donation_goal_bar (charity_event_guid)
                VALUES (:guid)';
            $stmt = $this->pdo->prepare($query);
            $stmt->execute([
                ':guid' => $guid
            ]);

            $this->pdo->commit();

            $stream = new Event();
            $stream->id = $id;
            $stream->guid = $guid;
            $stream->title = $title;
            return $stream;

            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function delete(Event $event)
    {
        $this->pdo->beginTransaction();

        try {
            $query = 'UPDATE ' . $this->prefix . 'charity_stream
                SET charity_event_id = null
                WHERE charity_event_id = ?';
            $stmt = $this->pdo->prepare($query);
            $stmt->execute([$event->id]);

            $query = 'DELETE FROM ' . $this->prefix . 'user_right
                WHERE id_charity_event = ?';
            $stmt = $this->pdo->prepare($query);
            $stmt->execute([$event->id]);

            $query = 'DELETE FROM ' . $this->prefix . 'widget_donation_goal_bar
                WHERE charity_event_guid = ?';
            $stmt = $this->pdo->prepare($query);
            $stmt->execute([$event->guid]);

            $query = 'DELETE FROM ' . $this->prefix . 'charity_event
                WHERE id = ?';
            $stmt = $this->pdo->prepare($query);
            $stmt->execute([$event->id]);

            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}

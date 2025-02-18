<?php

namespace App\Repositories;

use App\Models\Event;
use App\Models\Stream;
use App\Models\User;
use Exception;
use PDO;

class StreamRepository
{
    public function __construct(
        private PDO $pdo,
        private string $prefix
    ) {}

    function selectList(): array
    {
        $stmt = $this->pdo->query('
            SELECT c.*, u.email as admin
            FROM ' . $this->prefix . 'charity_stream c
            INNER JOIN ' . $this->prefix . 'user_right r ON r.id_charity_stream = c.id
            INNER JOIN ' . $this->prefix . 'users u ON u.id = r.id_user
        ');

        $stmt->setFetchMode(PDO::FETCH_CLASS, Stream::class);
        return $stmt->fetchAll();
    }

    function selectByGuid($guid): ?Stream
    {
        $stmt = $this->pdo->prepare('
            SELECT * 
            FROM ' . $this->prefix . 'charity_stream 
            WHERE guid = ?
        ');
        $stmt->setFetchMode(PDO::FETCH_CLASS, Stream::class);
        $stmt->execute([$guid]);
        return $stmt->fetch() ?: null;
    }

    function selectListByUser(User $user): array
    {
        $stmt = $this->pdo->prepare('
            SELECT c.*, u.email as admin
            FROM ' . $this->prefix . 'charity_stream c
            INNER JOIN ' . $this->prefix . 'user_right ur on ur.id_charity_stream = c.id
            INNER JOIN ' . $this->prefix . 'users u ON u.id = ur.id_user
            WHERE ur.id_user = :id_user

            UNION 

            SELECT c.*, u.email as admin
            FROM ' . $this->prefix . 'charity_stream c
            INNER JOIN ' . $this->prefix . 'user_right ur on ur.id_charity_event = c.charity_event_id
            INNER JOIN ' . $this->prefix . 'users u ON u.id = ur.id_user
            WHERE ur.id_user = :id_user
        ');
        $stmt->setFetchMode(PDO::FETCH_CLASS, Stream::class);
        $stmt->execute([':id_user' => $user->id]);
        return $stmt->fetchAll();
    }

    function selectListByEvent(Event $event): array
    {
        $stmt = $this->pdo->prepare('
            SELECT *
            FROM ' . $this->prefix . 'charity_stream
            WHERE charity_event_id = :id
        ');
        $stmt->setFetchMode(PDO::FETCH_CLASS, Stream::class);
        $stmt->execute([':id' => $event->id]);
        return $stmt->fetchAll();
    }

    function selectByUserAndGuid(User $user, $guid): ?Stream
    {
        if ($user->role == "ADMIN") {
            $stmt = $this->pdo->prepare('
            SELECT c.* 
            FROM ' . $this->prefix . 'charity_stream c
            WHERE c.guid = ?
        ');
            $stmt->setFetchMode(PDO::FETCH_CLASS, Stream::class);
            $stmt->execute([$guid]);
        } else {
            $stmt = $this->pdo->prepare('
            SELECT c.* 
            FROM ' . $this->prefix . 'charity_stream c
            INNER JOIN ' . $this->prefix . 'user_right ur on ur.id_charity_stream = c.id
            WHERE ur.id_user = :id_user 
            AND c.guid = :guid
            UNION
            SELECT c.* 
            FROM ' . $this->prefix . 'charity_stream c
            INNER JOIN ' . $this->prefix . 'user_right ur on ur.id_charity_event = c.charity_event_id
            WHERE ur.id_user = :id_user
            AND c.guid = :guid
        ');
            $stmt->setFetchMode(PDO::FETCH_CLASS, Stream::class);
            $stmt->execute([":id_user" => $user->id, ":guid" => $guid]);
        }

        $stream = $stmt->fetch();
        return $stream ?: null;
    }

    function insert($form_slug, $organization_slug, $title, $parent = null): Stream
    {
        $guid = bin2hex(random_bytes(16));

        $this->pdo->beginTransaction();

        try {
            $query = 'INSERT INTO ' . $this->prefix . 'charity_stream (guid, form_slug, organization_slug, title, charity_event_id) 
                VALUES (:guid, :form_slug, :organization_slug, :title, :charity_event_id)';
            $stmt = $this->pdo->prepare($query);
            $stmt->execute([
                ':guid' => $guid,
                ':form_slug' => $form_slug,
                ':organization_slug' => $organization_slug,
                ':title' => $title,
                ':charity_event_id' => $parent
            ]);

            $id = $this->pdo->lastInsertId();

            $query = 'INSERT INTO ' . $this->prefix . 'widget_donation_goal_bar (charity_stream_guid)
                VALUES (:guid)';
            $stmt = $this->pdo->prepare($query);
            $stmt->execute([
                ':guid' => $guid
            ]);

            $query = 'INSERT INTO ' . $this->prefix . 'widget_alert_box (charity_stream_guid, alert_duration, message_template, sound_volume)
                VALUES (:guid, 5, "{pseudo} vient de donner {amount}<br/>{message}", 50)';
            $stmt = $this->pdo->prepare($query);
            $stmt->execute([
                ':guid' => $guid
            ]);

            $this->pdo->commit();

            $stream = new Stream();
            $stream->id = $id;
            $stream->guid = $guid;
            $stream->form_slug = $form_slug;
            $stream->organization_slug = $organization_slug;
            $stream->title = $title;
            return $stream;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function delete($stream)
    {
        $this->pdo->beginTransaction();

        try {
            $query = 'DELETE FROM ' . $this->prefix . 'widget_donation_goal_bar
                WHERE charity_stream_guid = ?';
            $stmt = $this->pdo->prepare($query);
            $stmt->execute([$stream->guid]);

            $query = 'DELETE FROM ' . $this->prefix . 'widget_alert_box
                WHERE charity_stream_guid = ?';
            $stmt = $this->pdo->prepare($query);
            $stmt->execute([$stream->guid]);

            $query = 'DELETE FROM ' . $this->prefix . 'user_right
                WHERE id_charity_stream = ?';
            $stmt = $this->pdo->prepare($query);
            $stmt->execute([$stream->id]);

            $query = 'DELETE FROM ' . $this->prefix . 'charity_stream
                WHERE id = ?';
            $stmt = $this->pdo->prepare($query);
            $stmt->execute([$stream->id]);

            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}

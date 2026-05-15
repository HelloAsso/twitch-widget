<?php

namespace App\Repositories;

use App\Models\Event;
use App\Models\Stream;
use App\Models\WidgetAlert;
use App\Models\WidgetCard;
use App\Models\WidgetDonation;
use Exception;
use PDO;

class WidgetRepository
{
    public function __construct(
        private PDO $pdo,
        private string $prefix
    ) {}

    public function selectDonationWidgetByGuid(?string $streamGuid, ?string $eventGuid): ?WidgetDonation
    {
        $stmt = $this->pdo->prepare('
            SELECT
                id,
                charity_event_guid,
                charity_stream_guid,
                text_color_main,
                text_color_alt,
                text_content,
                bar_color,
                background_color,
                goal,
                cache_data,
                creation_date,
                last_update
            FROM ' . $this->prefix . 'widget_donation_goal_bar
            WHERE charity_stream_guid = ?
            OR charity_event_guid = ?
        ');
        $stmt->setFetchMode(PDO::FETCH_CLASS, WidgetDonation::class);
        $stmt->execute([$streamGuid, $eventGuid]);
        return $stmt->fetch() ?: null;
    }

    public function selectAlertWidgetByGuid(string $guid): ?WidgetAlert
    {
        $stmt = $this->pdo->prepare('
            SELECT
                id,
                charity_stream_guid,
                image,
                alert_duration,
                message_template,
                sound,
                sound_volume,
                cache_data,
                creation_date,
                last_update
            FROM ' . $this->prefix . 'widget_alert_box
            WHERE charity_stream_guid = ?
        ');
        $stmt->setFetchMode(PDO::FETCH_CLASS, WidgetAlert::class);
        $stmt->execute([$guid]);
        return $stmt->fetch() ?: null;
    }

    public function updateDonationWidget(?string $streamGuid, ?string $eventGuid, array $data): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE ' . $this->prefix . 'widget_donation_goal_bar
            SET text_color_main = ?, text_color_alt = ?, text_content = ?, bar_color = ?, background_color = ?, goal = ?
            WHERE charity_stream_guid = ?
            OR charity_event_guid = ?
        ');
        $stmt->execute([
            $data['text_color_main'],
            $data['text_color_alt'],
            $data['text_content'],
            $data['bar_color'],
            $data['background_color'],
            $data['goal'],
            $streamGuid,
            $eventGuid
        ]);
    }

    public function updateAlertWidget(string $guid, array $postData, ?string $image = null, ?string $sound = null): void
    {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare('
                UPDATE ' . $this->prefix . 'widget_alert_box
                SET alert_duration = ?, message_template = ?, sound_volume = ?
                WHERE charity_stream_guid = ?
            ');
            $stmt->execute([
                $postData['alert_duration'],
                $postData['message_template'],
                $postData['sound_volume'],
                $guid
            ]);

            if (isset($image)) {
                $stmt = $this->pdo->prepare('
                UPDATE ' . $this->prefix . 'widget_alert_box
                SET image = ?
                WHERE charity_stream_guid = ?
            ');
                $stmt->execute([
                    $image,
                    $guid
                ]);
            }

            if (isset($sound)) {
                $stmt = $this->pdo->prepare('
                UPDATE ' . $this->prefix . 'widget_alert_box
                SET sound = ?
                WHERE charity_stream_guid = ?
            ');
                $stmt->execute([
                    $sound,
                    $guid
                ]);
            }

            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // ── Generic cache helpers ────────────────────────────────────

    private function selectCacheData(string $table, string $column, string $guid): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT cache_data FROM ' . $this->prefix . $table . ' WHERE ' . $column . ' = ?'
        );
        $stmt->execute([$guid]);
        $data = $stmt->fetch();

        return $data ? json_decode($data["cache_data"] ?? "", true) : null;
    }

    private function updateCacheData(string $table, string $column, string $guid, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . $this->prefix . $table . ' SET cache_data = ? WHERE ' . $column . ' = ?'
        );
        $stmt->execute([json_encode($data), $guid]);
    }

    // ── Alert widget cache ────────────────────────────────────────

    public function selectAlertWidgetCacheData(Stream $stream): ?array
    {
        return $this->selectCacheData('widget_alert_box', 'charity_stream_guid', $stream->guid);
    }

    public function updateAlertWidgetCacheData(string $streamGuid, array $data): void
    {
        $this->updateCacheData('widget_alert_box', 'charity_stream_guid', $streamGuid, $data);
    }

    // ── Donation widget cache ─────────────────────────────────────

    public function selectStreamDonationWidgetCacheData(Stream $stream): ?array
    {
        return $this->selectCacheData('widget_donation_goal_bar', 'charity_stream_guid', $stream->guid);
    }

    public function updateStreamDonationWidgetCacheData(string $streamGuid, array $data): void
    {
        $this->updateCacheData('widget_donation_goal_bar', 'charity_stream_guid', $streamGuid, $data);
    }

    public function selectEventDonationWidgetCacheData(Event $event): ?array
    {
        return $this->selectCacheData('widget_donation_goal_bar', 'charity_event_guid', $event->guid);
    }

    public function updateEventDonationWidgetCacheData(string $eventGuid, array $data): void
    {
        $this->updateCacheData('widget_donation_goal_bar', 'charity_event_guid', $eventGuid, $data);
    }

    // ── Widget Card ──────────────────────────────────────────────

    public function selectCardWidgetByGuid(?string $streamGuid, ?string $eventGuid): ?WidgetCard
    {
        try {
            $stmt = $this->pdo->prepare('
                SELECT
                    id,
                    charity_stream_guid,
                    charity_event_guid,
                    image,
                    tag,
                    title,
                    description,
                    goal,
                    background_color,
                    bar_color,
                    bar_background_color,
                    text_color,
                    tag_color,
                    tag_background_color,
                    cache_data,
                    creation_date,
                    last_update
                FROM ' . $this->prefix . 'widget_card
                WHERE charity_stream_guid = ?
                OR charity_event_guid = ?
            ');
            $stmt->setFetchMode(PDO::FETCH_CLASS, WidgetCard::class);
            $stmt->execute([$streamGuid, $eventGuid]);
            return $stmt->fetch() ?: null;
        } catch (Exception $e) {
            // Table may not exist yet (migration not run)
            return null;
        }
    }

    public function insertCardWidget(?string $streamGuid, ?string $eventGuid): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO ' . $this->prefix . 'widget_card (charity_stream_guid, charity_event_guid, description)
            VALUES (?, ?, "")
        ');
        $stmt->execute([$streamGuid, $eventGuid]);
    }

    public function updateCardWidget(?string $streamGuid, ?string $eventGuid, array $data, ?string $image = null): void
    {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare('
                UPDATE ' . $this->prefix . 'widget_card
                SET tag = ?, title = ?, description = ?, goal = ?,
                    background_color = ?, bar_color = ?, bar_background_color = ?,
                    text_color = ?, tag_color = ?, tag_background_color = ?
                WHERE charity_stream_guid = ?
                OR charity_event_guid = ?
            ');
            $stmt->execute([
                $data['card_tag'] ?? '',
                $data['card_title'] ?? '',
                $data['card_description'] ?? '',
                $data['card_goal'] ?? 1000,
                $data['card_background_color'] ?? '#ffffff',
                $data['card_bar_color'] ?? '#2563eb',
                $data['card_bar_background_color'] ?? '#e5e7eb',
                $data['card_text_color'] ?? '#1a1a1a',
                $data['card_tag_color'] ?? '#166534',
                $data['card_tag_background_color'] ?? '#dcfce7',
                $streamGuid,
                $eventGuid
            ]);

            if ($image !== null) {
                $stmt = $this->pdo->prepare('
                    UPDATE ' . $this->prefix . 'widget_card
                    SET image = ?
                    WHERE charity_stream_guid = ?
                    OR charity_event_guid = ?
                ');
                $stmt->execute([$image, $streamGuid, $eventGuid]);
            }

            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // ── Card widget cache ─────────────────────────────────────────

    public function selectStreamCardWidgetCacheData(Stream $stream): ?array
    {
        return $this->selectCacheData('widget_card', 'charity_stream_guid', $stream->guid);
    }

    public function updateStreamCardWidgetCacheData(string $streamGuid, array $data): void
    {
        $this->updateCacheData('widget_card', 'charity_stream_guid', $streamGuid, $data);
    }

    public function selectEventCardWidgetCacheData(Event $event): ?array
    {
        return $this->selectCacheData('widget_card', 'charity_event_guid', $event->guid);
    }

    public function updateEventCardWidgetCacheData(string $eventGuid, array $data): void
    {
        $this->updateCacheData('widget_card', 'charity_event_guid', $eventGuid, $data);
    }
}

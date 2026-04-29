<?php

namespace App\Repositories;

use App\Models\WidgetAlert;
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
            SELECT * 
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
            SELECT * 
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

    public function selectAlertWidgetCacheData($stream): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT cache_data
            FROM ' . $this->prefix . 'widget_alert_box 
            WHERE charity_stream_guid = ?
        ');
        $stmt->execute([$stream->guid]);
        $data = $stmt->fetch();

        if ($data) {
            return json_decode($data["cache_data"] ?? "", true);
        }

        return null;
    }

    public function updateAlertWidgetCacheData(string $streamGuid, array $data): void
    {
        $stmt = $this->pdo->prepare('
        UPDATE ' . $this->prefix . 'widget_alert_box
        SET cache_data = ?
        WHERE charity_stream_guid = ?
    ');
        $stmt->execute([
            json_encode($data),
            $streamGuid
        ]);
    }

    public function selectStreamDonationWidgetCacheData($stream): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT cache_data
            FROM ' . $this->prefix . 'widget_donation_goal_bar 
            WHERE charity_stream_guid = ?
        ');
        $stmt->execute([$stream->guid]);
        $data = $stmt->fetch();

        if ($data) {
            return json_decode($data["cache_data"] ?? "", true);
        }

        return null;
    }

    public function updateStreamDonationWidgetCacheData(string $streamGuid, array $data): void
    {
        $stmt = $this->pdo->prepare('
        UPDATE ' . $this->prefix . 'widget_donation_goal_bar
        SET cache_data = ?
        WHERE charity_stream_guid = ?
    ');
        $stmt->execute([
            json_encode($data),
            $streamGuid
        ]);
    }

    public function selectEventDonationWidgetCacheData($event): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT cache_data
            FROM ' . $this->prefix . 'widget_donation_goal_bar 
            WHERE charity_event_guid = ?
        ');
        $stmt->execute([$event->guid]);
        $data = $stmt->fetch();

        if ($data) {
            return json_decode($data["cache_data"] ?? "", true);
        }

        return null;
    }

    public function updateEventDonationWidgetCacheData(string $eventGuid, array $data): void
    {
        $stmt = $this->pdo->prepare('
        UPDATE ' . $this->prefix . 'widget_donation_goal_bar
        SET cache_data = ?
        WHERE charity_event_guid = ?
    ');
        $stmt->execute([
            json_encode($data),
            $eventGuid
        ]);
    }
}

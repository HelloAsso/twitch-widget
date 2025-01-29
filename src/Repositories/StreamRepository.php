<?php

namespace App\Repositories;

use PDO;

class StreamRepository
{
    public function __construct(
        private PDO $pdo,
        private string $prefix
    ) {}

    function getCharityStreamsListDB()
    {
        $stmt = $this->pdo->query('SELECT * FROM ' . $this->prefix . 'charity_stream');
        return $stmt->fetchAll();
    }

    function getCharityStreamByGuidDB($guidBinary)
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . $this->prefix . 'charity_stream WHERE guid = ?');
        $stmt->execute([$guidBinary]);
        return $stmt->fetch();
    }

    function getCharityStreamByEmail($email)
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . $this->prefix . 'charity_stream WHERE owner_email = ?');
        $stmt->execute([$email]);
        return $stmt->fetchAll();
    }

    function getDonationGoalWidgetByGuidDB($guidBinary)
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . $this->prefix . 'widget_donation_goal_bar WHERE charity_stream_guid = ?');
        $stmt->execute([$guidBinary]);
        return $stmt->fetch();
    }

    function getAlertBoxWidgetByGuidDB($guidBinary)
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . $this->prefix . 'widget_alert_box WHERE charity_stream_guid = ?');
        $stmt->execute([$guidBinary]);
        return $stmt->fetch();
    }

    function updateDonationGoalWidgetDB($guidBinary, $data)
    {
        $stmt = $this->pdo->prepare('
            UPDATE ' . $this->prefix . 'widget_donation_goal_bar
            SET text_color_main = ?, text_color_alt = ?, text_content = ?, bar_color = ?, background_color = ?, goal = ?
            WHERE charity_stream_guid = ?
        ');
        $stmt->execute([
            $data['text_color_main'],
            $data['text_color_alt'],
            $data['text_content'],
            $data['bar_color'],
            $data['background_color'],
            $data['goal'],
            $guidBinary
        ]);
    }

    function updateAlertBoxWidgetDB($guidBinary, $postData, $image = null, $sound = null)
    {
        $stmt = $this->pdo->prepare('
            UPDATE ' . $this->prefix . 'widget_alert_box
            SET alert_duration = ?, message_template = ?, sound_volume = ?
            WHERE charity_stream_guid = ?
        ');
        $stmt->execute([
            $postData['alert_duration'],
            $postData['message_template'],
            $postData['sound_volume'],
            $guidBinary
        ]);

        if (isset($image)) {
            $stmt = $this->pdo->prepare('
                UPDATE ' . $this->prefix . 'widget_alert_box
                SET image = ?
                WHERE charity_stream_guid = ?
            ');
            $stmt->execute([
                $image,
                $guidBinary
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
                $guidBinary
            ]);
        }
    }

    function createCharityStreamDB($guid, $owner_email, $form_slug, $organization_slug, $title)
    {
        $query = 'INSERT INTO ' . $this->prefix . 'charity_stream (guid, owner_email, form_slug, organization_slug, title, state) 
                VALUES (:guid, :owner_email, :form_slug, :organization_slug, :title, 1)';
        $stmt = $this->pdo->prepare($query);
        $stmt->execute([
            ':guid' => hex2bin($guid),
            ':owner_email' => $owner_email,
            ':form_slug' => $form_slug,
            ':organization_slug' => $organization_slug,
            ':title' => $title
        ]);

        $query = 'INSERT INTO ' . $this->prefix . 'widget_donation_goal_bar (charity_stream_guid)
                VALUES (:guid)';
        $stmt = $this->pdo->prepare($query);
        $stmt->execute([
            ':guid' => hex2bin($guid)
        ]);

        $query = 'INSERT INTO ' . $this->prefix . 'widget_alert_box (charity_stream_guid, alert_duration, message_template, sound_volume)
                VALUES (:guid, 5, "{pseudo} vient de donner {amount}<br/>{message}", 50)';
        $stmt = $this->pdo->prepare($query);
        $stmt->execute([
            ':guid' => hex2bin($guid)
        ]);
    }

    public function deleteCharityStream($guid)
    {
        $query = 'DELETE FROM ' . $this->prefix . 'widget_donation_goal_bar
                WHERE charity_stream_guid = ?';
        $stmt = $this->pdo->prepare($query);
        $stmt->execute([$guid]);

        $query = 'DELETE FROM ' . $this->prefix . 'widget_alert_box
                WHERE charity_stream_guid = ?';
        $stmt = $this->pdo->prepare($query);
        $stmt->execute([$guid]);

        $query = 'DELETE FROM ' . $this->prefix . 'charity_stream
                WHERE guid = ?';
        $stmt = $this->pdo->prepare($query);
        $stmt->execute([$guid]);
    }
}

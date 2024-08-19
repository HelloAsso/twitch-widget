<?php

function GetCharityStreamsList($db) {
    // Requête pour récupérer les charity streams
    $stmt = $db->query('SELECT id, owner_email, title, guid FROM charity_stream');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function GetDonationGoalWidgetByGuid($db, $guidBinary) {
    $stmt = $db->prepare('SELECT * FROM widget_donation_goal_bar WHERE charity_stream_guid = ? LIMIT 1');
    $stmt->execute([$guidBinary]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function GetAlertBoxWidgetByGuid($db, $guidBinary) {
    $stmt = $db->prepare('SELECT * FROM widget_alert_box WHERE charity_stream_guid = ? LIMIT 1');
    $stmt->execute([$guidBinary]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
function UpdateDonationGoalWidget($db, $guidBinary, $data) {
    $stmt = $db->prepare('
        UPDATE widget_donation_goal_bar
        SET text_color = ?, bar_color = ?, background_color = ?, goal = ?, last_update = CURRENT_TIMESTAMP(6)
        WHERE charity_stream_guid = ?
    ');
    $stmt->execute([
        $data['text_color'],
        $data['bar_color'],
        $data['background_color'],
        $data['goal'],
        $guidBinary
    ]);
}

function UpdateAlertBoxWidget($db, $guidBinary, $data) {
    $stmt = $db->prepare('
        UPDATE widget_alert_box
        SET image = ?, alert_duration = ?, message_template = ?, sound = ?, sound_volume = ?, last_update = CURRENT_TIMESTAMP(6)
        WHERE charity_stream_guid = ?
    ');
    $stmt->execute([
        $data['image'],
        $data['alert_duration'],
        $data['message_template'],
        $data['sound'],
        $data['sound_volume'],
        $guidBinary
    ]);
}

function CreateCharityStream($db, $guid, $owner_email, $form_id, $title, $creation_date, $last_update) {
    // Insérer le nouveau Charity Stream
    $query = 'INSERT INTO charity_stream (guid, owner_email, form_id, title, state, creation_date, last_update) 
              VALUES (:guid, :owner_email, :form_id, :title, 1, :creation_date, :last_update)';
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':guid' => hex2bin($guid),
        ':owner_email' => $owner_email,
        ':form_id' => $form_id,
        ':title' => $title,
        ':creation_date' => $creation_date,
        ':last_update' => $last_update,
    ]);

    // Insérer un widget_donation_goal_bar associé avec des valeurs par défaut
    $query = 'INSERT INTO widget_donation_goal_bar (charity_stream_guid, goal, text_color, bar_color, background_color, creation_date, last_update)
              VALUES (:guid, 1000, "#FFFFFF", "#FF0000", "#000000", :creation_date, :last_update)';
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':guid' => hex2bin($guid),
        ':creation_date' => $creation_date,
        ':last_update' => $last_update,
    ]);

    // Insérer un widget_alert_box associé avec des valeurs par défaut
    $query = 'INSERT INTO widget_alert_box (charity_stream_guid, image, alert_duration, message_template, sound, sound_volume, creation_date, last_update)
              VALUES (:guid, "default_image.gif", 5, "{name} donated {amount} via Twitch Charity", "default_sound.mp3", 50, :creation_date, :last_update)';
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':guid' => hex2bin($guid),
        ':creation_date' => $creation_date,
        ':last_update' => $last_update,
    ]);
}



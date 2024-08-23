<?php

function GetCharityStreamsListDB($db, $environment) {
    // Requête pour récupérer les charity streams
    $stmt = $db->query('SELECT * FROM '. strtolower($environment) .'_charity_stream');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function GetCharityStreamByGuidDB($db, $environment, $guidBinary) {
    $stmt = $db->prepare('SELECT * FROM '. strtolower($environment) .'_charity_stream WHERE guid = ? LIMIT 1');
    $stmt->execute([$guidBinary]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function GetDonationGoalWidgetByGuidDB($db, $environment, $guidBinary) {
    $stmt = $db->prepare('SELECT * FROM '. strtolower($environment) .'_widget_donation_goal_bar WHERE charity_stream_guid = ? LIMIT 1');
    $stmt->execute([$guidBinary]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function GetAlertBoxWidgetByGuidDB($db, $environment, $guidBinary) {
    $stmt = $db->prepare('SELECT * FROM '. strtolower($environment) .'_widget_alert_box WHERE charity_stream_guid = ? LIMIT 1');
    $stmt->execute([$guidBinary]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function UpdateDonationGoalWidgetDB($db, $environment, $guidBinary, $data) {
    $stmt = $db->prepare('
        UPDATE  '. strtolower($environment) .'_widget_donation_goal_bar
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

function UpdateAlertBoxWidgetDB($db, $environment, $guidBinary, $data) {
    $stmt = $db->prepare('
        UPDATE  '. strtolower($environment) .'_widget_alert_box
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

function CreateCharityStreamDB($db, $environment, $guid, $owner_email, $form_slug, $organization_slug, $title, $creation_date, $last_update) {
    // Insérer le nouveau Charity Stream
    $query = 'INSERT INTO  '. strtolower($environment) .'_charity_stream (guid, owner_email, form_slug, organization_slug, title, state, creation_date, last_update) 
              VALUES (:guid, :owner_email, :form_slug, :organization_slug, :title, 1, :creation_date, :last_update)';
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':guid' => hex2bin($guid),
        ':owner_email' => $owner_email,
        ':form_slug' => $form_slug,
        ':organization_slug' => $organization_slug,
        ':title' => $title,
        ':creation_date' => $creation_date,
        ':last_update' => $last_update,
    ]);

    // Insérer un widget_donation_goal_bar associé avec des valeurs par défaut
    $query = 'INSERT INTO  '. strtolower($environment) .'_widget_donation_goal_bar (charity_stream_guid, goal, text_color, bar_color, background_color, creation_date, last_update)
              VALUES (:guid, 1000, "#FFFFFF", "#FF0000", "#000000", :creation_date, :last_update)';
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':guid' => hex2bin($guid),
        ':creation_date' => $creation_date,
        ':last_update' => $last_update,
    ]);

    // Insérer un widget_alert_box associé avec des valeurs par défaut
    $query = 'INSERT INTO  '. strtolower($environment) .'_widget_alert_box (charity_stream_guid, image, alert_duration, message_template, sound, sound_volume, creation_date, last_update)
              VALUES (:guid, "default_image.gif", 5, "{name} donated {amount} via Twitch Charity", "default_sound.mp3", 50, :creation_date, :last_update)';
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':guid' => hex2bin($guid),
        ':creation_date' => $creation_date,
        ':last_update' => $last_update,
    ]);
}

function InsertAccessTokenDB($db, $accessToken, $refreshToken, $organization_slug, $accessTokenExpiresAt, $refreshTokenExpiresAt, $environment) {
    $query = 'INSERT INTO  '. strtolower($environment) .'_access_token_partner_organization 
        (access_token, refresh_token, organization_slug, access_token_expires_at, refresh_token_expires_at, created_at, last_update)
        VALUES (:access_token, :refresh_token, :organization_slug, :access_token_expires_at, :refresh_token_expires_at, CURRENT_TIMESTAMP(6), CURRENT_TIMESTAMP(6))';
    $stmt = $db->prepare($query);

    // Exécuter la requête avec les valeurs
    $stmt->execute([
        ':access_token' => $accessToken,
        ':refresh_token' => $refreshToken,
        ':organization_slug' => $organization_slug,
        ':access_token_expires_at' => $accessTokenExpiresAt, // Format pour DATETIME(6)
        ':refresh_token_expires_at' => $refreshTokenExpiresAt // Format pour DATETIME(6)
    ]);
}

function UpdateAccessTokenDB($db, $access_token, $refresh_token, $organization_slug, $access_token_expires_at, $refresh_token_expires_at, $environment) {
    if (is_null($organization_slug)) 
    {
        $query = 'UPDATE ' . strtolower($environment) . '_access_token_partner_organization 
        SET access_token = :access_token, 
            refresh_token = :refresh_token, 
            access_token_expires_at = :access_token_expires_at, 
            organization_slug = :organization_slug,
            refresh_token_expires_at = :refresh_token_expires_at
        WHERE id = (SELECT id FROM ' . strtolower($environment) . '_access_token_partner_organization WHERE organization_slug IS NULL LIMIT 1)';

        $stmt = $db->prepare($query);

        $stmt->execute([
        ':access_token' => $access_token,
        ':refresh_token' => $refresh_token,
        ':organization_slug' => $organization_slug,
        ':access_token_expires_at' => $access_token_expires_at,
        ':refresh_token_expires_at' => $refresh_token_expires_at
        ]);
    } else {
        $query = 'UPDATE ' . strtolower($environment) . '_access_token_partner_organization 
        SET access_token = :access_token, 
            refresh_token = :refresh_token, 
            access_token_expires_at = :access_token_expires_at, 
            organization_slug = :organization_slug,
            refresh_token_expires_at = :refresh_token_expires_at
        WHERE id = (SELECT id FROM ' . strtolower($environment) . '_access_token_partner_organization WHERE organization_slug = :organization_slug LIMIT 1)';

        $stmt = $db->prepare($query);

        $stmt->execute([
        ':access_token' => $access_token,
        ':refresh_token' => $refresh_token,
        ':organization_slug' => $organization_slug,
        ':access_token_expires_at' => $access_token_expires_at,
        ':refresh_token_expires_at' => $refresh_token_expires_at
        ]);
    }
}


function InsertAuthorizationCodeDB($db, $id, $codeVerifier, $redirect_uri, $organizationSlug, $environment) {
    $query = 'INSERT INTO  '. strtolower($environment) .'_authorization_code (id, code_verifier, redirect_uri, organization_slug, creation_date, last_update)
        VALUES (:id, :code_verifier, :redirect_uri, :organization_slug, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)';
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':id' => $id,
        ':code_verifier' => $codeVerifier,
        ':redirect_uri' => $redirect_uri,
        ':organization_slug' => $organizationSlug
    ]);
}

function GetAccessTokensDB($db, $environment, $organization_slug) {
    if (is_null($organization_slug)) {
        $query = 'SELECT * FROM '. strtolower($environment) .'_access_token_partner_organization 
                  WHERE organization_slug IS NULL LIMIT 1';
        $stmt = $db->prepare($query);
        $stmt->execute();
    } else {
        $query = 'SELECT * FROM '. strtolower($environment) .'_access_token_partner_organization 
                  WHERE organization_slug = :organization_slug LIMIT 1';
        $stmt = $db->prepare($query);
        $stmt->execute([
            ':organization_slug' => $organization_slug
        ]);
    }

    // Retourner le résultat
    return $stmt->fetch(PDO::FETCH_ASSOC);
}


function GetAuthorizationCodeByIdDB($db, $id, $environment) {
    $query = 'SELECT * FROM ' . strtolower($environment) .'_authorization_code WHERE id = ? LIMIT 1';
    $stmt = $db->prepare($query);
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

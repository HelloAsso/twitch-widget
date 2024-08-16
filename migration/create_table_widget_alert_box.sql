CREATE TABLE widget_alert_box (
    id INT AUTO_INCREMENT PRIMARY KEY,  -- Identifiant unique, auto-incrémenté
    charity_stream_id BINARY(16) NOT NULL,  -- UUID en format binary, pour faire référence à `charity_stream`
    image VARCHAR(255) NOT NULL,  -- URL ou chemin vers l'image
    alert_duration INT NOT NULL,  -- Durée de l'alerte en secondes
    message_template TEXT NOT NULL,  -- Modèle de message, texte libre
    sound VARCHAR(255) NOT NULL,  -- Chemin ou nom du fichier son
    sound_volume INT NOT NULL,  -- Volume du son (0 à 100)
    creation_date TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),  -- Date de création avec précision à la microseconde
    last_update TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) NOT NULL  -- Date de dernière mise à jour avec précision à la microseconde
	CONSTRAINT fk_charity_stream_guid_donation_goal_bar FOREIGN KEY (charity_stream_guid) REFERENCES charity_stream(guid)
);

CREATE INDEX idx_charity_stream_id ON widget_alert_box(charity_stream_id);
CREATE INDEX idx_alert_duration ON widget_alert_box(alert_duration);

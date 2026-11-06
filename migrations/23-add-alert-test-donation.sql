-- Dons simulés en mode test, en attente d'affichage sur le widget d'alerte du stream.
-- Chaque instance du widget (onglet, source OBS) garde l'id du dernier don simulé reçu :
-- un don simulé s'affiche une seule fois par instance.
-- creation_date est en UTC (écrite par l'application). Les lignes de plus d'une heure sont purgées
-- à chaque nouveau don simulé.
CREATE TABLE {prefix}widget_alert_test_donation (
    id INT AUTO_INCREMENT PRIMARY KEY,
    charity_stream_guid CHAR(32) NOT NULL,
    pseudo VARCHAR(255) NOT NULL,
    message VARCHAR(255) NOT NULL DEFAULT '',
    amount INT NOT NULL,
    creation_date DATETIME(6) NOT NULL,
    INDEX idx_charity_stream_guid_id (charity_stream_guid, id),
    INDEX idx_creation_date (creation_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE widget_donation_goal_bar (
    id INT AUTO_INCREMENT PRIMARY KEY,  -- Identifiant unique, auto-incrémenté
    charity_stream_id BINARY(16) NOT NULL,  -- UUID en format binary, pour faire référence à `charity_stream`
    text_color CHAR(7) NOT NULL,  -- Code couleur en format hexadécimal (#RRGGBB)
    bar_color CHAR(7) NOT NULL,  -- Code couleur en format hexadécimal (#RRGGBB)
    background_color CHAR(7) NOT NULL,  -- Code couleur en format hexadécimal (#RRGGBB)
    goal INT NOT NULL,  -- Objectif de dons, représenté par un entier
    creation_date TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),  -- Date de création avec précision à la microseconde
    last_update TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) NOT NULL  -- Date de dernière mise à jour avec précision à la microseconde
);


CREATE INDEX idx_charity_stream_id ON widget_donation_goal_bar(charity_stream_id);
CREATE INDEX idx_goal ON widget_donation_goal_bar(goal);

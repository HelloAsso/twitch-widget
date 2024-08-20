CREATE TABLE sandbox_charity_stream (
    id INT AUTO_INCREMENT PRIMARY KEY,
    guid BINARY(16) NOT NULL UNIQUE,  -- UUID en format binaire
	title VARCHAR(500),  -- Titre avec une longueur maximale de 500 caractères
    owner_email VARCHAR(255) NOT NULL,  -- Email de l'utilisateur
    form_id VARCHAR(255) NOT NULL,  -- Identifiant du formulaire
    organization_id VARCHAR(255) NOT NULL,  -- Identifiant de l'association
    state TINYINT(1) NOT NULL,  -- État comme un entier (1 pour Enabled, 0 pour Disabled)
    creation_date TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),  -- Date de création avec précision à la microseconde
    last_update TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) NOT NULL  -- Date de dernière mise à jour avec précision à la microseconde
);

-- Index pour optimiser les requêtes sur certaines colonnes
CREATE INDEX idx_owner_email ON sandbox_charity_stream(owner_email);
CREATE INDEX idx_form_id ON sandbox_charity_stream(form_id);
CREATE INDEX idx_state ON sandbox_charity_stream(state);

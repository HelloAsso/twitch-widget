-- Créer la table authorization_code
CREATE TABLE sandbox_authorization_code (
    id CHAR(36) PRIMARY KEY, -- Utilisation de CHAR(36) pour les UUID
    random_string VARCHAR(255) NOT NULL, -- La longueur peut être ajustée selon vos besoins
    organization_slug VARCHAR(255) NOT NULL, -- La longueur peut être ajustée selon vos besoins
    redirect_uri VARCHAR(255) NOT NULL, -- La longueur peut être ajustée selon vos besoins
    creation_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP, -- Date de création avec valeur par défaut
    last_update TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP -- Date de mise à jour automatique
);

-- Ajouter un index unique sur la colonne id
CREATE UNIQUE INDEX idx_id ON sandbox_authorization_code (id);

-- Ajouter un index sur la colonne organization_slug
CREATE INDEX idx_organization_slug ON sandbox_authorization_code (organization_slug);

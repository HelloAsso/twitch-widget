CREATE TABLE prod_access_token_partner_organization (
    id INT AUTO_INCREMENT PRIMARY KEY,
    access_token TEXT NOT NULL,
    refresh_token TEXT NOT NULL,
    organization_slug VARCHAR(255),
    access_token_expires_at DATETIME(6) NOT NULL,
    refresh_token_expires_at DATETIME(6) NOT NULL,
    creation_date DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6),
    last_update DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
);


-- Index sur access_token pour les vérifications rapides d'authentification
CREATE INDEX idx_access_token ON prod_access_token_partner_organization(access_token);

-- Index sur refresh_token pour les vérifications rapides lors des rotations de tokens
CREATE INDEX idx_refresh_token ON prod_access_token_partner_organization(refresh_token);

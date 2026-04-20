-- BUG-09 : Ajoute la date d'expiration du token de réinitialisation de mot de passe
ALTER TABLE users ADD COLUMN reset_token_expires_at DATETIME NULL DEFAULT NULL AFTER reset_token;


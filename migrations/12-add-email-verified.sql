-- Ajoute le champ de vérification d'email pour l'inscription
ALTER TABLE {prefix}users ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 1 AFTER password;


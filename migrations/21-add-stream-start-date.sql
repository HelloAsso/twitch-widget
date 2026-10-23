-- Date à partir de laquelle les paiements sont pris en compte (NULL = tous les paiements).
-- Heure de Paris, voir Stream::TIMEZONE.
ALTER TABLE {prefix}charity_stream
    ADD COLUMN start_date DATETIME NULL DEFAULT NULL AFTER test_amount;

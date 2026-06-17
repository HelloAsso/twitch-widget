ALTER TABLE {prefix}user_right ADD COLUMN is_owner TINYINT(1) NOT NULL DEFAULT 0;

-- All existing event admins were sole owners
UPDATE {prefix}user_right SET is_owner = 1 WHERE id_charity_event IS NOT NULL;

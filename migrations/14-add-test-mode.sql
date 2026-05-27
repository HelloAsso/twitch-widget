ALTER TABLE {prefix}charity_stream
    ADD COLUMN is_test_mode TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN test_amount INT NOT NULL DEFAULT 0;

ALTER TABLE {prefix}charity_event
    ADD COLUMN is_test_mode TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN test_amount INT NOT NULL DEFAULT 0;


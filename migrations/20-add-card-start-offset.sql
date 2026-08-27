ALTER TABLE {prefix}widget_card
    ADD COLUMN start_amount INT NOT NULL DEFAULT 0 AFTER tag_background_color,
    ADD COLUMN start_donors INT NOT NULL DEFAULT 0 AFTER start_amount;

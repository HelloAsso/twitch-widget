ALTER TABLE {prefix}charity_stream ADD COLUMN form_type VARCHAR(50) NOT NULL DEFAULT 'Donation' AFTER form_slug;



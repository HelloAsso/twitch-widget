ALTER TABLE {prefix}charity_stream
    ADD COLUMN live_started_at DATETIME(6) NULL,
    ADD COLUMN live_continuation_token VARCHAR(255) NULL,
    ADD COLUMN live_cache_updated_at DATETIME(6) NULL;

CREATE TABLE {prefix}stream_donation_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    charity_stream_guid CHAR(32) NOT NULL,
    pseudo VARCHAR(255) NOT NULL,
    message TEXT NULL,
    amount INT NOT NULL,
    received_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_{prefix}charity_stream_guid_donation_history FOREIGN KEY (charity_stream_guid) REFERENCES {prefix}charity_stream(guid),
    INDEX idx_stream_received (charity_stream_guid, id)
);

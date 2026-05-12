CREATE TABLE {prefix}widget_card (
    id INT AUTO_INCREMENT PRIMARY KEY,
    charity_stream_guid CHAR(32) NULL,
    charity_event_guid CHAR(32) NULL,
    image VARCHAR(255) NULL,
    tag VARCHAR(255) NOT NULL DEFAULT '',
    title VARCHAR(500) NOT NULL DEFAULT '',
    description TEXT NOT NULL,
    goal INT NOT NULL DEFAULT 1000,
    background_color CHAR(7) NOT NULL DEFAULT '#ffffff',
    bar_color CHAR(7) NOT NULL DEFAULT '#2563eb',
    bar_background_color CHAR(7) NOT NULL DEFAULT '#e5e7eb',
    text_color CHAR(7) NOT NULL DEFAULT '#1a1a1a',
    tag_color CHAR(7) NOT NULL DEFAULT '#166534',
    tag_background_color CHAR(7) NOT NULL DEFAULT '#dcfce7',
    cache_data JSON NULL,
    creation_date DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    last_update DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) NOT NULL,
    CONSTRAINT fk_{prefix}charity_stream_guid_widget_card FOREIGN KEY (charity_stream_guid) REFERENCES {prefix}charity_stream(guid),
    CONSTRAINT fk_{prefix}charity_event_guid_widget_card FOREIGN KEY (charity_event_guid) REFERENCES {prefix}charity_event(guid),
    INDEX idx_charity_stream_guid (charity_stream_guid),
    INDEX idx_charity_event_guid (charity_event_guid)
);


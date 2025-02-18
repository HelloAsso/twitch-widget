ALTER TABLE {prefix}charity_stream DROP COLUMN state;

CREATE TABLE {prefix}charity_event (
    id INT AUTO_INCREMENT PRIMARY KEY,
    guid CHAR(32) NOT NULL UNIQUE, 
    title VARCHAR(500),
    creation_date DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    last_update DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) NOT NULL
);

ALTER TABLE {prefix}charity_stream ADD charity_event_id INT NULL AFTER id;

ALTER TABLE {prefix}widget_alert_box DROP FOREIGN KEY fk_{prefix}charity_stream_guid_alert_box;
ALTER TABLE {prefix}widget_donation_goal_bar DROP FOREIGN KEY fk_{prefix}charity_stream_guid_donation_goal_bar;

ALTER TABLE {prefix}charity_stream ADD COLUMN guid_hex CHAR(32) AFTER guid;
UPDATE {prefix}charity_stream SET guid_hex = LOWER(HEX(guid));
ALTER TABLE {prefix}charity_stream DROP COLUMN guid;
ALTER TABLE {prefix}charity_stream CHANGE guid_hex guid CHAR(32) NOT NULL UNIQUE;

ALTER TABLE {prefix}widget_alert_box ADD COLUMN charity_stream_guid_hex CHAR(32) AFTER charity_stream_guid;
UPDATE {prefix}widget_alert_box SET charity_stream_guid_hex = LOWER(HEX(charity_stream_guid));
ALTER TABLE {prefix}widget_alert_box DROP COLUMN charity_stream_guid;
ALTER TABLE {prefix}widget_alert_box CHANGE charity_stream_guid_hex charity_stream_guid CHAR(32) NOT NULL UNIQUE;
ALTER TABLE {prefix}widget_alert_box ADD cache_data JSON NULL AFTER sound_volume;

ALTER TABLE {prefix}widget_donation_goal_bar ADD COLUMN charity_stream_guid_hex CHAR(32) AFTER charity_stream_guid;
UPDATE {prefix}widget_donation_goal_bar SET charity_stream_guid_hex = LOWER(HEX(charity_stream_guid));
ALTER TABLE {prefix}widget_donation_goal_bar DROP COLUMN charity_stream_guid;
ALTER TABLE {prefix}widget_donation_goal_bar CHANGE charity_stream_guid_hex charity_stream_guid CHAR(32) NULL UNIQUE;

ALTER TABLE {prefix}widget_donation_goal_bar ADD charity_event_guid CHAR(32) NULL AFTER id;
ALTER TABLE {prefix}widget_donation_goal_bar ADD CONSTRAINT fk_{prefix}charity_event_guid_donation_goal_bar FOREIGN KEY (charity_event_guid) REFERENCES {prefix}charity_event(guid);
ALTER TABLE {prefix}widget_donation_goal_bar ADD INDEX idx_charity_event_id (charity_event_guid);
ALTER TABLE {prefix}widget_donation_goal_bar ADD cache_data JSON NULL AFTER goal;

ALTER TABLE {prefix}widget_alert_box ADD CONSTRAINT fk_{prefix}charity_stream_guid_alert_box FOREIGN KEY (charity_stream_guid) REFERENCES {prefix}charity_stream(guid);
ALTER TABLE {prefix}widget_donation_goal_bar ADD CONSTRAINT fk_{prefix}charity_stream_guid_donation_goal_bar FOREIGN KEY (charity_stream_guid) REFERENCES {prefix}charity_stream(guid);

CREATE TABLE sandbox_goals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    charity_stream_guid VARCHAR(255) NULL,
    charity_event_guid VARCHAR(255) NULL,
    amount INT NOT NULL,
    INDEX idx_stream_guid (charity_stream_guid),
    INDEX idx_event_guid (charity_event_guid)
);

INSERT INTO sandbox_goals (charity_stream_guid, amount)
SELECT guid, goal FROM sandbox_charity_stream WHERE goal > 0;

INSERT INTO sandbox_goals (charity_event_guid, amount)
SELECT guid, goal FROM sandbox_charity_event WHERE goal > 0;

ALTER TABLE sandbox_charity_stream DROP COLUMN goal;
ALTER TABLE sandbox_charity_event DROP COLUMN goal;

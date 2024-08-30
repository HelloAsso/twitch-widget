CREATE TABLE {prefix}access_token_partner_organization (
    id INT AUTO_INCREMENT PRIMARY KEY,
    access_token TEXT NOT NULL,
    refresh_token TEXT NOT NULL,
    organization_slug VARCHAR(255),
    access_token_expires_at DATETIME(6) NOT NULL,
    refresh_token_expires_at DATETIME(6) NOT NULL,
    creation_date DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6),
    last_update DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
);


CREATE TABLE {prefix}authorization_code (
    id CHAR(36) PRIMARY KEY,
    code_verifier VARCHAR(255) NOT NULL,
    organization_slug VARCHAR(255) NOT NULL,
    redirect_uri VARCHAR(255) NOT NULL,
    creation_date DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6),
    last_update DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE INDEX idx_id (id),
    INDEX idx_organization_slug (organization_slug)
);


CREATE TABLE {prefix}charity_stream (
    id INT AUTO_INCREMENT PRIMARY KEY,
    guid BINARY(16) NOT NULL UNIQUE, 
	title VARCHAR(500),
    owner_email VARCHAR(255) NOT NULL,
    form_slug VARCHAR(255) NOT NULL,
    organization_slug VARCHAR(255) NOT NULL,
    state TINYINT(1) NOT NULL,
    creation_date DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    last_update DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) NOT NULL,
    INDEX idx_owner_email (owner_email),
    INDEX idx_form_id (form_slug),
    INDEX idx_state (state)
);


CREATE TABLE {prefix}widget_alert_box (
    id INT AUTO_INCREMENT PRIMARY KEY,
    charity_stream_guid BINARY(16) NOT NULL,
    image VARCHAR(255) NULL,
    alert_duration INT NOT NULL,
    message_template TEXT NOT NULL,
    sound VARCHAR(255) NULL,
    sound_volume INT NOT NULL,
    creation_date DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    last_update DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) NOT NULL,
    CONSTRAINT fk_{prefix}charity_stream_guid_alert_box FOREIGN KEY (charity_stream_guid) REFERENCES {prefix}charity_stream(guid),
    INDEX idx_charity_stream_guid (charity_stream_guid),
    INDEX idx_alert_duration (alert_duration)
);


CREATE TABLE {prefix}widget_donation_goal_bar (
    id INT AUTO_INCREMENT PRIMARY KEY,
    charity_stream_guid BINARY(16) NOT NULL,
    text_color CHAR(7) NOT NULL,
    bar_color CHAR(7) NOT NULL,
    background_color CHAR(7) NOT NULL,
    goal INT NOT NULL,
    creation_date DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    last_update DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) NOT NULL,
	CONSTRAINT fk_{prefix}charity_stream_guid_donation_goal_bar FOREIGN KEY (charity_stream_guid) REFERENCES {prefix}charity_stream(guid),
    INDEX idx_charity_stream_id (charity_stream_guid),
    INDEX idx_goal (goal)
);
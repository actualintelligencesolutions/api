CREATE TABLE IF NOT EXISTS devices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    device_uuid VARCHAR(191) NOT NULL,
    device_name VARCHAR(100) NOT NULL,
    platform VARCHAR(50) NULL,
    upi_id VARCHAR(100) NOT NULL,
    recovery_phone VARCHAR(20) NOT NULL,
    owner_pin_hash VARCHAR(255) NOT NULL,
    device_role VARCHAR(20) NOT NULL DEFAULT 'owner',
    owner_device_id BIGINT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_devices_device_uuid (device_uuid),
    KEY idx_devices_upi_id (upi_id),
    KEY idx_devices_recovery_phone (recovery_phone),
    KEY idx_devices_is_active (is_active),
    KEY idx_devices_role (device_role),
    KEY idx_devices_owner_device_id (owner_device_id),
    CONSTRAINT fk_devices_owner_device
        FOREIGN KEY (owner_device_id) REFERENCES devices(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS refresh_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    device_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_refresh_tokens_token_hash (token_hash),
    KEY idx_refresh_tokens_device_id (device_id),
    KEY idx_refresh_tokens_lookup (token_hash, revoked_at, expires_at),
    CONSTRAINT fk_refresh_tokens_device
        FOREIGN KEY (device_id) REFERENCES devices(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS device_claim_grants (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_device_id BIGINT UNSIGNED NOT NULL,
    device_uuid VARCHAR(191) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_device_claim_grants_token_hash (token_hash),
    KEY idx_device_claim_grants_lookup (device_uuid, owner_device_id, expires_at, consumed_at),
    KEY idx_device_claim_grants_owner_device_id (owner_device_id),
    CONSTRAINT fk_device_claim_grants_owner_device
        FOREIGN KEY (owner_device_id) REFERENCES devices(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaigns (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    campaign_key VARCHAR(100) NOT NULL,
    name VARCHAR(150) NOT NULL,
    campaign_type VARCHAR(30) NOT NULL DEFAULT 'announcement',
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    render_mode VARCHAR(20) NOT NULL DEFAULT 'native_json',
    screen_type VARCHAR(20) NOT NULL DEFAULT 'full_screen',
    placement VARCHAR(50) NOT NULL DEFAULT 'app_open',
    audience_role VARCHAR(20) NOT NULL DEFAULT 'all',
    title VARCHAR(150) NOT NULL,
    subtitle VARCHAR(255) NULL,
    body_text TEXT NULL,
    primary_cta_label VARCHAR(80) NULL,
    primary_cta_url VARCHAR(255) NULL,
    secondary_cta_label VARCHAR(80) NULL,
    secondary_cta_url VARCHAR(255) NULL,
    theme_json JSON NULL,
    payload_json JSON NULL,
    html_body MEDIUMTEXT NULL,
    is_dismissible TINYINT(1) NOT NULL DEFAULT 1,
    priority INT NOT NULL DEFAULT 0,
    max_impressions_per_device INT NULL,
    cooldown_minutes INT NULL,
    start_at DATETIME NOT NULL,
    end_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_campaigns_campaign_key (campaign_key),
    KEY idx_campaigns_delivery (status, placement, start_at, end_at, priority),
    KEY idx_campaigns_audience (audience_role, render_mode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaign_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    campaign_id BIGINT UNSIGNED NOT NULL,
    device_uuid VARCHAR(191) NOT NULL,
    event_type VARCHAR(30) NOT NULL,
    cta_id VARCHAR(50) NULL,
    dwell_time_ms INT NULL,
    metadata_json JSON NULL,
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_campaign_events_lookup (campaign_id, device_uuid, event_type, occurred_at),
    KEY idx_campaign_events_device (device_uuid, occurred_at),
    CONSTRAINT fk_campaign_events_campaign
        FOREIGN KEY (campaign_id) REFERENCES campaigns(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deletion_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_type VARCHAR(30) NOT NULL,
    upi_id VARCHAR(100) NOT NULL,
    recovery_phone VARCHAR(20) NOT NULL,
    device_uuid VARCHAR(191) NULL,
    notes TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    completed_at DATETIME NULL,
    review_notes TEXT NULL,
    upi_id_hash CHAR(64) NULL,
    recovery_phone_hash CHAR(64) NULL,
    device_uuid_hash CHAR(64) NULL,
    PRIMARY KEY (id),
    KEY idx_deletion_requests_status_submitted (status, submitted_at),
    KEY idx_deletion_requests_lookup (request_type, upi_id, recovery_phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

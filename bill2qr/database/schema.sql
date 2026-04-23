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

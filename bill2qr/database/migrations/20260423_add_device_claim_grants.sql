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

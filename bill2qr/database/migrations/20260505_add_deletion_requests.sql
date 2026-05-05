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

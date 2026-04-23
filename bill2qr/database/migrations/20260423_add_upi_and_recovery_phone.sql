ALTER TABLE devices
    ADD COLUMN upi_id VARCHAR(100) NULL AFTER platform,
    ADD COLUMN recovery_phone VARCHAR(20) NULL AFTER upi_id,
    ADD KEY idx_devices_upi_id (upi_id),
    ADD KEY idx_devices_recovery_phone (recovery_phone);

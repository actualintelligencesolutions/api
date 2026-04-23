ALTER TABLE devices
    ADD COLUMN owner_pin_hash VARCHAR(255) NULL AFTER recovery_phone;

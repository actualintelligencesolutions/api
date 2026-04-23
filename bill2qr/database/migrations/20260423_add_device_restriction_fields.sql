ALTER TABLE devices
    ADD COLUMN device_role VARCHAR(20) NOT NULL DEFAULT 'owner' AFTER owner_pin_hash,
    ADD COLUMN owner_device_id BIGINT UNSIGNED NULL AFTER device_role,
    ADD KEY idx_devices_role (device_role),
    ADD KEY idx_devices_owner_device_id (owner_device_id),
    ADD CONSTRAINT fk_devices_owner_device
        FOREIGN KEY (owner_device_id) REFERENCES devices(id)
        ON DELETE SET NULL;

UPDATE devices
SET device_role = 'owner'
WHERE device_role IS NULL;

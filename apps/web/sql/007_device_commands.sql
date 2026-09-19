USE salareuniao;

CREATE TABLE IF NOT EXISTS device_commands (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id BIGINT UNSIGNED NOT NULL,
  command_type VARCHAR(80) NOT NULL,
  payload JSON NULL,
  status ENUM('pending','delivered','acked','failed') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  delivered_at DATETIME NULL,
  acked_at DATETIME NULL,
  ack_payload JSON NULL,
  INDEX idx_device_commands_pending (device_id,status,id),
  CONSTRAINT fk_device_commands_device FOREIGN KEY(device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

USE salareuniao;

CREATE TABLE IF NOT EXISTS firmware_releases (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_type ENUM('esp32') NOT NULL DEFAULT 'esp32',
  version VARCHAR(80) NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  file_size BIGINT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  notes TEXT NULL,
  active TINYINT(1) NOT NULL DEFAULT 0,
  required TINYINT(1) NOT NULL DEFAULT 0,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_firmware_type_version (device_type,version),
  INDEX idx_firmware_active (device_type,active,created_at),
  CONSTRAINT fk_firmware_created_by FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB;

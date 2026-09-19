CREATE DATABASE IF NOT EXISTS salareuniao
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE salareuniao;

CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','user') NOT NULL DEFAULT 'user',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE password_reset_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_password_reset_user (user_id,expires_at),
  CONSTRAINT fk_password_reset_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE api_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL DEFAULT 'API',
  expires_at DATETIME NULL,
  last_used_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_api_tokens_user (user_id,revoked_at,expires_at),
  CONSTRAINT fk_api_tokens_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE devices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  device_uid VARCHAR(120) NOT NULL UNIQUE,
  room_id BIGINT UNSIGNED NULL,
  type ENUM('esp32','esp8266','desktop','other') NOT NULL DEFAULT 'esp32',
  active TINYINT(1) NOT NULL DEFAULT 1,
  status VARCHAR(40) NOT NULL DEFAULT 'offline',
  firmware_version VARCHAR(80) NULL,
  ip_address VARCHAR(64) NULL,
  last_seen_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE device_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_device_tokens_device (device_id,revoked_at),
  CONSTRAINT fk_device_tokens_device FOREIGN KEY(device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE device_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(80) NOT NULL,
  payload JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_device_events (device_id,id),
  CONSTRAINT fk_device_events_device FOREIGN KEY(device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE device_commands (
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

CREATE TABLE rooms (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  description TEXT NULL,
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  status ENUM('scheduled','open','closed','cancelled') NOT NULL DEFAULT 'scheduled',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rooms_owner FOREIGN KEY (owner_user_id) REFERENCES users(id)
) ENGINE=InnoDB;

ALTER TABLE devices
  ADD CONSTRAINT fk_devices_room FOREIGN KEY(room_id) REFERENCES rooms(id) ON DELETE SET NULL;

CREATE TABLE room_invites (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  room_id BIGINT UNSIGNED NOT NULL,
  email VARCHAR(190) NOT NULL,
  token CHAR(64) NOT NULL UNIQUE,
  status ENUM('invited','waiting','approved','rejected','left') NOT NULL DEFAULT 'invited',
  display_name VARCHAR(120) NULL,
  participant_key CHAR(64) NULL,
  requested_at DATETIME NULL,
  approved_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_invite_room_status (room_id,status),
  INDEX idx_invite_participant (room_id,participant_key),
  CONSTRAINT fk_invites_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE room_presence (
  room_id BIGINT UNSIGNED NOT NULL,
  participant_key CHAR(64) NOT NULL,
  display_name VARCHAR(120) NOT NULL,
  mic_enabled TINYINT(1) NOT NULL DEFAULT 1,
  cam_enabled TINYINT(1) NOT NULL DEFAULT 1,
  screen_sharing TINYINT(1) NOT NULL DEFAULT 0,
  joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(room_id,participant_key),
  INDEX idx_presence_seen (room_id,last_seen_at),
  CONSTRAINT fk_presence_room FOREIGN KEY(room_id) REFERENCES rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE room_attendance (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  room_id BIGINT UNSIGNED NOT NULL,
  participant_key CHAR(64) NOT NULL,
  display_name VARCHAR(120) NOT NULL,
  joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  left_at DATETIME NULL,
  duration_seconds INT UNSIGNED NULL,
  INDEX idx_attendance_room (room_id,joined_at),
  INDEX idx_attendance_participant (room_id,participant_key),
  CONSTRAINT fk_attendance_room FOREIGN KEY(room_id) REFERENCES rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE room_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  room_id BIGINT UNSIGNED NOT NULL,
  participant_key CHAR(64) NOT NULL,
  display_name VARCHAR(120) NOT NULL,
  message TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_room_messages (room_id,id),
  CONSTRAINT fk_messages_room FOREIGN KEY(room_id) REFERENCES rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE signaling_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  room_id BIGINT UNSIGNED NOT NULL,
  sender_key CHAR(64) NOT NULL,
  recipient_key CHAR(64) NULL,
  message_type ENUM('offer','answer','ice','leave','peer-ready') NOT NULL,
  payload JSON NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_signal_delivery (room_id,recipient_key,id),
  CONSTRAINT fk_signal_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Primeiro administrador:
-- php -r "echo password_hash('SuaSenhaForte', PASSWORD_DEFAULT), PHP_EOL;"
-- INSERT INTO users(name,email,password_hash,role)
-- VALUES ('Administrador','admin@example.com','HASH_GERADO','admin');

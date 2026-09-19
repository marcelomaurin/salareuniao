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

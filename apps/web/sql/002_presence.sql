USE salareuniao;

ALTER TABLE room_invites
  ADD INDEX idx_invite_participant (room_id,participant_key);

CREATE TABLE IF NOT EXISTS room_presence (
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

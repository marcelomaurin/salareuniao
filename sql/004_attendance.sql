USE salareuniao;

CREATE TABLE IF NOT EXISTS room_attendance (
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

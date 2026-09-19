USE salareuniao;

CREATE TABLE IF NOT EXISTS room_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  room_id BIGINT UNSIGNED NOT NULL,
  participant_key CHAR(64) NOT NULL,
  display_name VARCHAR(120) NOT NULL,
  message TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_room_messages (room_id,id),
  CONSTRAINT fk_messages_room FOREIGN KEY(room_id) REFERENCES rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB;

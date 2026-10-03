-- Auditoria e histórico de comandos administrativos da reunião
CREATE TABLE IF NOT EXISTS room_control_audit (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id BIGINT UNSIGNED NOT NULL,
    admin_user_id BIGINT UNSIGNED NULL,
    participant_key CHAR(64) NULL,
    command_id VARCHAR(64) NULL,
    command VARCHAR(80) NOT NULL,
    payload JSON NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'applied',
    result_ack JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rca_room (room_id),
    INDEX idx_rca_cmd (command_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
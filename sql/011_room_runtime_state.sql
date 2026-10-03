-- Estado em tempo de execução da sala (Autoritativo no Servidor)
CREATE TABLE IF NOT EXISTS room_runtime_state (
    room_id BIGINT UNSIGNED PRIMARY KEY,
    room_mode ENUM('normal','presentation') NOT NULL DEFAULT 'normal',
    active_presenter_key CHAR(64) NULL,
    presentation_media_type ENUM('camera','screen') NULL,
    presentation_started_at DATETIME NULL,
    state_version INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_rstate_mode (room_mode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
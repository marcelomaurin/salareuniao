-- Migration 013: Acesso seguro por room_token, auditoria de IP e banimento por sala
-- Maurinsoft Sala Reunião

-- 1. Token público exclusivo da sala (rooms.join_token)
ALTER TABLE rooms ADD COLUMN join_token CHAR(64) NULL AFTER status;
ALTER TABLE rooms ADD UNIQUE KEY uq_rooms_join_token (join_token);

-- Preenche tokens para salas legadas que ainda não tenham join_token
UPDATE rooms 
SET join_token = SHA2(CONCAT(id, '-', UNIX_TIMESTAMP(NOW()), '-', RAND()), 256) 
WHERE join_token IS NULL OR join_token = '';

-- 2. Registro de IP e User-Agent em convites / sala de espera (room_invites)
ALTER TABLE room_invites ADD COLUMN request_ip VARCHAR(64) NULL AFTER participant_key;
ALTER TABLE room_invites ADD COLUMN request_user_agent VARCHAR(255) NULL AFTER request_ip;
ALTER TABLE room_invites ADD INDEX idx_room_invites_ip (room_id, request_ip);

-- 3. Tabela de banimentos por sala (room_bans)
CREATE TABLE IF NOT EXISTS room_bans (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id BIGINT UNSIGNED NOT NULL,
    ip_address VARCHAR(64) NOT NULL,
    display_name VARCHAR(120) NULL,
    original_invite_id BIGINT UNSIGNED NULL,
    reason VARCHAR(255) NULL,
    banned_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,

    UNIQUE KEY uq_room_ban_ip (room_id, ip_address),
    INDEX idx_room_ban_room (room_id),
    INDEX idx_room_ban_ip (ip_address),
    INDEX idx_room_ban_active (room_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

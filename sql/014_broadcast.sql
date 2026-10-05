-- Migration 014: servico broadcast (bcastd)
-- Maurinsoft Sala Reuniao
--
-- Idempotente: pode rodar mais de uma vez. Usa information_schema para
-- funcionar tanto em MySQL 8 quanto em MariaDB 10.x.
--
--   mysql -u root -p salareuniao < sql/014_broadcast.sql

-- Helper: executa @sql somente se @need = 1
-- (padrao repetido abaixo: monta o comando, prepara e executa)

-- 1. room_bans: banimento por identidade (participant_key / e-mail) alem de IP
SET @need = (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'room_bans' AND COLUMN_NAME = 'participant_key');
SET @sql = IF(@need, 'ALTER TABLE room_bans ADD COLUMN participant_key CHAR(64) NULL AFTER ip_address', 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @need = (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'room_bans' AND COLUMN_NAME = 'email');
SET @sql = IF(@need, 'ALTER TABLE room_bans ADD COLUMN email VARCHAR(190) NULL AFTER participant_key', 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @need = (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'room_bans' AND COLUMN_NAME = 'ban_type');
SET @sql = IF(@need, 'ALTER TABLE room_bans ADD COLUMN ban_type ENUM(''ip'',''identity'',''both'') NOT NULL DEFAULT ''ip'' AFTER email', 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

ALTER TABLE room_bans MODIFY ip_address VARCHAR(64) NULL;

-- A UNIQUE (room_id, ip_address) impedia banir de novo o mesmo IP e
-- banimentos so por identidade. Vira indice comum.
SET @need = (SELECT COUNT(*) > 0 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'room_bans' AND INDEX_NAME = 'uq_room_ban_ip');
SET @sql = IF(@need, 'ALTER TABLE room_bans DROP INDEX uq_room_ban_ip', 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @need = (SELECT COUNT(*) = 0 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'room_bans' AND INDEX_NAME = 'idx_room_ban_lookup_ip');
SET @sql = IF(@need, 'ALTER TABLE room_bans ADD INDEX idx_room_ban_lookup_ip (room_id, ip_address, active)', 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @need = (SELECT COUNT(*) = 0 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'room_bans' AND INDEX_NAME = 'idx_room_ban_lookup_email');
SET @sql = IF(@need, 'ALTER TABLE room_bans ADD INDEX idx_room_ban_lookup_email (room_id, email, active)', 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @need = (SELECT COUNT(*) = 0 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'room_bans' AND INDEX_NAME = 'idx_room_ban_lookup_pkey');
SET @sql = IF(@need, 'ALTER TABLE room_bans ADD INDEX idx_room_ban_lookup_pkey (room_id, participant_key, active)', 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- 2. room_invites: estado 'revoked' (convite cancelado pelo organizador)
ALTER TABLE room_invites
  MODIFY status ENUM('invited','waiting','approved','rejected','left','revoked') NOT NULL DEFAULT 'invited';

-- 3. room_presence: estado da conexao e papel, escritos pelo bcastd
SET @need = (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'room_presence' AND COLUMN_NAME = 'conn_state');
SET @sql = IF(@need, 'ALTER TABLE room_presence ADD COLUMN conn_state ENUM(''waiting'',''in_room'') NOT NULL DEFAULT ''in_room''', 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @need = (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'room_presence' AND COLUMN_NAME = 'role');
SET @sql = IF(@need, 'ALTER TABLE room_presence ADD COLUMN role ENUM(''admin'',''viewer'',''speaker'') NOT NULL DEFAULT ''viewer''', 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- 4. room_runtime_state: espelho do estado do bcastd
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

SET @need = (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'room_runtime_state' AND COLUMN_NAME = 'speaker_key');
SET @sql = IF(@need, 'ALTER TABLE room_runtime_state ADD COLUMN speaker_key CHAR(64) NULL, ADD COLUMN speaker_profile JSON NULL, ADD COLUMN locked TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN server_session VARCHAR(40) NULL', 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- 5. Auditoria de comandos (caso a 012 nao tenha sido aplicada)
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

-- 6. Fila de e-mails de convite criados pela sala (enviados por bin/broadcast_outbox.php)
CREATE TABLE IF NOT EXISTS broadcast_outbox (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kind VARCHAR(30) NOT NULL,
    invite_id BIGINT UNSIGNED NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME NULL,
    INDEX idx_outbox_pending (sent_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

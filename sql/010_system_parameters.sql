-- Tabela de parâmetros persistentes do sistema
CREATE TABLE IF NOT EXISTS system_parameters (
    parameter_key VARCHAR(100) PRIMARY KEY,
    parameter_value TEXT NULL,
    parameter_type VARCHAR(30) NOT NULL DEFAULT 'string',
    description VARCHAR(255) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by BIGINT UNSIGNED NULL,
    INDEX idx_sysparam_key (parameter_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO system_parameters (parameter_key, parameter_value, parameter_type, description, updated_at)
VALUES ('webrtc.max_mesh_participants', '4', 'integer', 'Quantidade máxima recomendada de participantes para operação WebRTC Mesh.', NOW())
ON DUPLICATE KEY UPDATE description = VALUES(description);
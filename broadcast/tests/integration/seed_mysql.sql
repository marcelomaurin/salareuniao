-- Dados de teste equivalentes a tests/fixtures/rooms.json para rodar a suite
-- de integracao contra MySQL/MariaDB real:
--   mysql -u root salareuniao_test < tests/integration/seed_mysql.sql
--   python3 tests/integration/test_broadcast.py --bin ./bcastd --mysql salareuniao_test
SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE room_bans; TRUNCATE room_messages; TRUNCATE room_presence; TRUNCATE room_attendance;
TRUNCATE room_invites; TRUNCATE room_admins; TRUNCATE room_runtime_state; TRUNCATE room_control_audit;
TRUNCATE broadcast_outbox; DELETE FROM rooms; DELETE FROM users;
SET FOREIGN_KEY_CHECKS = 1;

INSERT INTO users (id, name, email, password_hash, role) VALUES
 (1, 'Organizador', 'admin@example.com', 'x', 'user'),
 (2, 'Co-organizador', 'coadmin@example.com', 'x', 'user');

INSERT INTO rooms (id, owner_user_id, name, status, join_token) VALUES
 (1, 1, 'Sala de Teste', 'open', REPEAT('a', 64)),
 (2, 1, 'Sala Fechada', 'closed', REPEAT('f', 64));

INSERT INTO room_admins (room_id, user_id) VALUES (1, 2);

INSERT INTO room_invites (id, room_id, email, token, status, display_name, participant_key) VALUES
 (1, 1, 'admin@example.com', REPEAT('b', 64), 'approved', 'Organizador', REPEAT('c', 64)),
 (2, 1, 'convidado@example.com', REPEAT('d', 64), 'approved', 'Convidado Aprovado', REPEAT('e', 64)),
 (3, 1, 'coadmin@example.com', REPEAT('9', 64), 'approved', 'Co-organizador', REPEAT('8', 64));

INSERT INTO room_messages (room_id, participant_key, display_name, message)
 VALUES (1, REPEAT('c', 64), 'Organizador', 'Bem-vindos');

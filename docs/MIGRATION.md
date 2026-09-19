# Plano de Migração

## Estrutura atual

O projeto foi reorganizado como uma plataforma de videoconferência com Web, Desktop, ESP32 e serviços compartilhados.

## Web

A versão Web é PHP + MySQL e já contém autenticação, usuários, salas, convites, sala de espera, aprovação, WebRTC P2P, presença e compartilhamento de tela.

### Banco existente

Quem instalou o banco antes da presença de participantes deve aplicar:

```bash
mysql -u root -p salareuniao < apps/web/sql/002_presence.sql
```

A migration cria `room_presence` e o índice de participante em `room_invites`.

## Próxima etapa operacional

Antes de ampliar Desktop/ESP32, validar em ambiente HTTPS real:

1. dois navegadores na mesma rede;
2. dois navegadores em redes diferentes;
3. câmera e microfone;
4. entrada por convite;
5. aprovação;
6. compartilhamento de tela;
7. remoção e encerramento;
8. comportamento atrás de NAT/firewall.

Depois desses testes deve ser implantado TURN para aumentar a taxa de conexão.

## Legado

O firmware ESP8266/Nextion e o servidor TCP antigo continuam preservados durante a migração. O gateway de dispositivos será evoluído posteriormente para integrar o ESP32 às salas e reuniões.


## Migration 003 — chat

Para instalações existentes:

```bash
mysql -u root -p salareuniao < apps/web/sql/003_chat.sql
```

A migration cria `room_messages`, usada para o chat persistente e para o histórico da reunião.


## Migration 004 — histórico de participação

```bash
mysql -u root -p salareuniao < apps/web/sql/004_attendance.sql
```

Cria `room_attendance`, que registra cada sessão de conexão, entrada, saída e duração do participante. A aplicação também fecha sessões abandonadas quando o heartbeat expira.


## Migration 005 — recuperação de senha

```bash
mysql -u root -p salareuniao < apps/web/sql/005_password_reset.sql
```

Cria `password_reset_tokens`. Os tokens enviados por e-mail não são armazenados em texto puro: apenas o hash SHA-256 fica no banco, com expiração e marcação de uso.


## Migration 006 — API e dispositivos

```bash
mysql -u root -p salareuniao < apps/web/sql/006_api_devices.sql
```

Cria:
- `api_tokens`;
- `devices`;
- `device_tokens`;
- `device_events`.

Tokens são armazenados somente em forma de hash SHA-256.

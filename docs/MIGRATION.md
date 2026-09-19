# Plano de Migração

A reorganização é deliberadamente incremental para preservar o hardware já funcional.

## Fase 1 — organização

- Criar `apps/web`, `apps/desktop` e `apps/esp32`.
- Criar `services/api`, `services/signaling` e `services/device-gateway`.
- Preservar firmware ESP8266/Nextion e servidor TCP existentes.
- Formalizar protocolo legado como Device Protocol v0.

## Fase 2 — backend comum

- Autenticação.
- Usuários e grupos.
- Cadastro de salas.
- Reuniões e agenda.
- API REST.
- WebSocket de eventos.

## Fase 3 — videoconferência

- Cliente Web.
- WebRTC áudio/vídeo.
- Seleção de câmera e microfone.
- Compartilhamento de tela.
- Chat.
- STUN/TURN.
- Depois, SFU quando necessário.

## Fase 4 — Desktop

- Reutilizar contratos da API e sinalização.
- Integração nativa com câmera, áudio, notificações e compartilhamento de tela.

## Fase 5 — ESP32

- Migrar terminal ESP8266 para ESP32.
- Provisionamento seguro de Wi-Fi.
- Identidade do dispositivo.
- WebSocket/MQTT/TCP versionado.
- Agenda/status da sala.
- Botões físicos, LEDs, display e sensores.

## Legado

Durante a transição:
- `hardware/firmware` permanece como fonte original.
- `hardware/nextion` permanece como projeto de display.
- `script/server.py` permanece temporariamente para compatibilidade.
- A nova cópia operacional do gateway fica em `services/device-gateway`.

Após os novos componentes estarem validados, os caminhos antigos poderão ser removidos.

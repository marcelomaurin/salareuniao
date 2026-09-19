# Arquitetura da Plataforma

## Objetivo

Transformar o Sala Reunião em uma plataforma única de comunicação em tempo real, com clientes Web e Desktop e terminais ESP32 integrados às salas físicas.

## Visão lógica

```text
        +-------------------+
        |     Cliente Web   |
        +---------+---------+
                  |
             HTTPS/WSS
                  |
+---------+  +----v------------------+  +----------------+
| Desktop |->| API + Signaling       |<-| Administração  |
+---------+  | Auth / Rooms / Events |  +----------------+
             +----+--------------+----+
                  |              |
              WebRTC          eventos
                  |              |
          +-------v----+   +-----v-----------+
          | STUN/TURN  |   | Device Gateway |
          +------------+   +-----+-----------+
                                |
                         TCP/WSS/MQTT
                                |
                         +------v------+
                         | ESP32/Room  |
                         | Controller  |
                         +-------------+
```

## Responsabilidades

**apps/web**: interface de reunião, câmera/microfone, compartilhamento de tela, chat, participantes, agenda e administração.

**apps/desktop**: mesmas funções centrais do Web, acrescidas de integração nativa, dispositivos de áudio/vídeo, notificações, atalhos e compartilhamento de tela/janelas.

**apps/esp32**: terminal de sala. Não é o endpoint principal de vídeo. Exibe agenda/status, envia presença e telemetria e controla recursos físicos.

**services/api**: autenticação, usuários, salas, reuniões, agenda, permissões, histórico e emissão de tokens.

**services/signaling**: sessões WebSocket e mensagens necessárias ao estabelecimento de conexões WebRTC.

**services/device-gateway**: conexão de ESP8266/ESP32 e outros controladores, mantendo inicialmente compatibilidade com o protocolo existente.

## Domínios principais

- User
- Organization/Group
- Room
- Meeting
- Participant
- Message
- Device
- DeviceState
- DeviceCommand
- Presence
- Recording (futuro)
- File/Attachment (futuro)

## Mídia

A mídia deve ficar separada do tráfego de controle. WebRTC transporta áudio/vídeo/dados de tempo real. API/WebSocket transportam autenticação, agenda, chat, presença e controle.

Para evolução:
1. P2P para protótipo e poucas conexões.
2. STUN/TURN para redes com NAT/firewall.
3. SFU para reuniões com vários participantes e melhor escalabilidade.

## Princípio importante

Os clientes devem compartilhar **contratos**, não implementação de interface. O protocolo e modelos comuns ficam em `packages/`, evitando que Web, Desktop e firmware criem formatos incompatíveis.

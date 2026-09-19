# Sala Reunião — Plataforma de Videoconferência

O **Sala Reunião** está sendo evoluído de um painel de sala ESP8266/Nextion para uma **plataforma de comunicação e videoconferência multiplataforma**, inspirada no modelo de uso de ferramentas como Microsoft Teams.

A arquitetura passa a considerar quatro tipos principais de cliente:

- **Web** — acesso pelo navegador, reuniões, chat, agenda e administração.
- **Desktop** — cliente para Windows/Linux, com integração mais profunda com câmera, microfone, tela e sistema operacional.
- **ESP32 / terminal de sala** — presença física da sala, status, agenda, automação, botões, display, sensores, telemetria e comandos remotos.
- **Backend** — autenticação, usuários, salas, agenda, sinalização WebRTC, eventos, dispositivos e persistência.

> O ESP32 não deve transportar vídeo de conferência como um PC. Ele funciona como **terminal/controlador da sala**, integrado à mesma plataforma.

## Estrutura

```text
salareuniao/
├── apps/
│   ├── web/                # Cliente Web
│   ├── desktop/            # Cliente Desktop
│   └── esp32/              # Firmware de terminais/controladores
├── services/
│   ├── api/                # API principal
│   ├── signaling/          # Sinalização WebRTC
│   └── device-gateway/     # Gateway TCP/WebSocket/MQTT para dispositivos
├── packages/
│   ├── protocol/           # Contratos e mensagens compartilhadas
│   └── common/             # Tipos/utilitários compartilhados
├── hardware/
│   ├── nextion/            # Projeto da interface Nextion existente
│   └── firmware/           # Firmware original preservado durante migração
├── stl/                    # Modelos mecânicos
├── docs/                   # Arquitetura, protocolos e migração
└── IMG/                    # Imagens do projeto
```

## Componentes da plataforma

### Reunião e mídia

A videoconferência deverá usar **WebRTC** nos clientes Web/Desktop. O servidor de sinalização coordena entrada em sala, oferta/resposta SDP, candidatos ICE e estado dos participantes. Para poucas pessoas pode-se iniciar com P2P; para salas maiores a arquitetura deverá permitir adoção posterior de um SFU.

### Salas, usuários e agenda

O backend deverá centralizar usuários, autenticação, salas, reuniões, participantes, convites, agenda, permissões e presença.

### Dispositivos ESP32

O ESP32 representa a sala física. Ele pode exibir agenda/status, indicar reunião em andamento, receber comandos, controlar LEDs/display/relés, publicar telemetria e permitir ações como iniciar, chamar ou encerrar uma reunião no equipamento principal.

### Device Gateway

O antigo servidor TCP passa a ser tratado como **gateway de dispositivos**. Ele mantém compatibilidade com o protocolo atual `GET VAR:=valor`, mas a evolução prevista é um protocolo versionado e autenticado via WebSocket/MQTT/TCP.

## Migração

O código original foi preservado enquanto a nova estrutura é implantada. Consulte:

- `docs/ARCHITECTURE.md`
- `docs/PROTOCOL.md`
- `docs/MIGRATION.md`

## Autor

Marcelo Maurin Martins — MaurinSoft


## Firmware ESP32 atual

O firmware REST v1 está em `apps/esp32/firmware` e já implementa heartbeat, agenda/estado, telemetria, eventos, comandos remotos com ACK, LED/botão e suporte opcional ao Nextion.

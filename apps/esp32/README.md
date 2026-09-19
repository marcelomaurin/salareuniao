# ESP32 — Terminal de Sala

O ESP32 funciona como controlador/terminal físico integrado ao sistema de videoconferência.

O firmware novo está em:

```text
apps/esp32/firmware/
```

O legado ESP8266/Nextion foi preservado em:

```text
apps/esp32/legacy/
```

## Firmware novo

Recursos implementados:

- autenticação por token próprio do dispositivo;
- Wi-Fi com reconexão;
- configuração persistente em NVS/Preferences;
- HTTPS;
- heartbeat;
- telemetria;
- leitura do estado da sala;
- leitura da agenda;
- botão físico;
- LED de estado;
- eventos para o backend;
- comandos servidor -> ESP32;
- ACK de comandos;
- suporte opcional ao Nextion via Serial2;
- provisionamento por Serial;
- PlatformIO.

O ESP32 **não transporta vídeo da conferência**. Ele funciona como terminal/controlador da sala e conversa com o backend por API REST.

Consulte:

```text
apps/esp32/firmware/README.md
```

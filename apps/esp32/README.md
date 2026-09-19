# ESP32 — Terminal de Sala

O ESP32 funciona como controlador/terminal físico integrado ao sistema de videoconferência.

Responsabilidades:
- identificar a sala e o dispositivo;
- exibir agenda e estado da sala;
- informar presença/telemetria;
- receber comandos;
- controlar display, LEDs, botões, relés e sensores;
- solicitar ações ao backend.

O ESP32 **não substitui o cliente Web/Desktop na transmissão de vídeo**. A videoconferência permanece no endpoint com capacidade adequada de áudio/vídeo.

# Device Gateway

Gateway entre a plataforma Sala Reunião e os equipamentos físicos.

Nesta fase ele preserva compatibilidade com o protocolo legado TCP `GET VAR:=valor` usado pelo ESP8266/Nextion.

Evolução prevista:
- identificação única de dispositivo;
- autenticação por dispositivo;
- protocolo v1 estruturado;
- WebSocket e/ou MQTT;
- telemetria;
- fila de comandos e ACK;
- vínculo entre dispositivo e sala;
- tradução temporária entre Device Protocol v0 e v1.

O arquivo `server.py` foi trazido do servidor TCP original para iniciar essa migração sem quebrar o equipamento atual.

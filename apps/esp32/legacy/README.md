# Firmware legado

Esta pasta mantém uma cópia do firmware atual ESP8266 usado com o painel Nextion.

Ele é referência de comportamento para a migração ao ESP32, principalmente:
- persistência de SSID/senha/host/porta/sala;
- sincronização NTP;
- atualização do Nextion;
- protocolo `GET VAR:=valor`;
- comunicação TCP.

A migração para ESP32 deve preservar a compatibilidade funcional antes de introduzir o Device Protocol v1.

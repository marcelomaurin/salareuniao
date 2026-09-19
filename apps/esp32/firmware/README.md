# Firmware ESP32 — Terminal de Sala

Firmware novo do terminal físico do Sala Reunião.

O firmware legado ESP8266/Nextion permanece em `../legacy/`. Esta implementação usa a API REST v1 e autenticação própria de dispositivo.

## Recursos

- ESP32 + Arduino framework;
- Wi-Fi com reconexão;
- credenciais em NVS/Preferences;
- Bearer token exclusivo do dispositivo;
- HTTPS com CA configurável;
- heartbeat;
- leitura de estado da sala;
- leitura de agenda;
- telemetria;
- eventos físicos;
- botão de ação;
- LED de estado;
- fila de comandos servidor -> ESP32;
- ACK de comandos;
- suporte opcional a Nextion via Serial2;
- provisionamento pela Serial;
- Access Point de configuração;
- portal captive para Wi-Fi/API/token;
- configuração forçada pelo botão no boot;
- firmware versionado.

## Estrutura

```text
apps/esp32/firmware/
├── platformio.ini
├── config.h.example
├── salareuniao_esp32.ino
├── src/
│   └── main.cpp
├── .gitignore
└── README.md
```

O arquivo `src/main.cpp` permite compilar com PlatformIO. O `.ino` também pode ser usado como referência no Arduino IDE.

## Dependências

- ESP32 Arduino Core
- ArduinoJson 7.x

O `platformio.ini` instala ArduinoJson automaticamente.

## Configuração inicial

Copie:

```bash
cp config.h.example config.h
```

Não versione `config.h`.

O ideal é deixar SSID, senha e token vazios no arquivo e provisionar pela Serial.

## Cadastro no servidor

Em:

```text
Administração > Dispositivos
```

cadastre o equipamento, por exemplo:

```text
Nome: Painel Sala 1
UID: ESP32-SALA-01
Tipo: esp32
```

O servidor exibirá um token somente no momento da criação/rotação.

## Provisionamento por Access Point

Se o firmware iniciar sem SSID, API ou token válidos, ele cria automaticamente um Access Point.

Nome padrão:

```text
SalaReuniao-XXXXXX
```

onde o sufixo deriva do identificador do ESP32.

Senha padrão:

```text
config123
```

Conecte o celular ou notebook ao AP. O portal captive deve abrir automaticamente; se não abrir, acesse:

```text
http://192.168.4.1/
```

O formulário permite configurar:
- rede Wi-Fi;
- senha Wi-Fi;
- URL base da API;
- token do dispositivo.

A tela também lista redes Wi-Fi encontradas, RSSI incluído.

Após clicar em **Salvar e reiniciar**:
1. a configuração é gravada no NVS;
2. o navegador recebe a confirmação;
3. o ESP32 reinicia;
4. tenta entrar na rede configurada.

### Forçar modo de configuração

Mantenha o botão físico pressionado por aproximadamente 3 segundos durante a inicialização.

O firmware entra no portal mesmo que já tenha Wi-Fi configurado.

Também é possível ativar pela Serial:

```text
PORTAL
```

O portal fecha automaticamente após 5 minutos se já existir uma configuração salva.

Esses valores podem ser ajustados em `config.h`:

```cpp
#define ENABLE_CONFIG_PORTAL 1
#define CONFIG_AP_PREFIX "SalaReuniao-"
#define CONFIG_AP_PASSWORD "config123"
#define CONFIG_PORTAL_TIMEOUT_MS 300000
#define CONFIG_BUTTON_HOLD_MS 3000
```

Use uma senha de AP própria em produção.

## Provisionamento pela Serial

Monitor em 115200 baud.

```text
SET WIFI_SSID=MinhaRede
SET WIFI_PASS=MinhaSenha
SET API_BASE=https://meet.exemplo.com/api/v1
SET DEVICE_TOKEN=TOKEN_GERADO_NO_SERVIDOR
SAVE
RECONNECT
SHOW
```

Outros comandos:

```text
HELP
POLL
HEARTBEAT
COMMANDS
EVENT teste manual
CLEAR
```

`CLEAR` apaga o NVS do namespace do aplicativo.

## TLS

Produção:

```cpp
#define TLS_ALLOW_INSECURE 0
```

e coloque a CA que valida seu servidor em `ROOT_CA`.

Somente para testes controlados pode-se usar:

```cpp
#define TLS_ALLOW_INSECURE 1
```

Nesse modo o firmware avisa pela Serial que não está validando o certificado.

## Temporização padrão

```text
estado/agenda:     15 s
heartbeat:         30 s
comandos:           5 s
telemetria:         5 min
Wi-Fi retry:       10 s
```

## Heartbeat

Endpoint:

```text
POST /api/v1/device/heartbeat.php
```

Exemplo:

```json
{
  "status": "online",
  "firmware_version": "1.0.0",
  "free_heap": 180000,
  "rssi": -58,
  "uptime_seconds": 4200
}
```

## Estado

```text
GET /api/v1/device/state.php
```

O firmware atualiza:
- nome da sala;
- status;
- participantes online;
- próxima reunião;
- horário da próxima reunião.

## Comandos

O ESP32 consulta:

```text
GET /api/v1/device/commands.php
```

Comandos implementados:

```text
refresh       atualiza estado imediatamente
led_on        liga LED
led_off       desliga LED
message       mostra mensagem na Serial/Nextion
nextion_page  troca página Nextion
reboot        reinicia o ESP32
```

Depois responde:

```text
POST /api/v1/device/commands.php
```

com `acked` ou `failed`.

Se um comando for entregue mas o ESP32 cair antes do ACK, o servidor permite nova entrega após 30 segundos.

## Botão físico

Padrão:

```text
GPIO 4
INPUT_PULLUP
```

Pressão curta gera:

```text
device.button
```

Pressão longa (>= 1,5 s):

```text
meeting.call
```

Esses eventos ficam registrados no servidor para automações futuras.

## LED

Padrão:

```text
GPIO 2
```

- reunião `open`: aceso;
- Wi-Fi desconectado: pisca;
- demais estados: apagado.

Os pinos podem ser alterados em `config.h`.

## Nextion opcional

Ative:

```cpp
#define ENABLE_NEXTION 1
```

Padrão:

```text
ESP32 RX2 GPIO16 <- Nextion TX
ESP32 TX2 GPIO17 -> Nextion RX
9600 baud
```

Componentes esperados para compatibilidade inicial:

```text
sala
status
agenda
data
```

O firmware envia comandos Nextion diretamente por Serial2 e não precisa de biblioteca Nextion.

## PlatformIO

Dentro de `apps/esp32/firmware`:

```bash
pio run
pio run -t upload
pio device monitor
```

## Segurança

O firmware não contém login/senha de usuário do Sala Reunião.

Ele recebe somente um token de dispositivo revogável. No banco, o servidor mantém apenas SHA-256 desse token.

Se um equipamento for perdido:

1. Administração > Dispositivos;
2. desative ou rotacione o token;
3. o token anterior deixa de autenticar.


## Fluxo de instalação sem cabo

```text
1. Grave o firmware uma vez
2. Instale o ESP32 na sala
3. Ligue o equipamento
4. Celular encontra SalaReuniao-XXXXXX
5. Conecta no AP
6. Portal captive abre
7. Configura Wi-Fi + API + token
8. ESP32 reinicia
9. Conecta no servidor
10. Aparece online em Administração > Dispositivos
```

Se o equipamento mudar de rede posteriormente, mantenha o botão pressionado durante o boot e refaça apenas a configuração Wi-Fi.

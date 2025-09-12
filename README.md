# 📡 ESP8266 TCP Communication + Python Server

Projeto completo de comunicação entre um **ESP8266** e um **servidor Python** via **TCP**.

O ESP8266 envia e recebe comandos no formato `GET VARIAVEL:=[valor]` e atualiza variáveis locais, persistindo-as na flash.  
O servidor Python recebe, processa, persiste os dados em JSON e responde ao ESP ou a outros clientes.

---

## ✨ Funcionalidades

### 🖥️ ESP8266
- Conexão Wi-Fi com SSID/senha salvos na EEPROM.
- Variáveis persistentes na flash: **SSID**, **senha**, **host**, **porta**, **sala**.
- Atualização de **hora/data** via NTP.
- Comunicação serial com **Nextion** para exibir `sala`, `agenda`, `data`, `status`, `hora`.
- Processa múltiplos comandos recebidos pela serial ou pela rede (TCP).
- Suporte a comandos como:
  - `GET SALA:=Sala Reunião`
  - `GET AGENDA:=Reunião de TI`
  - `GET STATUS:=Em uso`
  - `GET HOST:=meu.servidor.local`
  - `GET PORT:=8085`
  - `GET ALL` → retorna todos os valores atuais.

### 🐍 Servidor Python
- Servidor TCP assíncrono (`asyncio`), suporta múltiplos clientes.
- Processa os mesmos comandos `GET VAR:=VAL` que o ESP.
- Persiste valores em `state.json`.
- Responde com confirmações ou valores atuais.
- Suporte a múltiplos comandos por conexão, inclusive separados por `;`.

---

## 🗂 Estrutura do Projeto

```
📁 esp8266-tcp-project/
 ├── src/
 │   └── esp8266_firmware.ino      # Código completo do ESP8266
 ├── server.py                     # Servidor TCP em Python
 ├── state.json                    # Persistência dos dados (criado em runtime)
 └── README.md                     # Este arquivo
```

---

## 🔧 Instalação e Uso

### 1️⃣ Firmware ESP8266
1. Abra o código `.ino` no Arduino IDE.
2. Configure:
   - Board: **NodeMCU 1.0 (ESP-12E)** ou equivalente.
   - Porta serial correta.
3. Compile e faça upload.

### 2️⃣ Servidor Python
1. Tenha Python 3.8+ instalado.
2. Clone este projeto e instale dependências (nenhuma extra além da stdlib).
3. Execute o servidor:
   ```bash
   python3 server.py --host 0.0.0.0 --port 8090
   ```
4. O servidor criará/atualizará o arquivo `state.json` para salvar valores.

---

## 🔌 Protocolo de Comunicação

Cada comando enviado deve seguir o padrão:

```
GET VARIAVEL:=valor\n
```

### Exemplos:
- `GET SALA:=Laboratório`
- `GET STATUS:=Livre`
- `GET ALL`

Também é aceito o formato sem `GET`:
```
SALA:=Laboratório
STATUS:=Livre
```

E múltiplos comandos em uma única linha:
```
GET SALA:=Lab;AGENDA:=Teste;STATUS:=Livre
```

---

## 📄 Exemplo de `state.json`

```json
{
  "ssid": "maurinsrv_1",
  "pass": "1425361425",
  "host": "maurinsoft.com.br",
  "port": 8090,
  "sala": "Sala 1",
  "agenda": "-",
  "data": "",
  "status": "Livre"
}
```

---

## 📡 Testes Rápidos

Para testar sem o ESP, use `netcat` ou `telnet`:

```bash
nc 127.0.0.1 8090
```

Digite:
```
GET SALA:=Reunião Geral
GET STATUS:=
GET ALL
```

---

## 🛠 Extensões Futuras

- API HTTP REST para leitura/escrita dos valores.
- Integração com banco de dados SQLite.
- Painel web em Flask/Streamlit para visualização em tempo real.
- Reconexão automática do ESP ao alterar SSID/PASS/HOST/PORT.

---

## 📜 Licença

Projeto open-source sob licença MIT.  
Sinta-se à vontade para usar, modificar e contribuir!

---

## 👨‍💻 Autor

Desenvolvido com ❤️ por **Marcelo Maurin Martins** (MaurinSoft) e ChatGPT.

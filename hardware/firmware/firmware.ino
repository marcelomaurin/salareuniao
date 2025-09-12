#include <ESP8266WiFi.h>
#include <WiFiUdp.h>
#include <NTPClient.h>
#include <SoftwareSerial.h>
#include <EEPROM.h>
#include <time.h>

// ====== CONFIG PADRÃO (fallback) ======
#ifndef STASSID
#define STASSID "maurinsrv_1"
#define STAPSK  "1425361425"
#endif
#define HOST_TCP_DEFAULT "maurinsoft.com.br"
#define PORT_CLIENT_DEFAULT 8090

const uint16_t PORT_QOTD = 17;
const long UTC_OFFSET = -3 * 3600;

// Nextion (pinos SoftSerial)
#define D5 (14)
#define D6 (12)
#define BAUD_RATE 9600

// ====== EEPROM LAYOUT ======
#define EEPROM_SIZE   256
#define EEPROM_SSID   0
#define EEPROM_PASS   64
#define EEPROM_SALA   128
#define EEPROM_HOST   192
#define EEPROM_PORT   248   // ocupa 2 bytes (uint16_t)

WiFiUDP ntpUDP;
NTPClient timeClient(ntpUDP, "south-america.pool.ntp.org", UTC_OFFSET);

SoftwareSerial swSer;
String inBuf;
bool haveLine = false;

// Buffers persistentes
char ssidBuf[64];
char passBuf[64];
char salaBuf[64];
char hostBuf[64];
uint16_t portClientValue = PORT_CLIENT_DEFAULT;

// Variáveis locais
String gSala   = "Sala 1";
String gAgenda = "-";
String gData   = "";
String gStatus = "Livre";

static inline bool is_empty_or_ff(const char* p) {
  return (p[0] == '\0' || (uint8_t)p[0] == 0xFF);
}

void eeprom_begin_once() {
  static bool started = false;
  if (!started) { EEPROM.begin(EEPROM_SIZE); started = true; }
}

void eeprom_load_all() {
  eeprom_begin_once();
  EEPROM.get(EEPROM_SSID, ssidBuf);
  EEPROM.get(EEPROM_PASS, passBuf);
  EEPROM.get(EEPROM_SALA, salaBuf);
  EEPROM.get(EEPROM_HOST, hostBuf);
  EEPROM.get(EEPROM_PORT, portClientValue);

  if (is_empty_or_ff(ssidBuf)) strncpy(ssidBuf, STASSID, sizeof(ssidBuf));
  ssidBuf[sizeof(ssidBuf) - 1] = '\0';

  if (is_empty_or_ff(passBuf)) strncpy(passBuf, STAPSK, sizeof(passBuf));
  passBuf[sizeof(passBuf) - 1] = '\0';

  if (is_empty_or_ff(salaBuf)) strncpy(salaBuf, gSala.c_str(), sizeof(salaBuf));
  salaBuf[sizeof(salaBuf) - 1] = '\0';
  gSala = String(salaBuf);

  if (is_empty_or_ff(hostBuf)) strncpy(hostBuf, HOST_TCP_DEFAULT, sizeof(hostBuf));
  hostBuf[sizeof(hostBuf) - 1] = '\0';

  if (portClientValue == 0xFFFF || portClientValue == 0)
    portClientValue = PORT_CLIENT_DEFAULT;
}

void eeprom_save_credentials(const char* newSsid, const char* newPass) {
  eeprom_begin_once();
  if (newSsid && *newSsid) {
    strncpy(ssidBuf, newSsid, sizeof(ssidBuf));
    EEPROM.put(EEPROM_SSID, ssidBuf);
  }
  if (newPass && *newPass) {
    strncpy(passBuf, newPass, sizeof(passBuf));
    EEPROM.put(EEPROM_PASS, passBuf);
  }
  EEPROM.commit();
}

void eeprom_save_sala(const char* novaSala) {
  eeprom_begin_once();
  strncpy(salaBuf, novaSala, sizeof(salaBuf));
  EEPROM.put(EEPROM_SALA, salaBuf);
  EEPROM.commit();
}

void eeprom_save_host(const char* novoHost) {
  eeprom_begin_once();
  strncpy(hostBuf, novoHost, sizeof(hostBuf));
  EEPROM.put(EEPROM_HOST, hostBuf);
  EEPROM.commit();
}

void eeprom_save_port(uint16_t novaPorta) {
  eeprom_begin_once();
  portClientValue = novaPorta;
  EEPROM.put(EEPROM_PORT, portClientValue);
  EEPROM.commit();
}

void wifi_connect() {
  WiFi.mode(WIFI_STA);
  WiFi.begin(ssidBuf, passBuf);
  unsigned long t0 = millis();
  while (WiFi.status() != WL_CONNECTED) {
    delay(300);
    Serial.print(".");
    if (millis() - t0 > 20000) {
      Serial.println("\nWiFi timeout, tentando de novo...");
      WiFi.disconnect();
      WiFi.begin(ssidBuf, passBuf);
      t0 = millis();
    }
  }
  Serial.printf("\nWiFi OK. IP: %s\n", WiFi.localIP().toString().c_str());
}

void time_init() { timeClient.begin(); }
void serial_init() {
  Serial.begin(9600);
  swSer.begin(BAUD_RATE, SWSERIAL_8N1, D5, D6, false, 128);
}

// ====== NEXTION ======
inline void nextionTerminator(Stream& s) {
  s.write(0xFF); s.write(0xFF); s.write(0xFF);
}

void nextionSetTxt(const char* comp, const char* text) {
  swSer.print(comp); swSer.print(".txt=\""); swSer.print(text); swSer.print("\"");
  nextionTerminator(swSer);
}

void nextionSetTxt(const char* comp, const String& text) {
  nextionSetTxt(comp, text.c_str());
}

void nextionPage(const char* page) {
  swSer.print("page "); swSer.print(page); nextionTerminator(swSer);
}

void pushAllFieldsToNextion() {
  nextionSetTxt("sala",   gSala);
  nextionSetTxt("agenda", gAgenda);
  nextionSetTxt("data",   gData);
  nextionSetTxt("status", gStatus);
}

// ====== TCP ======
bool tcpSendString(const char* host, uint16_t port, const String& s) {
  WiFiClient client;
  if (!client.connect(host, port)) {
    Serial.println("TCP falhou");
    return false;
  }
  client.print(s);
  client.flush();
  client.stop();
  return true;
}

// ====== SETTERS ======
void setSala(const String& v)   { gSala   = v; nextionSetTxt("sala",   gSala); }
void setAgenda(const String& v) { gAgenda = v; nextionSetTxt("agenda", gAgenda); }
void setData(const String& v)   { gData   = v; nextionSetTxt("data",   gData); }
void setStatus(const String& v) { gStatus = v; nextionSetTxt("status", gStatus); }

void atualizaDataHoraNextion() {
  String hhmmss = timeClient.getFormattedTime();
  nextionSetTxt("hora", hhmmss);
  time_t epoch = timeClient.getEpochTime();
  struct tm* tm_info = gmtime(&epoch);
  char bufData[16];
  if (tm_info) {
    snprintf(bufData, sizeof(bufData), "%02d/%02d/%04d",
             tm_info->tm_mday, tm_info->tm_mon + 1, tm_info->tm_year + 1900);
    String novaData = String(bufData);
    if (novaData != gData) setData(novaData);
  }
}

// ====== COMANDOS ======
void processaCmd(const String& cmdRaw) {
  // Trata cmdRaw como um “lote” de comandos no mesmo formato da serial:
  //   GET VARIAVEL:=valor\n
  // Suporta múltiplas linhas. Se não for um comando local, envia ao servidor.

  if (cmdRaw.length() == 0) return;

  // Normaliza quebras de linha
  String s = cmdRaw;
  s.replace("\r", "\n");

  int start = 0;
  while (true) {
    int nl = s.indexOf('\n', start);
    String line = (nl == -1) ? s.substring(start) : s.substring(start, nl);
    line.trim();

    if (line.length() > 0) {
      // Caso 1: já vem no padrão “GET ...”
      if (line.startsWith("GET ") || line.startsWith("get ") || line.startsWith("Get ")) {
        handleGetCommand(line);
      }
      // Caso 2: está no formato “VAR:=valor” (sem GET) — tratamos como GET
      else if (line.indexOf(":=") > 0) {
        handleGetCommand(String("GET ") + line);
      }
      // Caso 3: não é comando local — encaminha ao servidor
      else {
        bool ok = tcpSendString(hostBuf, portClientValue, line);
        Serial.printf("Enviado a %s:%u -> %s\n", hostBuf, portClientValue, ok ? "OK" : "ERRO");
      }
    }

    if (nl == -1) break;
    start = nl + 1;
  }
}


void handleGetCommand(const String& line) {
  if (!line.startsWith("GET ")) return;
  int sepPos = line.indexOf(":=");
  String var = "", val = "";
  if (sepPos > 0) { var = line.substring(4, sepPos); val = line.substring(sepPos + 2); }
  else var = line.substring(4);
  var.trim(); val.trim(); var.toLowerCase();

  if (var == "sala") {
    if (val.length()) { setSala(val); eeprom_save_sala(val.c_str()); Serial.println("[OK] sala salva"); }
    Serial.printf("sala=%s\n", gSala.c_str());
  }
  else if (var == "agenda") { if (val.length()) setAgenda(val); Serial.printf("agenda=%s\n", gAgenda.c_str()); }
  else if (var == "data")   { if (val.length()) setData(val); Serial.printf("data=%s\n", gData.c_str()); }
  else if (var == "status") { if (val.length()) setStatus(val); Serial.printf("status=%s\n", gStatus.c_str()); }
  else if (var == "ssid")   { if (val.length()) { eeprom_save_credentials(val.c_str(), passBuf); Serial.println("[OK] ssid salvo"); } Serial.printf("ssid=%s\n", ssidBuf); }
  else if (var == "pass")   { if (val.length()) { eeprom_save_credentials(ssidBuf, val.c_str()); Serial.println("[OK] senha salva"); } Serial.printf("pass=%s\n", passBuf); }
  else if (var == "host")   { if (val.length()) { eeprom_save_host(val.c_str()); Serial.println("[OK] host salvo"); } Serial.printf("host=%s\n", hostBuf); }
  else if (var == "port")   { if (val.length()) { uint16_t p = val.toInt(); if (p>0){ eeprom_save_port(p); Serial.println("[OK] porta salva"); } } Serial.printf("port=%u\n", portClientValue); }
  else if (var == "all") {
    Serial.printf("ssid=%s\npass=%s\nhost=%s\nport=%u\nsala=%s\nagenda=%s\ndata=%s\nstatus=%s\n",
                  ssidBuf, passBuf, hostBuf, portClientValue,
                  gSala.c_str(), gAgenda.c_str(), gData.c_str(), gStatus.c_str());
  }
  else Serial.printf("Variavel '%s' nao encontrada\n", var.c_str());
}

void loopSerialIn() {
  while (swSer.available()) {
    char c = (char)swSer.read();
    if (c == '\n') { haveLine = true; break; }
    if (c != '\r') if (inBuf.length() < 256) inBuf += c;
  }
  if (haveLine) {
    if (inBuf.startsWith("GET ")) handleGetCommand(inBuf);
    else processaCmd(inBuf);
    inBuf = ""; haveLine = false;
  }
}

void setup() {
  serial_init();
  eeprom_load_all();
  wifi_connect();
  time_init();
  inBuf.reserve(256);
  nextionPage("Main");
  setSala(String(salaBuf));
  pushAllFieldsToNextion();
  Serial.println("Setup concluído.");
}

unsigned long lastNtp = 0;
void loop() {
  if (millis() - lastNtp > 1000) {
    timeClient.update();
    atualizaDataHoraNextion();
    lastNtp = millis();
  }
  loopSerialIn();
}

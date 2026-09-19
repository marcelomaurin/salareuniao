#include <WiFi.h>
#include <HTTPClient.h>
#include <WiFiClientSecure.h>
#include <WebServer.h>
#include <DNSServer.h>
#include <Preferences.h>
#include <ArduinoJson.h>
#include "config.h"

Preferences prefs;

struct DeviceConfig {
  String ssid;
  String password;
  String apiBase;
  String token;
};

struct RoomState {
  long roomId = 0;
  String roomName = "-";
  String roomStatus = "offline";
  String startsAt = "";
  int onlineCount = 0;
  String nextMeeting = "-";
  String nextMeetingAt = "";
};

DeviceConfig cfg;
RoomState state;

unsigned long lastHeartbeat = 0;
unsigned long lastStatePoll = 0;
unsigned long lastTelemetry = 0;
unsigned long lastCommandPoll = 0;
unsigned long lastWifiAttempt = 0;
unsigned long buttonDownAt = 0;
bool buttonWasDown = false;

const unsigned long HEARTBEAT_INTERVAL = 30000;
const unsigned long STATE_INTERVAL = 15000;
const unsigned long TELEMETRY_INTERVAL = 300000;
const unsigned long COMMAND_INTERVAL = 5000;
const unsigned long WIFI_RETRY_INTERVAL = 10000;

HardwareSerial Nextion(2);
WebServer configServer(80);
DNSServer dnsServer;
bool configPortalActive = false;
unsigned long configPortalStarted = 0;
String configApSsid;

String normalizeBase(String s) {
  s.trim();
  while (s.endsWith("/")) s.remove(s.length() - 1);
  return s;
}

void loadConfig() {
  prefs.begin("salareuniao", true);
  cfg.ssid = prefs.getString("ssid", DEFAULT_WIFI_SSID);
  cfg.password = prefs.getString("pass", DEFAULT_WIFI_PASS);
  cfg.apiBase = prefs.getString("api", DEFAULT_API_BASE);
  cfg.token = prefs.getString("token", DEFAULT_DEVICE_TOKEN);
  prefs.end();
  cfg.apiBase = normalizeBase(cfg.apiBase);
}

void saveConfig() {
  prefs.begin("salareuniao", false);
  prefs.putString("ssid", cfg.ssid);
  prefs.putString("pass", cfg.password);
  prefs.putString("api", normalizeBase(cfg.apiBase));
  prefs.putString("token", cfg.token);
  prefs.end();
  Serial.println("[OK] Configuração salva no NVS.");
}

void clearConfig() {
  prefs.begin("salareuniao", false);
  prefs.clear();
  prefs.end();
  Serial.println("[OK] NVS limpo. Reinicie o dispositivo.");
}

void printConfig() {
  Serial.println("=== Sala Reunião ESP32 ===");
  Serial.printf("FW: %s\n", FW_VERSION);
  Serial.printf("SSID: %s\n", cfg.ssid.c_str());
  Serial.printf("API: %s\n", cfg.apiBase.c_str());
  Serial.printf("TOKEN: %s\n", cfg.token.length() ? "(configurado)" : "(vazio)");
  Serial.printf("IP: %s\n", WiFi.status() == WL_CONNECTED ? WiFi.localIP().toString().c_str() : "(offline)");
  Serial.printf("RSSI: %d\n", WiFi.status() == WL_CONNECTED ? WiFi.RSSI() : 0);
}

void printHelp() {
  Serial.println();
  Serial.println("Comandos de provisionamento:");
  Serial.println("  SHOW");
  Serial.println("  SET WIFI_SSID=nome");
  Serial.println("  SET WIFI_PASS=senha");
  Serial.println("  SET API_BASE=https://host/api/v1");
  Serial.println("  SET DEVICE_TOKEN=token");
  Serial.println("  SAVE");
  Serial.println("  RECONNECT");
  Serial.println("  PORTAL");
  Serial.println("  POLL");
  Serial.println("  HEARTBEAT");
  Serial.println("  COMMANDS");
  Serial.println("  EVENT texto");
  Serial.println("  CLEAR");
  Serial.println("  HELP");
}

void connectWifi() {
  if (cfg.ssid.isEmpty()) {
    Serial.println("[WIFI] SSID não configurado.");
    return;
  }

  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(true);
  WiFi.persistent(false);
  Serial.printf("[WIFI] Conectando em %s", cfg.ssid.c_str());
  WiFi.begin(cfg.ssid.c_str(), cfg.password.c_str());

  unsigned long started = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - started < 15000) {
    delay(300);
    Serial.print(".");
  }
  Serial.println();

  if (WiFi.status() == WL_CONNECTED) {
    Serial.printf("[WIFI] OK IP=%s RSSI=%d\n",
      WiFi.localIP().toString().c_str(), WiFi.RSSI());
  } else {
    Serial.println("[WIFI] Falha/timeout.");
  }
}

String htmlEscape(const String &s) {
  String out = s;
  out.replace("&", "&amp;");
  out.replace("<", "&lt;");
  out.replace(">", "&gt;");
  out.replace("\"", "&quot;");
  return out;
}

String buildConfigPage(const String &message = "") {
  int n = WiFi.scanNetworks();
  String options;
  for (int i = 0; i < n; i++) {
    String ssid = WiFi.SSID(i);
    options += "<option value=\"" + htmlEscape(ssid) + "\">" +
      htmlEscape(ssid) + " (" + String(WiFi.RSSI(i)) + " dBm)</option>";
  }

  String page = "<!doctype html><html><head><meta charset='utf-8'>"
    "<meta name='viewport' content='width=device-width,initial-scale=1'>"
    "<title>Sala Reunião - Configuração</title>"
    "<style>body{font-family:Arial,sans-serif;background:#f4f6f8;margin:0;padding:20px}"
    ".box{max-width:620px;margin:auto;background:white;padding:22px;border-radius:12px;"
    "box-shadow:0 2px 10px #0002}label{display:block;margin-top:14px;font-weight:bold}"
    "input,select{width:100%;box-sizing:border-box;padding:10px;margin-top:6px}"
    "button{margin-top:20px;padding:12px 16px;background:#2563eb;color:white;border:0;"
    "border-radius:8px}.msg{background:#ecfdf5;padding:10px;border-radius:8px}</style></head><body><div class='box'>"
    "<h1>Sala Reunião</h1><p>Configure a rede e a conexão com o servidor.</p>";

  if (message.length()) page += "<div class='msg'>" + htmlEscape(message) + "</div>";

  page += "<form method='POST' action='/save'>"
    "<label>Redes encontradas</label><select onchange=\"document.getElementById('ssid').value=this.value\">"
    "<option value=''>Selecione...</option>" + options + "</select>"
    "<label>SSID</label><input id='ssid' name='ssid' value='" + htmlEscape(cfg.ssid) + "' required>"
    "<label>Senha Wi-Fi</label><input name='password' type='password' value='" + htmlEscape(cfg.password) + "'>"
    "<label>API Base</label><input name='api' value='" + htmlEscape(cfg.apiBase) + "' required>"
    "<label>Token do dispositivo</label><input name='token' type='password' value='" + htmlEscape(cfg.token) + "' required>"
    "<button type='submit'>Salvar e reiniciar</button></form>"
    "<p style='margin-top:20px;font-size:12px;color:#666'>AP: " + htmlEscape(configApSsid) +
    "<br>IP: " + WiFi.softAPIP().toString() + "</p>"
    "</div></body></html>";
  return page;
}

void handlePortalRoot() {
  configServer.send(200, "text/html; charset=utf-8", buildConfigPage());
}

void handlePortalSave() {
  if (!configServer.hasArg("ssid") || !configServer.hasArg("api") ||
      !configServer.hasArg("token")) {
    configServer.send(400, "text/html; charset=utf-8",
      buildConfigPage("Campos obrigatórios ausentes."));
    return;
  }

  cfg.ssid = configServer.arg("ssid");
  cfg.password = configServer.arg("password");
  cfg.apiBase = normalizeBase(configServer.arg("api"));
  cfg.token = configServer.arg("token");
  saveConfig();

  configServer.send(200, "text/html; charset=utf-8",
    "<html><body style='font-family:Arial;padding:30px'><h2>Configuração salva.</h2>"
    "<p>O dispositivo será reiniciado.</p></body></html>");
  delay(1200);
  ESP.restart();
}

void handlePortalNotFound() {
  configServer.sendHeader("Location", "http://192.168.4.1/", true);
  configServer.send(302, "text/plain", "");
}

String deviceSuffix() {
  uint64_t chipid = ESP.getEfuseMac();
  char buf[7];
  snprintf(buf, sizeof(buf), "%06X", (uint32_t)(chipid & 0xFFFFFF));
  return String(buf);
}

void startConfigPortal() {
#if ENABLE_CONFIG_PORTAL
  if (configPortalActive) return;

  configApSsid = String(CONFIG_AP_PREFIX) + deviceSuffix();
  WiFi.mode(WIFI_AP_STA);

  bool apOk;
  if (strlen(CONFIG_AP_PASSWORD) >= 8)
    apOk = WiFi.softAP(configApSsid.c_str(), CONFIG_AP_PASSWORD);
  else
    apOk = WiFi.softAP(configApSsid.c_str());

  if (!apOk) {
    Serial.println("[PORTAL] Falha ao iniciar Access Point.");
    return;
  }

  dnsServer.start(53, "*", WiFi.softAPIP());
  configServer.on("/", HTTP_GET, handlePortalRoot);
  configServer.on("/generate_204", HTTP_ANY, handlePortalRoot);
  configServer.on("/hotspot-detect.html", HTTP_ANY, handlePortalRoot);
  configServer.on("/ncsi.txt", HTTP_ANY, handlePortalRoot);
  configServer.on("/connecttest.txt", HTTP_ANY, handlePortalRoot);
  configServer.on("/save", HTTP_POST, handlePortalSave);
  configServer.onNotFound(handlePortalNotFound);
  configServer.begin();

  configPortalActive = true;
  configPortalStarted = millis();

  Serial.println();
  Serial.println("=== PORTAL DE CONFIGURAÇÃO ===");
  Serial.printf("SSID: %s\n", configApSsid.c_str());
  Serial.printf("Senha: %s\n", strlen(CONFIG_AP_PASSWORD) ? CONFIG_AP_PASSWORD : "(aberto)");
  Serial.printf("Abra: http://%s/\n", WiFi.softAPIP().toString().c_str());
#else
  Serial.println("[PORTAL] Desabilitado em config.h.");
#endif
}

void stopConfigPortal() {
#if ENABLE_CONFIG_PORTAL
  if (!configPortalActive) return;
  configServer.stop();
  dnsServer.stop();
  WiFi.softAPdisconnect(true);
  configPortalActive = false;
  Serial.println("[PORTAL] Encerrado.");
#endif
}

void handleConfigPortal() {
#if ENABLE_CONFIG_PORTAL
  if (!configPortalActive) return;
  dnsServer.processNextRequest();
  configServer.handleClient();

  if (CONFIG_PORTAL_TIMEOUT_MS > 0 &&
      millis() - configPortalStarted >= CONFIG_PORTAL_TIMEOUT_MS &&
      !cfg.ssid.isEmpty()) {
    stopConfigPortal();
    connectWifi();
  }
#endif
}

bool shouldStartPortalAtBoot() {
#if ENABLE_CONFIG_PORTAL
  if (cfg.ssid.isEmpty() || cfg.apiBase.isEmpty() || cfg.token.isEmpty())
    return true;

  if (digitalRead(PIN_ACTION_BUTTON) != BUTTON_ACTIVE_LEVEL)
    return false;

  unsigned long started = millis();
  while (digitalRead(PIN_ACTION_BUTTON) == BUTTON_ACTIVE_LEVEL) {
    if (millis() - started >= CONFIG_BUTTON_HOLD_MS) return true;
    delay(20);
  }
#endif
  return false;
}

bool configureTls(WiFiClientSecure &client) {
#if TLS_ALLOW_INSECURE
  client.setInsecure();
  Serial.println("[TLS] AVISO: certificado não validado (modo desenvolvimento).");
  return true;
#else
  String ca = String(ROOT_CA);
  if (ca.indexOf("COLE_AQUI") >= 0 || ca.length() < 100) {
    Serial.println("[TLS] ROOT_CA não configurada.");
    return false;
  }
  client.setCACert(ROOT_CA);
  return true;
#endif
}

bool apiRequest(const char *method, const String &path, const String &body,
                String &response, int &httpCode) {
  response = "";
  httpCode = -1;

  if (WiFi.status() != WL_CONNECTED) return false;
  if (cfg.apiBase.isEmpty() || cfg.token.isEmpty()) {
    Serial.println("[API] API/token não configurados.");
    return false;
  }

  String url = normalizeBase(cfg.apiBase) + path;
  HTTPClient http;
  WiFiClientSecure tls;
  WiFiClient plain;

  if (url.startsWith("https://")) {
    if (!configureTls(tls)) return false;
    if (!http.begin(tls, url)) return false;
  } else {
    if (!http.begin(plain, url)) return false;
  }

  http.setTimeout(10000);
  http.addHeader("Accept", "application/json");
  http.addHeader("Content-Type", "application/json");
  http.addHeader("Authorization", "Bearer " + cfg.token);
  http.addHeader("User-Agent", String("SalaReuniaoESP32/") + FW_VERSION);

  if (!strcmp(method, "GET")) {
    httpCode = http.GET();
  } else if (!strcmp(method, "POST")) {
    httpCode = http.POST(body);
  } else {
    http.end();
    return false;
  }

  if (httpCode > 0) response = http.getString();

  bool ok = httpCode >= 200 && httpCode < 300;
  if (!ok) {
    Serial.printf("[API] %s %s -> HTTP %d\n", method, url.c_str(), httpCode);
    if (response.length()) Serial.println(response);
  }

  http.end();
  return ok;
}

void nextionSendRaw(const String &command) {
#if ENABLE_NEXTION
  Nextion.print(command);
  Nextion.write(0xFF);
  Nextion.write(0xFF);
  Nextion.write(0xFF);
#endif
}

void nextionText(const char *component, const String &value) {
#if ENABLE_NEXTION
  String safe = value;
  safe.replace("\"", "'");
  nextionSendRaw(String(component) + ".txt=\"" + safe + "\"");
#endif
}

void updateDisplay() {
  Serial.printf("[STATE] sala=%s status=%s online=%d próxima=%s %s\n",
    state.roomName.c_str(), state.roomStatus.c_str(), state.onlineCount,
    state.nextMeeting.c_str(), state.nextMeetingAt.c_str());

#if ENABLE_NEXTION
  nextionText("sala", state.roomName);
  nextionText("status", state.roomStatus);
  nextionText("agenda", state.nextMeeting);
  nextionText("data", state.nextMeetingAt);
#endif

  if (state.roomStatus == "open") {
    digitalWrite(PIN_STATUS_LED, HIGH);
  } else {
    digitalWrite(PIN_STATUS_LED, LOW);
  }
}

bool sendHeartbeat() {
  JsonDocument doc;
  doc["status"] = "online";
  doc["firmware_version"] = FW_VERSION;
  doc["free_heap"] = ESP.getFreeHeap();
  doc["rssi"] = WiFi.status() == WL_CONNECTED ? WiFi.RSSI() : 0;
  doc["uptime_seconds"] = millis() / 1000;

  String body, response;
  serializeJson(doc, body);
  int code;
  bool ok = apiRequest("POST", "/device/heartbeat.php", body, response, code);
  if (ok) Serial.println("[API] heartbeat OK");
  return ok;
}

bool sendEvent(const String &type, JsonDocument &payload) {
  JsonDocument doc;
  doc["type"] = type;
  doc["payload"].set(payload.as<JsonVariantConst>());

  String body, response;
  serializeJson(doc, body);
  int code;
  bool ok = apiRequest("POST", "/device/events.php", body, response, code);
  if (ok) Serial.printf("[API] evento %s OK\n", type.c_str());
  return ok;
}

bool sendSimpleEvent(const String &type, const String &message) {
  JsonDocument payload;
  payload["message"] = message;
  payload["millis"] = millis();
  return sendEvent(type, payload);
}

bool pollState() {
  String response;
  int code;
  if (!apiRequest("GET", "/device/state.php", "", response, code)) return false;

  JsonDocument doc;
  DeserializationError err = deserializeJson(doc, response);
  if (err) {
    Serial.printf("[JSON] state: %s\n", err.c_str());
    return false;
  }

  JsonObject room = doc["room"];
  if (!room.isNull()) {
    state.roomId = room["id"] | 0;
    state.roomName = String((const char *)(room["name"] | "-"));
    state.roomStatus = String((const char *)(room["status"] | "unknown"));
    state.startsAt = String((const char *)(room["starts_at"] | ""));
    state.onlineCount = room["online_count"] | 0;
  } else {
    state.roomId = 0;
    state.roomName = "-";
    state.roomStatus = "unbound";
    state.startsAt = "";
    state.onlineCount = 0;
  }

  state.nextMeeting = "-";
  state.nextMeetingAt = "";
  JsonArray agenda = doc["agenda"].as<JsonArray>();
  if (!agenda.isNull() && agenda.size() > 0) {
    JsonObject next = agenda[0];
    state.nextMeeting = String((const char *)(next["name"] | "-"));
    state.nextMeetingAt = String((const char *)(next["starts_at"] | ""));
  }

  updateDisplay();
  return true;
}

bool ackCommand(long commandId, const String &status, const String &message) {
  JsonDocument doc;
  doc["command_id"] = commandId;
  doc["status"] = status;
  doc["ack_payload"]["message"] = message;
  doc["ack_payload"]["firmware_version"] = FW_VERSION;

  String body, response;
  serializeJson(doc, body);
  int code;
  return apiRequest("POST", "/device/commands.php", body, response, code);
}

bool executeCommand(JsonObject cmd) {
  long id = cmd["id"] | 0;
  String type = String((const char *)(cmd["command_type"] | ""));
  JsonObject payload = cmd["payload"].as<JsonObject>();
  String value = payload.isNull() ? "" : String((const char *)(payload["value"] | ""));

  Serial.printf("[CMD] id=%ld type=%s value=%s\n", id, type.c_str(), value.c_str());

  if (type == "refresh") {
    bool ok = pollState();
    ackCommand(id, ok ? "acked" : "failed", ok ? "state refreshed" : "refresh failed");
    return ok;
  }

  if (type == "led_on") {
    digitalWrite(PIN_STATUS_LED, HIGH);
    ackCommand(id, "acked", "led on");
    return true;
  }

  if (type == "led_off") {
    digitalWrite(PIN_STATUS_LED, LOW);
    ackCommand(id, "acked", "led off");
    return true;
  }

  if (type == "message") {
    Serial.printf("[MESSAGE] %s\n", value.c_str());
#if ENABLE_NEXTION
    nextionText("agenda", value);
#endif
    ackCommand(id, "acked", "message displayed");
    return true;
  }

  if (type == "nextion_page") {
#if ENABLE_NEXTION
    nextionSendRaw(String("page ") + value);
    ackCommand(id, "acked", "page changed");
    return true;
#else
    ackCommand(id, "failed", "Nextion disabled");
    return false;
#endif
  }

  if (type == "reboot") {
    ackCommand(id, "acked", "rebooting");
    delay(300);
    ESP.restart();
    return true;
  }

  ackCommand(id, "failed", "unknown command");
  return false;
}

bool pollCommands() {
  String response;
  int code;
  if (!apiRequest("GET", "/device/commands.php", "", response, code)) return false;

  JsonDocument doc;
  DeserializationError err = deserializeJson(doc, response);
  if (err) {
    Serial.printf("[JSON] commands: %s\n", err.c_str());
    return false;
  }

  JsonArray commands = doc["commands"].as<JsonArray>();
  if (commands.isNull()) return true;

  for (JsonObject cmd : commands) {
    executeCommand(cmd);
  }
  return true;
}

void sendTelemetry() {
  JsonDocument payload;
  payload["free_heap"] = ESP.getFreeHeap();
  payload["min_free_heap"] = ESP.getMinFreeHeap();
  payload["rssi"] = WiFi.status() == WL_CONNECTED ? WiFi.RSSI() : 0;
  payload["uptime_seconds"] = millis() / 1000;
  payload["wifi_connected"] = WiFi.status() == WL_CONNECTED;
  payload["room_status"] = state.roomStatus;
  payload["room_id"] = state.roomId;
  sendEvent("device.telemetry", payload);
}

void handleActionButton() {
  bool down = digitalRead(PIN_ACTION_BUTTON) == BUTTON_ACTIVE_LEVEL;

  if (down && !buttonWasDown) {
    buttonDownAt = millis();
  }

  if (!down && buttonWasDown) {
    unsigned long duration = millis() - buttonDownAt;
    JsonDocument payload;
    payload["duration_ms"] = duration;
    payload["room_id"] = state.roomId;
    payload["room_status"] = state.roomStatus;

    if (duration >= 1500) {
      payload["action"] = "long_press";
      sendEvent("meeting.call", payload);
      Serial.println("[BTN] meeting.call");
    } else {
      payload["action"] = "short_press";
      sendEvent("device.button", payload);
      Serial.println("[BTN] device.button");
    }
  }

  buttonWasDown = down;
}

void processSerialLine(String line) {
  line.trim();
  if (line.isEmpty()) return;

  if (line.equalsIgnoreCase("HELP")) printHelp();
  else if (line.equalsIgnoreCase("SHOW")) printConfig();
  else if (line.equalsIgnoreCase("SAVE")) saveConfig();
  else if (line.equalsIgnoreCase("CLEAR")) clearConfig();
  else if (line.equalsIgnoreCase("RECONNECT")) {
    WiFi.disconnect(true, false);
    delay(200);
    connectWifi();
  }
  else if (line.equalsIgnoreCase("PORTAL")) startConfigPortal();
  else if (line.equalsIgnoreCase("POLL")) pollState();
  else if (line.equalsIgnoreCase("HEARTBEAT")) sendHeartbeat();
  else if (line.equalsIgnoreCase("COMMANDS")) pollCommands();
  else if (line.startsWith("EVENT ")) sendSimpleEvent("device.manual", line.substring(6));
  else if (line.startsWith("SET ")) {
    int eq = line.indexOf('=');
    if (eq < 5) {
      Serial.println("[ERR] use SET CHAVE=valor");
      return;
    }
    String key = line.substring(4, eq);
    String value = line.substring(eq + 1);
    key.trim();
    value.trim();
    key.toUpperCase();

    if (key == "WIFI_SSID") cfg.ssid = value;
    else if (key == "WIFI_PASS") cfg.password = value;
    else if (key == "API_BASE") cfg.apiBase = normalizeBase(value);
    else if (key == "DEVICE_TOKEN") cfg.token = value;
    else {
      Serial.println("[ERR] chave desconhecida.");
      return;
    }
    Serial.printf("[OK] %s alterado em RAM; use SAVE.\n", key.c_str());
  }
  else {
    Serial.println("[ERR] comando desconhecido. Digite HELP.");
  }
}

void handleSerial() {
  static String line;
  while (Serial.available()) {
    char c = Serial.read();
    if (c == '\n') {
      processSerialLine(line);
      line = "";
    } else if (c != '\r' && line.length() < 1024) {
      line += c;
    }
  }
}

void setup() {
  pinMode(PIN_STATUS_LED, OUTPUT);
  digitalWrite(PIN_STATUS_LED, LOW);
  pinMode(PIN_ACTION_BUTTON, INPUT_PULLUP);

  Serial.begin(115200);
  delay(400);
  Serial.println();
  Serial.println("Sala Reunião ESP32 iniciando...");

#if ENABLE_NEXTION
  Nextion.begin(NEXTION_BAUD, SERIAL_8N1, NEXTION_RX_PIN, NEXTION_TX_PIN);
  delay(300);
#endif

  loadConfig();
  printConfig();
  printHelp();

  if (shouldStartPortalAtBoot()) {
    startConfigPortal();
  } else {
    connectWifi();
    if (WiFi.status() != WL_CONNECTED) startConfigPortal();
  }

  if (WiFi.status() == WL_CONNECTED) {
    sendHeartbeat();
    pollState();
  }

  unsigned long now = millis();
  lastHeartbeat = now;
  lastStatePoll = now;
  lastTelemetry = now;
  lastCommandPoll = now;
}

void loop() {
  handleSerial();
  handleActionButton();
  handleConfigPortal();

  unsigned long now = millis();

  if (configPortalActive) {
    digitalWrite(PIN_STATUS_LED, (now / 200) % 2);
    delay(2);
    return;
  }

  if (WiFi.status() != WL_CONNECTED) {
    digitalWrite(PIN_STATUS_LED, (now / 500) % 2);
    if (now - lastWifiAttempt >= WIFI_RETRY_INTERVAL) {
      lastWifiAttempt = now;
      connectWifi();
    }
    delay(5);
    return;
  }

  if (now - lastHeartbeat >= HEARTBEAT_INTERVAL) {
    lastHeartbeat = now;
    sendHeartbeat();
  }

  if (now - lastStatePoll >= STATE_INTERVAL) {
    lastStatePoll = now;
    pollState();
  }

  if (now - lastTelemetry >= TELEMETRY_INTERVAL) {
    lastTelemetry = now;
    sendTelemetry();
  }

  if (now - lastCommandPoll >= COMMAND_INTERVAL) {
    lastCommandPoll = now;
    pollCommands();
  }

  delay(5);
}

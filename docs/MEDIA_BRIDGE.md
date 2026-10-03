# PHP Media Bridge Relay — Sala Reunião (Tarefas 39 a 78)

## 1. Visão Geral da Arquitetura

O **PHP Media Bridge Relay** é um mecanismo experimental de fallback audiovisual implementado exclusivamente em **PHP (Ratchet / ReactPHP)** sem dependência de daemons externos (Janus, Mediasoup, Kurento) nem portas públicas adicionais além da porta **443 (HTTPS/WSS)**.

```text
NAVEGADOR A (Emissor/Apresentador)
  │
  ├── 1. Tenta WebRTC Mesh P2P normal
  │
  └── 2. Se WebRTC falhar (ou em Modo Apresentação Full):
        ▼
   MediaRecorder (WebM VP8/VP9 + Opus, blocos de ~250ms)
        ▼
   WSS binário direto (cabeçalho de timestamp 8 bytes)
        ▼
   APACHE (ProxyPass :443)
        ▼
   PHP Ratchet Daemon (127.0.0.1:8089/bridge)
        ▼ Zero transcoding, redistribuição em memória RAM
   APACHE (ProxyPass :443)
        ▼
   WSS binário direto
        ▼
   NAVEGADOR B / C / D (Espectadores)
        ▼
   MediaSource Extensions (MSE) + SourceBuffer (fila sequencial + descarte de atraso)
        ▼
   Elemento <video>
```

---

## 2. Princípios de Implementação

1. **Zero Transcodificação no PHP**:
   - O PHP não codifica, não decodifica e não converte mídia.
   - O navegador codifica nativamente via `MediaRecorder` usando `video/webm;codecs=vp8,opus`.
   - O PHP atua puramente como um relay binário assíncrono em memória (`socket -> RAM -> socket`).
   - Zero gravação em disco (`/tmp` ou similar).

2. **Autenticação Autoritativa**:
   - Conexão via `wss://maurinsoft.com.br/salareuniao/bridge?token=...`.
   - O PHP autentica o token via `room_invites`, verifica status de aprovação, expiração e bans de IP.
   - O `room_id` nunca é aceito do cliente; é obtido diretamente do registro autenticado no MySQL.

3. **Modo Apresentação (Full)**:
   - Em Modo Apresentação, o apresentador publica audiovisual no Bridge.
   - Todos os demais participantes suspendem o envio de vídeo e apenas assinam o stream do apresentador.

4. **Fallback Automático WebRTC**:
   - O WebRTC continua sendo o transporte primário de baixa latência.
   - Se 3 tentativas de `restartIce` falharem ou o estado persistir em `failed` por mais de 8 segundos, o watchdog aciona `MeetingBridge.activateFallback(remoteKey)`.
   - Quando o WebRTC estabilizar por mais de 3 segundos no estado `connected`, o cliente retorna automaticamente ao WebRTC (`WEBRTC_RESTORED`).

---

## 3. Configuração do Apache (Proxy Reverso WSS na Porta 443)

Não há exposição de porta pública `:8089`. No arquivo de configuração do VirtualHost SSL do Apache (`/etc/apache2/sites-available/...`):

```apache
<VirtualHost *:443>
    ServerName maurinsoft.com.br
    # ... configurações SSL normais ...

    # Habilitar módulos: proxy, proxy_http, proxy_wstunnel
    # a2enmod proxy proxy_wstunnel

    ProxyRequests Off
    ProxyPreserveHost On

    # 1. Canal de Controle Administrativo
    ProxyPass /salareuniao/control ws://127.0.0.1:8089/control
    ProxyPassReverse /salareuniao/control ws://127.0.0.1:8089/control

    # 2. Canal Media Bridge de Mídia (Tarefas 75 e 76)
    ProxyPass /salareuniao/bridge ws://127.0.0.1:8089/bridge
    ProxyPassReverse /salareuniao/bridge ws://127.0.0.1:8089/bridge

    # Aplicação PHP Sala Reunião normal
    # ...
</VirtualHost>
```

---

## 4. Execução do Daemon Ratchet Unificado

O mesmo daemon PHP atende as duas rotas simultaneamente (`/control` e `/bridge`):

```bash
php services/control/server.php
```

Parâmetros configurados em `config.php`:
```php
'bridge' => [
    'enabled' => true,
    'public_url' => 'wss://maurinsoft.com.br/salareuniao/bridge',
    'path' => '/bridge',
    'max_clients_per_room' => 20,
    'max_message_bytes' => 524288,   // 512 KB
    'max_buffer_bytes' => 4194304,   // 4 MB
    'video_bitrate' => 350000,       // 350 kbps
    'audio_bitrate' => 48000,        // 48 kbps
    'chunk_ms' => 250,               // 250 ms por bloco
    'fallback_timeout_ms' => 8000,   // 8 s para ativação de fallback
],
```

---

## 5. Diagnóstico e Métricas em Tempo Real

No painel de diagnóstico (botão **Ações > Diagnóstico**):
- **Transporte de Mídia**: `● WebRTC (Mesh P2P)` ou `● Bridge HTTPS (Ratchet Relay)`
- **Métricas do Bridge**:
  - Latência fim-a-fim (`receiving_time - capturing_time` via cabeçalho de 8 bytes Float64)
  - Fila de buffer (KB)
  - Bitrate estimado (kbps)
  - Chunks descartados por atraso (> 8 blocos em fila)
  - Volume de dados enviados e recebidos

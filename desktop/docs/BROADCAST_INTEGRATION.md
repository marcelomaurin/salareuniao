# Integração com o Protocolo Específico do Broadcast (bcastd) · Sala Reunião Desktop

Este documento detalha o **protocolo de videoconferência de alta eficiência do `bcastd`** (`linuxsrv/broadcast`), implementado diretamente no cliente desktop em Lazarus.

---

## 1. Visão Geral do Protocolo de Videoconferência

O `bcastd` não utiliza SIP nem SFU tradicional RTP/RTCP pesado. Ele emprega um **protocolo proprietário otimizado para conferências e apresentações centralizadas**, trafegando em uma única conexão multiplexada por participante (via WebSocket ou WebRTC DataChannel):

1. **Plano de Controle (Quadros de Texto JSON):**
   - Versionamento estrito: `{"v": 1, "t": "<tipo>", ...}`.
   - Idempotência administrativa via `id` (UUID v4) e confirmações `ack` com `ref`.
2. **Plano de Mídia Audiovisual (Quadros Binários):**
   - Cabeçalho binário fixo de **16 bytes big-endian** (`magic 0xB5`).
   - Carga útil contendo fatias de vídeo e áudio **WebM (VP8/VP9 + Opus)** produzidas pelo codec de hardware/software.
   - Tratamento inteligente de estruturas EBML no servidor (reconhecimento de cabeçalhos de inicialização e limites de Cluster/Keyframe para reconexão sem perda de sinc).

---

## 2. Estrutura Binária dos Quadros de Mídia (Header de 16 Bytes)

Todos os pacotes de áudio/vídeo transmitidos ou recebidos contêm o cabeçalho fixo:

```text
 0                   1                   2                   3
 0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1
+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+
|  Magic (0xB5) |  Tipo (1/2)   |     Flags     |    Geração    |
+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+
|                       Sequência (seq)                         |
+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+
|                                                               |
+                      Timestamp (64 bits, ms)                  +
|                                                               |
+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+
|                     Payload WebM (Variável)                   |
|                              ...                              |
+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+
```

### Detalhamento dos Campos:
| Offset (Bytes) | Campo | Tipo | Descrição |
| :--- | :--- | :--- | :--- |
| **0** | `magic` | `UInt8` | Sempre `0xB5` (identificador único do pacote de broadcast). |
| **1** | `tipo` | `UInt8` | `1` = Cabeçalho de Inicialização (EBML Header), `2` = Dados de Mídia contínuos. |
| **2** | `flags` | `UInt8` | Bit 0 (`0x01`): Marca início de Cluster com **Keyframe** de vídeo. |
| **3** | `geracao` | `UInt8` | ID da geração da transmissão (incrementado a cada `media.init`). |
| **4–7** | `seq` | `UInt32 BE` | Número sequencial monotônico para detecção de pacotes perdidos ou desordenados. |
| **8–15** | `timestamp` | `UInt64 BE` | Carimbo de tempo do emissor em milissegundos (usado para cálculo de latência e jitter). |
| **16+** | `payload` | Binário | Fluxo de bytes WebM. |

---

## 3. Implementação da Unidade Pascal (`uBcastProtocol.pas`)

No Lazarus, a manipulação deste cabeçalho é encapsulada em tipos nativos do Free Pascal:

```pascal
type
  TBcastFrameType = (bftInit = 1, bftData = 2);

  {$PACKRECORDS 1}
  TBcastHeader = record
    Magic: Byte;         // 0xB5
    FrameType: Byte;     // 1=Init, 2=Data
    Flags: Byte;         // bit 0 = Keyframe
    Generation: Byte;    // Contador de geração
    Seq: Cardinal;       // Big-endian
    Timestamp: QWord;    // Big-endian (ms)
  end;
  {$PACKRECORDS DEFAULT}

function DecodeBcastHeader(const Buffer: PByte; BufferSize: Integer; out Header: TBcastHeader): Boolean;
var
  H: TBcastHeader;
begin
  Result := False;
  if BufferSize < SizeOf(TBcastHeader) then Exit;
  Move(Buffer^, H, SizeOf(TBcastHeader));
  if H.Magic <> $B5 then Exit;

  Header.Magic := H.Magic;
  Header.FrameType := H.FrameType;
  Header.Flags := H.Flags;
  Header.Generation := H.Generation;
  Header.Seq := BEtoN(H.Seq);
  Header.Timestamp := BEtoN(H.Timestamp);
  Result := True;
end;
```

---

## 4. Máquina de Estados e Ciclo de Vida da Conexão

```text
[DESCONECTADO]
      │
      ▼ Conectar WebSocket / DataChannel
[HANDSHAKE]
      │
      ▼ Enviar {"v":1,"t":"hello", ...}
[AUTENTICAÇÃO]
      │
      ├─ Se role = admin ou convite de host ─────────► [IN_ROOM (Videoconferência)]
      │                                                      ▲
      └─ Se usuário comum / sem permissão direta ─► [WAITING] │
                                                       │      │
                                         admin.admit   └──────┘
```

### Estados de Transmissão do Participante:
- **`viewer`:** Apenas recebe áudio e vídeo distribuídos pelo servidor.
- **`hand`:** Participante com a mão levantada na fila FIFO aguardando permissão.
- **`pending`:** O administrador concedeu a palavra (`speaker.you`); o cliente prepara a câmera/microfone e envia `media.init`.
- **`speaking`:** O cliente envia ativamente quadros binários de mídia (`0xB5` + WebM); o servidor redistribui para todos os ouvintes da sala.

---

## 5. Renderização e Decodificação no Cliente Lazarus

Para consumir e reproduzir o fluxo WebM de forma fluida, o cliente desktop oferece:
1. **Pipeline Acelerado com CEF4Delphi:**
   - O componente embutido consome o WebSocket diretamente e alimenta `MediaSource Extensions (MSE)` com `video/webm; codecs="vp8,opus"`, proporcionando aceleração total por GPU (D3D11/OpenGL).
2. **Integração Bidirecional LCL:**
   - O aplicativo nativo Lazarus intercepta todas as mensagens de controle, mantendo a grade visual, botões de ação e moderação perfeitamente sincronizados com o servidor `bcastd`.

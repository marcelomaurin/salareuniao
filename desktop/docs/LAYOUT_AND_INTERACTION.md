# Sistema de Layout Dinâmico, Menu Views e Interação · Sala Reunião Desktop

Este documento detalha o sistema de visualização, o menu **Views** (Organização) e a interação por participante, baseado na interface corporativa de referência do **Microsoft Teams**.

---

## 1. Barra Superior de Controle e Status

```text
┌────────────────────────────────────────────────────────────────────────────────────────┐
│ [●] 22:06              "Título da Reunião"          [💬] [👥9] [✋] [😊] [🪟Views] ... [📷] [🎤] [⬆] [Leave] │
└────────────────────────────────────────────────────────────────────────────────────────┘
```

- **Lado Esquerdo:**
  - Indicador de status/gravação `[●]`.
  - Cronômetro decorrido em tempo real (`22:06`).
- **Centro:**
  - Nome/Título da Sala ou Reunião em andamento (ex.: *"Planejamento Q4"*).
- **Lado Direito (Ações Rápidas):**
  - 💬 **Chat:** Alterna o painel de mensagens instantâneas.
  - 👥 **People (Contador):** Abre a lista de participantes, contadores e sala de espera (lobby).
  - ✋ **Raise:** Levanta/abaixa a mão na fila FIFO (`hand.raise` / `hand.lower`).
  - 😊 **React:** Reações rápidas (palmas, coração, joinha).
  - 🪟 **Views (Menu de Organização):** Menu suspenso para alternar os modos de layout.
  - ⋯ **More:** Configurações de dispositivos (câmera, microfone, saída de áudio).
  - 📷 **Camera:** Alterna transmissão de vídeo local.
  - 🎤 **Mic:** Alterna mudo/ativo do microfone.
  - ⬆ **Share:** Compartilhamento de tela ou janela.
  - 🛑 **Leave (Botão Vermelho):** Sair ou encerrar a reunião.

---

## 2. Menu de Modos de Exibição (Dropdown `Views`)

O botão **Views** abre um menu suspenso nativo com as opções de organização da sala:

```text
┌───────────────────────────────────────┐
│ [✓] Gallery (Grade Dinâmica)          │
│ [ ] Speaker (Foco no Orador Ativo)    │
│ [ ] Spotlight (Participante Fixado)   │
│ [ ] Large gallery (Muitos Vídeos)     │
│ [ ] Focus on content (Foco no Slide)  │
├───────────────────────────────────────┤
│ Prioritize videos                     │
│ Select max gallery size >             │
│ More options >                        │
│   ┌─────────────────────────────────┐ │
│   │ [✓] Gallery at side / top       │ │
│   │ Turn off incoming video         │ │
│   │ Hide me / Self-view             │ │
│   │ [⛶] Full screen                 │ │
│   └─────────────────────────────────┘ │
└───────────────────────────────────────┘
```

### Comportamento dos Modos:
1. **Gallery (Grade):** Distribuição equilibrada de todos os participantes na tela em matriz adaptativa (1x1, 2x2, 3x3, etc.).
2. **Speaker (Orador):** O participante que estiver falando ou com a palavra concedida pelo `bcastd` assume o palco principal; os demais vão para a galeria de miniaturas.
3. **Spotlight / Pin (Participante Selecionado):**
   - O usuário seleciona qualquer pessoa.
   - **Esta pessoa ganha o palco principal amplo.**
   - **Todos os demais participantes são automaticamente reduzidos para miniaturas.**
4. **Posicionamento da Galeria de Miniaturas:**
   - **Galeria Lateral (`Gallery at side`):** Miniaturas em coluna vertical à direita (padrão em telas widescreen).
   - **Galeria Superior (`Gallery at top`):** Faixa horizontal de miniaturas no topo, liberando toda a largura inferior para o vídeo principal.

---

## 3. Ações no Cartão do Participante (Menu `⋯` Contextual)

Cada cartão de participante na grade exibe:
- Nome completo no rodapé esquerdo.
- Ícone de microfone (mudo ou ativo).
- Borda de destaque visual (accent) quando o participante estiver com a palavra.
- Botão de opções `⋯` que aciona um menu contextual:
  - 📌 **Pin for me (Fixar na minha tela):** Coloca o participante no palco grande exclusivamente para este usuário.
  - ⭐ **Spotlight for everyone (Destacar para todos):** Comando administrativo (`admin.speaker.set`) que coloca a pessoa no palco principal para todos os presentes.
  - 🔇 **Mute participant:** Silencia o microfone do participante (`admin.mute`).
  - 🚫 **Remove from meeting:** Expulsar participante (`admin.kick`).

---

## 4. Estrutura de Classes no Lazarus (`src/`)

```pascal
type
  TViewMode = (vmGallery, vmSpeaker, vmSpotlight, vmLargeGallery);
  TGalleryPosition = (gpRight, gpTop);

  TMeetingViewLayout = class
  private
    FViewMode: TViewMode;
    FGalleryPosition: TGalleryPosition;
    FPinnedParticipantKey: string;
    FStageControl: TControl;
    FGalleryContainer: TWinControl;
  public
    procedure SetViewMode(AMode: TViewMode);
    procedure SetGalleryPosition(APos: TGalleryPosition);
    procedure PinParticipant(const APKey: string);
    procedure Unpin;
    procedure RecalculateBounds(ParentWidth, ParentHeight: Integer);
    property ViewMode: TViewMode read FViewMode;
    property GalleryPosition: TGalleryPosition read FGalleryPosition;
    property PinnedKey: string read FPinnedParticipantKey;
  end;
```

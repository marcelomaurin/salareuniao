# Sistema de Layout Dinâmico e Seleção de Participantes · Sala Reunião Desktop

Este documento especifica o comportamento interativo de **reorganização de janelas e redimensionamento dinâmico por seleção de participante** na aplicação Lazarus Desktop.

---

## 1. Princípio de Funcionamento (Pin / Spotlight Dinâmico)

O usuário da aplicação possui controle total sobre a disposição e proporção visual dos participantes na tela da videoconferência:

1. **Seleção Direta (Clique no Participante):**
   - Ao clicar sobre o quadro de qualquer participante (ou pelo menu de contexto *"Destacar no Palco"* / *"Fixar"*):
     - O participante selecionado é promovido automaticamente para a **Janela Grande Principal (Palco / Stage)**.
     - **Todos os demais participantes são imediatamente redimensionados para miniaturas uniformes**, organizados na galeria vertical/lateral à direita (exatamente como ilustrado na imagem de referência do Microsoft Teams).
2. **Desafixar / Retornar à Grade Geral:**
   - Um botão discreto *"Desafixar"* ou *"Exibir em Grade"* permite restaurar a grade proporcional homogênea.
3. **Múltiplas Opções de Organização:**
   - **Modo Grade Equitativa (`lmGrid`):** Todos os participantes com dimensões iguais distribuídos em matriz adaptativa.
   - **Modo Foco no Participante (`lmSpotlight`):** O participante escolhido ocupa o palco de destaque, e os demais são agrupados em miniaturas laterais.
   - **Modo Apresentação (`lmPresentation`):** A tela compartilhada ou o orador oficial ocupa a tela ampla, mantendo a galeria lateral para os demais.

---

## 2. Estrutura do Layout em Camadas Visuais

```text
┌──────────────────────────────────────────────────────────────────────────────┐
│  BARRA SUPERIOR (Timer 22:06 | Cam | Mic | Tela | Mão | Chat | Sair)         │
├───────────────────────────────────────────────────────┬──────────────┬───────┤
│                                                       │ GALERIA DE   │TRILHA │
│                                                       │ MINIATURAS   │CIRC.  │
│                                                       ├──────┬───────┤       │
│                                                       │ Part │ Part  │ (TJ)  │
│                                                       │   1  │   2   │       │
│               PALCO PRINCIPAL (STAGE)                 ├──────┼───────┤ (DF)  │
│                                                       │ Part │ Part  │       │
│      [ PARTICIPANTE SELECIONADO EM DESTAQUE ]         │   3  │   4   │ (ER)  │
│                                                       ├──────┼───────┤       │
│         • Vídeo em alta resolução                     │ Part │ Part  │  +2   │
│         • Nome no canto inferior                      │   5  │   6   │       │
│         • Botão de alternar / desafixar               ├──────┼───────┤       │
│                                                       │ Part │ Part  │       │
│                                                       │   7  │   8   │       │
└───────────────────────────────────────────────────────┴──────┴───────┴───────┘
```

---

## 3. Implementação Técnica no Lazarus / LCL

A tela de reunião (`uMeetingView.pas`) implementa um gerenciador de layout personalizado:

```pascal
type
  TLayoutMode = (lmGrid, lmSpotlight, lmPresentation);

  TMeetingLayoutManager = class
  private
    FContainer: TWinControl;
    FLayoutMode: TLayoutMode;
    FPinnedParticipantKey: string;
    FStagePanel: TPanel;
    FSidebarGallery: TScrollBox;
  public
    procedure PinParticipant(const APKey: string);
    procedure UnpinParticipant;
    procedure Rearrange(Width, Height: Integer);
    property LayoutMode: TLayoutMode read FLayoutMode;
    property PinnedKey: string read FPinnedParticipantKey;
  end;
```

### Comportamento do Redimensionamento:
- **Janela de Destaque (`FStagePanel`):**
  - Ocupa aproximadamente 70% a 75% da largura útil da janela.
  - Ajusta a proporção nativa 16:9 sem distorção.
- **Galeria Lateral (`FSidebarGallery`):**
  - Ocupa os restantes 25% a 30% da largura.
  - Organiza as miniaturas em grade de 2 colunas com rolagem vertical suave caso o número de participantes exceda a altura visível.
- **Trilha de Avatares:**
  - Círculos de presença rápida com iniciais estilizadas para participantes sem câmera ativa.

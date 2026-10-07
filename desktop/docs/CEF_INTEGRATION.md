# Integração com Chromium Embedded (CEF4Delphi) · Sala Reunião Desktop

Este documento descreve como o **Sala Reunião Desktop** incorpora a videoconferência diretamente na aplicação Lazarus utilizando o **CEF4Delphi** (Chromium Embedded Framework).

---

## 1. Dois Modos de Compilação

O projeto é estruturado para suportar compilação flexível:

1. **Modo Padrão (`SalaReuniaoDesktop.lpi`):**
   - Não depende do pacote `cef4delphi_lazarus`.
   - Compila rapidamente em qualquer ambiente Lazarus / FPC sem bibliotecas externas.
   - Ao ingressar em uma reunião, abre a URL da sala no navegador padrão do usuário via `OpenURL`.

2. **Modo Chromium Embutido (`SalaReuniaoDesktopCEF.lpi`):**
   - Ativa a diretiva de compilação `{$DEFINE USE_CEF4DELPHI}`.
   - Utiliza `TChromiumWindow` para renderizar a página WebRTC da reunião (`room.php?token=...`) diretamente na janela nativa da aplicação.
   - Fornece botão de contingência *"Abrir no Navegador"* caso o runtime Chromium não esteja presente.

---

## 2. Requisitos de Runtime (Arquivos em `desktop/bin/`)

Para que o executável com CEF funcione, os binários compilados do Chromium (disponibilizados na distribuição oficial do CEF4Delphi) devem estar presentes no mesmo diretório do executável (`desktop/bin/`):

```text
desktop/bin/
├── SalaReuniaoDesktop.exe
├── libcef.dll
├── chrome_elf.dll
├── d3dcompiler_47.dll
├── libEGL.dll
├── libGLESv2.dll
├── icudtl.dat
├── v8_context_snapshot.bin
├── snapshot_blob.bin
├── locales/
│   ├── pt-BR.pak
│   └── en-US.pak
└── resources/ (ou na raiz):
    ├── cef.pak
    ├── cef_100_percent.pak
    ├── cef_200_percent.pak
    └── devtools_resources.pak
```

---

## 3. Configuração de Mídia (WebRTC, Câmera e Microfone)

No arquivo principal do programa (`.lpr`), a inicialização do `GlobalCEFApp` configura as opções essenciais para permitir WebRTC e captura de dispositivos de mídia:

```pascal
GlobalCEFApp := TCefApplication.Create;
GlobalCEFApp.EnableMediaStream := True;
GlobalCEFApp.EnableSpeechInput := True;
GlobalCEFApp.AddCustomCommandLine('--enable-media-stream');
GlobalCEFApp.AddCustomCommandLine('--use-fake-ui-for-media-stream'); // Auto-concede permissao de mic/camera em ambientes corporativos
GlobalCEFApp.AddCustomCommandLine('--autoplay-policy=no-user-gesture-required');
GlobalCEFApp.AddCustomCommandLine('--ignore-certificate-errors'); // Opcional em servidores locais de teste
```

### Evento de Permissões de Mídia:
No formulário da reunião (`uMeetingView.pas`), o componente Chromium intercepta o evento `OnCheckMediaAccessPermission` ou `OnPermissionPrompt`:
```pascal
procedure TMeetingForm.ChromiumPermissionPrompt(Sender: TObject;
  const browser: ICefBrowser; promptId: QWord; const requestingOrigin: ustring;
  promptTypes: Cardinal; const callback: ICefPermissionPromptCallback);
begin
  // Concede automaticamente acesso a Microfone e Câmera para a origem do servidor oficial
  callback.Continue(CEF_PERMISSION_RESULT_ACCEPT);
end;
```

---

## 4. Ciclo de Vida e Fechamento Controlado

O Chromium Embedded requer um procedimento rigoroso de fechamento para não deixar processos secundários ou threads orfãs em execução:

1. Ao solicitar fechar a janela da reunião (`TMeetingForm.FormCloseQuery`):
   - Se o browser estiver ativo, a ação de fechamento é suspensa (`CanClose := False`).
   - É chamado `ChromiumWindow.CloseBrowser(True)`.
2. No evento `OnBeforeClose` do Chromium:
   - A flag de término é definida e o formulário prossegue com `Close`.
3. Na saída do programa principal (`.lpr`):
   ```pascal
   DestroyGlobalCEFApp;
   ```

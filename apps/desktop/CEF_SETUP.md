# Videoconferência embutida com CEF4Delphi

O projeto Desktop possui dois modos.

## 1. Projeto padrão

Abra:

```text
SalaReuniaoDesktop.lpi
```

Ele não depende do CEF4Delphi e continua compilando somente com Lazarus/FPC. Ao entrar em uma reunião, a janela interna oferece fallback para o navegador externo.

## 2. Projeto com Chromium embutido

Abra:

```text
SalaReuniaoDesktopCEF.lpi
```

Esse projeto define:

```text
USE_CEF4DELPHI
```

e declara dependência do pacote Lazarus:

```text
cef4delphi_lazarus
```

## Instalação do CEF4Delphi

No Lazarus:

1. abra **Package > Online Package Manager**;
2. instale **DCPcrypt**;
3. instale **CEF4Delphi**;

ou abra manualmente o pacote `cef4delphi_lazarus.lpk` e instale.

## Runtime CEF

Além do pacote Lazarus, o executável precisa das bibliotecas binárias do CEF correspondentes ao sistema/arquitetura.

Na distribuição do CEF4Delphi, copie os conteúdos dos diretórios:

```text
Release/
Resources/
```

para o diretório onde ficará o executável Desktop, conforme a documentação do CEF4Delphi.

Exemplo:

```text
apps/desktop/bin/
├── SalaReuniaoDesktop.exe
├── libcef.dll
├── chrome_elf.dll
├── icudtl.dat
├── locales/
└── ...
```

No Linux os nomes dos binários diferem, mas o princípio é o mesmo.

## Funcionamento

Quando compilado com CEF:

```text
Desktop
  ↓
TMeetingForm
  ↓
TChromiumWindow
  ↓
room.php?token=...
  ↓
WebRTC / WebSocket / TURN
```

A janela inclui também o botão **Abrir no navegador**, que funciona como fallback caso o runtime Chromium não consiga iniciar.

## Câmera e microfone

A página da reunião continua sendo a mesma aplicação HTTPS. Assim, permissões de câmera/microfone, WebRTC, TURN, chat e compartilhamento de tela permanecem no código Web existente.

Use sempre HTTPS válido em produção.

## Compilação

```bash
lazbuild SalaReuniaoDesktopCEF.lpi
```

Se o compilador não localizar `uCEFApplication` ou `uCEFChromiumWindow`, confirme que o pacote `cef4delphi_lazarus` foi instalado no Lazarus usado pelo `lazbuild`.

## Fechamento

A janela solicita o fechamento do browser CEF antes de ser destruída, evitando deixar a instância Chromium ativa após sair da videoconferência.

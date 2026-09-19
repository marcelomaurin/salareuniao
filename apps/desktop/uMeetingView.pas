unit uMeetingView;

{$mode objfpc}{$H+}

interface

uses
  Classes, SysUtils, Forms, Controls, StdCtrls, ExtCtrls, Dialogs, LCLIntf
  {$IFDEF USE_CEF4DELPHI}
  , uCEFChromiumWindow
  {$ENDIF}
  ;

type
  TMeetingForm = class(TForm)
  private
    FUrl: string;
    FToolbar: TPanel;
    FContent: TPanel;
    FBtnExternal: TButton;
    FBtnClose: TButton;
    FStatus: TLabel;
    {$IFDEF USE_CEF4DELPHI}
    FBrowser: TChromiumWindow;
    procedure BrowserAfterCreated(Sender: TObject);
    {$ENDIF}
    procedure ExternalClick(Sender: TObject);
    procedure CloseClick(Sender: TObject);
    procedure FormShown(Sender: TObject);
    procedure FormClosing(Sender: TObject; var CloseAction: TCloseAction);
    procedure BuildUi;
  public
    constructor CreateForUrl(TheOwner: TComponent; const AUrl: string);
  end;

procedure OpenMeetingWindow(AOwner: TComponent; const AUrl: string);

implementation

constructor TMeetingForm.CreateForUrl(TheOwner: TComponent; const AUrl: string);
begin
  inherited CreateNew(TheOwner, 1);
  FUrl := AUrl;
  Caption := 'Sala Reunião - Videoconferência';
  Width := 1280;
  Height := 800;
  Position := poOwnerFormCenter;
  OnShow := @FormShown;
  OnClose := @FormClosing;
  BuildUi;
end;

procedure TMeetingForm.BuildUi;
begin
  FToolbar := TPanel.Create(Self);
  FToolbar.Parent := Self;
  FToolbar.Align := alTop;
  FToolbar.Height := 48;

  FStatus := TLabel.Create(FToolbar);
  FStatus.Parent := FToolbar;
  FStatus.Left := 12;
  FStatus.Top := 17;
  {$IFDEF USE_CEF4DELPHI}
  FStatus.Caption := 'Inicializando Chromium embutido...';
  {$ELSE}
  FStatus.Caption := 'CEF4Delphi não habilitado; use o navegador externo.';
  {$ENDIF}

  FBtnExternal := TButton.Create(FToolbar);
  FBtnExternal.Parent := FToolbar;
  FBtnExternal.Caption := 'Abrir no navegador';
  FBtnExternal.Left := 930;
  FBtnExternal.Top := 9;
  FBtnExternal.Width := 145;
  FBtnExternal.Anchors := [akTop, akRight];
  FBtnExternal.OnClick := @ExternalClick;

  FBtnClose := TButton.Create(FToolbar);
  FBtnClose.Parent := FToolbar;
  FBtnClose.Caption := 'Fechar';
  FBtnClose.Left := 1090;
  FBtnClose.Top := 9;
  FBtnClose.Width := 90;
  FBtnClose.Anchors := [akTop, akRight];
  FBtnClose.OnClick := @CloseClick;

  FContent := TPanel.Create(Self);
  FContent.Parent := Self;
  FContent.Align := alClient;
  FContent.BevelOuter := bvNone;

  {$IFDEF USE_CEF4DELPHI}
  FBrowser := TChromiumWindow.Create(Self);
  FBrowser.Parent := FContent;
  FBrowser.Align := alClient;
  FBrowser.OnAfterCreated := @BrowserAfterCreated;
  {$ELSE}
  with TLabel.Create(FContent) do
  begin
    Parent := FContent;
    Align := alClient;
    Alignment := taCenter;
    Layout := tlCenter;
    WordWrap := True;
    Caption := 'Esta compilação não possui CEF4Delphi.' + LineEnding +
      'Clique em "Abrir no navegador" para participar da reunião.' + LineEnding + LineEnding +
      FUrl;
  end;
  {$ENDIF}
end;

procedure TMeetingForm.FormShown(Sender: TObject);
begin
  {$IFDEF USE_CEF4DELPHI}
  if not FBrowser.CreateBrowser then
  begin
    FStatus.Caption := 'Não foi possível iniciar o Chromium.';
    if MessageDlg('Chromium não iniciou. Abrir a reunião no navegador externo?',
      mtConfirmation, [mbYes, mbNo], 0) = mrYes then
      OpenURL(FUrl);
  end;
  {$ELSE}
  FStatus.Caption := 'Modo externo disponível.';
  {$ENDIF}
end;

{$IFDEF USE_CEF4DELPHI}
procedure TMeetingForm.BrowserAfterCreated(Sender: TObject);
begin
  FStatus.Caption := 'Videoconferência embutida';
  FBrowser.LoadURL(FUrl);
end;
{$ENDIF}

procedure TMeetingForm.ExternalClick(Sender: TObject);
begin
  if not OpenURL(FUrl) then
    MessageDlg('Não foi possível abrir o navegador externo.', mtError, [mbOK], 0);
end;

procedure TMeetingForm.CloseClick(Sender: TObject);
begin
  Close;
end;

procedure TMeetingForm.FormClosing(Sender: TObject; var CloseAction: TCloseAction);
begin
  {$IFDEF USE_CEF4DELPHI}
  if Assigned(FBrowser) and FBrowser.Initialized then
    FBrowser.CloseBrowser(True);
  {$ENDIF}
  CloseAction := caFree;
end;

procedure OpenMeetingWindow(AOwner: TComponent; const AUrl: string);
var
  F: TMeetingForm;
begin
  F := TMeetingForm.CreateForUrl(AOwner, AUrl);
  F.Show;
end;

end.

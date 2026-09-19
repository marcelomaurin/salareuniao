program SalaReuniaoDesktop;

{$mode objfpc}{$H+}

uses
  Interfaces, Forms
  {$IFDEF USE_CEF4DELPHI}
  , uCEFApplication
  {$ENDIF}
  , uMain, uApiClient, uAppConfig, uMeetingView;

{$R *.res}

begin
  RequireDerivedFormResource := False;

  {$IFDEF USE_CEF4DELPHI}
  GlobalCEFApp := TCefApplication.Create;
  if GlobalCEFApp.StartMainProcess then
  begin
  {$ENDIF}

    Application.Initialize;
    Application.Title := 'Sala Reunião Desktop';
    Application.CreateForm(TMainForm, MainForm);
    Application.Run;

  {$IFDEF USE_CEF4DELPHI}
  end;
  DestroyGlobalCEFApp;
  {$ENDIF}
end.

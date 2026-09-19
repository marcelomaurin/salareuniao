program SalaReuniaoDesktop;

{$mode objfpc}{$H+}

uses
  Interfaces, Forms,
  uMain, uApiClient, uAppConfig;

{$R *.res}

begin
  RequireDerivedFormResource := False;
  Application.Initialize;
  Application.Title := 'Sala Reunião Desktop';
  Application.CreateForm(TMainForm, MainForm);
  Application.Run;
end.

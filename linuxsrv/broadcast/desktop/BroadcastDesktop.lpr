program BroadcastDesktop;

{$mode objfpc}{$H+}

uses
  cthreads, Interfaces, Forms, BcastMain;

begin
  RequireDerivedFormResource := True;
  Application.Scaled := True;
  Application.Initialize;
  Application.Title := 'Sala Reunião · Servidor Broadcast';
  Application.CreateForm(TBroadcastForm, BroadcastForm);
  Application.Run;
end.

unit uDesktopNotify;

{$mode objfpc}{$H+}

interface

uses
  Classes, SysUtils, Forms, ExtCtrls, Dialogs;

type
  TDesktopNotifier = class
  private
    FTray: TTrayIcon;
  public
    constructor Create(AOwner: TComponent);
    destructor Destroy; override;
    procedure Notify(const ATitle, AMessage: string);
  end;

implementation

constructor TDesktopNotifier.Create(AOwner: TComponent);
begin
  inherited Create;
  FTray := TTrayIcon.Create(AOwner);
  FTray.Hint := 'Sala Reunião';
  FTray.Visible := True;
end;

destructor TDesktopNotifier.Destroy;
begin
  FTray.Free;
  inherited Destroy;
end;

procedure TDesktopNotifier.Notify(const ATitle, AMessage: string);
begin
  try
    FTray.BalloonTitle := ATitle;
    FTray.BalloonHint := AMessage;
    FTray.BalloonTimeout := 5000;
    FTray.ShowBalloonHint;
  except
    on E: Exception do
      MessageDlg(ATitle, AMessage, mtInformation, [mbOK], 0);
  end;
end;

end.

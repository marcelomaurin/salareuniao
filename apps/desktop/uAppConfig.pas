unit uAppConfig;

{$mode objfpc}{$H+}

interface

uses
  Classes, SysUtils, IniFiles;

type
  TAppConfig = class
  private
    FIni: TIniFile;
    FFileName: string;
  public
    constructor Create;
    destructor Destroy; override;
    function ApiBaseUrl: string;
    procedure SetApiBaseUrl(const AValue: string);
    function WebBaseUrl: string;
    procedure SetWebBaseUrl(const AValue: string);
    function SavedEmail: string;
    procedure SetSavedEmail(const AValue: string);
    function NotificationsEnabled: Boolean;
    procedure SetNotificationsEnabled(AValue: Boolean);
    function AutoJoinEnabled: Boolean;
    procedure SetAutoJoinEnabled(AValue: Boolean);
    function AutoJoinMinutes: Integer;
    procedure SetAutoJoinMinutes(AValue: Integer);
    function AutoUpdateEnabled: Boolean;
    procedure SetAutoUpdateEnabled(AValue: Boolean);
    procedure Save;
    property FileName: string read FFileName;
  end;

implementation

function StripTrailingSlash(const S: string): string;
begin
  Result := Trim(S);
  while (Length(Result) > 0) and (Result[Length(Result)] = '/') do
    Delete(Result, Length(Result), 1);
end;

constructor TAppConfig.Create;
var
  Dir: string;
begin
  inherited Create;
  Dir := GetAppConfigDir(False);
  if not DirectoryExists(Dir) then
    ForceDirectories(Dir);
  FFileName := IncludeTrailingPathDelimiter(Dir) + 'salareuniao.ini';
  FIni := TIniFile.Create(FFileName);
end;

destructor TAppConfig.Destroy;
begin
  FIni.Free;
  inherited Destroy;
end;

function TAppConfig.ApiBaseUrl: string;
begin
  Result := FIni.ReadString('server', 'api_base_url',
    'https://meet.seu-dominio.example/api/v1');
end;

procedure TAppConfig.SetApiBaseUrl(const AValue: string);
begin
  FIni.WriteString('server', 'api_base_url', StripTrailingSlash(AValue));
end;

function TAppConfig.WebBaseUrl: string;
begin
  Result := FIni.ReadString('server', 'web_base_url',
    'https://meet.seu-dominio.example');
end;

procedure TAppConfig.SetWebBaseUrl(const AValue: string);
begin
  FIni.WriteString('server', 'web_base_url', StripTrailingSlash(AValue));
end;

function TAppConfig.SavedEmail: string;
begin
  Result := FIni.ReadString('user', 'email', '');
end;

procedure TAppConfig.SetSavedEmail(const AValue: string);
begin
  FIni.WriteString('user', 'email', Trim(AValue));
end;

function TAppConfig.NotificationsEnabled: Boolean;
begin
  Result := FIni.ReadBool('desktop','notifications',True);
end;

procedure TAppConfig.SetNotificationsEnabled(AValue: Boolean);
begin
  FIni.WriteBool('desktop','notifications',AValue);
end;

function TAppConfig.AutoJoinEnabled: Boolean;
begin
  Result := FIni.ReadBool('desktop','auto_join',False);
end;

procedure TAppConfig.SetAutoJoinEnabled(AValue: Boolean);
begin
  FIni.WriteBool('desktop','auto_join',AValue);
end;

function TAppConfig.AutoJoinMinutes: Integer;
begin
  Result := FIni.ReadInteger('desktop','auto_join_minutes',2);
end;

procedure TAppConfig.SetAutoJoinMinutes(AValue: Integer);
begin
  if AValue < 0 then AValue := 0;
  if AValue > 60 then AValue := 60;
  FIni.WriteInteger('desktop','auto_join_minutes',AValue);
end;

function TAppConfig.AutoUpdateEnabled: Boolean;
begin
  Result := FIni.ReadBool('desktop','auto_update',True);
end;

procedure TAppConfig.SetAutoUpdateEnabled(AValue: Boolean);
begin
  FIni.WriteBool('desktop','auto_update',AValue);
end;

procedure TAppConfig.Save;
begin
  FIni.UpdateFile;
end;

end.

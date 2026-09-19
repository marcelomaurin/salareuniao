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
    procedure Save;
    property FileName: string read FFileName;
  end;

implementation

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
  FIni.WriteString('server', 'api_base_url', TrimRight(AValue, ['/']));
end;

function TAppConfig.WebBaseUrl: string;
begin
  Result := FIni.ReadString('server', 'web_base_url',
    'https://meet.seu-dominio.example');
end;

procedure TAppConfig.SetWebBaseUrl(const AValue: string);
begin
  FIni.WriteString('server', 'web_base_url', TrimRight(AValue, ['/']));
end;

function TAppConfig.SavedEmail: string;
begin
  Result := FIni.ReadString('user', 'email', '');
end;

procedure TAppConfig.SetSavedEmail(const AValue: string);
begin
  FIni.WriteString('user', 'email', Trim(AValue));
end;

procedure TAppConfig.Save;
begin
  FIni.UpdateFile;
end;

end.

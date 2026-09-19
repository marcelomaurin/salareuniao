unit uUpdater;

{$mode objfpc}{$H+}

interface

uses
  Classes, SysUtils, fphttpclient, opensslsockets, LCLIntf, fpjson, uApiClient,
  uVersion;

type
  TUpdateInfo = record
    Available: Boolean;
    Required: Boolean;
    Version: string;
    Notes: string;
    Url: string;
  end;

function CurrentPlatform: string;
function CompareVersions(const A, B: string): Integer;
function CheckForUpdate(AApi: TSalaApiClient): TUpdateInfo;
function DownloadAndLaunchUpdate(const AUrl: string; out AFileName: string): Boolean;

implementation

function CurrentPlatform: string;
begin
  {$IFDEF Windows}
  Result := 'windows';
  {$ELSE}
    {$IFDEF Linux}
    Result := 'linux';
    {$ELSE}
    Result := 'other';
    {$ENDIF}
  {$ENDIF}
end;

function NextVersionPart(const S: string; var P: Integer): Integer;
var
  Start: Integer;
begin
  while (P <= Length(S)) and not (S[P] in ['0'..'9']) do Inc(P);
  Start := P;
  while (P <= Length(S)) and (S[P] in ['0'..'9']) do Inc(P);
  if Start <= Length(S) then
    Result := StrToIntDef(Copy(S, Start, P - Start), 0)
  else
    Result := 0;
end;

function CompareVersions(const A, B: string): Integer;
var
  PA, PB, I, VA, VB: Integer;
begin
  PA := 1; PB := 1;
  for I := 1 to 4 do
  begin
    VA := NextVersionPart(A, PA);
    VB := NextVersionPart(B, PB);
    if VA < VB then Exit(-1);
    if VA > VB then Exit(1);
  end;
  Result := 0;
end;

function CheckForUpdate(AApi: TSalaApiClient): TUpdateInfo;
var
  J: TJSONObject;
begin
  Result.Available := False;
  Result.Required := False;
  Result.Version := '';
  Result.Notes := '';
  Result.Url := '';
  J := nil;
  try
    J := AApi.DesktopUpdate(CurrentPlatform);
    Result.Version := J.Get('version', '0.0.0');
    Result.Required := J.Get('required', False);
    Result.Notes := J.Get('notes', '');
    Result.Url := J.Get('url', '');
    Result.Available := J.Get('enabled', False)
      and (Result.Url <> '')
      and (CompareVersions(APP_VERSION, Result.Version) < 0);
  finally
    J.Free;
  end;
end;

function DownloadAndLaunchUpdate(const AUrl: string; out AFileName: string): Boolean;
var
  C: TFPHTTPClient;
  S: TFileStream;
  Ext, UrlPath: string;
  QPos: SizeInt;
begin
  Result := False;
  AFileName := '';

  UrlPath := AUrl;
  QPos := Pos('?', UrlPath);
  if QPos > 0 then Delete(UrlPath, QPos, MaxInt);
  Ext := ExtractFileExt(UrlPath);
  if Ext = '' then
  begin
    {$IFDEF Windows}
    Ext := '.exe';
    {$ELSE}
    Ext := '.bin';
    {$ENDIF}
  end;

  AFileName := IncludeTrailingPathDelimiter(GetTempDir(False)) +
    'SalaReuniaoDesktop-update' + Ext;

  C := TFPHTTPClient.Create(nil);
  S := TFileStream.Create(AFileName, fmCreate);
  try
    C.AddHeader('User-Agent','SalaReuniaoDesktop/' + APP_VERSION);
    C.Get(AUrl, S);
  finally
    S.Free;
    C.Free;
  end;

  {$IFDEF Windows}
  Result := OpenDocument(AFileName);
  {$ELSE}
  Result := OpenDocument(AFileName);
  {$ENDIF}
end;

end.

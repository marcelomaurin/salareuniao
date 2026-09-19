unit uApiClient;

{$mode objfpc}{$H+}

interface

uses
  Classes, SysUtils, fphttpclient, opensslsockets, fpjson, jsonparser;

type
  ESalaApiError = class(Exception);

  TSalaApiClient = class
  private
    FBaseUrl: string;
    FToken: string;
    function BuildUrl(const APath: string): string;
    function Execute(const AMethod, APath: string; ABody: TJSONData = nil): TJSONData;
    procedure AddHeaders(AClient: TFPHTTPClient);
  public
    constructor Create(const ABaseUrl: string);
    procedure SetBaseUrl(const AValue: string);
    function Login(const AEmail, APassword, AClientName: string): TJSONObject;
    procedure Logout;
    function Me: TJSONObject;
    function Rooms(const AScope: string = 'mine'): TJSONArray;
    function Agenda(ADays: Integer = 30; const AScope: string = 'mine'): TJSONArray;
    function RoomDetail(ARoomId: Int64): TJSONObject;
    function CreateRoom(const AName, ADescription, AStartsAt: string): TJSONObject;
    procedure RoomAction(ARoomId: Int64; const AAction: string);
    function RoomInvites(ARoomId: Int64): TJSONArray;
    procedure InviteAction(ARoomId, AInviteId: Int64; const AAction: string);
    procedure AddInvites(ARoomId: Int64; const AEmails: array of string);
    property Token: string read FToken write FToken;
    property BaseUrl: string read FBaseUrl write SetBaseUrl;
  end;

implementation

constructor TSalaApiClient.Create(const ABaseUrl: string);
begin
  inherited Create;
  SetBaseUrl(ABaseUrl);
end;

procedure TSalaApiClient.SetBaseUrl(const AValue: string);
begin
  FBaseUrl := AValue;
  while (Length(FBaseUrl) > 0) and (FBaseUrl[Length(FBaseUrl)] = '/') do
    Delete(FBaseUrl, Length(FBaseUrl), 1);
end;

function TSalaApiClient.BuildUrl(const APath: string): string;
begin
  if (APath <> '') and (APath[1] = '/') then
    Result := FBaseUrl + APath
  else
    Result := FBaseUrl + '/' + APath;
end;

procedure TSalaApiClient.AddHeaders(AClient: TFPHTTPClient);
begin
  AClient.AddHeader('Accept', 'application/json');
  AClient.AddHeader('Content-Type', 'application/json; charset=utf-8');
  AClient.AddHeader('User-Agent', 'SalaReuniaoDesktop/1.0');
  if FToken <> '' then
    AClient.AddHeader('Authorization', 'Bearer ' + FToken);
end;

function TSalaApiClient.Execute(const AMethod, APath: string;
  ABody: TJSONData): TJSONData;
var
  C: TFPHTTPClient;
  Req, Resp: TStringStream;
  S: string;
begin
  Result := nil;
  C := TFPHTTPClient.Create(nil);
  Req := nil;
  Resp := TStringStream.Create('');
  try
    AddHeaders(C);
    if Assigned(ABody) then
    begin
      Req := TStringStream.Create(ABody.AsJSON);
      C.RequestBody := Req;
    end;

    try
      C.HTTPMethod(AMethod, BuildUrl(APath), Resp, [200, 201, 204]);
    except
      on E: EHTTPClient do
      begin
        S := Resp.DataString;
        if S = '' then S := E.Message;
        raise ESalaApiError.Create('Falha HTTP: ' + S);
      end;
    end;

    if Resp.DataString = '' then
      Exit(TJSONObject.Create);

    Result := GetJSON(Resp.DataString);
  finally
    Resp.Free;
    Req.Free;
    C.Free;
  end;
end;

function TSalaApiClient.Login(const AEmail, APassword,
  AClientName: string): TJSONObject;
var
  B: TJSONObject;
  D: TJSONData;
begin
  B := TJSONObject.Create;
  try
    B.Add('email', AEmail);
    B.Add('password', APassword);
    B.Add('client_name', AClientName);
    D := Execute('POST', 'auth/login.php', B);
    Result := TJSONObject(D);
    FToken := Result.Get('token', '');
  finally
    B.Free;
  end;
end;

procedure TSalaApiClient.Logout;
var
  D: TJSONData;
begin
  if FToken = '' then Exit;
  D := Execute('POST', 'auth/logout.php');
  D.Free;
  FToken := '';
end;

function TSalaApiClient.Me: TJSONObject;
begin
  Result := TJSONObject(Execute('GET', 'auth/me.php'));
end;

function TSalaApiClient.Rooms(const AScope: string): TJSONArray;
var
  D: TJSONObject;
begin
  D := TJSONObject(Execute('GET', 'rooms.php?scope=' + AScope));
  try
    Result := TJSONArray(D.Arrays['rooms'].Clone);
  finally
    D.Free;
  end;
end;

function TSalaApiClient.Agenda(ADays: Integer; const AScope: string): TJSONArray;
var
  D: TJSONObject;
begin
  D := TJSONObject(Execute('GET', Format('agenda.php?days=%d&scope=%s', [ADays, AScope])));
  try
    Result := TJSONArray(D.Arrays['agenda'].Clone);
  finally
    D.Free;
  end;
end;

function TSalaApiClient.RoomDetail(ARoomId: Int64): TJSONObject;
begin
  Result := TJSONObject(Execute('GET', 'room.php?id=' + IntToStr(ARoomId)));
end;

function TSalaApiClient.CreateRoom(const AName, ADescription,
  AStartsAt: string): TJSONObject;
var
  B: TJSONObject;
begin
  B := TJSONObject.Create;
  try
    B.Add('name', AName);
    B.Add('description', ADescription);
    if AStartsAt <> '' then B.Add('starts_at', AStartsAt);
    Result := TJSONObject(Execute('POST', 'rooms.php', B));
  finally
    B.Free;
  end;
end;

procedure TSalaApiClient.RoomAction(ARoomId: Int64; const AAction: string);
var
  B: TJSONObject;
  D: TJSONData;
begin
  B := TJSONObject.Create;
  try
    B.Add('action', AAction);
    D := Execute('POST', 'room.php?id=' + IntToStr(ARoomId), B);
    D.Free;
  finally
    B.Free;
  end;
end;

function TSalaApiClient.RoomInvites(ARoomId: Int64): TJSONArray;
var
  D: TJSONObject;
begin
  D := TJSONObject(Execute('GET', 'room_invites.php?room_id=' + IntToStr(ARoomId)));
  try
    Result := TJSONArray(D.Arrays['invites'].Clone);
  finally
    D.Free;
  end;
end;

procedure TSalaApiClient.InviteAction(ARoomId, AInviteId: Int64;
  const AAction: string);
var
  B: TJSONObject;
  D: TJSONData;
begin
  B := TJSONObject.Create;
  try
    B.Add('room_id', ARoomId);
    B.Add('invite_id', AInviteId);
    B.Add('action', AAction);
    D := Execute('POST', 'room_invites.php', B);
    D.Free;
  finally
    B.Free;
  end;
end;

procedure TSalaApiClient.AddInvites(ARoomId: Int64;
  const AEmails: array of string);
var
  B: TJSONObject;
  A: TJSONArray;
  I: Integer;
  D: TJSONData;
begin
  B := TJSONObject.Create;
  A := TJSONArray.Create;
  try
    for I := Low(AEmails) to High(AEmails) do
      if Trim(AEmails[I]) <> '' then A.Add(Trim(AEmails[I]));
    B.Add('room_id', ARoomId);
    B.Add('action', 'add');
    B.Add('emails', A);
    A := nil;
    D := Execute('POST', 'room_invites.php', B);
    D.Free;
  finally
    A.Free;
    B.Free;
  end;
end;

end.

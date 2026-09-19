unit uMain;

{$mode objfpc}{$H+}

interface

uses
  Classes, SysUtils, Forms, Controls, Graphics, Dialogs, StdCtrls, ExtCtrls,
  ComCtrls, Grids, fpjson, LCLIntf, uApiClient, uAppConfig, uInvites;

type
  TMainForm = class(TForm)
  private
    FApi: TSalaApiClient;
    FConfig: TAppConfig;
    FCurrentUserRole: string;

    LoginPanel: TPanel;
    MainPanel: TPanel;
    EdEmail, EdPassword: TEdit;
    BtnLogin, BtnSettings: TButton;
    LblLoginStatus: TLabel;

    TopPanel: TPanel;
    LblUser: TLabel;
    BtnRefresh, BtnLogout, BtnNewRoom, BtnOpenMeeting, BtnOpenRoom,
      BtnCloseRoom, BtnCancelRoom, BtnInvites: TButton;

    Tabs: TPageControl;
    TabRooms, TabAgenda: TTabSheet;
    GridRooms, GridAgenda: TStringGrid;
    StatusBar: TStatusBar;

    procedure BuildUi;
    procedure BuildLogin;
    procedure BuildMain;
    procedure LoginClick(Sender: TObject);
    procedure LogoutClick(Sender: TObject);
    procedure SettingsClick(Sender: TObject);
    procedure RefreshClick(Sender: TObject);
    procedure NewRoomClick(Sender: TObject);
    procedure OpenMeetingClick(Sender: TObject);
    procedure InvitesClick(Sender: TObject);
    procedure RoomActionClick(Sender: TObject);
    procedure ShowLogin;
    procedure ShowMain(const AName, ARole: string);
    procedure RefreshAll;
    procedure LoadRooms;
    procedure LoadAgenda;
    function SelectedRoomId: Int64;
    procedure SetBusy(const AValue: Boolean; const AMsg: string = '');
    procedure ApiError(const E: Exception);
  public
    constructor Create(TheOwner: TComponent); override;
    destructor Destroy; override;
  end;

var
  MainForm: TMainForm;

implementation

constructor TMainForm.Create(TheOwner: TComponent);
begin
  inherited Create(TheOwner);
  Caption := 'Sala Reunião Desktop';
  Width := 1100;
  Height := 720;
  Position := poScreenCenter;

  FConfig := TAppConfig.Create;
  FApi := TSalaApiClient.Create(FConfig.ApiBaseUrl);
  BuildUi;
  EdEmail.Text := FConfig.SavedEmail;
  ShowLogin;
end;

destructor TMainForm.Destroy;
begin
  FApi.Free;
  FConfig.Free;
  inherited Destroy;
end;

procedure TMainForm.BuildUi;
begin
  BuildLogin;
  BuildMain;

  StatusBar := TStatusBar.Create(Self);
  StatusBar.Parent := Self;
  StatusBar.Align := alBottom;
  StatusBar.SimpleText := 'Pronto';
end;

procedure TMainForm.BuildLogin;
var
  L: TLabel;
begin
  LoginPanel := TPanel.Create(Self);
  LoginPanel.Parent := Self;
  LoginPanel.Align := alClient;
  LoginPanel.BevelOuter := bvNone;

  L := TLabel.Create(LoginPanel);
  L.Parent := LoginPanel;
  L.Caption := 'Sala Reunião';
  L.Font.Size := 22;
  L.Font.Style := [fsBold];
  L.Left := 60;
  L.Top := 55;

  L := TLabel.Create(LoginPanel);
  L.Parent := LoginPanel;
  L.Caption := 'E-mail';
  L.Left := 60;
  L.Top := 125;

  EdEmail := TEdit.Create(LoginPanel);
  EdEmail.Parent := LoginPanel;
  EdEmail.Left := 60;
  EdEmail.Top := 145;
  EdEmail.Width := 360;

  L := TLabel.Create(LoginPanel);
  L.Parent := LoginPanel;
  L.Caption := 'Senha';
  L.Left := 60;
  L.Top := 190;

  EdPassword := TEdit.Create(LoginPanel);
  EdPassword.Parent := LoginPanel;
  EdPassword.Left := 60;
  EdPassword.Top := 210;
  EdPassword.Width := 360;
  EdPassword.PasswordChar := '*';

  BtnLogin := TButton.Create(LoginPanel);
  BtnLogin.Parent := LoginPanel;
  BtnLogin.Caption := 'Entrar';
  BtnLogin.Left := 60;
  BtnLogin.Top := 260;
  BtnLogin.Width := 130;
  BtnLogin.OnClick := @LoginClick;

  BtnSettings := TButton.Create(LoginPanel);
  BtnSettings.Parent := LoginPanel;
  BtnSettings.Caption := 'Servidor...';
  BtnSettings.Left := 205;
  BtnSettings.Top := 260;
  BtnSettings.Width := 130;
  BtnSettings.OnClick := @SettingsClick;

  LblLoginStatus := TLabel.Create(LoginPanel);
  LblLoginStatus.Parent := LoginPanel;
  LblLoginStatus.Left := 60;
  LblLoginStatus.Top := 310;
  LblLoginStatus.Width := 600;
  LblLoginStatus.Caption := '';
end;

procedure TMainForm.BuildMain;
begin
  MainPanel := TPanel.Create(Self);
  MainPanel.Parent := Self;
  MainPanel.Align := alClient;
  MainPanel.BevelOuter := bvNone;

  TopPanel := TPanel.Create(MainPanel);
  TopPanel.Parent := MainPanel;
  TopPanel.Align := alTop;
  TopPanel.Height := 56;

  LblUser := TLabel.Create(TopPanel);
  LblUser.Parent := TopPanel;
  LblUser.Left := 14;
  LblUser.Top := 20;
  LblUser.Caption := 'Usuário';

  BtnRefresh := TButton.Create(TopPanel);
  BtnRefresh.Parent := TopPanel;
  BtnRefresh.Caption := 'Atualizar';
  BtnRefresh.Left := 290;
  BtnRefresh.Top := 12;
  BtnRefresh.OnClick := @RefreshClick;

  BtnNewRoom := TButton.Create(TopPanel);
  BtnNewRoom.Parent := TopPanel;
  BtnNewRoom.Caption := 'Nova sala';
  BtnNewRoom.Left := 380;
  BtnNewRoom.Top := 12;
  BtnNewRoom.OnClick := @NewRoomClick;

  BtnOpenMeeting := TButton.Create(TopPanel);
  BtnOpenMeeting.Parent := TopPanel;
  BtnOpenMeeting.Caption := 'Entrar';
  BtnOpenMeeting.Left := 470;
  BtnOpenMeeting.Top := 12;
  BtnOpenMeeting.OnClick := @OpenMeetingClick;

  BtnInvites := TButton.Create(TopPanel);
  BtnInvites.Parent := TopPanel;
  BtnInvites.Caption := 'Convidados';
  BtnInvites.Left := 545;
  BtnInvites.Top := 12;
  BtnInvites.Width := 90;
  BtnInvites.OnClick := @InvitesClick;

  BtnOpenRoom := TButton.Create(TopPanel);
  BtnOpenRoom.Parent := TopPanel;
  BtnOpenRoom.Caption := 'Abrir sala';
  BtnOpenRoom.Left := 645;
  BtnOpenRoom.Top := 12;
  BtnOpenRoom.Tag := 1;
  BtnOpenRoom.OnClick := @RoomActionClick;

  BtnCloseRoom := TButton.Create(TopPanel);
  BtnCloseRoom.Parent := TopPanel;
  BtnCloseRoom.Caption := 'Encerrar';
  BtnCloseRoom.Left := 735;
  BtnCloseRoom.Top := 12;
  BtnCloseRoom.Tag := 2;
  BtnCloseRoom.OnClick := @RoomActionClick;

  BtnCancelRoom := TButton.Create(TopPanel);
  BtnCancelRoom.Parent := TopPanel;
  BtnCancelRoom.Caption := 'Cancelar';
  BtnCancelRoom.Left := 820;
  BtnCancelRoom.Top := 12;
  BtnCancelRoom.Tag := 3;
  BtnCancelRoom.OnClick := @RoomActionClick;

  BtnLogout := TButton.Create(TopPanel);
  BtnLogout.Parent := TopPanel;
  BtnLogout.Caption := 'Sair';
  BtnLogout.Left := 910;
  BtnLogout.Top := 12;
  BtnLogout.OnClick := @LogoutClick;

  Tabs := TPageControl.Create(MainPanel);
  Tabs.Parent := MainPanel;
  Tabs.Align := alClient;

  TabRooms := TTabSheet.Create(Tabs);
  TabRooms.PageControl := Tabs;
  TabRooms.Caption := 'Salas';

  GridRooms := TStringGrid.Create(TabRooms);
  GridRooms.Parent := TabRooms;
  GridRooms.Align := alClient;
  GridRooms.ColCount := 7;
  GridRooms.FixedRows := 1;
  GridRooms.RowCount := 2;
  GridRooms.Options := GridRooms.Options + [goRowSelect];
  GridRooms.Cells[0,0] := 'ID';
  GridRooms.Cells[1,0] := 'Nome';
  GridRooms.Cells[2,0] := 'Início';
  GridRooms.Cells[3,0] := 'Status';
  GridRooms.Cells[4,0] := 'Convites';
  GridRooms.Cells[5,0] := 'Online';
  GridRooms.Cells[6,0] := 'Descrição';
  GridRooms.ColWidths[0] := 60;
  GridRooms.ColWidths[1] := 230;
  GridRooms.ColWidths[2] := 150;
  GridRooms.ColWidths[3] := 95;
  GridRooms.ColWidths[4] := 75;
  GridRooms.ColWidths[5] := 70;
  GridRooms.ColWidths[6] := 320;

  TabAgenda := TTabSheet.Create(Tabs);
  TabAgenda.PageControl := Tabs;
  TabAgenda.Caption := 'Agenda';

  GridAgenda := TStringGrid.Create(TabAgenda);
  GridAgenda.Parent := TabAgenda;
  GridAgenda.Align := alClient;
  GridAgenda.ColCount := 6;
  GridAgenda.FixedRows := 1;
  GridAgenda.RowCount := 2;
  GridAgenda.Options := GridAgenda.Options + [goRowSelect];
  GridAgenda.Cells[0,0] := 'ID';
  GridAgenda.Cells[1,0] := 'Nome';
  GridAgenda.Cells[2,0] := 'Início';
  GridAgenda.Cells[3,0] := 'Término';
  GridAgenda.Cells[4,0] := 'Status';
  GridAgenda.Cells[5,0] := 'Descrição';
  GridAgenda.ColWidths[0] := 60;
  GridAgenda.ColWidths[1] := 260;
  GridAgenda.ColWidths[2] := 155;
  GridAgenda.ColWidths[3] := 155;
  GridAgenda.ColWidths[4] := 95;
  GridAgenda.ColWidths[5] := 330;
end;

procedure TMainForm.ShowLogin;
begin
  MainPanel.Visible := False;
  LoginPanel.Visible := True;
  EdPassword.Text := '';
  EdEmail.SetFocus;
end;

procedure TMainForm.ShowMain(const AName, ARole: string);
begin
  FCurrentUserRole := ARole;
  LblUser.Caption := AName + ' [' + ARole + ']';
  LoginPanel.Visible := False;
  MainPanel.Visible := True;
  RefreshAll;
end;

procedure TMainForm.SetBusy(const AValue: Boolean; const AMsg: string);
begin
  Screen.Cursor := crDefault;
  if AValue then Screen.Cursor := crHourGlass;
  if AMsg <> '' then StatusBar.SimpleText := AMsg;
  Application.ProcessMessages;
end;

procedure TMainForm.ApiError(const E: Exception);
begin
  StatusBar.SimpleText := 'Erro';
  MessageDlg('Erro de comunicação', E.Message, mtError, [mbOK], 0);
end;

procedure TMainForm.LoginClick(Sender: TObject);
var
  R, U: TJSONObject;
begin
  if (Trim(EdEmail.Text) = '') or (EdPassword.Text = '') then
  begin
    MessageDlg('Informe e-mail e senha.', mtWarning, [mbOK], 0);
    Exit;
  end;

  SetBusy(True, 'Autenticando...');
  R := nil;
  try
    R := FApi.Login(Trim(EdEmail.Text), EdPassword.Text, 'Lazarus Desktop');
    U := R.Objects['user'];
    FConfig.SetSavedEmail(Trim(EdEmail.Text));
    FConfig.Save;
    ShowMain(U.Get('name', ''), U.Get('role', 'user'));
    LblLoginStatus.Caption := '';
  except
    on E: Exception do
    begin
      LblLoginStatus.Caption := 'Falha no login.';
      ApiError(E);
    end;
  end;
  R.Free;
  SetBusy(False, 'Pronto');
end;

procedure TMainForm.LogoutClick(Sender: TObject);
begin
  try
    FApi.Logout;
  except
    on E: Exception do ApiError(E);
  end;
  ShowLogin;
end;

procedure TMainForm.SettingsClick(Sender: TObject);
var
  ApiUrl, WebUrl: string;
begin
  ApiUrl := FConfig.ApiBaseUrl;
  WebUrl := FConfig.WebBaseUrl;

  if not InputQuery('Servidor', 'URL base da API v1:', ApiUrl) then Exit;
  if not InputQuery('Servidor', 'URL base Web:', WebUrl) then Exit;

  FConfig.SetApiBaseUrl(ApiUrl);
  FConfig.SetWebBaseUrl(WebUrl);
  FConfig.Save;
  FApi.BaseUrl := FConfig.ApiBaseUrl;
  MessageDlg('Configuração salva.', mtInformation, [mbOK], 0);
end;

procedure TMainForm.RefreshClick(Sender: TObject);
begin
  RefreshAll;
end;

procedure TMainForm.RefreshAll;
begin
  SetBusy(True, 'Atualizando...');
  try
    LoadRooms;
    LoadAgenda;
    StatusBar.SimpleText := 'Atualizado';
  except
    on E: Exception do ApiError(E);
  end;
  SetBusy(False);
end;

procedure TMainForm.LoadRooms;
var
  A: TJSONArray;
  O: TJSONObject;
  I: Integer;
  Scope: string;
begin
  Scope := 'mine';
  A := FApi.Rooms(Scope);
  try
    GridRooms.RowCount := A.Count + 1;
    if GridRooms.RowCount < 2 then GridRooms.RowCount := 2;
    for I := 0 to A.Count - 1 do
    begin
      O := A.Objects[I];
      GridRooms.Cells[0,I+1] := IntToStr(O.Get('id', 0));
      GridRooms.Cells[1,I+1] := O.Get('name', '');
      GridRooms.Cells[2,I+1] := O.Get('starts_at', '');
      GridRooms.Cells[3,I+1] := O.Get('status', '');
      GridRooms.Cells[4,I+1] := IntToStr(O.Get('invite_count', 0));
      GridRooms.Cells[5,I+1] := IntToStr(O.Get('online_count', 0));
      GridRooms.Cells[6,I+1] := O.Get('description', '');
    end;
  finally
    A.Free;
  end;
end;

procedure TMainForm.LoadAgenda;
var
  A: TJSONArray;
  O: TJSONObject;
  I: Integer;
begin
  A := FApi.Agenda(30, 'mine');
  try
    GridAgenda.RowCount := A.Count + 1;
    if GridAgenda.RowCount < 2 then GridAgenda.RowCount := 2;
    for I := 0 to A.Count - 1 do
    begin
      O := A.Objects[I];
      GridAgenda.Cells[0,I+1] := IntToStr(O.Get('id', 0));
      GridAgenda.Cells[1,I+1] := O.Get('name', '');
      GridAgenda.Cells[2,I+1] := O.Get('starts_at', '');
      GridAgenda.Cells[3,I+1] := O.Get('ends_at', '');
      GridAgenda.Cells[4,I+1] := O.Get('status', '');
      GridAgenda.Cells[5,I+1] := O.Get('description', '');
    end;
  finally
    A.Free;
  end;
end;

function TMainForm.SelectedRoomId: Int64;
var
  S: string;
begin
  Result := 0;
  if GridRooms.Row < 1 then Exit;
  S := GridRooms.Cells[0, GridRooms.Row];
  Result := StrToInt64Def(S, 0);
end;

procedure TMainForm.NewRoomClick(Sender: TObject);
var
  Name, Desc, Starts: string;
  R: TJSONObject;
begin
  Name := '';
  Desc := '';
  Starts := '';
  if not InputQuery('Nova sala', 'Nome:', Name) then Exit;
  if Trim(Name) = '' then Exit;
  InputQuery('Nova sala', 'Descrição:', Desc);
  InputQuery('Nova sala', 'Início (AAAA-MM-DD HH:NN:SS ou vazio):', Starts);

  SetBusy(True, 'Criando sala...');
  R := nil;
  try
    R := FApi.CreateRoom(Name, Desc, Starts);
    MessageDlg('Sala criada.', mtInformation, [mbOK], 0);
    RefreshAll;
  except
    on E: Exception do ApiError(E);
  end;
  R.Free;
  SetBusy(False);
end;

procedure TMainForm.OpenMeetingClick(Sender: TObject);
var
  ID: Int64;
  D: TJSONObject;
  Token, Url: string;
begin
  ID := SelectedRoomId;
  if ID <= 0 then
  begin
    MessageDlg('Selecione uma sala.', mtWarning, [mbOK], 0);
    Exit;
  end;

  D := nil;
  try
    D := FApi.RoomDetail(ID);
    Token := D.Get('host_join_token', '');
    if Token = '' then
      raise Exception.Create('A API não retornou o token de entrada do anfitrião.');

    Url := FConfig.WebBaseUrl + '/room.php?token=' + Token;
    if not OpenURL(Url) then
      raise Exception.Create('Não foi possível abrir o navegador.');
  except
    on E: Exception do ApiError(E);
  end;
  D.Free;
end;

procedure TMainForm.InvitesClick(Sender: TObject);
var
  ID: Int64;
  F: TInviteForm;
begin
  ID := SelectedRoomId;
  if ID <= 0 then
  begin
    MessageDlg('Selecione uma sala.', mtWarning, [mbOK], 0);
    Exit;
  end;

  F := TInviteForm.CreateForRoom(Self, FApi, ID);
  try
    F.ShowModal;
    RefreshAll;
  finally
    F.Free;
  end;
end;

procedure TMainForm.RoomActionClick(Sender: TObject);
var
  ID: Int64;
  Action, Prompt: string;
begin
  ID := SelectedRoomId;
  if ID <= 0 then
  begin
    MessageDlg('Selecione uma sala.', mtWarning, [mbOK], 0);
    Exit;
  end;

  case TButton(Sender).Tag of
    1: begin Action := 'open'; Prompt := 'Abrir esta sala?'; end;
    2: begin Action := 'close'; Prompt := 'Encerrar esta sala?'; end;
    3: begin Action := 'cancel'; Prompt := 'Cancelar esta reunião?'; end;
  else
    Exit;
  end;

  if MessageDlg(Prompt, mtConfirmation, [mbYes, mbNo], 0) <> mrYes then Exit;

  SetBusy(True, 'Executando...');
  try
    FApi.RoomAction(ID, Action);
    RefreshAll;
  except
    on E: Exception do ApiError(E);
  end;
  SetBusy(False);
end;

end.

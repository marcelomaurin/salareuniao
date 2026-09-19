unit uInvites;

{$mode objfpc}{$H+}

interface

uses
  Classes, SysUtils, Forms, Controls, Dialogs, StdCtrls, ExtCtrls, Grids,
  fpjson, uApiClient;

type
  TInviteForm = class(TForm)
  private
    FApi: TSalaApiClient;
    FRoomId: Int64;
    Grid: TStringGrid;
    TopPanel, BottomPanel: TPanel;
    EdEmails: TEdit;
    BtnAdd, BtnRefresh, BtnApprove, BtnReject, BtnResend, BtnRemove, BtnClose: TButton;
    procedure BuildUi;
    procedure RefreshClick(Sender: TObject);
    procedure AddClick(Sender: TObject);
    procedure ActionClick(Sender: TObject);
    procedure CloseClick(Sender: TObject);
    procedure LoadInvites;
    function SelectedInviteId: Int64;
    procedure ShowApiError(E: Exception);
  public
    constructor CreateForRoom(TheOwner: TComponent; AApi: TSalaApiClient; ARoomId: Int64);
  end;

implementation

constructor TInviteForm.CreateForRoom(TheOwner: TComponent; AApi: TSalaApiClient;
  ARoomId: Int64);
begin
  inherited CreateNew(TheOwner, 1);
  FApi := AApi;
  FRoomId := ARoomId;
  Caption := 'Convidados da sala #' + IntToStr(FRoomId);
  Width := 900;
  Height := 560;
  Position := poOwnerFormCenter;
  BuildUi;
  LoadInvites;
end;

procedure TInviteForm.BuildUi;
begin
  TopPanel := TPanel.Create(Self);
  TopPanel.Parent := Self;
  TopPanel.Align := alTop;
  TopPanel.Height := 58;

  EdEmails := TEdit.Create(TopPanel);
  EdEmails.Parent := TopPanel;
  EdEmails.Left := 10;
  EdEmails.Top := 14;
  EdEmails.Width := 570;
  EdEmails.TextHint := 'e-mails separados por vírgula ou ponto e vírgula';

  BtnAdd := TButton.Create(TopPanel);
  BtnAdd.Parent := TopPanel;
  BtnAdd.Caption := 'Adicionar';
  BtnAdd.Left := 590;
  BtnAdd.Top := 12;
  BtnAdd.Width := 90;
  BtnAdd.OnClick := @AddClick;

  BtnRefresh := TButton.Create(TopPanel);
  BtnRefresh.Parent := TopPanel;
  BtnRefresh.Caption := 'Atualizar';
  BtnRefresh.Left := 690;
  BtnRefresh.Top := 12;
  BtnRefresh.Width := 90;
  BtnRefresh.OnClick := @RefreshClick;

  Grid := TStringGrid.Create(Self);
  Grid.Parent := Self;
  Grid.Align := alClient;
  Grid.ColCount := 7;
  Grid.FixedRows := 1;
  Grid.RowCount := 2;
  Grid.Options := Grid.Options + [goRowSelect];
  Grid.Cells[0,0] := 'ID';
  Grid.Cells[1,0] := 'E-mail';
  Grid.Cells[2,0] := 'Nome';
  Grid.Cells[3,0] := 'Status';
  Grid.Cells[4,0] := 'Solicitou';
  Grid.Cells[5,0] := 'Aprovado';
  Grid.Cells[6,0] := 'Criado';
  Grid.ColWidths[0] := 55;
  Grid.ColWidths[1] := 230;
  Grid.ColWidths[2] := 170;
  Grid.ColWidths[3] := 90;
  Grid.ColWidths[4] := 125;
  Grid.ColWidths[5] := 125;
  Grid.ColWidths[6] := 125;

  BottomPanel := TPanel.Create(Self);
  BottomPanel.Parent := Self;
  BottomPanel.Align := alBottom;
  BottomPanel.Height := 58;

  BtnApprove := TButton.Create(BottomPanel);
  BtnApprove.Parent := BottomPanel;
  BtnApprove.Caption := 'Autorizar';
  BtnApprove.Left := 10;
  BtnApprove.Top := 12;
  BtnApprove.Tag := 1;
  BtnApprove.OnClick := @ActionClick;

  BtnReject := TButton.Create(BottomPanel);
  BtnReject.Parent := BottomPanel;
  BtnReject.Caption := 'Recusar';
  BtnReject.Left := 100;
  BtnReject.Top := 12;
  BtnReject.Tag := 2;
  BtnReject.OnClick := @ActionClick;

  BtnResend := TButton.Create(BottomPanel);
  BtnResend.Parent := BottomPanel;
  BtnResend.Caption := 'Reenviar';
  BtnResend.Left := 190;
  BtnResend.Top := 12;
  BtnResend.Tag := 3;
  BtnResend.OnClick := @ActionClick;

  BtnRemove := TButton.Create(BottomPanel);
  BtnRemove.Parent := BottomPanel;
  BtnRemove.Caption := 'Remover';
  BtnRemove.Left := 280;
  BtnRemove.Top := 12;
  BtnRemove.Tag := 4;
  BtnRemove.OnClick := @ActionClick;

  BtnClose := TButton.Create(BottomPanel);
  BtnClose.Parent := BottomPanel;
  BtnClose.Caption := 'Fechar';
  BtnClose.Left := 790;
  BtnClose.Top := 12;
  BtnClose.OnClick := @CloseClick;
end;

procedure TInviteForm.ShowApiError(E: Exception);
begin
  MessageDlg('Erro de comunicação', E.Message, mtError, [mbOK], 0);
end;

procedure TInviteForm.LoadInvites;
var
  A: TJSONArray;
  O: TJSONObject;
  I: Integer;
begin
  A := nil;
  try
    A := FApi.RoomInvites(FRoomId);
    Grid.RowCount := A.Count + 1;
    if Grid.RowCount < 2 then Grid.RowCount := 2;

    for I := 1 to Grid.ColCount - 1 do
      if A.Count = 0 then Grid.Cells[I,1] := '';
    if A.Count = 0 then Grid.Cells[0,1] := '';

    for I := 0 to A.Count - 1 do
    begin
      O := A.Objects[I];
      Grid.Cells[0,I+1] := IntToStr(O.Get('id', 0));
      Grid.Cells[1,I+1] := O.Get('email', '');
      Grid.Cells[2,I+1] := O.Get('display_name', '');
      Grid.Cells[3,I+1] := O.Get('status', '');
      Grid.Cells[4,I+1] := O.Get('requested_at', '');
      Grid.Cells[5,I+1] := O.Get('approved_at', '');
      Grid.Cells[6,I+1] := O.Get('created_at', '');
    end;
  except
    on E: Exception do ShowApiError(E);
  end;
  A.Free;
end;

function TInviteForm.SelectedInviteId: Int64;
begin
  Result := 0;
  if Grid.Row < 1 then Exit;
  Result := StrToInt64Def(Grid.Cells[0,Grid.Row], 0);
end;

procedure TInviteForm.RefreshClick(Sender: TObject);
begin
  LoadInvites;
end;

procedure TInviteForm.AddClick(Sender: TObject);
var
  Raw: string;
  L: TStringList;
  Arr: array of string;
  I: Integer;
begin
  Raw := Trim(EdEmails.Text);
  if Raw = '' then Exit;

  Raw := StringReplace(Raw, ',', ';', [rfReplaceAll]);
  L := TStringList.Create;
  try
    L.StrictDelimiter := True;
    L.Delimiter := ';';
    L.DelimitedText := Raw;
    SetLength(Arr, L.Count);
    for I := 0 to L.Count - 1 do Arr[I] := Trim(L[I]);

    try
      FApi.AddInvites(FRoomId, Arr);
      EdEmails.Clear;
      LoadInvites;
    except
      on E: Exception do ShowApiError(E);
    end;
  finally
    L.Free;
  end;
end;

procedure TInviteForm.ActionClick(Sender: TObject);
var
  InviteId: Int64;
  Action: string;
begin
  InviteId := SelectedInviteId;
  if InviteId <= 0 then
  begin
    MessageDlg('Selecione um convidado.', mtWarning, [mbOK], 0);
    Exit;
  end;

  case TButton(Sender).Tag of
    1: Action := 'approve';
    2: Action := 'reject';
    3: Action := 'resend';
    4: Action := 'remove';
  else
    Exit;
  end;

  if (Action = 'remove') and
     (MessageDlg('Remover este participante?', mtConfirmation, [mbYes, mbNo], 0) <> mrYes) then
    Exit;

  try
    FApi.InviteAction(FRoomId, InviteId, Action);
    LoadInvites;
  except
    on E: Exception do ShowApiError(E);
  end;
end;

procedure TInviteForm.CloseClick(Sender: TObject);
begin
  Close;
end;

end.

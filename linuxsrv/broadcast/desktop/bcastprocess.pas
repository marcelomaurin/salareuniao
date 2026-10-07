unit BcastProcess;

{$mode objfpc}{$H+}

interface

uses Classes, SysUtils, Process, BaseUnix;

type
  TBcastProcess = class
  private
    FProcess: TProcess;
    FStopping: Boolean;
    FStopAt: QWord;
    FExitCode: Integer;
    function GetRunning: Boolean;
  public
    destructor Destroy; override;
    procedure Start(const BinaryPath, ConfigPath: string; ValidateOnly: Boolean);
    function ReadOutput: string;
    procedure RequestStop;
    procedure ForceStop;
    function Poll: Boolean;
    function StopTimedOut: Boolean;
    property Running: Boolean read GetRunning;
    property Stopping: Boolean read FStopping;
    property ExitCode: Integer read FExitCode;
  end;

implementation

function TBcastProcess.GetRunning: Boolean;
begin
  Result := Assigned(FProcess) and FProcess.Running;
end;

procedure TBcastProcess.Start(const BinaryPath, ConfigPath: string;
  ValidateOnly: Boolean);
begin
  if Running then raise Exception.Create('O processo já está em execução.');
  if not FileExists(BinaryPath) then
    raise Exception.Create('Executável não encontrado: ' + BinaryPath);
  if fpAccess(PChar(BinaryPath), X_OK) <> 0 then
    raise Exception.Create('O arquivo não tem permissão de execução.');
  if not FileExists(ConfigPath) then
    raise Exception.Create('Selecione um arquivo de configuração existente.');
  FreeAndNil(FProcess);
  FProcess := TProcess.Create(nil);
  FProcess.Executable := ExpandFileName(BinaryPath);
  FProcess.CurrentDirectory := ExtractFileDir(ExpandFileName(ConfigPath));
  FProcess.Parameters.Add('-c');
  FProcess.Parameters.Add(ExpandFileName(ConfigPath));
  if ValidateOnly then FProcess.Parameters.Add('-t')
  else FProcess.Parameters.Add('-f');
  FProcess.Options := [poUsePipes, poStderrToOutPut];
  FStopping := False;
  FExitCode := -1;
  try
    FProcess.Execute;
  except
    FreeAndNil(FProcess);
    raise;
  end;
end;

function TBcastProcess.ReadOutput: string;
var Buffer: array[0..4095] of Char; N, Remaining: Integer; Chunk: string;
begin
  Result := '';
  if not Assigned(FProcess) then Exit;
  Remaining := 65536; // Limita o trabalho de cada ciclo da interface.
  while (Remaining > 0) and (FProcess.Output.NumBytesAvailable > 0) do
  begin
    N := FProcess.Output.NumBytesAvailable;
    if N > SizeOf(Buffer) then N := SizeOf(Buffer);
    if N > Remaining then N := Remaining;
    N := FProcess.Output.Read(Buffer, N);
    if N <= 0 then Break;
    SetString(Chunk, PChar(@Buffer[0]), N);
    Result := Result + Chunk;
    Dec(Remaining, N);
  end;
end;

procedure TBcastProcess.RequestStop;
begin
  if not Running or FStopping then Exit;
  if fpKill(FProcess.ProcessID, SIGTERM) <> 0 then
    raise Exception.Create('Não foi possível solicitar a parada do processo.');
  FStopping := True;
  FStopAt := GetTickCount64;
end;

procedure TBcastProcess.ForceStop;
begin
  if Running then
    if fpKill(FProcess.ProcessID, SIGKILL) <> 0 then
      raise Exception.Create('Não foi possível encerrar o processo.');
end;

function TBcastProcess.StopTimedOut: Boolean;
begin
  Result := Running and FStopping and (GetTickCount64 - FStopAt >= 10000);
end;

function TBcastProcess.Poll: Boolean;
begin
  Result := Assigned(FProcess) and not FProcess.Running;
  if Result then
  begin
    FExitCode := FProcess.ExitCode;
    FStopping := False;
  end;
end;

destructor TBcastProcess.Destroy;
begin
  // A janela aguarda a parada antes de destruir o controlador.
  if Running then
  begin
    fpKill(FProcess.ProcessID, SIGTERM);
    if not FProcess.WaitOnExit(1000) then
    begin
      fpKill(FProcess.ProcessID, SIGKILL);
      FProcess.WaitOnExit(1000);
    end;
  end;
  FProcess.Free;
  inherited Destroy;
end;

end.

# Cliente Web — PHP + MySQL + WebRTC P2P

O cliente Web do Sala Reunião usa **PHP 8.1+**, **MySQL/MariaDB** e **WebRTC** no navegador.

## Recursos implementados

- login por sessão PHP;
- perfis `admin` e `user`;
- administração de usuários;
- criação e abertura/encerramento de salas;
- convite por e-mail com token individual;
- sala de espera;
- aprovação/recusa pelo responsável;
- identidade automática para o anfitrião;
- sinalização WebRTC via PHP/MySQL;
- áudio e vídeo P2P;
- lista de participantes online;
- heartbeat de presença;
- estado de microfone, câmera e compartilhamento;
- compartilhamento de tela com `getDisplayMedia()` e `replaceTrack()`;
- remoção de participante pelo responsável;
- tratamento de ICE recebido antes da descrição remota;
- layout responsivo básico da videoconferência.

## Fluxo

1. Usuário autenticado cria uma sala.
2. O sistema cria automaticamente a identidade aprovada do anfitrião.
3. Os convidados recebem links individuais.
4. O convidado solicita entrada e fica em `waiting`.
5. O responsável autoriza ou rejeita.
6. Com a sala aberta, participantes aprovados entram em `room.php`.
7. Os browsers trocam `peer-ready`, `offer`, `answer`, `ice` e `leave`.
8. ICE/STUN negocia a rota e o WebRTC estabelece mídia P2P quando possível.

## Banco

Instalação nova:

```bash
mysql -u root -p < sql/schema.sql
```

Se o banco já foi criado antes da implementação de presença:

```bash
mysql -u root -p < sql/002_presence.sql
```

## Configuração

1. Copie `config.example.php` para `config.php`.
2. Configure MySQL, `base_url`, remetente de e-mail e ICE servers.
3. Gere uma senha com `password_hash()` e insira o primeiro administrador.
4. Publique o site em **HTTPS**.

HTTPS é necessário para câmera, microfone e compartilhamento de tela fora de `localhost`.

## P2P e NAT

O PHP não transmite vídeo. Ele faz autenticação, controle de sala e sinalização.

O WebRTC usa ICE para descobrir IPs/portas. STUN ajuda a descobrir o endereço público. Para redes onde a conexão direta não funciona, ainda será necessário configurar um servidor **TURN**.

## Próximos itens

- SMTP autenticado/PHPMailer e e-mails HTML (implementado);
- chat persistente da reunião (implementado);
- edição de reunião, novos convidados, reenvio e cancelamento (implementado);
- TURN/Coturn com credenciais temporárias (implementado; requer configuração do servidor);
- limpeza automática de sinalização antiga;
- troca do polling por WebSocket ou canal de eventos dedicado;
- cliente Desktop;
- integração ESP32.


## TURN em produção

O cliente gera credenciais TURN temporárias no servidor PHP usando o segredo compartilhado configurado em `webrtc.turn.secret`. O mesmo segredo deve ser configurado no Coturn em `static-auth-secret`.

Consulte `../../docs/PRODUCTION.md` e `../../deployment/coturn/turnserver.conf.example`.

O painel `admin_system.php` verifica os pré-requisitos básicos da instalação. Dentro da reunião, o indicador ICE mostra se a conexão está usando P2P direto ou TURN relay.


## Chat e histórico

O chat é persistido em MySQL na tabela `room_messages`. O cliente carrega as últimas mensagens ao entrar e faz polling incremental enquanto a reunião está aberta.

Para bancos já existentes, aplique:

```bash
mysql -u root -p salareuniao < apps/web/sql/003_chat.sql
```

O anfitrião e o administrador podem consultar o histórico em `room_history.php`.


## Gestão de reuniões

O responsável pela sala, e o administrador global, podem:
- editar nome, descrição e horários;
- adicionar convidados depois da criação;
- reenviar um convite existente;
- abrir e encerrar a sala;
- cancelar a reunião;
- remover participantes;
- consultar histórico de chat e participação.

A tabela `room_attendance` registra cada sessão de entrada/saída. Se um navegador desaparecer sem executar a saída normal, o heartbeat fecha a sessão usando o último `last_seen_at`.

Para bancos existentes:

```bash
mysql -u root -p salareuniao < apps/web/sql/004_attendance.sql
```


## WebSocket em tempo real

A sinalização WebRTC, presença e chat usam preferencialmente o serviço PHP Ratchet em `services/signaling`.

Quando a conexão WebSocket está disponível, a tela mostra `Conectado em tempo real`. Em caso de falha, o cliente retorna automaticamente aos endpoints HTTP de polling, permitindo implantação gradual e maior tolerância a falhas.


## SMTP e recuperação de senha

O cliente Web usa PHPMailer via Composer para SMTP autenticado.

```bash
cd apps/web
composer install --no-dev --optimize-autoloader
```

Configure a seção `mail` do `config.php`. O painel **Administração > Diagnóstico** verifica se o PHPMailer está instalado, se o SMTP foi configurado e permite enviar uma mensagem de teste para o administrador autenticado.

Os fluxos de e-mail incluem:
- convite inicial;
- reenvio de convite;
- aviso de cancelamento;
- recuperação de senha.

A recuperação usa token aleatório de 256 bits; somente o SHA-256 do token é salvo no banco. O link expira em 60 minutos e é invalidado após o uso.

Para bancos existentes:

```bash
mysql -u root -p salareuniao < apps/web/sql/005_password_reset.sql
```


---

## Arquitetura de Controle e Modo Apresentação

### 1. Parâmetro Persistente `max_mesh_participants`
- Gerenciável através do painel administrativo em **Sistema > WebRTC / Mídia**.
- Define o limite recomendado de participantes simultâneos em topologia WebRTC Mesh pura (padrão: `4`, configurável entre 2 e 100).
- Quando a sala ultrapassa este valor, o sistema alerta sobre a sobrecarga de upload dos clientes e orienta a utilização do **Modo Apresentação**.

### 2. Modo Normal vs. Modo Apresentação / Full
- **Modo Normal (Grade Colaborativa):** Todos os participantes com câmera ligada transmitem vídeo na resolução padrão (`640x360 @ 24fps`, ~350 kbps), adequado para reuniões em grupo reduzido.
- **Modo Apresentação (Otimização Extrema de Mídia):**
  - **Não é apenas uma alteração visual de layout.** É um modo de conservação de rede e elevação de qualidade.
  - O apresentador aprovado assume o palco principal com qualidade máxima (`1920x1080 @ 30fps` ou `1280x720 @ 30fps`, 1.2–2 Mbps).
  - Os demais participantes **suspendem o envio de vídeo** via `RTCRtpSender.replaceTrack(null)`, reduzindo drasticamente o consumo de banda de upload para zero bytes de vídeo, mantendo o hardware da câmera pronto para restauração imediata.
  - O áudio de todos permanece ativo para perguntas e comentários.
  - Ao término da apresentação, os vídeos anteriormente ativos são restaurados automaticamente, respeitando as preferências de quem já estava com a câmera desligada.

### 3. Pedir Palavra e Fila FIFO
- O participante clica em **✋ Pedir Palavra**, registrando `hand_requested_at` no servidor.
- O administrador visualiza uma fila estritamente ordenada por ordem de chegada (**FIFO**).
- O administrador pode **ACEITAR** (promovendo atomicamente o participante a apresentador e iniciando o Modo Apresentação) ou **RECUSAR** (notificando o solicitante e liberando a fila).
- Correção do bug de ressurreição: o heartbeat do cliente nunca sobrepõe uma recusa ou aprovação do servidor.

### 4. Canal WebSocket de Controle Administrativo (`/control`)
- Canal independente da sinalização WebRTC Mesh (`ControlSocket` em PHP Ratchet).
- Permite que o anfitrião mantenha autoridade total mesmo se as conexões de mídia P2P sofrerem degradação:
  - `participant.video.allow` / `participant.video.inhibit`
  - `participant.audio.allow` / `participant.audio.inhibit`
  - `participant.screen.allow` / `participant.screen.inhibit`
  - `participant.kick` (expulsão autoritativa com invalidação imediata no servidor)
  - `room.presentation.start` / `room.presentation.end`
- Cada comando possui `command_id` único para idempotência, versionamento monotônico de estado (`state_version`), confirmação formal por **ACK** e sincronização de estado (`state.sync`) após reconexão.

### 5. Scripts SQL desta Atualização
Para aplicar as migrações em instalações existentes:
```bash
mysql -u root -p salareuniao < sql/010_system_parameters.sql
mysql -u root -p salareuniao < sql/011_room_runtime_state.sql
mysql -u root -p salareuniao < sql/012_room_audit.sql
```
*(O sistema também realiza auto-provisionamento automático destas tabelas via `lib/bootstrap.php`).*

Consulte a documentação técnica detalhada em:
- [docs/CONTROL_PROTOCOL.md](docs/CONTROL_PROTOCOL.md)
- [docs/PRESENTATION_MODE.md](docs/PRESENTATION_MODE.md)

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

- SMTP autenticado/PHPMailer;
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

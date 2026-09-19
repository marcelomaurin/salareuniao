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
- chat da reunião;
- convite/edição/cancelamento mais completos;
- TURN;
- limpeza automática de sinalização antiga;
- troca do polling por WebSocket ou canal de eventos dedicado;
- cliente Desktop;
- integração ESP32.

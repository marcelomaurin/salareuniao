# Cliente Web — PHP + MySQL + WebRTC P2P

O cliente Web do Sala Reunião usa **PHP 8.1+**, **MySQL/MariaDB** e **WebRTC** no navegador.

## Fluxo principal

1. Usuário autenticado cria uma sala.
2. Informa os e-mails dos convidados.
3. O PHP gera um token individual e envia um link por e-mail.
4. O convidado abre o link, informa o nome e entra na sala de espera.
5. O responsável pela sala autoriza ou rejeita a entrada.
6. Após aprovação e abertura da sala, o convidado entra em `room.php`.
7. Os browsers trocam SDP e candidatos ICE pelos endpoints PHP.
8. O WebRTC negocia a rota e estabelece áudio/vídeo P2P sempre que possível.

## Sinalização

O PHP/MySQL **não transmite o vídeo**. Ele guarda e entrega apenas:
- `peer-ready`
- `offer`
- `answer`
- `ice`
- `leave`

A descoberta de IPs e portas é feita pelo mecanismo ICE do WebRTC. STUN ajuda a descobrir endereços públicos. Em NAT/firewall restritivo deve existir TURN como fallback.

## Instalação

1. Copie `config.example.php` para `config.php`.
2. Configure MySQL, `base_url`, remetente de e-mail e ICE servers.
3. Execute `sql/schema.sql`.
4. Gere uma senha com `password_hash()` e insira o primeiro administrador.
5. Publique o site em **HTTPS**, necessário para câmera e microfone fora de localhost.

## Administração

`admin.php` apresenta usuários e salas. `room_manage.php` é o painel do responsável pela reunião, onde a sala é aberta/fechada e participantes em espera são aprovados ou rejeitados.

# Arquitetura da Plataforma

## Stack principal

- **Web:** PHP 8.1+, MySQL/MariaDB, HTML/CSS/JavaScript.
- **Videoconferência:** WebRTC no navegador.
- **Sinalização:** endpoints PHP persistindo mensagens de controle no MySQL.
- **Desktop:** deverá consumir os mesmos contratos de sala/sinalização.
- **ESP32:** terminal/controlador físico da sala.

## Fluxo de reunião

1. Usuário autenticado cria uma sala.
2. O sistema gera convites individuais por e-mail.
3. Cada convite contém um token aleatório.
4. O convidado abre o link e solicita entrada.
5. O responsável pela sala vê o convidado em estado `waiting`.
6. O responsável aprova ou rejeita.
7. Com a sala aberta e o convite aprovado, o navegador acessa a videoconferência.
8. Os navegadores trocam mensagens WebRTC por meio do servidor PHP:
   - peer-ready
   - offer
   - answer
   - ice
   - leave
9. ICE/STUN descobre IPs e portas possíveis.
10. O WebRTC cria a conexão de áudio/vídeo P2P sempre que a rede permitir.

## Topologia

```text
                    PHP + MySQL
              autenticação / administração
              salas / convites / aprovação
              sinalização WebRTC
                     /        \
                    /          \
            offer/answer      ICE candidates
                  /              \
          +------v----+       +---v-------+
          | Browser A |<----->| Browser B |
          +-----------+  P2P  +-----------+
                 áudio/vídeo direto
```

O PHP não encaminha a mídia no modo P2P.

## IP e porta

O servidor não deve tentar escolher manualmente um IP e uma porta para os navegadores. Navegadores modernos utilizam ICE. Cada browser gera candidatos de conectividade contendo os endereços/portas que podem ser tentados. Esses candidatos são enviados ao outro participante pelo serviço de sinalização.

- **host candidate:** endereço local quando disponível;
- **server-reflexive candidate:** endereço público descoberto via STUN;
- **relay candidate:** endereço fornecido por TURN, quando conexão direta não é possível.

Assim, o servidor efetivamente ajuda os terminais a trocar as informações de conexão, mas a seleção final do caminho é feita pelo WebRTC/ICE.

## P2P em grupos

A primeira implementação usa **mesh P2P**. Cada participante mantém uma conexão com cada outro participante. Isso é simples e elimina servidor de mídia, mas aumenta consumo de upload e CPU conforme a reunião cresce.

Para salas maiores poderá ser introduzido um SFU sem alterar autenticação, administração, convites e sala de espera.

## Segurança

- autenticação por sessão PHP;
- senha com `password_hash()`/`password_verify()`;
- CSRF nos formulários administrativos;
- links de convite com 256 bits de aleatoriedade;
- prepared statements via PDO;
- `config.php` fora do Git;
- HTTPS obrigatório para câmera/microfone;
- TURN com credenciais temporárias quando for implantado.

## Módulos Web

```text
apps/web/
├── admin.php
├── admin_users.php
├── api/
│   ├── signal_poll.php
│   └── signal_send.php
├── lib/
│   └── bootstrap.php
├── sql/
│   └── schema.sql
├── config.example.php
├── index.php
├── login.php
├── logout.php
├── join.php
├── room_create.php
├── room_manage.php
└── room.php
```

# Perfis e controle de acesso

O Sala Reunião possui exatamente dois perfis autenticados no sistema:

## admin

Administrador global da plataforma.

Pode:
- cadastrar, ativar/desativar e alterar perfil de usuários;
- visualizar todas as salas, independentemente do proprietário;
- inspecionar salas e participantes;
- acompanhar salas abertas, participantes online, compartilhamentos e volume de sinalização;
- intervir em qualquer sala quando necessário;
- acessar também os recursos normais de criação de reuniões.

O painel administrativo **não captura nem grava o conteúdo de áudio/vídeo**. Em P2P, a mídia trafega entre os clientes. A administração vê metadados operacionais e presença.

## user

Usuário operacional.

Pode:
- autenticar-se;
- criar salas próprias;
- convidar participantes;
- abrir/encerrar as próprias salas;
- aprovar ou rejeitar convidados das próprias salas;
- entrar como anfitrião;
- participar da conversa;
- controlar microfone, câmera e compartilhamento de tela;
- remover participantes das salas que criou.

Não pode:
- acessar o dashboard administrativo global;
- cadastrar/alterar outros usuários;
- visualizar ou administrar salas criadas por outros usuários;
- consultar métricas globais do sistema.

## Administrador x anfitrião

São conceitos diferentes:

- **admin** é um perfil de sistema.
- **anfitrião** é o proprietário de uma reunião/sala.

Um usuário comum pode ser anfitrião de suas próprias reuniões sem possuir privilégios administrativos globais.

## Métricas

O dashboard administrativo mede:
- usuários ativos;
- quantidade de admins e usuários;
- total de salas;
- salas abertas/agendadas;
- participantes online;
- compartilhamentos de tela ativos;
- convites;
- volume recente de sinalização.

Como a mídia é WebRTC P2P, a banda de áudio/vídeo não passa normalmente pelo PHP. Quando houver TURN, as métricas de rede do TURN deverão ser integradas ao dashboard para representar consumo de relay.

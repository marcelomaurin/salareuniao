# Guia e Especificação de Testes WebRTC - Sala Reunião

Este documento estabelece o roteiro e os critérios de validação manual obrigatória da arquitetura WebRTC Mesh da plataforma Sala Reunião.

---

## Topologia e Princípios de Funcionamento

1. **Topologia**: Full Mesh P2P (`N` participantes resultam em `N-1` conexões `RTCPeerConnection` por cliente e `N*(N-1)/2` relações totais na sala).
2. **Sinalização**: Ratchet WebSocket como canal primário de alta velocidade e polling HTTP (`signal_poll.php`, `signal_send.php`, `presence.php`, `chat_poll.php`) como fallback automático e transparente.
3. **Mídia P2P**: Vídeo e áudio trafegam diretamente entre os navegadores via STUN (P2P direto). Caso haja bloqueio por NAT simétrico ou firewalls corporativos, a mídia é roteada via servidor TURN (Coturn) em modo `relay`.
4. **Perfect Negotiation**: Negociação determinística e simétrica baseada no identificador `participant_key`, prevenindo colisões de `offer/answer`.
5. **Filas de ICE**: Candidatos que chegam antes da descrição remota são enfileirados e processados ordenadamente após `setRemoteDescription()`.

---

## Roteiro de Testes Obrigatórios

### Teste A: Comunicação Direta entre 2 Navegadores (Mesma Rede)
* **Cenário**: 2 instâncias de navegadores (ex: Chrome e Firefox ou abas em modo anônimo) na mesma rede local ou Wi-Fi.
* **Procedimento**:
  1. O Usuário 1 cria e abre uma sala de reunião.
  2. O Usuário 2 entra na mesma sala via link de convite.
  3. Validar a conexão mútua no palco.
* **Critérios de Aceite**:
  - [ ] **Áudio**: Som bidirecional claro e audível sem eco.
  - [ ] **Vídeo**: Imagem fluida de ambos os participantes.
  - [ ] **Mute de Microfone**: Clicar no botão de microfone desativa o envio do áudio; reativar restaura a transmissão imediatamente.
  - [ ] **Câmera On/Off**: Desligar a câmera oculta o vídeo e exibe o avatar com inicial sem fechar a conexão WebRTC. Ligar a câmera restaura o vídeo em alta definição.
  - [ ] **Compartilhamento de Tela**: Clicar em "Compartilhar" substitui temporariamente o track de vídeo pela tela capturada sem criar uma segunda conexão WebRTC. Ao parar, a câmera é restaurada.
  - [ ] **Chat**: Mensagens enviadas de um participante chegam instantaneamente no painel de chat do outro.

---

### Teste B: Conexão Cruzada Wi-Fi vs 4G/5G (STUN, TURN Relay e NAT Travessia)
* **Cenário**: Participante 1 em conexão Wi-Fi residencial/corporativa e Participante 2 em rede de dados móveis 4G/5G (ou duas redes com NAT restritivo).
* **Procedimento**:
  1. Conectar Participante 1 no notebook via Wi-Fi.
  2. Conectar Participante 2 no smartphone via 4G/5G.
  3. Abrir o painel de diagnóstico de conexão em cada navegador.
* **Critérios de Aceite**:
  - [ ] **STUN / TURN**: A chamada se estabelece com sucesso mesmo sob NAT duplo ou restritivo.
  - [ ] **Candidato Relay**: Quando a conexão direta P2P (`host` ou `srflx`) for bloqueada pelo roteador móvel, o WebRTC utiliza o candidato `relay` gerado pelo Coturn.
  - [ ] **Indicador de Rota**: O diagnóstico exibe rota "TURN relay" ou "P2P direto" correspondente ao par de candidatos negociados.

---

### Teste C: Sala com 3 Participantes
* **Cenário**: Usuários A, B e C ingressam na mesma sala em ordens aleatórias.
* **Procedimento**:
  1. Usuário A entra na sala.
  2. Usuário B entra na sala -> A e B se conectam.
  3. Usuário C entra na sala -> C descobre A e B; A e B descobrem C.
* **Critérios de Aceite**:
  - [ ] **Peers Locais**: Cada navegador possui exatamente 2 `RTCPeerConnection`s ativas e conectadas no mapa (`pcs.size === 2`).
  - [ ] **Total da Sala**: Exatamente 3 conexões WebRTC no mesh (`A↔B`, `A↔C`, `B↔C`).
  - [ ] **Contador**: O cabeçalho exibe `Participantes: 3 | Peers esperados: 2 | Conectados: 2` com status "Todos conectados".
  - [ ] **Grid**: Layout ajustado proporcionalmente para 3 vídeos com avatares e estados sincronizados.

---

### Teste D: Sala com 4 Participantes (Limite Mesh Recomendado)
* **Cenário**: Usuários A, B, C e D simultaneamente na sala.
* **Procedimento**:
  1. Os 4 participantes ingressam na sala.
  2. Validar que não ocorrem colisões de oferta mesmo se C e D entrarem simultaneamente.
* **Critérios de Aceite**:
  - [ ] **Peers Locais**: Cada navegador possui exatamente 3 `RTCPeerConnection`s ativas (`pcs.size === 3`).
  - [ ] **Total da Sala**: Exatamente 6 relações WebRTC ativas:
    - `A ↔ B`, `A ↔ C`, `A ↔ D`
    - `B ↔ C`, `B ↔ D`
    - `C ↔ D`
  - [ ] **Contador**: O indicador mostra `Participantes: 4 | Peers esperados: 3 | Conectados: 3`.
  - [ ] **Estabilidade**: Áudio e vídeo sincronizados em todos os nós sem travamentos na sinalização.

---

### Teste E: Resiliência de Rede e Recuperação de Queda (Reconexão e ICE Restart)
* **Cenário**: Um dos participantes sofre oscilação temporária de conexão (ex: Wi-Fi desliga e liga).
* **Procedimento**:
  1. Em chamada ativa entre 2 ou mais participantes, desativar a rede do Participante A por 5 segundos.
  2. Observar a transição de estado da conexão (`disconnected`).
  3. Reativar a rede do Participante A.
* **Critérios de Aceite**:
  - [ ] **Detecção**: O sistema identifica a perda de conexão e inicia carência sem recarregar a página.
  - [ ] **ICE Restart**: O participante executa `restartIce()` e reoferta `{ iceRestart: true }`.
  - [ ] **Recuperação**: O estado retorna para `connected` automaticamente e os fluxos de áudio/vídeo voltam a fluir.
  - [ ] **Fallback em Caso de Falha**: Se permanecer em `failed`, apenas aquele peer pontual é destruído e recriado via `getOrCreatePeer()` sem afetar as demais conexões.

---

### Teste F: Saída Abrupta de Participante (Queda de Janela / Fechamento de Aba)
* **Cenário**: Participante fecha o navegador abruptamente sem clicar em "Sair".
* **Procedimento**:
  1. Dois participantes estão na sala com vídeo ativo.
  2. O Participante B fecha a aba ou o navegador diretamente.
* **Critérios de Aceite**:
  - [ ] **sendBeacon / WebSocket close**: O evento de fechamento envia aviso de saída imediata ao servidor.
  - [ ] **Limpeza no Remoto**: O Participante A recebe `leave` ou detecta a expiração de presença e remove o tile de vídeo e a conexão correspondente.
  - [ ] **Sem Conexões Órfãs**: O mapa `pcs` remove o peer desconectado e o contador de participantes é decrementado.

---

### Teste G: Entrada com Câmera Bloqueada ou Indisponível
* **Cenário**: Participante ingressa na reunião com a câmera bloqueada nas permissões do navegador ou ocupada por outro aplicativo.
* **Procedimento**:
  1. Negar a permissão de câmera nas preferências do navegador antes de entrar.
  2. Ingressar na reunião fornecendo apenas permissão de microfone.
* **Critérios de Aceite**:
  - [ ] **Degradação Elegante**: A aplicação não lança exceção fatal; entra normalmente em modo áudio.
  - [ ] **Avatar Ativo**: O tile do participante exibe o avatar com a inicial do seu nome.
  - [ ] **Áudio Bidirecional**: O áudio continua funcionando perfeitamente em ambas as direções.
  - [ ] **Ativação Posterior**: Caso a câmera seja liberada no sistema e o usuário clique em "Ligar Câmera", o track é adicionado via `replaceTrack()` sem reiniciar a chamada.

---

### Teste H: Entrada como Ouvinte (Câmera e Microfone Bloqueados)
* **Cenário**: Participante acessa a reunião sem nenhum dispositivo de mídia disponível ou com todas as permissões negadas.
* **Procedimento**:
  1. Bloquear câmera e microfone.
  2. Ingressar na sala.
* **Critérios de Aceite**:
  - [ ] **Modo Ouvinte**: O usuário entra na sala com sucesso com `localStream` vazio (0 tracks de áudio/vídeo locais).
  - [ ] **Recepção de Mídia**: O ouvinte consegue ver e ouvir perfeitamente os demais participantes da sala.
  - [ ] **Interação**: O ouvinte pode participar livremente do chat de texto e visualizar compartilhamento de tela.

# Produção: HTTPS + Coturn

## Objetivo

Permitir que reuniões funcionem entre redes diferentes, inclusive quando o P2P direto falhar por NAT, CGNAT ou firewall.

## DNS

Crie dois nomes, por exemplo:

- `meet.seu-dominio.example` -> servidor PHP/HTTPS
- `turn.seu-dominio.example` -> servidor Coturn

Podem apontar para a mesma máquina, desde que as portas estejam disponíveis.

## Portas

No firewall do servidor TURN, libere:

- TCP/UDP 3478
- TCP 5349
- UDP 49160-49260

Se alterar a faixa `min-port/max-port`, ajuste o firewall.

## Coturn

1. Instale Coturn.
2. Copie `deployment/coturn/turnserver.conf.example` para `/etc/turnserver.conf`.
3. Gere um segredo forte:

```bash
openssl rand -hex 32
```

4. Coloque o mesmo segredo em:
   - `static-auth-secret` do Coturn;
   - `webrtc.turn.secret` do `apps/web/config.php`.
5. Configure certificado TLS para `turn.seu-dominio.example`.
6. Reinicie o Coturn.

O projeto usa credenciais temporárias no formato de autenticação REST do Coturn. O segredo nunca é enviado ao navegador.

## HTTPS

O cliente Web deve ser servido em HTTPS. Configure Apache/Nginx com certificado válido.

## Teste mínimo

Teste com:

1. computador A em uma rede;
2. computador B em outra rede, de preferência 4G/5G ou outro provedor;
3. abrir a mesma sala;
4. confirmar áudio e vídeo;
5. abrir o painel de diagnóstico na reunião;
6. verificar o tipo do candidato selecionado.

Interpretação:

- `host` / `srflx`: conexão direta P2P;
- `relay`: mídia passando pelo TURN.

## Produção

Depois do teste funcional:

- monitore uso de CPU, RAM e banda do Coturn;
- monitore quantidade de allocations;
- dimensione a faixa de portas;
- use limites de banda/quota adequados;
- automatize renovação do certificado;
- mantenha `config.php` fora do Git.

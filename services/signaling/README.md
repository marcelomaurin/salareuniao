# Signaling

Serviço de eventos em tempo real e sinalização WebRTC.

Responsável por entrada/saída em reuniões, presença e troca de mensagens `offer`, `answer` e `ice-candidate`.

O serviço de sinalização não deve processar vídeo diretamente. A mídia utiliza WebRTC e, quando necessário, infraestrutura STUN/TURN/SFU.

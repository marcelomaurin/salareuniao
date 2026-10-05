#ifndef BC_FSM_H
#define BC_FSM_H

/* Estados da conexao: novo cliente > sala de espera > na sala > fim. */
typedef enum {
    BC_ST_NEW = 0,
    BC_ST_HANDSHAKE,
    BC_ST_AUTH,
    BC_ST_WAITING,
    BC_ST_IN_ROOM,
    BC_ST_DENIED,
    BC_ST_KICKED,
    BC_ST_BANNED,
    BC_ST_LEFT,
    BC_ST_CLOSED,
    BC_ST__COUNT
} bc_state;

typedef enum {
    BC_EV_TCP_OPEN = 0,
    BC_EV_WS_OK,
    BC_EV_WS_BAD,
    BC_EV_HELLO_WAIT,
    BC_EV_HELLO_ROOM,
    BC_EV_HELLO_BANNED,
    BC_EV_HELLO_BAD,
    BC_EV_ADMIT,
    BC_EV_DENY,
    BC_EV_BAN,
    BC_EV_KICK,
    BC_EV_BYE,
    BC_EV_TIMEOUT,
    BC_EV_SOCK_CLOSED,
    BC_EV_ROOM_CLOSED,
    BC_EV_REPLACED,
    BC_EV__COUNT
} bc_event;

/* Subestado de fala dentro de IN_ROOM. */
typedef enum {
    BC_SUB_NONE = 0,
    BC_SUB_VIEWER,
    BC_SUB_HAND,
    BC_SUB_PENDING,
    BC_SUB_SPEAKING
} bc_sub;

/* Estado da sala. */
typedef enum {
    BC_RS_CREATED = 0,
    BC_RS_OPEN_IDLE,
    BC_RS_OPEN_LIVE,
    BC_RS_CLOSING,
    BC_RS_CLOSED
} bc_room_state;

/* Retorna o proximo estado ou -1 se a transicao nao existe. */
int  bc_fsm_next(bc_state from, bc_event ev);
int  bc_fsm_is_terminal(bc_state s);
int  bc_fsm_is_active(bc_state s);      /* WAITING ou IN_ROOM */
const char *bc_state_name(bc_state s);  /* nome curto para o protocolo */
const char *bc_event_name(bc_event e);
const char *bc_sub_name(bc_sub s);
const char *bc_room_state_name(bc_room_state s);

/* Transicoes do subestado de fala; -1 se invalida. */
int  bc_sub_next(bc_sub from, bc_sub to);

#endif

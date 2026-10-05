/*
 * bc_fsm.c - tabelas das maquinas de estado
 *
 * Toda mudanca de estado de conexao passa por bc_fsm_next(); a tabela e a
 * unica fonte de verdade sobre quais transicoes existem. As acoes de cada
 * transicao ficam em bc_room.c (bc_room_transition).
 */
#include "config.h"
#include "bc_fsm.h"

#define X (-1)

/* linhas: estado de origem; colunas: evento (ordem de bc_event) */
static const int TABLE[BC_ST__COUNT][BC_EV__COUNT] = {
/*                 TCP_OPEN      WS_OK         WS_BAD        HELLO_WAIT     HELLO_ROOM     HELLO_BANNED  HELLO_BAD     ADMIT          DENY          BAN           KICK          BYE           TIMEOUT       SOCK_CLOSED   ROOM_CLOSED   REPLACED */
/* NEW       */ { BC_ST_HANDSHAKE, X,          X,            X,             X,             X,            X,            X,             X,            X,            X,            X,            BC_ST_CLOSED, BC_ST_CLOSED, X,            X },
/* HANDSHAKE */ { X,           BC_ST_AUTH,     BC_ST_CLOSED, X,             X,             X,            X,            X,             X,            X,            X,            X,            BC_ST_CLOSED, BC_ST_CLOSED, X,            X },
/* AUTH      */ { X,           X,              X,            BC_ST_WAITING, BC_ST_IN_ROOM, BC_ST_BANNED, BC_ST_CLOSED, X,             X,            X,            X,            BC_ST_CLOSED, BC_ST_CLOSED, BC_ST_CLOSED, BC_ST_CLOSED, X },
/* WAITING   */ { X,           X,              X,            X,             X,             X,            X,            BC_ST_IN_ROOM, BC_ST_DENIED, BC_ST_BANNED, X,            BC_ST_LEFT,   BC_ST_LEFT,   BC_ST_LEFT,   BC_ST_LEFT,   BC_ST_LEFT },
/* IN_ROOM   */ { X,           X,              X,            X,             X,             X,            X,            X,             X,            BC_ST_BANNED, BC_ST_KICKED, BC_ST_LEFT,   BC_ST_LEFT,   BC_ST_LEFT,   BC_ST_LEFT,   BC_ST_LEFT },
/* DENIED    */ { X,           X,              X,            X,             X,             X,            X,            X,             X,            X,            X,            X,            X,            BC_ST_DENIED, X,            X },
/* KICKED    */ { X,           X,              X,            X,             X,             X,            X,            X,             X,            X,            X,            X,            X,            BC_ST_KICKED, X,            X },
/* BANNED    */ { X,           X,              X,            X,             X,             X,            X,            X,             X,            X,            X,            X,            X,            BC_ST_BANNED, X,            X },
/* LEFT      */ { X,           X,              X,            X,             X,             X,            X,            X,             X,            X,            X,            X,            X,            BC_ST_LEFT,   X,            X },
/* CLOSED    */ { X,           X,              X,            X,             X,             X,            X,            X,             X,            X,            X,            X,            X,            BC_ST_CLOSED, X,            X }
};

int bc_fsm_next(bc_state from, bc_event ev)
{
    if ((int)from < 0 || from >= BC_ST__COUNT || (int)ev < 0 || ev >= BC_EV__COUNT) return X;
    return TABLE[from][ev];
}

int bc_fsm_is_terminal(bc_state s)
{
    return s == BC_ST_DENIED || s == BC_ST_KICKED || s == BC_ST_BANNED ||
           s == BC_ST_LEFT || s == BC_ST_CLOSED;
}

int bc_fsm_is_active(bc_state s)
{
    return s == BC_ST_WAITING || s == BC_ST_IN_ROOM;
}

const char *bc_state_name(bc_state s)
{
    static const char *n[] = { "new", "handshake", "auth", "waiting", "in_room",
                               "denied", "kicked", "banned", "left", "closed" };
    if ((int)s < 0 || s >= BC_ST__COUNT) return "?";
    return n[s];
}

const char *bc_event_name(bc_event e)
{
    static const char *n[] = { "tcp_open", "ws_ok", "ws_bad", "hello_wait", "hello_room",
                               "hello_banned", "hello_bad", "admit", "deny", "ban", "kick",
                               "bye", "timeout", "sock_closed", "room_closed", "replaced" };
    if ((int)e < 0 || e >= BC_EV__COUNT) return "?";
    return n[e];
}

const char *bc_sub_name(bc_sub s)
{
    static const char *n[] = { "none", "viewer", "hand", "pending", "speaking" };
    if ((int)s < 0 || s > BC_SUB_SPEAKING) return "?";
    return n[s];
}

const char *bc_room_state_name(bc_room_state s)
{
    static const char *n[] = { "created", "open_idle", "open_live", "closing", "closed" };
    if ((int)s < 0 || s > BC_RS_CLOSED) return "?";
    return n[s];
}

int bc_sub_next(bc_sub from, bc_sub to)
{
    switch (from) {
    case BC_SUB_NONE:     return (to == BC_SUB_VIEWER) ? (int)to : X;
    case BC_SUB_VIEWER:   return (to == BC_SUB_HAND || to == BC_SUB_PENDING) ? (int)to : X;
    case BC_SUB_HAND:     return (to == BC_SUB_VIEWER || to == BC_SUB_PENDING) ? (int)to : X;
    case BC_SUB_PENDING:  return (to == BC_SUB_VIEWER || to == BC_SUB_SPEAKING) ? (int)to : X;
    case BC_SUB_SPEAKING: return (to == BC_SUB_VIEWER || to == BC_SUB_PENDING) ? (int)to : X;
    }
    return X;
}

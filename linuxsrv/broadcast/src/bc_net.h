#ifndef BC_NET_H
#define BC_NET_H

#include "bc_types.h"
#include "bc_buf.h"
#include "bc_fsm.h"
#include <pthread.h>

struct bc_room;
struct bc_worker;
struct bc_conn;

typedef void (*bc_task_fn)(struct bc_conn *c, void *arg);

typedef struct bc_outq {
    bc_buf *b;
    size_t  off;
    int rtc;
    struct bc_outq *next;
} bc_outq;

/*
 * Conexao de um cliente.
 *
 * Posse e travas:
 *  - fd, in, frag, timestamps, room (ponteiro): so a thread worker dona.
 *  - refs, fila de saida, flags de fechamento: conn->mu.
 *  - state, sub, role, synced, hand_since e campos de identidade depois que
 *    room != NULL: room->mu (outras threads alteram via comandos de admin).
 */
typedef struct bc_conn {
    pthread_mutex_t mu;
    int     refs;
    int     fd;
    struct bc_rtc *rtc;
    int rtc_ready;
    size_t rtc_pending;
    bc_u64  id;
    struct bc_worker *w;

    /* entrada (somente worker) */
    bc_bytes in;
    bc_bytes frag;
    int      frag_op;
    int      ws_open;
    bc_u64   created_ms;
    bc_u64   last_rx_ms;
    bc_u64   last_ping_ms;
    bc_u64   rate_win_ms;
    int      rate_msgs;
    int      rate_chat;
    bc_u64   media_win_ms;
    size_t   media_bytes;
    int      auth_pending;

    /* saida (conn->mu) */
    bc_outq *oq_head, *oq_tail;
    size_t   out_bytes;
    int      close_after_flush;
    int      closed;
    int      dirty_listed;
    int      epollout;

    /* identidade e estado (room->mu quando room != NULL) */
    bc_state state;
    bc_sub   sub;
    int      is_admin;
    int      is_owner;
    int      synced;        /* recebendo midia ao vivo */
    bc_u64   hand_since;
    bc_u64   waiting_since;
    bc_u64   joined_ms;
    bc_u64   pending_since;
    int      muted;
    bc_u64   invite_id;
    char     pkey[BC_PKEY_LEN + 1];
    char     name[BC_NAME_MAX + 1];
    char     email[BC_EMAIL_MAX + 1];
    char     ip[BC_IP_MAX];
    char     ua[BC_UA_MAX + 1];
    char     client[16];
    char     origin[256];
    int      pref_w, pref_h, pref_fps, pref_kbps;
    struct bc_room *room;
} bc_conn;

typedef struct bc_task {
    bc_task_fn fn;
    bc_conn   *c;
    void      *arg;
    struct bc_task *next;
} bc_task;

typedef struct bc_worker {
    pthread_t th;
    int       idx;
    int       epfd;
    int       evfd;      /* eventfd ou pipe (leitura) */
    int       evfd_w;    /* escrita (igual a evfd com eventfd) */
    pthread_mutex_t mu;  /* protege as filas abaixo */
    bc_conn **newc;  int newc_n, newc_cap;
    bc_conn **dirty; int dirty_n, dirty_cap;
    bc_task  *task_head, *task_tail;
    /* somente a propria thread */
    bc_conn **conns; int conns_n, conns_cap;
    char      name[16];
} bc_worker;

int  bc_net_start(void);
void bc_net_stop_accept(void);
void bc_net_stop(void);
void bc_net_stats(int *clients, bc_u64 *bytes_in, bc_u64 *bytes_out);

bc_conn *bc_conn_ref(bc_conn *c);
void     bc_conn_unref(bc_conn *c);

/* Enfileira (ref do buffer e assumida pela fila: o chamador passa a sua). */
int  bc_conn_send(bc_conn *c, bc_buf *b);
int bc_conn_send_signal(bc_conn *c, const char *json);
void bc_net_message(bc_conn *c, int op, bc_u8 *p, size_t n);
int  bc_conn_send_text(bc_conn *c, const char *json);
/* Enfileira midia; retorna -1 se o cliente esta atrasado (fila cheia). */
int  bc_conn_send_media(bc_conn *c, bc_buf *b);
/* Envia close (codigo/motivo) e fecha apos esvaziar a fila. */
void bc_conn_close_soon(bc_conn *c, int code, const char *reason);
size_t bc_conn_out_bytes(bc_conn *c);
int    bc_conn_closing(bc_conn *c);   /* fechada ou com fechamento pedido */

/* Executa fn(c, arg) na thread worker dona da conexao. */
void bc_worker_post(bc_conn *c, bc_task_fn fn, void *arg);

#endif

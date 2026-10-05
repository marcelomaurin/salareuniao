#ifndef BC_ROOM_H
#define BC_ROOM_H

#include "bc_types.h"
#include "bc_fsm.h"
#include "bc_buf.h"
#include "bc_ebml.h"
#include "bc_net.h"
#include "bc_db.h"
#include <pthread.h>

/*
 * ORDEM DE TRAVAS (obrigatoria, nunca inverter):
 *   rooms_lock (registro)  ->  room->mu  ->  conn->mu  ->  worker->mu  ->  buf->mu
 * Com room->mu presa: nada de E/S de socket nem chamada ao banco; apenas
 * enfileirar buffers (bc_conn_send) e jobs (bc_db_submit).
 */

#define BC_CMD_RING   256
#define BC_CHAT_RING  200
#define BC_MEDIA_HDR  16
#define BC_MEDIA_MAGIC 0xB5

enum { BC_MK_INIT = 1, BC_MK_DATA = 2 };

typedef struct bc_room {
    pthread_mutex_t mu;
    int      refs;              /* protegido por rooms_lock */
    int      in_registry;
    bc_u64   id;
    char     name[161];
    bc_u64   owner_user_id;
    bc_room_state state;
    int      locked;

    bc_conn **m;                /* conexoes ligadas (espera + sala) */
    int      mn, mcap;
    bc_conn *speaker;           /* sub = PENDING ou SPEAKING */

    int      prof_w, prof_h, prof_fps, prof_kbps;

    /* fluxo de midia da geracao atual */
    int      gen;
    char     mime[96];
    bc_u32   out_seq;
    bc_ebml  ebml;
    bc_bytes init;
    int      init_done;
    bc_bytes cache;             /* bytes desde cache_start */
    bc_u64   cache_start;       /* deslocamento absoluto */
    bc_u64   cur_cluster;
    int      have_key;
    int      stream_ok;

    bc_u32   version;
    char     cmd_ids[BC_CMD_RING][40];
    char    *cmd_acks[BC_CMD_RING];
    int      cmd_pos;

    char    *chat[BC_CHAT_RING];
    int      chat_n, chat_pos;
    int      chat_loaded;

    bc_u64   empty_since;
    bc_u64   closing_since;
    bc_u64   last_status_poll;
    int      poll_inflight;

    bc_u64   bytes_in, bytes_out, drops, chunks;
    bc_u64   last_media_ms;
} bc_room;

/* ---- registro ---- */
bc_room *bc_room_get(bc_u64 id, const char *name, bc_u64 owner);  /* ref++ */
void     bc_room_unref(bc_room *r);
int      bc_rooms_count(void);
void     bc_rooms_maint(void);
void     bc_rooms_shutdown(const char *reason);
void     bc_rooms_free_all(void);
char    *bc_rooms_metrics(void);

/* ---- chamadas das threads worker ---- */
void bc_room_on_auth(bc_conn *c, bc_auth_res *res);
void bc_room_conn_gone(bc_conn *c, bc_event ev, const char *reason);
void bc_room_check_timeouts(bc_conn *c, bc_u64 now);
void bc_room_on_media(bc_conn *c, const bc_u8 *p, size_t n);

/* ---- usados por bc_proto.c (room->mu presa) ---- */
int   bc_room_fire(bc_room *r, bc_conn *c, bc_event ev, const char *reason);
void  bc_room_set_sub(bc_room *r, bc_conn *c, bc_sub to, const char *reason);
void  bc_room_set_speaker(bc_room *r, bc_conn *c, const char *reason);
void  bc_room_stream_reset(bc_room *r, int gen, const char *mime);
void  bc_room_bump(bc_room *r);
void  bc_room_persist_runtime(bc_room *r);
bc_conn *bc_room_find(bc_room *r, const char *pkey);
void  bc_room_send_all(bc_room *r, const char *json, int in_room, int waiting, int admins_only);
void  bc_room_send_admins(bc_room *r, const char *json);
char *bc_room_state_json(bc_room *r, bc_conn *viewer);   /* state.sync */
char *bc_room_participant_json(bc_conn *c, int with_private);
void  bc_room_hand_queue_send(bc_room *r);
void  bc_room_chat_add(bc_room *r, const char *json);
void  bc_room_close(bc_room *r, const char *reason);
int   bc_room_count_in_room(bc_room *r);

/* Cache de command_id para idempotencia. */
const char *bc_room_cmd_seen(bc_room *r, const char *id);
void  bc_room_cmd_store(bc_room *r, const char *id, const char *ack_json);

#endif

#ifndef BC_DB_H
#define BC_DB_H

#include "bc_types.h"

struct bc_conn;

/*
 * Thread de banco de dados. Nenhuma outra thread toca no MySQL: tudo vira um
 * job numa fila FIFO. Resultados voltam para a thread worker da conexao via
 * bc_worker_post() (conn_cb) ou rodam na propria thread de banco (cb).
 */

enum {
    BC_JOB_AUTH = 1,
    BC_JOB_CHAT,
    BC_JOB_BAN,
    BC_JOB_UNBAN,
    BC_JOB_BANS_LIST,
    BC_JOB_PRESENCE,
    BC_JOB_ATT_OPEN,
    BC_JOB_ATT_CLOSE,
    BC_JOB_RUNTIME,
    BC_JOB_AUDIT,
    BC_JOB_INVITE_STATUS,
    BC_JOB_INVITE_CREATE,
    BC_JOB_INVITE_REVOKE,
    BC_JOB_ROOM_CLOSE,
    BC_JOB_ROOM_STATUS
};

#define BC_CHAT_HIST_MAX 200

typedef struct bc_auth_res {
    int    ok;
    char   err[32];
    bc_u64 room_id;
    char   room_status[16];
    char   room_name[161];
    bc_u64 owner_user_id;
    int    has_invite;
    bc_u64 invite_id;
    char   invite_status[16];
    char   invite_email[BC_EMAIL_MAX + 1];
    char   invite_pkey[BC_PKEY_LEN + 1];
    char   invite_name[BC_NAME_MAX + 1];
    int    is_admin;
    int    is_owner;
    bc_u64 user_id;       /* users.id do convidado, se for usuario cadastrado */
    int    banned;
    char   ban_reason[256];
    int    chat_n;
    char  *chat[BC_CHAT_HIST_MAX];  /* JSON chat.msg pronto (mais antigo primeiro) */
} bc_auth_res;

typedef struct bc_db_job bc_db_job;
typedef void (*bc_db_conn_cb)(struct bc_conn *c, bc_db_job *j);
typedef void (*bc_db_cb)(bc_db_job *j);

struct bc_db_job {
    int    kind;
    char  *s[8];          /* parametros texto (copias proprias) */
    bc_i64 n[6];          /* parametros numericos */
    bc_auth_res *auth;    /* BC_JOB_AUTH */
    int    rc;            /* 0 ok, <0 erro */
    char  *out;           /* resultado textual (JSON, token...) */
    bc_i64 out_n;
    struct bc_conn *conn; /* ref mantida enquanto o job existe */
    bc_db_conn_cb conn_cb;
    bc_db_cb      cb;
    void  *user;
    bc_db_job *next;
};

int  bc_db_start(void);
void bc_db_stop(void);
int  bc_db_available(void);
const char *bc_db_backend_name(void);

bc_db_job *bc_db_job_new(int kind);
void bc_db_job_set_s(bc_db_job *j, int i, const char *v);
void bc_db_job_free(bc_db_job *j);
/* Entrega o job a thread de banco (assume a posse). */
void bc_db_submit(bc_db_job *j);

void bc_auth_res_free(bc_auth_res *r);

/* ---- Interface do backend (bc_db_mysql.c ou bc_db_stub.c) ---- */
int  bc_be_connect(char *err, size_t errlen);
void bc_be_disconnect(void);
int  bc_be_ping(void);
/* Executa o job; preenche rc/out/auth. Retorna 0 ou -1 (conexao perdida). */
int  bc_be_run(bc_db_job *j);
const char *bc_be_name(void);

#endif

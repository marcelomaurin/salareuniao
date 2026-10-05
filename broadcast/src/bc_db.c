/*
 * bc_db.c - fila de jobs e thread de banco (independente do backend)
 *
 * Parametros por tipo de job (s[] = texto, n[] = numeros):
 *  AUTH          s0 room_token, s1 invite_token, s2 ip                  -> auth
 *  CHAT          n0 room_id; s0 pkey, s1 nome, s2 texto
 *  BAN           n0 room_id, n1 invite_id, n2 by_user_id, n3 minutos;
 *                s0 ip, s1 pkey, s2 email, s3 nome, s4 motivo, s5 tipo  -> out_n ban_id
 *  UNBAN         n0 room_id, n1 ban_id                                  -> rc
 *  BANS_LIST     n0 room_id                                             -> out (JSON)
 *  PRESENCE      n0 room_id, n1 presente(1/0); s0 pkey, s1 nome, s2 conn_state, s3 role
 *  ATT_OPEN      n0 room_id; s0 pkey, s1 nome
 *  ATT_CLOSE     n0 room_id; s0 pkey
 *  RUNTIME       n0 room_id, n1 state_version, n2 locked; s0 speaker_key, s1 profile JSON
 *  AUDIT         n0 room_id, n1 admin_user_id; s0 alvo, s1 command_id, s2 comando, s3 payload, s4 status
 *  INVITE_STATUS n0 invite_id; s0 status
 *  INVITE_CREATE n0 room_id; s0 email, s1 nome, s2 pkey, s3 ip, s4 status -> out token, out_n id
 *  INVITE_REVOKE n0 room_id, n1 invite_id                               -> rc
 *  ROOM_CLOSE    n0 room_id
 *  ROOM_STATUS   n0 room_id                                             -> out status
 */
#include "config.h"
#include "bc_db.h"
#include "bc_net.h"
#include "bc_util.h"
#include "bc_log.h"

#include <stdlib.h>
#include <string.h>
#include <pthread.h>
#include <time.h>

static pthread_t       db_th;
static pthread_mutex_t db_mu = PTHREAD_MUTEX_INITIALIZER;
static pthread_cond_t  db_cv = PTHREAD_COND_INITIALIZER;
static bc_db_job      *q_head = NULL, *q_tail = NULL;
static int             q_len = 0;
static int             db_stop = 0;
static int             db_up = 0;
static int             db_started = 0;

#define DB_QUEUE_MAX 20000

bc_db_job *bc_db_job_new(int kind)
{
    bc_db_job *j = (bc_db_job *)bc_xcalloc(1, sizeof(bc_db_job));
    j->kind = kind;
    return j;
}

void bc_db_job_set_s(bc_db_job *j, int i, const char *v)
{
    free(j->s[i]);
    j->s[i] = bc_xstrdup(v ? v : "");
}

void bc_auth_res_free(bc_auth_res *r)
{
    int i;
    if (!r) return;
    for (i = 0; i < r->chat_n; i++) free(r->chat[i]);
    free(r);
}

void bc_db_job_free(bc_db_job *j)
{
    int i;
    if (!j) return;
    for (i = 0; i < 8; i++) free(j->s[i]);
    bc_auth_res_free(j->auth);
    free(j->out);
    if (j->conn) bc_conn_unref(j->conn);
    free(j);
}

static void deliver_task(bc_conn *c, void *arg)
{
    bc_db_job *j = (bc_db_job *)arg;
    if (j->conn_cb) j->conn_cb(c, j);
    bc_db_job_free(j);
}

static void finish(bc_db_job *j)
{
    if (j->cb) j->cb(j);
    if (j->conn && j->conn_cb) {
        bc_worker_post(j->conn, deliver_task, j);
        return;
    }
    bc_db_job_free(j);
}

void bc_db_submit(bc_db_job *j)
{
    pthread_mutex_lock(&db_mu);
    if (db_stop || q_len >= DB_QUEUE_MAX) {
        pthread_mutex_unlock(&db_mu);
        if (j->kind == BC_JOB_AUTH || j->conn_cb || j->cb) {
            j->rc = -1;
            if (j->auth) bc_strlcpy(j->auth->err, "service_unavailable", sizeof(j->auth->err));
            finish(j);
        } else {
            BC_LOG_WARN("fila do banco cheia: job %d descartado", j->kind);
            bc_db_job_free(j);
        }
        return;
    }
    j->next = NULL;
    if (q_tail) q_tail->next = j; else q_head = j;
    q_tail = j;
    q_len++;
    pthread_cond_signal(&db_cv);
    pthread_mutex_unlock(&db_mu);
}

static void *db_main(void *arg)
{
    unsigned backoff = 1000;
    bc_u64 last_ping = 0;
    (void)arg;
    bc_log_set_thread_name("db");
    for (;;) {
        bc_db_job *j;
        char err[256];

        if (!db_up) {
            err[0] = '\0';
            if (bc_be_connect(err, sizeof(err)) == 0) {
                BC_LOG_INFO("banco conectado (%s)", bc_be_name());
                pthread_mutex_lock(&db_mu); db_up = 1; pthread_mutex_unlock(&db_mu);
                backoff = 1000;
            } else {
                BC_LOG_ERROR("falha ao conectar no banco: %s (nova tentativa em %u ms)", err, backoff);
            }
        }

        pthread_mutex_lock(&db_mu);
        while (!db_stop && q_head == NULL) {
            struct timespec ts;
            bc_u64 now = bc_wall_ms() + (db_up ? 5000 : backoff);
            ts.tv_sec = (time_t)(now / 1000);
            ts.tv_nsec = (long)(now % 1000) * 1000000L;
            if (pthread_cond_timedwait(&db_cv, &db_mu, &ts) != 0) break;
        }
        if (db_stop && q_head == NULL) { pthread_mutex_unlock(&db_mu); break; }
        j = q_head;
        if (j) {
            q_head = j->next;
            if (!q_head) q_tail = NULL;
            q_len--;
        }
        pthread_mutex_unlock(&db_mu);

        if (!j) {
            if (db_up && bc_now_ms() - last_ping > 30000) {
                last_ping = bc_now_ms();
                if (bc_be_ping() != 0) {
                    pthread_mutex_lock(&db_mu); db_up = 0; pthread_mutex_unlock(&db_mu);
                    bc_be_disconnect();
                }
            } else if (!db_up) {
                backoff = backoff * 2 > 30000 ? 30000 : backoff * 2;
            }
            continue;
        }

        if (!db_up) {
            j->rc = -1;
            if (j->auth) bc_strlcpy(j->auth->err, "service_unavailable", sizeof(j->auth->err));
            if (j->kind == BC_JOB_AUTH || j->conn_cb || j->cb) finish(j);
            else bc_db_job_free(j);
            continue;
        }
        if (bc_be_run(j) != 0) {
            BC_LOG_ERROR("conexao com o banco perdida (job %d)", j->kind);
            pthread_mutex_lock(&db_mu); db_up = 0; pthread_mutex_unlock(&db_mu);
            bc_be_disconnect();
            if (j->auth) bc_strlcpy(j->auth->err, "service_unavailable", sizeof(j->auth->err));
            j->rc = -1;
        }
        finish(j);
    }
    bc_be_disconnect();
    return NULL;
}

int bc_db_start(void)
{
    db_stop = 0;
    if (pthread_create(&db_th, NULL, db_main, NULL) != 0) return -1;
    db_started = 1;
    return 0;
}

void bc_db_stop(void)
{
    if (!db_started) return;
    pthread_mutex_lock(&db_mu);
    db_stop = 1;
    pthread_cond_broadcast(&db_cv);
    pthread_mutex_unlock(&db_mu);
    pthread_join(db_th, NULL);
    db_started = 0;
}

int bc_db_available(void)
{
    int up;
    pthread_mutex_lock(&db_mu);
    up = db_up;
    pthread_mutex_unlock(&db_mu);
    return up;
}

const char *bc_db_backend_name(void) { return bc_be_name(); }

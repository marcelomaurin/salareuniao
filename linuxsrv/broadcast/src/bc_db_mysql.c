/*
 * bc_db_mysql.c - backend MySQL/MariaDB (libmysqlclient / libmariadb)
 *
 * Toda consulta usa prepared statements com parametros texto; nada de SQL
 * concatenado com dados do cliente. Executado somente pela thread de banco.
 */
#include "config.h"
#include "bc_db.h"
#include "bc_config.h"
#include "bc_json.h"
#include "bc_log.h"
#include "bc_util.h"

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <mysql.h>
#include <errmsg.h>

#define MAXCOL 16
#define COLBUF 4096

static MYSQL *db = NULL;
static int has_ban_identity = 0;   /* migracao 014 aplicada em room_bans */
static int has_revoked = 0;        /* room_invites.status aceita 'revoked' */
static int has_presence_state = 0; /* room_presence.conn_state */
static int has_runtime_ext = 0;    /* room_runtime_state.speaker_key */
static int has_outbox = 0;         /* tabela broadcast_outbox */

const char *bc_be_name(void) { return "mysql"; }

typedef int (*row_fn)(void *u, char **col, unsigned long *len, char *isnull, int ncol);

static int lost(unsigned int e)
{
    return e == CR_SERVER_GONE_ERROR || e == CR_SERVER_LOST || e == CR_CONNECTION_ERROR ||
           e == CR_CONN_HOST_ERROR;
}

/* Executa SQL preparado. Retorna 0 ok, -1 conexao perdida, -2 erro de SQL. */
static int run(const char *sql, const char **p, int np, row_fn fn, void *u, bc_u64 *ins_id, bc_u64 *affected)
{
    MYSQL_STMT *st;
    MYSQL_BIND in[24], out[MAXCOL];
    unsigned long inlen[24];
    char innull[24];
    MYSQL_RES *meta;
    int i, ncol = 0, rc = 0;
    static char cols[MAXCOL][COLBUF];
    char *colp[MAXCOL];
    unsigned long clen[MAXCOL];
    char cnull[MAXCOL];

    if (!db) return -1;
    st = mysql_stmt_init(db);
    if (!st) return -1;
    if (mysql_stmt_prepare(st, sql, (unsigned long)strlen(sql)) != 0) {
        unsigned int e = mysql_stmt_errno(st);
        BC_LOG_ERROR("prepare falhou (%u): %s | %s", e, mysql_stmt_error(st), sql);
        mysql_stmt_close(st);
        return lost(e) ? -1 : -2;
    }
    memset(in, 0, sizeof(in));
    for (i = 0; i < np && i < 24; i++) {
        in[i].buffer_type = MYSQL_TYPE_STRING;
        innull[i] = p[i] == NULL;
        in[i].buffer = (void *)(p[i] ? p[i] : "");
        inlen[i] = p[i] ? (unsigned long)strlen(p[i]) : 0;
        in[i].buffer_length = inlen[i];
        in[i].length = &inlen[i];
        in[i].is_null = (void *)&innull[i];
    }
    if (np > 0 && mysql_stmt_bind_param(st, in) != 0) {
        BC_LOG_ERROR("bind falhou: %s", mysql_stmt_error(st));
        mysql_stmt_close(st);
        return -2;
    }
    if (mysql_stmt_execute(st) != 0) {
        unsigned int e = mysql_stmt_errno(st);
        BC_LOG_ERROR("execute falhou (%u): %s", e, mysql_stmt_error(st));
        mysql_stmt_close(st);
        return lost(e) ? -1 : -2;
    }
    if (ins_id) *ins_id = (bc_u64)mysql_stmt_insert_id(st);
    if (affected) *affected = (bc_u64)mysql_stmt_affected_rows(st);
    meta = mysql_stmt_result_metadata(st);
    if (meta) {
        ncol = (int)mysql_num_fields(meta);
        if (ncol > MAXCOL) ncol = MAXCOL;
        memset(out, 0, sizeof(out));
        for (i = 0; i < ncol; i++) {
            out[i].buffer_type = MYSQL_TYPE_STRING;
            out[i].buffer = cols[i];
            out[i].buffer_length = COLBUF - 1;
            out[i].length = &clen[i];
            out[i].is_null = (void *)&cnull[i];
            colp[i] = cols[i];
        }
        mysql_stmt_bind_result(st, out);
        for (;;) {
            int f = mysql_stmt_fetch(st);
            if (f == MYSQL_NO_DATA || f == 1) break;
            for (i = 0; i < ncol; i++) {
                if (clen[i] > COLBUF - 1) clen[i] = COLBUF - 1;
                cols[i][cnull[i] ? 0 : clen[i]] = '\0';
            }
            if (fn && fn(u, colp, clen, cnull, ncol) != 0) break;
        }
        mysql_free_result(meta);
    }
    mysql_stmt_close(st);
    return rc;
}

static int exists_cb(void *u, char **c, unsigned long *l, char *n, int nc)
{
    (void)c; (void)l; (void)n; (void)nc;
    *(int *)u = 1;
    return 1;
}

static int type_has_revoked_cb(void *u, char **c, unsigned long *l, char *n, int nc)
{
    (void)l; (void)n;
    if (nc >= 2 && strstr(c[1], "revoked")) *(int *)u = 1;
    return 1;
}

static void detect_schema(void)
{
    const char *p[2];
    has_ban_identity = has_revoked = has_presence_state = has_runtime_ext = has_outbox = 0;
    p[0] = "room_bans"; p[1] = "participant_key";
    run("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?",
        p, 2, exists_cb, &has_ban_identity, NULL, NULL);
    p[0] = "room_presence"; p[1] = "conn_state";
    run("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?",
        p, 2, exists_cb, &has_presence_state, NULL, NULL);
    p[0] = "room_runtime_state"; p[1] = "speaker_key";
    run("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?",
        p, 2, exists_cb, &has_runtime_ext, NULL, NULL);
    p[0] = "room_invites"; p[1] = "status";
    run("SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?",
        p, 2, type_has_revoked_cb, &has_revoked, NULL, NULL);
    p[0] = "broadcast_outbox";
    run("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?",
        p, 1, exists_cb, &has_outbox, NULL, NULL);
    if (!has_ban_identity)
        BC_LOG_WARN("migracao sql/014_broadcast.sql nao aplicada: banimento so por IP");
}

int bc_be_connect(char *err, size_t errlen)
{
    unsigned int timeout = 5;
    db = mysql_init(NULL);
    if (!db) { snprintf(err, errlen, "mysql_init falhou"); return -1; }
    mysql_options(db, MYSQL_OPT_CONNECT_TIMEOUT, &timeout);
    mysql_options(db, MYSQL_OPT_READ_TIMEOUT, &timeout);
    mysql_options(db, MYSQL_OPT_WRITE_TIMEOUT, &timeout);
    if (!mysql_real_connect(db, g_cfg.db_host, g_cfg.db_user, g_cfg.db_pass, g_cfg.db_name,
                            (unsigned int)g_cfg.db_port, g_cfg.db_socket[0] ? g_cfg.db_socket : NULL, 0)) {
        snprintf(err, errlen, "%s", mysql_error(db));
        mysql_close(db);
        db = NULL;
        return -1;
    }
    mysql_set_character_set(db, "utf8mb4");
    detect_schema();
    return 0;
}

void bc_be_disconnect(void)
{
    if (db) mysql_close(db);
    db = NULL;
}

int bc_be_ping(void)
{
    return (db && mysql_ping(db) == 0) ? 0 : -1;
}

/* ------------------------------------------------------------- AUTH */

typedef struct {
    bc_auth_res *r;
    char owner_email[BC_EMAIL_MAX + 1];
    int found;
} auth_ctx;

static int room_cb(void *u, char **c, unsigned long *l, char *n, int nc)
{
    auth_ctx *a = (auth_ctx *)u;
    (void)l; (void)n;
    if (nc < 5) return 1;
    a->found = 1;
    a->r->room_id = (bc_u64)strtoull(c[0], NULL, 10);
    bc_strlcpy(a->r->room_name, c[1], sizeof(a->r->room_name));
    bc_strlcpy(a->r->room_status, c[2], sizeof(a->r->room_status));
    a->r->owner_user_id = (bc_u64)strtoull(c[3], NULL, 10);
    bc_strlcpy(a->owner_email, c[4], sizeof(a->owner_email));
    return 1;
}

static int invite_cb(void *u, char **c, unsigned long *l, char *n, int nc)
{
    bc_auth_res *r = (bc_auth_res *)u;
    (void)l; (void)n;
    if (nc < 5) return 1;
    r->has_invite = 1;
    r->invite_id = (bc_u64)strtoull(c[0], NULL, 10);
    bc_strlcpy(r->invite_email, c[1], sizeof(r->invite_email));
    bc_strlcpy(r->invite_status, c[2], sizeof(r->invite_status));
    bc_strlcpy(r->invite_name, c[3], sizeof(r->invite_name));
    bc_strlcpy(r->invite_pkey, c[4], sizeof(r->invite_pkey));
    return 1;
}

static int uid_cb(void *u, char **c, unsigned long *l, char *n, int nc)
{
    bc_auth_res *r = (bc_auth_res *)u;
    (void)l; (void)n;
    if (nc >= 2) {
        r->user_id = (bc_u64)strtoull(c[0], NULL, 10);
        if (strcmp(c[1], "admin") == 0) r->is_admin = 1;
    }
    return 1;
}

static int ban_cb(void *u, char **c, unsigned long *l, char *n, int nc)
{
    bc_auth_res *r = (bc_auth_res *)u;
    (void)l; (void)n;
    r->banned = 1;
    if (nc >= 2) bc_strlcpy(r->ban_reason, c[1], sizeof(r->ban_reason));
    return 1;
}

static int chat_cb(void *u, char **c, unsigned long *l, char *n, int nc)
{
    bc_auth_res *r = (bc_auth_res *)u;
    cJSON *m;
    (void)l; (void)n;
    if (nc < 5 || r->chat_n >= BC_CHAT_HIST_MAX) return 1;
    m = bc_json_msg("chat.msg");
    cJSON_AddStringToObject(m, "id", c[0]);
    cJSON_AddStringToObject(m, "pkey", c[1]);
    cJSON_AddStringToObject(m, "name", c[2]);
    cJSON_AddBoolToObject(m, "admin", 0);
    cJSON_AddStringToObject(m, "text", c[3]);
    cJSON_AddNumberToObject(m, "ts", strtod(c[4], NULL));
    r->chat[r->chat_n++] = bc_json_take(m);
    return 0;
}

static int ieq(const char *a, const char *b)
{
    while (*a && *b) {
        char x = *a, y = *b;
        if (x >= 'A' && x <= 'Z') x = (char)(x - 'A' + 'a');
        if (y >= 'A' && y <= 'Z') y = (char)(y - 'A' + 'a');
        if (x != y) return 0;
        a++; b++;
    }
    return *a == *b;
}

static int do_auth(bc_db_job *j)
{
    bc_auth_res *r = j->auth;
    auth_ctx a;
    const char *p[6];
    char rid[24], lim[16];
    int rc, i;

    memset(&a, 0, sizeof(a));
    a.r = r;
    p[0] = j->s[0];
    rc = run("SELECT r.id, r.name, r.status, r.owner_user_id, u.email FROM rooms r "
             "JOIN users u ON u.id = r.owner_user_id WHERE r.join_token = ? LIMIT 1", p, 1, room_cb, &a, NULL, NULL);
    if (rc == -1) return -1;
    if (!a.found) { bc_strlcpy(r->err, "room_not_found", sizeof(r->err)); return 0; }
    if (strcmp(r->room_status, "open") != 0) { bc_strlcpy(r->err, "room_not_open", sizeof(r->err)); return 0; }
    snprintf(rid, sizeof(rid), "%llu", (unsigned long long)r->room_id);

    if (j->s[1] && *j->s[1]) {
        p[0] = j->s[1]; p[1] = rid;
        rc = run("SELECT id, email, status, COALESCE(display_name,''), COALESCE(participant_key,'') "
                 "FROM room_invites WHERE token = ? AND room_id = ? LIMIT 1", p, 2, invite_cb, r, NULL, NULL);
        if (rc == -1) return -1;
        if (r->has_invite && strcmp(r->invite_status, "revoked") == 0) {
            bc_strlcpy(r->err, "invite_revoked", sizeof(r->err));
            return 0;
        }
    }
    if (r->has_invite && r->invite_email[0] && strcmp(r->invite_status, "rejected") != 0) {
        r->is_owner = ieq(r->invite_email, a.owner_email);
        p[0] = r->invite_email;
        if (run("SELECT id, role FROM users WHERE LOWER(email) = LOWER(?) AND active = 1 LIMIT 1",
                p, 1, uid_cb, r, NULL, NULL) == -1) return -1;
        if (r->is_owner) r->is_admin = 1;
        if (!r->is_admin && r->user_id) {
            int found = 0;
            char uid[24];
            snprintf(uid, sizeof(uid), "%llu", (unsigned long long)r->user_id);
            p[0] = rid; p[1] = uid;
            if (run("SELECT 1 FROM room_admins WHERE room_id = ? AND user_id = ? LIMIT 1",
                    p, 2, exists_cb, &found, NULL, NULL) == -1) return -1;
            if (found) r->is_admin = 1;
        }
    }
    if (!r->is_owner) {
        p[0] = rid; p[1] = j->s[2];
        if (has_ban_identity) {
            p[2] = r->invite_pkey; p[3] = r->invite_email;
            rc = run("SELECT id, COALESCE(reason,'') FROM room_bans WHERE room_id = ? AND active = 1 "
                     "AND (expires_at IS NULL OR expires_at > NOW()) AND ((ip_address IS NOT NULL AND ip_address = ?) "
                     "OR (participant_key IS NOT NULL AND participant_key <> '' AND participant_key = ?) "
                     "OR (email IS NOT NULL AND email <> '' AND email = ?)) LIMIT 1", p, 4, ban_cb, r, NULL, NULL);
        } else {
            rc = run("SELECT id, COALESCE(reason,'') FROM room_bans WHERE room_id = ? AND active = 1 "
                     "AND (expires_at IS NULL OR expires_at > NOW()) AND ip_address = ? LIMIT 1",
                     p, 2, ban_cb, r, NULL, NULL);
        }
        if (rc == -1) return -1;
    }
    if (g_cfg.chat_history > 0) {
        char sql[256];
        /* LIMIT vem da configuracao (inteiro validado), nao do cliente */
        snprintf(lim, sizeof(lim), "%d", g_cfg.chat_history > BC_CHAT_HIST_MAX ? BC_CHAT_HIST_MAX : g_cfg.chat_history);
        snprintf(sql, sizeof(sql), "SELECT id, participant_key, display_name, message, UNIX_TIMESTAMP(created_at)*1000 "
                 "FROM room_messages WHERE room_id = ? ORDER BY id DESC LIMIT %s", lim);
        p[0] = rid;
        rc = run(sql, p, 1, chat_cb, r, NULL, NULL);
        if (rc == -1) return -1;
        /* veio do mais novo para o mais antigo: inverte */
        for (i = 0; i < r->chat_n / 2; i++) {
            char *t = r->chat[i];
            r->chat[i] = r->chat[r->chat_n - 1 - i];
            r->chat[r->chat_n - 1 - i] = t;
        }
    }
    r->ok = 1;
    return 0;
}

/* ------------------------------------------------------------- listas */

static int bans_cb(void *u, char **c, unsigned long *l, char *n, int nc)
{
    cJSON *arr = (cJSON *)u, *o;
    (void)l; (void)n;
    if (nc < 8) return 1;
    o = cJSON_CreateObject();
    cJSON_AddNumberToObject(o, "id", strtod(c[0], NULL));
    cJSON_AddStringToObject(o, "ip", c[1]);
    cJSON_AddStringToObject(o, "name", c[2]);
    cJSON_AddStringToObject(o, "email", c[3]);
    cJSON_AddStringToObject(o, "type", c[4]);
    cJSON_AddStringToObject(o, "reason", c[5]);
    cJSON_AddStringToObject(o, "created_at", c[6]);
    cJSON_AddStringToObject(o, "expires_at", c[7]);
    cJSON_AddItemToArray(arr, o);
    return 0;
}

static int str_cb(void *u, char **c, unsigned long *l, char *n, int nc)
{
    (void)l; (void)n;
    if (nc >= 1) *(char **)u = bc_xstrdup(c[0]);
    return 1;
}

/* ------------------------------------------------------------- despacho */

int bc_be_run(bc_db_job *j)
{
    const char *p[12];
    char a[24], b[24], c3[24], d[24];
    bc_u64 id = 0, aff = 0;
    int rc = 0;

    snprintf(a, sizeof(a), "%lld", (long long)j->n[0]);
    snprintf(b, sizeof(b), "%lld", (long long)j->n[1]);
    snprintf(c3, sizeof(c3), "%lld", (long long)j->n[2]);
    snprintf(d, sizeof(d), "%lld", (long long)j->n[3]);

    switch (j->kind) {
    case BC_JOB_AUTH:
        return do_auth(j);

    case BC_JOB_CHAT:
        p[0] = a; p[1] = j->s[0]; p[2] = j->s[1]; p[3] = j->s[2];
        rc = run("INSERT INTO room_messages (room_id, participant_key, display_name, message) VALUES (?, ?, ?, ?)",
                 p, 4, NULL, NULL, NULL, NULL);
        break;

    case BC_JOB_BAN:
        if (has_ban_identity) {
            p[0] = a; p[1] = (j->s[0] && *j->s[0]) ? j->s[0] : NULL; p[2] = (j->s[1] && *j->s[1]) ? j->s[1] : NULL;
            p[3] = (j->s[2] && *j->s[2]) ? j->s[2] : NULL; p[4] = j->s[5]; p[5] = j->s[3];
            p[6] = j->n[1] ? b : NULL; p[7] = j->s[4]; p[8] = j->n[2] ? c3 : NULL; p[9] = d; p[10] = d;
            rc = run("INSERT INTO room_bans (room_id, ip_address, participant_key, email, ban_type, display_name, "
                     "original_invite_id, reason, banned_by_user_id, expires_at, active) VALUES "
                     "(?, ?, ?, ?, ?, ?, ?, ?, ?, IF(? > 0, NOW() + INTERVAL ? MINUTE, NULL), 1)",
                     p, 11, NULL, NULL, &id, NULL);
        } else if (j->s[0] && *j->s[0]) {
            p[0] = a; p[1] = j->s[0]; p[2] = j->s[3]; p[3] = j->n[1] ? b : NULL; p[4] = j->s[4];
            p[5] = j->n[2] ? c3 : NULL; p[6] = d; p[7] = d;
            rc = run("INSERT INTO room_bans (room_id, ip_address, display_name, original_invite_id, reason, "
                     "banned_by_user_id, expires_at, active) VALUES (?, ?, ?, ?, ?, ?, "
                     "IF(? > 0, NOW() + INTERVAL ? MINUTE, NULL), 1) ON DUPLICATE KEY UPDATE active = 1, "
                     "reason = VALUES(reason), expires_at = VALUES(expires_at), created_at = NOW()",
                     p, 8, NULL, NULL, &id, NULL);
        }
        j->out_n = (bc_i64)id;
        break;

    case BC_JOB_UNBAN:
        p[0] = b; p[1] = a;
        rc = run("UPDATE room_bans SET active = 0 WHERE id = ? AND room_id = ?", p, 2, NULL, NULL, NULL, &aff);
        if (rc == 0 && aff == 0) j->rc = 1;
        break;

    case BC_JOB_BANS_LIST: {
        cJSON *arr = cJSON_CreateArray();
        p[0] = a;
        if (has_ban_identity)
            rc = run("SELECT id, COALESCE(ip_address,''), COALESCE(display_name,''), COALESCE(email,''), ban_type, "
                     "COALESCE(reason,''), created_at, COALESCE(expires_at,'') FROM room_bans "
                     "WHERE room_id = ? AND active = 1 ORDER BY id DESC LIMIT 200", p, 1, bans_cb, arr, NULL, NULL);
        else
            rc = run("SELECT id, COALESCE(ip_address,''), COALESCE(display_name,''), '', 'ip', "
                     "COALESCE(reason,''), created_at, COALESCE(expires_at,'') FROM room_bans "
                     "WHERE room_id = ? AND active = 1 ORDER BY id DESC LIMIT 200", p, 1, bans_cb, arr, NULL, NULL);
        j->out = bc_json_take(arr);
        break;
    }

    case BC_JOB_PRESENCE:
        p[0] = a; p[1] = j->s[0];
        if (j->n[1]) {
            p[2] = j->s[1];
            if (has_presence_state) {
                p[3] = j->s[2]; p[4] = j->s[3];
                rc = run("INSERT INTO room_presence (room_id, participant_key, display_name, conn_state, role) "
                         "VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), "
                         "conn_state = VALUES(conn_state), role = VALUES(role), last_seen_at = NOW()",
                         p, 5, NULL, NULL, NULL, NULL);
            } else {
                rc = run("INSERT INTO room_presence (room_id, participant_key, display_name) VALUES (?, ?, ?) "
                         "ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), last_seen_at = NOW()",
                         p, 3, NULL, NULL, NULL, NULL);
            }
        } else {
            rc = run("DELETE FROM room_presence WHERE room_id = ? AND participant_key = ?", p, 2, NULL, NULL, NULL, NULL);
        }
        break;

    case BC_JOB_ATT_OPEN:
        p[0] = a; p[1] = j->s[0]; p[2] = j->s[1];
        rc = run("INSERT INTO room_attendance (room_id, participant_key, display_name) VALUES (?, ?, ?)",
                 p, 3, NULL, NULL, NULL, NULL);
        break;

    case BC_JOB_ATT_CLOSE:
        p[0] = a; p[1] = j->s[0];
        rc = run("UPDATE room_attendance SET left_at = NOW(), duration_seconds = TIMESTAMPDIFF(SECOND, joined_at, NOW()) "
                 "WHERE room_id = ? AND participant_key = ? AND left_at IS NULL", p, 2, NULL, NULL, NULL, NULL);
        break;

    case BC_JOB_RUNTIME:
        p[0] = a; p[1] = j->s[0]; p[2] = j->s[0]; p[3] = b;
        if (has_runtime_ext) {
            p[4] = j->s[0]; p[5] = j->s[1]; p[6] = c3;
            rc = run("INSERT INTO room_runtime_state (room_id, room_mode, active_presenter_key, state_version, "
                     "speaker_key, speaker_profile, locked, server_session) VALUES "
                     "(?, IF(? = '', 'normal', 'presentation'), NULLIF(?, ''), ?, NULLIF(?, ''), ?, ?, 'bcastd') "
                     "ON DUPLICATE KEY UPDATE room_mode = VALUES(room_mode), active_presenter_key = VALUES(active_presenter_key), "
                     "state_version = VALUES(state_version), speaker_key = VALUES(speaker_key), "
                     "speaker_profile = VALUES(speaker_profile), locked = VALUES(locked), server_session = 'bcastd'",
                     p, 7, NULL, NULL, NULL, NULL);
        } else {
            rc = run("INSERT INTO room_runtime_state (room_id, room_mode, active_presenter_key, state_version) VALUES "
                     "(?, IF(? = '', 'normal', 'presentation'), NULLIF(?, ''), ?) ON DUPLICATE KEY UPDATE "
                     "room_mode = VALUES(room_mode), active_presenter_key = VALUES(active_presenter_key), "
                     "state_version = VALUES(state_version)", p, 4, NULL, NULL, NULL, NULL);
        }
        if (rc == -2) rc = 0;   /* tabela opcional */
        break;

    case BC_JOB_AUDIT:
        p[0] = a; p[1] = j->n[1] ? b : NULL; p[2] = (j->s[0] && *j->s[0]) ? j->s[0] : NULL;
        p[3] = (j->s[1] && *j->s[1]) ? j->s[1] : NULL; p[4] = j->s[2]; p[5] = j->s[3]; p[6] = j->s[4];
        rc = run("INSERT INTO room_control_audit (room_id, admin_user_id, participant_key, command_id, command, payload, status) "
                 "VALUES (?, ?, ?, ?, ?, ?, ?)", p, 7, NULL, NULL, NULL, NULL);
        if (rc == -2) rc = 0;
        break;

    case BC_JOB_INVITE_STATUS:
        p[0] = j->s[0]; p[1] = j->s[0]; p[2] = a;
        rc = run("UPDATE room_invites SET status = ?, approved_at = IF(? = 'approved', NOW(), approved_at) WHERE id = ?",
                 p, 3, NULL, NULL, NULL, NULL);
        break;

    case BC_JOB_INVITE_CREATE: {
        char token[65];
        bc_random_hex(token, 32);
        p[0] = a; p[1] = j->s[0]; p[2] = token; p[3] = j->s[4];
        p[4] = j->s[1]; p[5] = j->s[2]; p[6] = j->s[3]; p[7] = j->s[5]; p[8] = j->s[4];
        rc = run("INSERT INTO room_invites (room_id, email, token, status, display_name, participant_key, request_ip, "
                 "request_user_agent, requested_at, approved_at) VALUES (?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), "
                 "NULLIF(?, ''), NULLIF(?, ''), NOW(), IF(? = 'approved', NOW(), NULL))", p, 9, NULL, NULL, &id, NULL);
        if (rc == 0) {
            j->out = bc_xstrdup(token);
            j->out_n = (bc_i64)id;
            if (j->n[1] == 1 && has_outbox) {
                char iid[24];
                snprintf(iid, sizeof(iid), "%llu", (unsigned long long)id);
                p[0] = iid;
                run("INSERT INTO broadcast_outbox (kind, invite_id) VALUES ('invite', ?)", p, 1, NULL, NULL, NULL, NULL);
            }
        }
        break;
    }

    case BC_JOB_INVITE_REVOKE:
        p[0] = b; p[1] = a;
        rc = run(has_revoked ? "UPDATE room_invites SET status = 'revoked' WHERE id = ? AND room_id = ?"
                             : "UPDATE room_invites SET status = 'rejected' WHERE id = ? AND room_id = ?",
                 p, 2, NULL, NULL, NULL, &aff);
        break;

    case BC_JOB_ROOM_CLOSE:
        p[0] = a;
        rc = run("UPDATE rooms SET status = 'closed', ends_at = NOW() WHERE id = ? AND status = 'open'", p, 1, NULL, NULL, NULL, NULL);
        if (rc == 0) rc = run("DELETE FROM room_presence WHERE room_id = ?", p, 1, NULL, NULL, NULL, NULL);
        break;

    case BC_JOB_ROOM_STATUS:
        p[0] = a;
        rc = run("SELECT status FROM rooms WHERE id = ?", p, 1, str_cb, &j->out, NULL, NULL);
        if (rc == 0 && !j->out) j->out = bc_xstrdup("missing");
        break;

    default:
        BC_LOG_WARN("job desconhecido %d", j->kind);
        break;
    }
    if (rc == -1) return -1;
    if (rc == -2 && j->rc == 0) j->rc = -2;
    return 0;
}

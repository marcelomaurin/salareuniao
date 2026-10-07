/*
 * bc_proto.c - despacho das mensagens JSON do protocolo (versao 1)
 *
 * Roda sempre na thread worker dona da conexao. Handlers de sala executam
 * com room->mu presa e so enfileiram envios e jobs de banco.
 */
#include "config.h"
#include "bc_proto.h"
#include "bc_room.h"
#include "bc_db.h"
#include "bc_json.h"
#include "bc_config.h"
#include "bc_log.h"
#include "bc_util.h"
#include "bc_rtc.h"

#include <stdio.h>
#include <stdlib.h>
#include <string.h>

/* ============================================================ respostas */

static void send_take(bc_conn *c, cJSON *o)
{
    char *s = bc_json_take(o);
    bc_conn_send_text(c, s);
    free(s);
}

void bc_proto_error(bc_conn *c, const char *ref, const char *code, const char *msg)
{
    cJSON *o = bc_json_msg("error");
    cJSON_AddStringToObject(o, "code", code);
    if (msg) cJSON_AddStringToObject(o, "msg", msg);
    if (ref && *ref) cJSON_AddStringToObject(o, "ref", ref);
    send_take(c, o);
}

void bc_proto_ack(bc_conn *c, const char *ref, const char *status, const char *error)
{
    cJSON *o = bc_json_msg("ack");
    if (ref && *ref) cJSON_AddStringToObject(o, "ref", ref);
    cJSON_AddStringToObject(o, "status", status);
    if (error) cJSON_AddStringToObject(o, "error", error);
    send_take(c, o);
}

/* Remove caracteres de controle e corta no limite de bytes (sem quebrar UTF-8). */
static void clean_text(char *dst, size_t dmax, const char *src, int allow_newline)
{
    size_t o = 0, i = 0, n = strlen(src);
    while (i < n && o + 1 < dmax) {
        unsigned char ch = (unsigned char)src[i];
        size_t len = 1;
        if (ch >= 0xF0) len = 4; else if (ch >= 0xE0) len = 3; else if (ch >= 0xC0) len = 2;
        if (o + len >= dmax) break;
        if (ch < 0x20 && !(allow_newline && ch == '\n')) { dst[o++] = ' '; i++; continue; }
        if (ch == 0x7F) { i++; continue; }
        memcpy(dst + o, src + i, len);
        o += len; i += len;
    }
    dst[o] = '\0';
    bc_trim(dst);
}

/* ============================================================ hello */

static void auth_done(bc_conn *c, bc_db_job *j)
{
    bc_room_on_auth(c, j->auth);
}

static void handle_hello(bc_conn *c, cJSON *o)
{
    const char *room_token = bc_json_str(o, "room_token", BC_TOKEN_MAX);
    const char *invite_token = bc_json_str(o, "invite_token", BC_TOKEN_MAX);
    const char *name = bc_json_str(o, "name", 400);
    const char *client = bc_json_str(o, "client", 15);
    bc_db_job *j;

    if (!room_token || !bc_is_hex(room_token, 32, BC_TOKEN_MAX)) {
        bc_proto_error(c, NULL, "bad_hello", "room_token ausente ou invalido");
        bc_conn_close_soon(c, 1008, "bad_hello");
        return;
    }
    if (invite_token && *invite_token && !bc_is_hex(invite_token, 32, BC_TOKEN_MAX)) {
        bc_proto_error(c, NULL, "bad_hello", "invite_token invalido");
        bc_conn_close_soon(c, 1008, "bad_hello");
        return;
    }
    if (name) clean_text(c->name, sizeof(c->name), name, 0);
    if (!c->name[0] && !(invite_token && *invite_token)) {
        bc_proto_error(c, NULL, "bad_hello", "informe seu nome");
        bc_conn_close_soon(c, 1008, "bad_hello");
        return;
    }
    bc_strlcpy(c->client, client ? client : "web", sizeof(c->client));
    j = bc_db_job_new(BC_JOB_AUTH);
    bc_db_job_set_s(j, 0, room_token);
    bc_db_job_set_s(j, 1, invite_token ? invite_token : "");
    bc_db_job_set_s(j, 2, c->ip);
    j->auth = (bc_auth_res *)bc_xcalloc(1, sizeof(bc_auth_res));
    j->conn = bc_conn_ref(c);
    j->conn_cb = auth_done;
    c->auth_pending = 1;
    bc_db_submit(j);
}

/* ============================================================ helpers de sala */

typedef const char *(*bc_handler)(bc_room *r, bc_conn *c, cJSON *o, const char *id);

static bc_conn *target_of(bc_room *r, cJSON *o)
{
    const char *pk = bc_json_str(o, "pkey", BC_PKEY_LEN);
    if (!pk || !bc_is_hex(pk, 16, BC_PKEY_LEN)) return NULL;
    return bc_room_find(r, pk);
}

static const char *reason_of(cJSON *o, char *buf, size_t n, const char *def)
{
    const char *rs = bc_json_str(o, "reason", 250);
    clean_text(buf, n, rs ? rs : def, 0);
    if (!buf[0]) bc_strlcpy(buf, def, n);
    return buf;
}

static bc_u64 admin_user(bc_room *r, bc_conn *c)
{
    return c->is_owner ? r->owner_user_id : 0;
}

/* ============================================================ participantes */

static const char *h_bye(bc_room *r, bc_conn *c, cJSON *o, const char *id)
{
    (void)o; (void)id;
    bc_room_fire(r, c, BC_EV_BYE, "bye");
    return NULL;
}

static const char *h_chat(bc_room *r, bc_conn *c, cJSON *o, const char *id)
{
    const char *t = bc_json_str(o, "text", 8000);
    char text[2048 * 4 + 1], uid[37];
    cJSON *m;
    char *s;
    bc_db_job *j;
    (void)id;
    if (!t) return "bad_request";
    clean_text(text, sizeof(text), t, 1);
    if (!text[0]) return "empty_message";
    if (bc_utf8_len(text) > 2000) return "message_too_long";
    if (++c->rate_chat > 5) return "rate_limited";
    bc_uuid4(uid);
    m = bc_json_msg("chat.msg");
    cJSON_AddStringToObject(m, "id", uid);
    cJSON_AddStringToObject(m, "pkey", c->pkey);
    cJSON_AddStringToObject(m, "name", c->name);
    cJSON_AddBoolToObject(m, "admin", c->is_admin);
    cJSON_AddStringToObject(m, "text", text);
    cJSON_AddNumberToObject(m, "ts", (double)bc_wall_ms());
    s = bc_json_take(m);
    bc_room_send_all(r, s, 1, 0, 0);
    bc_room_chat_add(r, s);
    free(s);
    j = bc_db_job_new(BC_JOB_CHAT);
    j->n[0] = (bc_i64)r->id;
    bc_db_job_set_s(j, 0, c->pkey);
    bc_db_job_set_s(j, 1, c->name);
    bc_db_job_set_s(j, 2, text);
    bc_db_submit(j);
    return NULL;
}

/* Mensagem privada: admin -> qualquer um; quem esta na espera -> admins. */
static const char *h_chat_private(bc_room *r, bc_conn *c, cJSON *o, const char *id)
{
    const char *t = bc_json_str(o, "text", 8000);
    char text[4096];
    cJSON *m;
    char *s;
    (void)id;
    if (!t) return "bad_request";
    clean_text(text, sizeof(text), t, 1);
    if (!text[0]) return "empty_message";
    if (++c->rate_chat > 5) return "rate_limited";
    m = bc_json_msg("chat.private");
    cJSON_AddStringToObject(m, "from", c->pkey);
    cJSON_AddStringToObject(m, "from_name", c->name);
    cJSON_AddBoolToObject(m, "admin", c->is_admin);
    cJSON_AddStringToObject(m, "text", text);
    cJSON_AddNumberToObject(m, "ts", (double)bc_wall_ms());
    if (c->is_admin) {
        bc_conn *tg = target_of(r, o);
        if (!tg) { cJSON_Delete(m); return "bad_target"; }
        cJSON_AddStringToObject(m, "to", tg->pkey);
        s = bc_json_take(m);
        bc_conn_send_text(tg, s);
        bc_conn_send_text(c, s);
    } else {
        cJSON_AddStringToObject(m, "to", "admins");
        s = bc_json_take(m);
        bc_room_send_admins(r, s);
        bc_conn_send_text(c, s);
    }
    free(s);
    return NULL;
}

static const char *h_hand_raise(bc_room *r, bc_conn *c, cJSON *o, const char *id)
{
    (void)o; (void)id;
    if (c->sub == BC_SUB_HAND) return NULL;
    if (c->sub != BC_SUB_VIEWER) return "not_allowed";
    bc_room_set_sub(r, c, BC_SUB_HAND, "hand_raise");
    bc_room_hand_queue_send(r);
    return NULL;
}

static const char *h_hand_lower(bc_room *r, bc_conn *c, cJSON *o, const char *id)
{
    (void)o; (void)id;
    if (c->sub != BC_SUB_HAND) return NULL;
    bc_room_set_sub(r, c, BC_SUB_VIEWER, "hand_lower");
    bc_room_hand_queue_send(r);
    return NULL;
}

static const char *h_media_init(bc_room *r, bc_conn *c, cJSON *o, const char *id)
{
    const char *mime = bc_json_str(o, "mime", 90);
    int ok = 1;
    long gen = bc_json_int(o, "gen", -1, &ok);
    cJSON *m;
    (void)id;
    if (r->speaker != c) return "not_speaker";
    if (!mime || (strncmp(mime, "video/webm", 10) != 0 && strncmp(mime, "audio/webm", 10) != 0))
        return "unsupported_media";
    if (!ok || gen < 0 || gen > 255) return "bad_request";
    /* o orador pode abrir uma nova geracao (troca de camera/tela) */
    if (gen != r->gen && gen != ((r->gen + 1) & 0xFF)) return "stale_gen";
    bc_room_stream_reset(r, (int)gen, mime);
    {
        int w = (int)bc_json_int(o, "w", 0, NULL), h = (int)bc_json_int(o, "h", 0, NULL);
        if (w > 0 && h > 0 && w <= 4096 && h <= 4096) {
            r->prof_w = w; r->prof_h = h;
        }
    }
    if (c->sub == BC_SUB_PENDING) bc_room_set_sub(r, c, BC_SUB_SPEAKING, "media_init");
    bc_room_bump(r);
    m = bc_json_msg("speaker.changed");
    {
        cJSON *sp = cJSON_CreateObject();
        cJSON_AddStringToObject(sp, "pkey", c->pkey);
        cJSON_AddStringToObject(sp, "name", c->name);
        cJSON_AddBoolToObject(sp, "live", 1);
        cJSON_AddNumberToObject(sp, "gen", r->gen);
        cJSON_AddStringToObject(sp, "mime", r->mime);
        cJSON_AddBoolToObject(sp, "muted", c->muted);
        cJSON_AddItemToObject(m, "speaker", sp);
    }
    cJSON_AddStringToObject(m, "reason", "media_init");
    cJSON_AddNumberToObject(m, "state_version", r->version);
    {
        char *s = bc_json_take(m);
        bc_room_send_all(r, s, 1, 0, 0);
        free(s);
    }
    bc_room_persist_runtime(r);
    return NULL;
}

static const char *h_media_stop(bc_room *r, bc_conn *c, cJSON *o, const char *id)
{
    (void)o; (void)id;
    if (r->speaker != c) return "not_speaker";
    bc_room_set_speaker(r, NULL, "speaker_stopped");
    return NULL;
}

/* ============================================================ administrador */

static void invite_created_cb(bc_conn *c, bc_db_job *j)
{
    bc_room *r = c->room;
    cJSON *m;
    if (j->rc != 0 || !j->out) return;
    if (r) {
        pthread_mutex_lock(&r->mu);
        if (c->state == BC_ST_IN_ROOM) c->invite_id = (bc_u64)j->out_n;
        pthread_mutex_unlock(&r->mu);
    }
    m = bc_json_msg("invite.token");
    cJSON_AddStringToObject(m, "invite_token", j->out);
    send_take(c, m);
}

/* Admite um participante da espera e registra o convite (room->mu presa). */
static int admit_one(bc_room *r, bc_conn *t)
{
    if (bc_room_fire(r, t, BC_EV_ADMIT, "admin") != 0) return -1;
    if (t->invite_id) {
        bc_db_job *j = bc_db_job_new(BC_JOB_INVITE_STATUS);
        j->n[0] = (bc_i64)t->invite_id;
        bc_db_job_set_s(j, 0, "approved");
        bc_db_submit(j);
    } else {
        /* convidado do link publico: cria um convite aprovado para reconexao */
        bc_db_job *j = bc_db_job_new(BC_JOB_INVITE_CREATE);
        j->n[0] = (bc_i64)r->id;
        bc_db_job_set_s(j, 0, "");
        bc_db_job_set_s(j, 1, t->name);
        bc_db_job_set_s(j, 2, t->pkey);
        bc_db_job_set_s(j, 3, t->ip);
        bc_db_job_set_s(j, 4, "approved");
        bc_db_job_set_s(j, 5, t->ua);
        j->conn = bc_conn_ref(t);
        j->conn_cb = invite_created_cb;
        bc_db_submit(j);
    }
    return 0;
}

static const char *h_admit(bc_room *r, bc_conn *c, cJSON *o, const char *id)
{
    bc_conn *t = target_of(r, o);
    (void)c; (void)id;
    if (!t || t->state != BC_ST_WAITING) return "bad_target";
    if (admit_one(r, t) != 0) return "invalid_transition";
    return NULL;
}

static const char *h_admit_all(bc_room *r, bc_conn *c, cJSON *o, const char *id)
{
    int i, n = 0;
    (void)c; (void)o; (void)id;
    /* bc_room_fire nao remove de r->m quem vai para a sala, mas percorre de tras para frente por seguranca */
    for (i = r->mn - 1; i >= 0; i--) {
        bc_conn *t = r->m[i];
        if (t->state == BC_ST_WAITING && admit_one(r, t) == 0) n++;
    }
    return n ? NULL : "nobody_waiting";
}

static const char *h_deny(bc_room *r, bc_conn *c, cJSON *o, const char *id)
{
    bc_conn *t = target_of(r, o);
    char reason[256];
    (void)c; (void)id;
    if (!t || t->state != BC_ST_WAITING) return "bad_target";
    reason_of(o, reason, sizeof(reason), "denied");
    if (bc_room_fire(r, t, BC_EV_DENY, reason) != 0) return "invalid_transition";
    return NULL;
}

static void submit_ban(bc_room *r, bc_conn *admin, bc_conn *t, const char *by, const char *reason, long minutes)
{
    bc_db_job *j = bc_db_job_new(BC_JOB_BAN);
    j->n[0] = (bc_i64)r->id;
    j->n[1] = (bc_i64)t->invite_id;
    j->n[2] = (bc_i64)admin_user(r, admin);
    j->n[3] = minutes;
    bc_db_job_set_s(j, 0, (strcmp(by, "identity") == 0) ? "" : t->ip);
    bc_db_job_set_s(j, 1, (strcmp(by, "ip") == 0) ? "" : t->pkey);
    bc_db_job_set_s(j, 2, (strcmp(by, "ip") == 0) ? "" : t->email);
    bc_db_job_set_s(j, 3, t->name);
    bc_db_job_set_s(j, 4, reason);
    bc_db_job_set_s(j, 5, by);
    bc_db_submit(j);
}

static const char *do_ban(bc_room *r, bc_conn *c, cJSON *o, bc_state need)
{
    bc_conn *t = target_of(r, o);
    const char *by = bc_json_str(o, "by", 10);
    long minutes = bc_json_int(o, "minutes", 0, NULL);
    char reason[256], ip[BC_IP_MAX];
    int i;
    if (!t || t->state != need) return "bad_target";
    if (t == c) return "not_allowed";
    if (t->is_owner) return "not_allowed";
    if (!by) by = "both";
    if (strcmp(by, "ip") != 0 && strcmp(by, "identity") != 0 && strcmp(by, "both") != 0) return "bad_request";
    if (minutes < 0 || minutes > 525600) return "bad_request";
    reason_of(o, reason, sizeof(reason), "banned");
    bc_strlcpy(ip, t->ip, sizeof(ip));
    submit_ban(r, c, t, by, reason, minutes);
    if (bc_room_fire(r, t, BC_EV_BAN, reason) != 0) return "invalid_transition";
    /* banimento por IP derruba tambem outras conexoes ativas do mesmo IP */
    if (strcmp(by, "identity") != 0) {
        for (i = r->mn - 1; i >= 0; i--) {
            bc_conn *x = r->m[i];
            if (x != c && !x->is_owner && strcmp(x->ip, ip) == 0 && bc_fsm_is_active(x->state))
                bc_room_fire(r, x, BC_EV_BAN, reason);
        }
    }
    return NULL;
}

static const char *h_ban_waiting(bc_room *r, bc_conn *c, cJSON *o, const char *id)
{
    (void)id;
    return do_ban(r, c, o, BC_ST_WAITING);
}

static const char *h_ban(bc_room *r, bc_conn *c, cJSON *o, const char *id)
{
    (void)id;
    return do_ban(r, c, o, BC_ST_IN_ROOM);
}

static const char *h_kick(bc_room *r, bc_conn *c, cJSON *o, const char *id)
{
    bc_conn *t = target_of(r, o);
    char reason[256];
    (void)id;
    if (!t || t->state != BC_ST_IN_ROOM) return "bad_target";
    if (t == c || t->is_owner) return "not_allowed";
    reason_of(o, reason, sizeof(reason), "kicked");
    if (bc_room_fire(r, t, BC_EV_KICK, reason) != 0) return "invalid_transition";
    return NULL;
}

static void bans_list_cb(bc_conn *c, bc_db_job *j)
{
    cJSON *m = bc_json_msg("bans");
    cJSON *list = (j->rc == 0 && j->out) ? cJSON_Parse(j->out) : NULL;
    cJSON_AddItemToObject(m, "list", list ? list : cJSON_CreateArray());
    send_take(c, m);
}

static const char *h_bans_list(bc_room *r, bc_conn *c, cJSON *o, const char *id)
{
    bc_db_job *j = bc_db_job_new(BC_JOB_BANS_LIST);
    (void)o; (void)id;
    j->n[0] = (bc_i64)r->id;
    j->conn = bc_conn_ref(c);
    j->conn_cb = bans_list_cb;
    bc_db_submit(j);
    return NULL;
}

static void unban_cb(bc_conn *c, bc_db_job *j)
{
    bc_db_job *k;
    if (j->rc != 0) { bc_proto_error(c, NULL, "unban_failed", "banimento nao encontrado"); return; }
    k = bc_db_job_new(BC_JOB_BANS_LIST);
    k->n[0] = j->n[0];
    k->conn = bc_conn_ref(c);
    k->conn_cb = bans_list_cb;
    bc_db_submit(k);
}

static const char *h_unban(bc_room *r, bc_conn *c, cJSON *o, const char *id)
{
    int ok = 1;
    long ban_id = bc_json_int(o, "ban_id", 0, &ok);
    bc_db_job *j;
    (void)id;
    if (!ok || ban_id <= 0) return "bad_request";
    j = bc_db_job_new(BC_JOB_UNBAN);
    j->n[0] = (bc_i64)r->id;
    j->n[1] = ban_id;
    j->conn = bc_conn_ref(c);
    j->conn_cb = unban_cb;
    bc_db_submit(j);
    return NULL;
}

static void invite_link_cb(bc_conn *c, bc_db_job *j)
{
    cJSON *m;
    char link[512];
    if (j->rc != 0 || !j->out) { bc_proto_error(c, NULL, "invite_failed", "nao foi possivel criar o convite"); return; }
    snprintf(link, sizeof(link), "%s/join.php?token=%s", g_cfg.base_url, j->out);
    m = bc_json_msg("invite.created");
    cJSON_AddNumberToObject(m, "invite_id", (double)j->out_n);
    cJSON_AddStringToObject(m, "email", j->s[0] ? j->s[0] : "");
    cJSON_AddStringToObject(m, "name", j->s[1] ? j->s[1] : "");
    cJSON_AddStringToObject(m, "link", link);
    send_take(c, m);
}

static int email_ok(const char *e)
{
    const char *at = strchr(e, '@');
    size_t n = strlen(e);
    size_t i;
    if (!at || at == e || n < 5 || n > BC_EMAIL_MAX || strchr(at + 1, '@') || !strchr(at, '.')) return 0;
    for (i = 0; i < n; i++) if ((unsigned char)e[i] <= ' ' || e[i] == '<' || e[i] == '>' || e[i] == '"') return 0;
    return 1;
}

static const char *h_invite(bc_room *r, bc_conn *c, cJSON *o, const char *id)
{
    const char *email = bc_json_str(o, "email", BC_EMAIL_MAX);
    const char *name = bc_json_str(o, "name", 400);
    char nm[BC_NAME_MAX + 1], em[BC_EMAIL_MAX + 1];
    bc_db_job *j;
    size_t i;
    (void)id;
    if (!email || !email_ok(email)) return "bad_email";
    bc_strlcpy(em, email, sizeof(em));
    for (i = 0; em[i]; i++) if (em[i] >= 'A' && em[i] <= 'Z') em[i] = (char)(em[i] - 'A' + 'a');
    clean_text(nm, sizeof(nm), name ? name : "", 0);
    j = bc_db_job_new(BC_JOB_INVITE_CREATE);
    j->n[0] = (bc_i64)r->id;
    j->n[1] = 1;                       /* enfileirar e-mail */
    bc_db_job_set_s(j, 0, em);
    bc_db_job_set_s(j, 1, nm);
    bc_db_job_set_s(j, 2, "");
    bc_db_job_set_s(j, 3, "");
    bc_db_job_set_s(j, 4, "invited");
    bc_db_job_set_s(j, 5, "");
    j->conn = bc_conn_ref(c);
    j->conn_cb = invite_link_cb;
    bc_db_submit(j);
    return NULL;
}

static const char *h_invite_revoke(bc_room *r, bc_conn *c, cJSON *o, const char *id)
{
    int ok = 1, i;
    long inv = bc_json_int(o, "invite_id", 0, &ok);
    bc_db_job *j;
    (void)c; (void)id;
    if (!ok || inv <= 0) return "bad_request";
    j = bc_db_job_new(BC_JOB_INVITE_REVOKE);
    j->n[0] = (bc_i64)r->id;
    j->n[1] = inv;
    bc_db_submit(j);
    for (i = r->mn - 1; i >= 0; i--) {
        bc_conn *x = r->m[i];
        if (x->invite_id == (bc_u64)inv && !x->is_owner && bc_fsm_is_active(x->state))
            bc_room_fire(r, x, x->state == BC_ST_WAITING ? BC_EV_DENY : BC_EV_KICK, "invite_revoked");
    }
    return NULL;
}

static const char *h_speaker_set(bc_room *r, bc_conn *c, cJSON *o, const char *id)
{
    bc_conn *t = target_of(r, o);
    (void)c; (void)id;
    if (!t || t->state != BC_ST_IN_ROOM) return "bad_target";
    bc_room_set_speaker(r, t, "admin");
    return NULL;
}

static const char *h_speaker_clear(bc_room *r, bc_conn *c, cJSON *o, const char *id)
{
    (void)c; (void)o; (void)id;
    bc_room_set_speaker(r, NULL, "admin");
    return NULL;
}

static const char *h_hand_accept(bc_room *r, bc_conn *c, cJSON *o, const char *id)
{
    bc_conn *t = target_of(r, o);
    (void)c; (void)id;
    if (!t || t->state != BC_ST_IN_ROOM || t->sub != BC_SUB_HAND) return "bad_target";
    bc_room_set_speaker(r, t, "hand_accepted");
    return NULL;
}

static const char *h_hand_reject(bc_room *r, bc_conn *c, cJSON *o, const char *id)
{
    bc_conn *t = target_of(r, o);
    cJSON *m;
    (void)c; (void)id;
    if (!t || t->state != BC_ST_IN_ROOM || t->sub != BC_SUB_HAND) return "bad_target";
    bc_room_set_sub(r, t, BC_SUB_VIEWER, "hand_rejected");
    m = bc_json_msg("hand.rejected");
    send_take(t, m);
    bc_room_hand_queue_send(r);
    return NULL;
}

typedef struct { int w, h, fps, kbps; } bc_profile;
static const bc_profile PROFILES[] = {
    { 426, 240, 15, 250 }, { 640, 360, 24, 500 }, { 854, 480, 24, 800 },
    { 1280, 720, 30, 1500 }, { 1920, 1080, 30, 2500 }
};

static const char *h_resolution(bc_room *r, bc_conn *c, cJSON *o, const char *id)
{
    bc_conn *t = target_of(r, o);
    int w = (int)bc_json_int(o, "w", 0, NULL), h = (int)bc_json_int(o, "h", 0, NULL);
    int fps = (int)bc_json_int(o, "fps", 0, NULL), kbps = (int)bc_json_int(o, "kbps", 0, NULL);
    size_t i;
    const bc_profile *p = NULL;
    cJSON *m;
    (void)c; (void)id;
    if (!t || t->state != BC_ST_IN_ROOM) return "bad_target";
    for (i = 0; i < sizeof(PROFILES) / sizeof(PROFILES[0]); i++)
        if (PROFILES[i].w == w && PROFILES[i].h == h) p = &PROFILES[i];
    if (!p) return "bad_profile";
    if (fps == 0) fps = p->fps;
    if (kbps == 0) kbps = p->kbps;
    if (fps < 5 || fps > 30 || kbps < 100 || kbps > 4000) return "bad_profile";
    t->pref_w = w; t->pref_h = h; t->pref_fps = fps; t->pref_kbps = kbps;
    if (t == r->speaker) {
        r->prof_w = w; r->prof_h = h; r->prof_fps = fps; r->prof_kbps = kbps;
        bc_room_stream_reset(r, (r->gen + 1) & 0xFF, "");
        if (t->sub == BC_SUB_SPEAKING) bc_room_set_sub(r, t, BC_SUB_PENDING, "resolution_change");
        m = bc_json_msg("media.profile");
        cJSON_AddNumberToObject(m, "gen", r->gen);
        {
            cJSON *pf = cJSON_CreateObject();
            cJSON_AddNumberToObject(pf, "w", w);
            cJSON_AddNumberToObject(pf, "h", h);
            cJSON_AddNumberToObject(pf, "fps", fps);
            cJSON_AddNumberToObject(pf, "kbps", kbps);
            cJSON_AddItemToObject(m, "profile", pf);
        }
        send_take(t, m);
        bc_room_persist_runtime(r);
    }
    return NULL;
}

static const char *h_mute(bc_room *r, bc_conn *c, cJSON *o, const char *id, int mute)
{
    bc_conn *t = target_of(r, o);
    cJSON *m;
    (void)c; (void)id;
    if (!t || t->state != BC_ST_IN_ROOM) return "bad_target";
    t->muted = mute;
    m = bc_json_msg("media.mute");
    cJSON_AddBoolToObject(m, "muted", mute);
    send_take(t, m);
    bc_room_bump(r);
    m = bc_json_msg("participant.state");
    {
        char *pj = bc_room_participant_json(t, 0);
        cJSON_AddItemToObject(m, "participant", cJSON_Parse(pj));
        free(pj);
    }
    cJSON_AddStringToObject(m, "reason", mute ? "muted" : "unmuted");
    {
        char *s = bc_json_take(m);
        bc_room_send_all(r, s, 1, 0, 0);
        free(s);
    }
    return NULL;
}

static const char *h_mute_on(bc_room *r, bc_conn *c, cJSON *o, const char *id) { return h_mute(r, c, o, id, 1); }
static const char *h_mute_off(bc_room *r, bc_conn *c, cJSON *o, const char *id) { return h_mute(r, c, o, id, 0); }

static const char *h_lock(bc_room *r, bc_conn *c, cJSON *o, const char *id, int lock)
{
    cJSON *m;
    (void)c; (void)o; (void)id;
    r->locked = lock;
    bc_room_bump(r);
    m = bc_json_msg("room.state");
    cJSON_AddBoolToObject(m, "locked", lock);
    cJSON_AddStringToObject(m, "state", bc_room_state_name(r->state));
    cJSON_AddNumberToObject(m, "state_version", r->version);
    {
        char *s = bc_json_take(m);
        bc_room_send_all(r, s, 1, 0, 0);
        free(s);
    }
    bc_room_persist_runtime(r);
    return NULL;
}

static const char *h_lock_on(bc_room *r, bc_conn *c, cJSON *o, const char *id) { return h_lock(r, c, o, id, 1); }
static const char *h_lock_off(bc_room *r, bc_conn *c, cJSON *o, const char *id) { return h_lock(r, c, o, id, 0); }

static const char *h_close(bc_room *r, bc_conn *c, cJSON *o, const char *id)
{
    bc_db_job *j = bc_db_job_new(BC_JOB_ROOM_CLOSE);
    (void)c; (void)o; (void)id;
    j->n[0] = (bc_i64)r->id;
    bc_db_submit(j);
    bc_room_close(r, "room_closed");
    return NULL;
}

static const char *h_stats(bc_room *r, bc_conn *c, cJSON *o, const char *id)
{
    cJSON *m = bc_json_msg("stats");
    int i, w = 0, in = 0, synced = 0;
    (void)o; (void)id;
    for (i = 0; i < r->mn; i++) {
        if (r->m[i]->state == BC_ST_WAITING) w++;
        if (r->m[i]->state == BC_ST_IN_ROOM) { in++; if (r->m[i]->synced) synced++; }
    }
    cJSON_AddNumberToObject(m, "waiting", w);
    cJSON_AddNumberToObject(m, "in_room", in);
    cJSON_AddNumberToObject(m, "synced", synced);
    cJSON_AddNumberToObject(m, "bytes_in", (double)r->bytes_in);
    cJSON_AddNumberToObject(m, "bytes_out", (double)r->bytes_out);
    cJSON_AddNumberToObject(m, "drops", (double)r->drops);
    cJSON_AddNumberToObject(m, "chunks", (double)r->chunks);
    cJSON_AddNumberToObject(m, "gen", r->gen);
    cJSON_AddBoolToObject(m, "have_key", r->have_key);
    cJSON_AddNumberToObject(m, "cache_bytes", (double)r->cache.len);
    send_take(c, m);
    return NULL;
}

static const char *h_sync(bc_room *r, bc_conn *c, cJSON *o, const char *id)
{
    char *s;
    (void)o; (void)id;
    if (c->state != BC_ST_IN_ROOM) return "not_allowed";
    s = bc_room_state_json(r, c);
    bc_conn_send_text(c, s);
    free(s);
    return NULL;
}

/* ============================================================ despacho */

enum { NEED_WAITING = 1, NEED_IN_ROOM = 2, NEED_ADMIN = 4, ACKED = 8, ACK_FIRST = 16 };

typedef struct {
    const char *type;
    bc_handler  fn;
    int         flags;
} bc_route;

static const bc_route ROUTES[] = {
    { "bye",                h_bye,            NEED_WAITING | NEED_IN_ROOM },
    { "chat.send",          h_chat,           NEED_IN_ROOM | ACKED },
    { "chat.private",       h_chat_private,   NEED_WAITING | NEED_IN_ROOM | ACKED },
    { "hand.raise",         h_hand_raise,     NEED_IN_ROOM | ACKED },
    { "hand.lower",         h_hand_lower,     NEED_IN_ROOM | ACKED },
    { "media.init",         h_media_init,     NEED_IN_ROOM | ACKED },
    { "media.stop",         h_media_stop,     NEED_IN_ROOM | ACKED },
    { "state.sync.request", h_sync,           NEED_IN_ROOM },
    { "admin.admit",        h_admit,          NEED_IN_ROOM | NEED_ADMIN | ACKED },
    { "admin.admit_all",    h_admit_all,      NEED_IN_ROOM | NEED_ADMIN | ACKED },
    { "admin.deny",         h_deny,           NEED_IN_ROOM | NEED_ADMIN | ACKED },
    { "admin.ban_waiting",  h_ban_waiting,    NEED_IN_ROOM | NEED_ADMIN | ACKED },
    { "admin.kick",         h_kick,           NEED_IN_ROOM | NEED_ADMIN | ACKED },
    { "admin.ban",          h_ban,            NEED_IN_ROOM | NEED_ADMIN | ACKED },
    { "admin.unban",        h_unban,          NEED_IN_ROOM | NEED_ADMIN | ACKED },
    { "admin.bans.list",    h_bans_list,      NEED_IN_ROOM | NEED_ADMIN },
    { "admin.invite",       h_invite,         NEED_IN_ROOM | NEED_ADMIN | ACKED },
    { "admin.invite.revoke",h_invite_revoke,  NEED_IN_ROOM | NEED_ADMIN | ACKED },
    { "admin.speaker.set",  h_speaker_set,    NEED_IN_ROOM | NEED_ADMIN | ACKED },
    { "admin.speaker.clear",h_speaker_clear,  NEED_IN_ROOM | NEED_ADMIN | ACKED },
    { "admin.hand.accept",  h_hand_accept,    NEED_IN_ROOM | NEED_ADMIN | ACKED },
    { "admin.hand.reject",  h_hand_reject,    NEED_IN_ROOM | NEED_ADMIN | ACKED },
    { "admin.resolution.set", h_resolution,   NEED_IN_ROOM | NEED_ADMIN | ACKED },
    { "admin.mute",         h_mute_on,        NEED_IN_ROOM | NEED_ADMIN | ACKED },
    { "admin.unmute",       h_mute_off,       NEED_IN_ROOM | NEED_ADMIN | ACKED },
    { "admin.lock",         h_lock_on,        NEED_IN_ROOM | NEED_ADMIN | ACKED },
    { "admin.unlock",       h_lock_off,       NEED_IN_ROOM | NEED_ADMIN | ACKED },
    { "admin.close",        h_close,          NEED_IN_ROOM | NEED_ADMIN | ACK_FIRST },
    { "admin.stats",        h_stats,          NEED_IN_ROOM | NEED_ADMIN }
};

static void audit(bc_room *r, bc_conn *c, const char *type, const char *id, cJSON *o, const char *status)
{
    bc_db_job *j = bc_db_job_new(BC_JOB_AUDIT);
    const char *pk = bc_json_str(o, "pkey", BC_PKEY_LEN);
    char *payload = cJSON_PrintUnformatted(o);
    j->n[0] = (bc_i64)r->id;
    j->n[1] = (bc_i64)admin_user(r, c);
    bc_db_job_set_s(j, 0, pk ? pk : "");
    bc_db_job_set_s(j, 1, id ? id : "");
    bc_db_job_set_s(j, 2, type);
    bc_db_job_set_s(j, 3, payload ? payload : "{}");
    bc_db_job_set_s(j, 4, status);
    free(payload);
    bc_db_submit(j);
}

static void dispatch(bc_conn *c, const char *type, const char *id, cJSON *o)
{
    bc_room *r = c->room;
    const bc_route *rt = NULL;
    size_t i;
    const char *err;

    for (i = 0; i < sizeof(ROUTES) / sizeof(ROUTES[0]); i++) {
        if (strcmp(ROUTES[i].type, type) == 0) { rt = &ROUTES[i]; break; }
    }
    if (!rt) { bc_proto_error(c, id, "unknown_type", type); return; }

    pthread_mutex_lock(&r->mu);
    if (!bc_fsm_is_active(c->state)) { pthread_mutex_unlock(&r->mu); return; }
    if (!((c->state == BC_ST_WAITING && (rt->flags & NEED_WAITING)) ||
          (c->state == BC_ST_IN_ROOM && (rt->flags & NEED_IN_ROOM)))) {
        pthread_mutex_unlock(&r->mu);
        bc_proto_error(c, id, c->state == BC_ST_WAITING ? "waiting" : "not_allowed",
                       "comando nao permitido neste estado");
        return;
    }
    if ((rt->flags & NEED_ADMIN) && !c->is_admin) {
        pthread_mutex_unlock(&r->mu);
        bc_proto_error(c, id, "not_admin", "somente o administrador da sala");
        return;
    }
    if ((rt->flags & NEED_ADMIN) && id) {
        const char *prev = bc_room_cmd_seen(r, id);
        if (prev) {
            char *dup = bc_xstrdup(prev);
            pthread_mutex_unlock(&r->mu);
            bc_conn_send_text(c, dup);   /* reenvia o ACK original (idempotencia) */
            free(dup);
            return;
        }
    }
    if (rt->flags & ACK_FIRST) {
        /* o comando encerra a propria conexao: confirma antes de executar */
        bc_proto_ack(c, id, "applied", NULL);
    }
    err = rt->fn(r, c, o, id);
    if (rt->flags & ACKED) {
        cJSON *a = bc_json_msg("ack");
        char *s;
        if (id) cJSON_AddStringToObject(a, "ref", id);
        cJSON_AddStringToObject(a, "cmd", type);
        cJSON_AddStringToObject(a, "status", err ? "failed" : "applied");
        if (err) cJSON_AddStringToObject(a, "error", err);
        cJSON_AddNumberToObject(a, "state_version", r->version);
        s = bc_json_take(a);
        if (rt->flags & NEED_ADMIN) bc_room_cmd_store(r, id, s);
        if (bc_fsm_is_active(c->state) || (rt->flags & NEED_ADMIN)) bc_conn_send_text(c, s);
        free(s);
    } else if (err) {
        bc_proto_error(c, id, err, NULL);
    }
    if (rt->flags & NEED_ADMIN) audit(r, c, type, id, o, err ? err : "applied");
    pthread_mutex_unlock(&r->mu);
}

void bc_proto_text(bc_conn *c, const char *s, size_t n)
{
    cJSON *o = bc_json_parse(s, n);
    const char *type, *id;
    int ok = 1;
    long ver;

    if (!o) { bc_proto_error(c, NULL, "bad_json", "JSON invalido"); return; }
    type = bc_json_str(o, "t", 40);
    id = bc_json_str(o, "id", 39);
    ver = bc_json_int(o, "v", 1, &ok);
    if (!type) { bc_proto_error(c, id, "bad_request", "campo t ausente"); cJSON_Delete(o); return; }
    if (!ok || ver != 1) { bc_proto_error(c, id, "protocol_version", "use v=1"); cJSON_Delete(o); return; }

    if (bc_rtc_signal(c, type, o)) {
        cJSON_Delete(o); return;
    }

    if (strcmp(type, "ping") == 0) {
        cJSON *p = bc_json_msg("pong");
        cJSON_AddNumberToObject(p, "ts", (double)bc_wall_ms());
        send_take(c, p);
    } else if (!c->room) {
        if (strcmp(type, "hello") == 0 && !c->auth_pending && c->state == BC_ST_AUTH) {
            handle_hello(c, o);
        } else if (strcmp(type, "bye") == 0) {
            bc_conn_close_soon(c, 1000, "bye");
        } else if (!c->auth_pending) {
            bc_proto_error(c, id, "not_authenticated", "envie hello primeiro");
        }
    } else {
        dispatch(c, type, id, o);
    }
    cJSON_Delete(o);
}

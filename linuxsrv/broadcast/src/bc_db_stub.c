/*
 * bc_db_stub.c - backend em memoria para testes e desenvolvimento sem MySQL
 *
 * Carrega o arquivo db_fixture (JSON) com:
 *   rooms:   [{id, name, status, owner_user_id, join_token}]
 *   users:   [{id, email, role}]
 *   invites: [{id, room_id, token, email, status, display_name, participant_key}]
 *   room_admins: [{room_id, user_id}]
 *   bans:    [{id, room_id, ip, participant_key, email, ...}]
 * As gravacoes ficam em memoria. Somente a thread de banco acessa.
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

static cJSON *data = NULL;
static long next_id = 100000;

const char *bc_be_name(void) { return "stub"; }

static cJSON *arr(const char *name)
{
    cJSON *a = cJSON_GetObjectItemCaseSensitive(data, name);
    if (!cJSON_IsArray(a)) {
        a = cJSON_CreateArray();
        cJSON_AddItemToObject(data, name, a);
    }
    return a;
}

static const char *sget(const cJSON *o, const char *k)
{
    const cJSON *v = cJSON_GetObjectItemCaseSensitive(o, k);
    return (cJSON_IsString(v) && v->valuestring) ? v->valuestring : "";
}

static double nget(const cJSON *o, const char *k)
{
    const cJSON *v = cJSON_GetObjectItemCaseSensitive(o, k);
    return cJSON_IsNumber(v) ? v->valuedouble : 0;
}

static void sset(cJSON *o, const char *k, const char *v)
{
    cJSON_DeleteItemFromObjectCaseSensitive(o, k);
    cJSON_AddStringToObject(o, k, v ? v : "");
}

int bc_be_connect(char *err, size_t errlen)
{
    FILE *fp;
    long n;
    char *buf;
    if (data) return 0;
    if (!g_cfg.db_fixture[0]) {
        data = cJSON_CreateObject();
        return 0;
    }
    fp = fopen(g_cfg.db_fixture, "rb");
    if (!fp) { snprintf(err, errlen, "fixture %s nao encontrada", g_cfg.db_fixture); return -1; }
    fseek(fp, 0, SEEK_END);
    n = ftell(fp);
    fseek(fp, 0, SEEK_SET);
    buf = (char *)bc_xmalloc((size_t)n + 1);
    if (fread(buf, 1, (size_t)n, fp) != (size_t)n) { fclose(fp); free(buf); snprintf(err, errlen, "erro lendo fixture"); return -1; }
    buf[n] = '\0';
    fclose(fp);
    data = cJSON_Parse(buf);
    free(buf);
    if (!data) { snprintf(err, errlen, "fixture com JSON invalido"); return -1; }
    return 0;
}

void bc_be_disconnect(void) { /* mantem os dados em memoria */ }
int  bc_be_ping(void) { return 0; }

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

static cJSON *find_by(const char *table, const char *k, const char *v)
{
    cJSON *it;
    cJSON_ArrayForEach(it, arr(table)) {
        if (strcmp(sget(it, k), v) == 0) return it;
    }
    return NULL;
}

static cJSON *find_id(const char *table, double id)
{
    cJSON *it;
    cJSON_ArrayForEach(it, arr(table)) {
        if (nget(it, "id") == id) return it;
    }
    return NULL;
}

static void do_auth(bc_db_job *j)
{
    bc_auth_res *r = j->auth;
    cJSON *room = find_by("rooms", "join_token", j->s[0]), *inv = NULL, *owner, *it;
    if (!room) { bc_strlcpy(r->err, "room_not_found", sizeof(r->err)); return; }
    r->room_id = (bc_u64)nget(room, "id");
    bc_strlcpy(r->room_name, sget(room, "name"), sizeof(r->room_name));
    bc_strlcpy(r->room_status, sget(room, "status"), sizeof(r->room_status));
    r->owner_user_id = (bc_u64)nget(room, "owner_user_id");
    if (strcmp(r->room_status, "open") != 0) { bc_strlcpy(r->err, "room_not_open", sizeof(r->err)); return; }
    if (j->s[1] && *j->s[1]) {
        inv = find_by("invites", "token", j->s[1]);
        if (inv && (bc_u64)nget(inv, "room_id") != r->room_id) inv = NULL;
    }
    if (inv) {
        r->has_invite = 1;
        r->invite_id = (bc_u64)nget(inv, "id");
        bc_strlcpy(r->invite_email, sget(inv, "email"), sizeof(r->invite_email));
        bc_strlcpy(r->invite_status, sget(inv, "status"), sizeof(r->invite_status));
        bc_strlcpy(r->invite_name, sget(inv, "display_name"), sizeof(r->invite_name));
        bc_strlcpy(r->invite_pkey, sget(inv, "participant_key"), sizeof(r->invite_pkey));
        if (strcmp(r->invite_status, "revoked") == 0) { bc_strlcpy(r->err, "invite_revoked", sizeof(r->err)); return; }
    }
    owner = find_id("users", (double)r->owner_user_id);
    if (r->has_invite && r->invite_email[0] && strcmp(r->invite_status, "rejected") != 0) {
        cJSON *u;
        if (owner && ieq(sget(owner, "email"), r->invite_email)) { r->is_owner = 1; r->is_admin = 1; }
        cJSON_ArrayForEach(u, arr("users")) {
            if (ieq(sget(u, "email"), r->invite_email)) {
                r->user_id = (bc_u64)nget(u, "id");
                if (strcmp(sget(u, "role"), "admin") == 0) r->is_admin = 1;
            }
        }
        cJSON_ArrayForEach(it, arr("room_admins")) {
            if ((bc_u64)nget(it, "room_id") == r->room_id && r->user_id && (bc_u64)nget(it, "user_id") == r->user_id)
                r->is_admin = 1;
        }
    }
    if (!r->is_owner) {
        cJSON_ArrayForEach(it, arr("bans")) {
            if ((bc_u64)nget(it, "room_id") != r->room_id || nget(it, "active") == 0) continue;
            if ((*sget(it, "ip") && strcmp(sget(it, "ip"), j->s[2]) == 0) ||
                (*sget(it, "participant_key") && strcmp(sget(it, "participant_key"), r->invite_pkey) == 0) ||
                (*sget(it, "email") && ieq(sget(it, "email"), r->invite_email))) {
                r->banned = 1;
                bc_strlcpy(r->ban_reason, sget(it, "reason"), sizeof(r->ban_reason));
                break;
            }
        }
    }
    {
        cJSON *msgs = arr("messages");
        int n = cJSON_GetArraySize(msgs), start = n - g_cfg.chat_history, i;
        if (start < 0) start = 0;
        for (i = start; i < n && r->chat_n < BC_CHAT_HIST_MAX; i++) {
            cJSON *m = cJSON_GetArrayItem(msgs, i), *o;
            char idb[24];
            if ((bc_u64)nget(m, "room_id") != r->room_id) continue;
            o = bc_json_msg("chat.msg");
            snprintf(idb, sizeof(idb), "%.0f", nget(m, "id"));
            cJSON_AddStringToObject(o, "id", idb);
            cJSON_AddStringToObject(o, "pkey", sget(m, "participant_key"));
            cJSON_AddStringToObject(o, "name", sget(m, "display_name"));
            cJSON_AddBoolToObject(o, "admin", 0);
            cJSON_AddStringToObject(o, "text", sget(m, "message"));
            cJSON_AddNumberToObject(o, "ts", nget(m, "ts"));
            r->chat[r->chat_n++] = bc_json_take(o);
        }
    }
    r->ok = 1;
}

int bc_be_run(bc_db_job *j)
{
    cJSON *o, *it;
    switch (j->kind) {
    case BC_JOB_AUTH:
        do_auth(j);
        break;
    case BC_JOB_CHAT:
        o = cJSON_CreateObject();
        cJSON_AddNumberToObject(o, "id", (double)next_id++);
        cJSON_AddNumberToObject(o, "room_id", (double)j->n[0]);
        cJSON_AddStringToObject(o, "participant_key", j->s[0]);
        cJSON_AddStringToObject(o, "display_name", j->s[1]);
        cJSON_AddStringToObject(o, "message", j->s[2]);
        cJSON_AddNumberToObject(o, "ts", (double)bc_wall_ms());
        cJSON_AddItemToArray(arr("messages"), o);
        break;
    case BC_JOB_BAN:
        o = cJSON_CreateObject();
        j->out_n = next_id++;
        cJSON_AddNumberToObject(o, "id", (double)j->out_n);
        cJSON_AddNumberToObject(o, "room_id", (double)j->n[0]);
        cJSON_AddNumberToObject(o, "active", 1);
        cJSON_AddStringToObject(o, "ip", j->s[0]);
        cJSON_AddStringToObject(o, "participant_key", j->s[1]);
        cJSON_AddStringToObject(o, "email", j->s[2]);
        cJSON_AddStringToObject(o, "name", j->s[3]);
        cJSON_AddStringToObject(o, "reason", j->s[4]);
        cJSON_AddStringToObject(o, "type", j->s[5]);
        cJSON_AddStringToObject(o, "created_at", "now");
        cJSON_AddStringToObject(o, "expires_at", "");
        cJSON_AddItemToArray(arr("bans"), o);
        break;
    case BC_JOB_UNBAN:
        it = find_id("bans", (double)j->n[1]);
        if (it && (bc_i64)nget(it, "room_id") == j->n[0] && nget(it, "active") != 0) {
            cJSON_ReplaceItemInObjectCaseSensitive(it, "active", cJSON_CreateNumber(0));
        } else {
            j->rc = 1;
        }
        break;
    case BC_JOB_BANS_LIST: {
        cJSON *out = cJSON_CreateArray();
        cJSON_ArrayForEach(it, arr("bans")) {
            if ((bc_i64)nget(it, "room_id") == j->n[0] && nget(it, "active") != 0)
                cJSON_AddItemToArray(out, cJSON_Duplicate(it, 1));
        }
        j->out = bc_json_take(out);
        break;
    }
    case BC_JOB_INVITE_STATUS:
        it = find_id("invites", (double)j->n[0]);
        if (it) sset(it, "status", j->s[0]);
        break;
    case BC_JOB_INVITE_CREATE: {
        char token[65];
        bc_random_hex(token, 32);
        o = cJSON_CreateObject();
        j->out_n = next_id++;
        cJSON_AddNumberToObject(o, "id", (double)j->out_n);
        cJSON_AddNumberToObject(o, "room_id", (double)j->n[0]);
        cJSON_AddStringToObject(o, "token", token);
        cJSON_AddStringToObject(o, "email", j->s[0]);
        cJSON_AddStringToObject(o, "display_name", j->s[1]);
        cJSON_AddStringToObject(o, "participant_key", j->s[2]);
        cJSON_AddStringToObject(o, "status", j->s[4]);
        cJSON_AddItemToArray(arr("invites"), o);
        j->out = bc_xstrdup(token);
        break;
    }
    case BC_JOB_INVITE_REVOKE:
        it = find_id("invites", (double)j->n[1]);
        if (it) sset(it, "status", "revoked");
        break;
    case BC_JOB_ROOM_CLOSE:
        it = find_id("rooms", (double)j->n[0]);
        if (it) sset(it, "status", "closed");
        break;
    case BC_JOB_ROOM_STATUS:
        it = find_id("rooms", (double)j->n[0]);
        j->out = bc_xstrdup(it ? sget(it, "status") : "missing");
        break;
    default:
        /* presenca, frequencia, auditoria e estado: sem efeito no stub */
        break;
    }
    return 0;
}

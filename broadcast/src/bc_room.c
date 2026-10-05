/*
 * bc_room.c - registro de salas, acoes das transicoes de estado,
 * orador unico e distribuicao (fan-out) da midia WebM.
 *
 * Ordem de travas: ver bc_room.h.
 */
#include "config.h"
#include "bc_room.h"
#include "bc_config.h"
#include "bc_json.h"
#include "bc_log.h"
#include "bc_util.h"
#include "bc_ws.h"
#include "bc_proto.h"

#include <stdio.h>
#include <stdlib.h>
#include <string.h>

static pthread_mutex_t rooms_lock = PTHREAD_MUTEX_INITIALIZER;
static bc_room **rooms = NULL;
static int       rooms_n = 0, rooms_cap = 0;

/* ================================================================ registro */

static bc_room *room_new(bc_u64 id, const char *name, bc_u64 owner)
{
    bc_room *r = (bc_room *)bc_xcalloc(1, sizeof(bc_room));
    pthread_mutex_init(&r->mu, NULL);
    r->id = id;
    bc_strlcpy(r->name, name ? name : "", sizeof(r->name));
    r->owner_user_id = owner;
    r->state = BC_RS_OPEN_IDLE;
    r->prof_w = 640; r->prof_h = 360; r->prof_fps = 24; r->prof_kbps = 500;
    r->version = 1;
    r->in_registry = 1;
    r->refs = 1;                         /* referencia do registro */
    r->empty_since = bc_now_ms();
    bc_ebml_init(&r->ebml);
    bc_bytes_init(&r->init);
    bc_bytes_init(&r->cache);
    return r;
}

static void room_free(bc_room *r)
{
    int i;
    for (i = 0; i < BC_CMD_RING; i++) free(r->cmd_acks[i]);
    for (i = 0; i < BC_CHAT_RING; i++) free(r->chat[i]);
    bc_ebml_free(&r->ebml);
    bc_bytes_free(&r->init);
    bc_bytes_free(&r->cache);
    free(r->m);
    pthread_mutex_destroy(&r->mu);
    free(r);
}

bc_room *bc_room_get(bc_u64 id, const char *name, bc_u64 owner)
{
    int i;
    bc_room *r = NULL;
    pthread_mutex_lock(&rooms_lock);
    for (i = 0; i < rooms_n; i++) {
        if (rooms[i]->id == id) { r = rooms[i]; break; }
    }
    if (!r) {
        if (name == NULL || rooms_n >= g_cfg.max_rooms) {
            pthread_mutex_unlock(&rooms_lock);
            return NULL;
        }
        r = room_new(id, name, owner);
        if (rooms_n == rooms_cap) {
            rooms_cap = rooms_cap ? rooms_cap * 2 : 16;
            rooms = (bc_room **)bc_xrealloc(rooms, sizeof(bc_room *) * (size_t)rooms_cap);
        }
        rooms[rooms_n++] = r;
        BC_LOG_INFO("sala %llu carregada (%s)", (unsigned long long)id, r->name);
    }
    r->refs++;
    pthread_mutex_unlock(&rooms_lock);
    return r;
}

void bc_room_unref(bc_room *r)
{
    int dofree;
    if (!r) return;
    pthread_mutex_lock(&rooms_lock);
    r->refs--;
    dofree = (r->refs == 0 && !r->in_registry);
    pthread_mutex_unlock(&rooms_lock);
    if (dofree) room_free(r);
}

int bc_rooms_count(void)
{
    int n;
    pthread_mutex_lock(&rooms_lock);
    n = rooms_n;
    pthread_mutex_unlock(&rooms_lock);
    return n;
}

/* ================================================================ membros */

static void member_add(bc_room *r, bc_conn *c)
{
    if (r->mn == r->mcap) {
        r->mcap = r->mcap ? r->mcap * 2 : 16;
        r->m = (bc_conn **)bc_xrealloc(r->m, sizeof(bc_conn *) * (size_t)r->mcap);
    }
    r->m[r->mn++] = c;
}

static void member_remove(bc_room *r, bc_conn *c)
{
    int i;
    for (i = 0; i < r->mn; i++) {
        if (r->m[i] == c) {
            memmove(r->m + i, r->m + i + 1, sizeof(bc_conn *) * (size_t)(r->mn - i - 1));
            r->mn--;
            if (r->mn == 0) r->empty_since = bc_now_ms();
            return;
        }
    }
}

bc_conn *bc_room_find(bc_room *r, const char *pkey)
{
    int i;
    if (!pkey || !*pkey) return NULL;
    for (i = 0; i < r->mn; i++) {
        if (strcmp(r->m[i]->pkey, pkey) == 0 && bc_fsm_is_active(r->m[i]->state)) return r->m[i];
    }
    return NULL;
}

int bc_room_count_in_room(bc_room *r)
{
    int i, n = 0;
    for (i = 0; i < r->mn; i++) if (r->m[i]->state == BC_ST_IN_ROOM) n++;
    return n;
}

void bc_room_send_all(bc_room *r, const char *json, int in_room, int waiting, int admins_only)
{
    int i;
    bc_buf *b = NULL;
    for (i = 0; i < r->mn; i++) {
        bc_conn *c = r->m[i];
        if (!((in_room && c->state == BC_ST_IN_ROOM) || (waiting && c->state == BC_ST_WAITING))) continue;
        if (admins_only && !c->is_admin) continue;
        if (!b) b = bc_ws_make_text(json);
        bc_conn_send(c, bc_buf_ref(b));
    }
    if (b) bc_buf_unref(b);
}

void bc_room_send_admins(bc_room *r, const char *json)
{
    bc_room_send_all(r, json, 1, 0, 1);
}

static void send_take(bc_conn *c, cJSON *o)
{
    char *s = bc_json_take(o);
    bc_conn_send_text(c, s);
    free(s);
}

static void all_take(bc_room *r, cJSON *o, int in_room, int waiting, int admins_only)
{
    char *s = bc_json_take(o);
    bc_room_send_all(r, s, in_room, waiting, admins_only);
    free(s);
}

void bc_room_bump(bc_room *r) { r->version++; }

/* ================================================================ JSON */

static cJSON *participant_obj(bc_conn *c, int with_private)
{
    cJSON *o = cJSON_CreateObject();
    cJSON_AddStringToObject(o, "pkey", c->pkey);
    cJSON_AddStringToObject(o, "name", c->name);
    cJSON_AddStringToObject(o, "role", c->is_admin ? "admin" : "viewer");
    cJSON_AddStringToObject(o, "state", bc_state_name(c->state));
    cJSON_AddStringToObject(o, "sub", bc_sub_name(c->sub));
    cJSON_AddBoolToObject(o, "hand", c->sub == BC_SUB_HAND);
    if (c->sub == BC_SUB_HAND) cJSON_AddNumberToObject(o, "hand_since", (double)c->hand_since);
    cJSON_AddBoolToObject(o, "muted", c->muted);
    if (with_private) {
        cJSON_AddStringToObject(o, "ip", c->ip);
        cJSON_AddStringToObject(o, "user_agent", c->ua);
        cJSON_AddStringToObject(o, "client", c->client);
        cJSON_AddBoolToObject(o, "invited", c->invite_id != 0);
        if (c->email[0]) cJSON_AddStringToObject(o, "email", c->email);
        if (c->state == BC_ST_WAITING)
            cJSON_AddNumberToObject(o, "waiting_ms", (double)(bc_now_ms() - c->waiting_since));
    }
    return o;
}

char *bc_room_participant_json(bc_conn *c, int with_private)
{
    return bc_json_take(participant_obj(c, with_private));
}

static cJSON *profile_obj(int w, int h, int fps, int kbps)
{
    cJSON *o = cJSON_CreateObject();
    cJSON_AddNumberToObject(o, "w", w);
    cJSON_AddNumberToObject(o, "h", h);
    cJSON_AddNumberToObject(o, "fps", fps);
    cJSON_AddNumberToObject(o, "kbps", kbps);
    return o;
}

static int cmp_hand(const void *a, const void *b)
{
    const bc_conn *x = *(bc_conn * const *)a;
    const bc_conn *y = *(bc_conn * const *)b;
    if (x->hand_since < y->hand_since) return -1;
    if (x->hand_since > y->hand_since) return 1;
    return 0;
}

static cJSON *hand_queue_arr(bc_room *r)
{
    cJSON *arr = cJSON_CreateArray();
    bc_conn **tmp;
    int i, n = 0;
    tmp = (bc_conn **)bc_xcalloc((size_t)r->mn + 1, sizeof(bc_conn *));
    for (i = 0; i < r->mn; i++)
        if (r->m[i]->state == BC_ST_IN_ROOM && r->m[i]->sub == BC_SUB_HAND) tmp[n++] = r->m[i];
    qsort(tmp, (size_t)n, sizeof(bc_conn *), cmp_hand);
    for (i = 0; i < n; i++) {
        cJSON *o = cJSON_CreateObject();
        cJSON_AddStringToObject(o, "pkey", tmp[i]->pkey);
        cJSON_AddStringToObject(o, "name", tmp[i]->name);
        cJSON_AddNumberToObject(o, "since", (double)tmp[i]->hand_since);
        cJSON_AddNumberToObject(o, "position", i + 1);
        cJSON_AddItemToArray(arr, o);
    }
    free(tmp);
    return arr;
}

void bc_room_hand_queue_send(bc_room *r)
{
    cJSON *o = bc_json_msg("hand.queue");
    cJSON_AddItemToObject(o, "list", hand_queue_arr(r));
    cJSON_AddNumberToObject(o, "state_version", r->version);
    all_take(r, o, 1, 0, 0);
}

static cJSON *speaker_obj(bc_room *r)
{
    cJSON *o;
    if (!r->speaker) return cJSON_CreateNull();
    o = cJSON_CreateObject();
    cJSON_AddStringToObject(o, "pkey", r->speaker->pkey);
    cJSON_AddStringToObject(o, "name", r->speaker->name);
    cJSON_AddBoolToObject(o, "live", r->speaker->sub == BC_SUB_SPEAKING);
    cJSON_AddNumberToObject(o, "gen", r->gen);
    if (r->mime[0]) cJSON_AddStringToObject(o, "mime", r->mime);
    cJSON_AddBoolToObject(o, "muted", r->speaker->muted);
    return o;
}

char *bc_room_state_json(bc_room *r, bc_conn *me)
{
    cJSON *o = bc_json_msg("state.sync");
    cJSON *room = cJSON_CreateObject();
    cJSON *you = participant_obj(me, 1);
    cJSON *parts = cJSON_CreateArray();
    cJSON *chat = cJSON_CreateArray();
    int i;

    cJSON_AddNumberToObject(o, "state_version", r->version);
    cJSON_AddNumberToObject(room, "id", (double)r->id);
    cJSON_AddStringToObject(room, "name", r->name);
    cJSON_AddStringToObject(room, "state", bc_room_state_name(r->state));
    cJSON_AddBoolToObject(room, "locked", r->locked);
    cJSON_AddItemToObject(room, "speaker", speaker_obj(r));
    cJSON_AddItemToObject(room, "profile", profile_obj(r->prof_w, r->prof_h, r->prof_fps, r->prof_kbps));
    cJSON_AddItemToObject(o, "room", room);
    cJSON_AddItemToObject(o, "you", you);
    for (i = 0; i < r->mn; i++)
        if (r->m[i]->state == BC_ST_IN_ROOM) cJSON_AddItemToArray(parts, participant_obj(r->m[i], me->is_admin));
    cJSON_AddItemToObject(o, "participants", parts);
    cJSON_AddItemToObject(o, "hand_queue", hand_queue_arr(r));
    if (me->is_admin) {
        cJSON *wait = cJSON_CreateArray();
        for (i = 0; i < r->mn; i++)
            if (r->m[i]->state == BC_ST_WAITING) cJSON_AddItemToArray(wait, participant_obj(r->m[i], 1));
        cJSON_AddItemToObject(o, "waiting", wait);
    }
    for (i = 0; i < r->chat_n; i++) {
        int idx = (r->chat_pos - r->chat_n + i + BC_CHAT_RING) % BC_CHAT_RING;
        cJSON *m = cJSON_Parse(r->chat[idx]);
        if (m) cJSON_AddItemToArray(chat, m);
    }
    cJSON_AddItemToObject(o, "chat", chat);
    return bc_json_take(o);
}

void bc_room_chat_add(bc_room *r, const char *json)
{
    int limit = g_cfg.chat_history > BC_CHAT_RING ? BC_CHAT_RING : g_cfg.chat_history;
    if (limit <= 0) return;
    free(r->chat[r->chat_pos]);
    r->chat[r->chat_pos] = bc_xstrdup(json);
    r->chat_pos = (r->chat_pos + 1) % BC_CHAT_RING;
    if (r->chat_n < limit) r->chat_n++;
}

/* ================================================================ idempotencia */

const char *bc_room_cmd_seen(bc_room *r, const char *id)
{
    int i;
    if (!id || !*id) return NULL;
    for (i = 0; i < BC_CMD_RING; i++) {
        if (r->cmd_acks[i] && strcmp(r->cmd_ids[i], id) == 0) return r->cmd_acks[i];
    }
    return NULL;
}

void bc_room_cmd_store(bc_room *r, const char *id, const char *ack)
{
    if (!id || !*id) return;
    free(r->cmd_acks[r->cmd_pos]);
    bc_strlcpy(r->cmd_ids[r->cmd_pos], id, sizeof(r->cmd_ids[0]));
    r->cmd_acks[r->cmd_pos] = bc_xstrdup(ack);
    r->cmd_pos = (r->cmd_pos + 1) % BC_CMD_RING;
}

/* ================================================================ persistencia */

void bc_room_persist_runtime(bc_room *r)
{
    bc_db_job *j = bc_db_job_new(BC_JOB_RUNTIME);
    char *prof;
    j->n[0] = (bc_i64)r->id;
    j->n[1] = (bc_i64)r->version;
    j->n[2] = r->locked;
    bc_db_job_set_s(j, 0, r->speaker ? r->speaker->pkey : "");
    prof = bc_json_take(profile_obj(r->prof_w, r->prof_h, r->prof_fps, r->prof_kbps));
    bc_db_job_set_s(j, 1, prof);
    free(prof);
    bc_db_submit(j);
}

static void db_presence(bc_room *r, bc_conn *c, int present)
{
    bc_db_job *j = bc_db_job_new(BC_JOB_PRESENCE);
    j->n[0] = (bc_i64)r->id;
    j->n[1] = present;
    bc_db_job_set_s(j, 0, c->pkey);
    bc_db_job_set_s(j, 1, c->name);
    bc_db_job_set_s(j, 2, c->state == BC_ST_WAITING ? "waiting" : "in_room");
    bc_db_job_set_s(j, 3, c->is_admin ? "admin" : (c->sub == BC_SUB_SPEAKING ? "speaker" : "viewer"));
    bc_db_submit(j);
}

static void db_attendance(bc_room *r, bc_conn *c, int open)
{
    bc_db_job *j = bc_db_job_new(open ? BC_JOB_ATT_OPEN : BC_JOB_ATT_CLOSE);
    j->n[0] = (bc_i64)r->id;
    bc_db_job_set_s(j, 0, c->pkey);
    bc_db_job_set_s(j, 1, c->name);
    bc_db_submit(j);
}

/* ================================================================ midia */

static bc_buf *media_frame(bc_room *r, int kind, int key, bc_u64 ts, const bc_u8 *p, size_t n)
{
    bc_u8 h[BC_MEDIA_HDR];
    bc_u32 seq = r->out_seq++;
    int i;
    h[0] = BC_MEDIA_MAGIC;
    h[1] = (bc_u8)kind;
    h[2] = (bc_u8)(key ? 1 : 0);
    h[3] = (bc_u8)(r->gen & 0xFF);
    h[4] = (bc_u8)(seq >> 24); h[5] = (bc_u8)(seq >> 16); h[6] = (bc_u8)(seq >> 8); h[7] = (bc_u8)seq;
    for (i = 0; i < 8; i++) h[15 - i] = (bc_u8)(ts >> (8 * i));
    return bc_ws_make(BC_WS_BIN, h, BC_MEDIA_HDR, p, n, 1);
}

/* Reenvia init + cache a partir do ultimo keyframe (room->mu presa). */
static void resync(bc_room *r, bc_conn *c)
{
    cJSON *o;
    if (!r->init_done || !r->have_key || c == r->speaker) return;
    if (bc_conn_out_bytes(c) > g_cfg.max_queue_bytes / 2) return;
    o = bc_json_msg("stream.reset");
    cJSON_AddNumberToObject(o, "gen", r->gen);
    cJSON_AddStringToObject(o, "mime", r->mime);
    if (r->speaker) cJSON_AddStringToObject(o, "pkey", r->speaker->pkey);
    send_take(c, o);
    bc_conn_send_media(c, media_frame(r, BC_MK_INIT, 0, bc_wall_ms(), r->init.p, r->init.len));
    if (bc_conn_send_media(c, media_frame(r, BC_MK_DATA, 1, bc_wall_ms(), r->cache.p, r->cache.len)) == 0)
        c->synced = 1;
}

void bc_room_stream_reset(bc_room *r, int gen, const char *mime)
{
    int i;
    r->gen = gen;
    bc_strlcpy(r->mime, mime ? mime : "", sizeof(r->mime));
    bc_ebml_free(&r->ebml);
    bc_ebml_init(&r->ebml);
    bc_bytes_clear(&r->init);
    bc_bytes_clear(&r->cache);
    r->init_done = 0;
    r->have_key = 0;
    r->cache_start = 0;
    r->cur_cluster = 0;
    r->stream_ok = 1;
    for (i = 0; i < r->mn; i++) r->m[i]->synced = 0;
}

static void trim_cache(bc_room *r, bc_u64 new_start)
{
    if (new_start <= r->cache_start) return;
    bc_bytes_consume(&r->cache, (size_t)(new_start - r->cache_start));
    r->cache_start = new_start;
}

void bc_room_on_media(bc_conn *c, const bc_u8 *p, size_t n)
{
    bc_room *r = c->room;
    bc_ebml_ev ev[64];
    int nev, i, keyfound = 0;
    bc_u64 base, ts = 0, new_start;
    const bc_u8 *pl;
    size_t pn;
    bc_buf *frame = NULL;

    if (n < BC_MEDIA_HDR || p[0] != BC_MEDIA_MAGIC) return;
    pthread_mutex_lock(&r->mu);
    if (c->state != BC_ST_IN_ROOM || r->speaker != c || c->sub != BC_SUB_SPEAKING) {
        pthread_mutex_unlock(&r->mu);
        return;
    }
    if (p[3] != (bc_u8)(r->gen & 0xFF) || !r->stream_ok) {
        pthread_mutex_unlock(&r->mu);
        return;                                  /* geracao antiga: descarta */
    }
    for (i = 8; i < 16; i++) ts = (ts << 8) | p[i];
    pl = p + BC_MEDIA_HDR;
    pn = n - BC_MEDIA_HDR;
    r->bytes_in += n;
    r->chunks++;
    r->last_media_ms = bc_now_ms();

    base = r->ebml.off;
    nev = bc_ebml_feed(&r->ebml, pl, pn, ev, 64);

    /* 1) cabecalho de inicializacao: bytes antes do primeiro Cluster */
    if (!r->init_done) {
        size_t split = pn;
        for (i = 0; i < nev; i++) {
            if (ev[i].type == BC_EBML_EV_CLUSTER) { split = (size_t)(ev[i].off - base); break; }
        }
        bc_bytes_append(&r->init, pl, split);
        if (split < pn || i < nev) {
            r->init_done = 1;
            r->cache_start = base + split;
            bc_bytes_append(&r->cache, pl + split, pn - split);
        }
        if (r->init.len > 1048576) r->stream_ok = 0;
    } else {
        bc_bytes_append(&r->cache, pl, pn);
    }

    /* 2) eventos: inicio de cluster e keyframe */
    new_start = r->cache_start;
    for (i = 0; i < nev; i++) {
        if (ev[i].type == BC_EBML_EV_CLUSTER) {
            r->cur_cluster = ev[i].off;
            if (!r->have_key) new_start = ev[i].off;
        } else if (ev[i].type == BC_EBML_EV_KEY && ev[i].key) {
            r->have_key = 1;
            new_start = ev[i].off;
            keyfound = 1;
        } else if (ev[i].type == BC_EBML_EV_ERROR) {
            BC_LOG_WARN("sala %llu: WebM invalido do orador; pedindo reinicio", (unsigned long long)r->id);
            r->stream_ok = 0;
        }
    }
    if (r->init_done) trim_cache(r, new_start);
    if (r->cache.len > g_cfg.max_cache_bytes) {
        r->have_key = 0;
        trim_cache(r, r->cur_cluster);
        if (r->cache.len > g_cfg.max_cache_bytes) {
            r->cache_start += r->cache.len;
            bc_bytes_clear(&r->cache);
        }
    }

    /* 3) repasse para quem ja esta sincronizado */
    /*
     * Ninguem esta sincronizado antes do primeiro keyframe da geracao, entao o
     * pedaco que ainda contem o cabecalho nunca chega aqui com destinatarios.
     */
    if (r->init_done && pn > 0) {
        for (i = 0; i < r->mn; i++) {
            bc_conn *v = r->m[i];
            if (v == c || v->state != BC_ST_IN_ROOM || !v->synced) continue;
            if (!frame) frame = media_frame(r, BC_MK_DATA, keyfound, ts, pl, pn);
            if (bc_conn_send_media(v, bc_buf_ref(frame)) != 0) {
                v->synced = 0;
                r->drops++;
            } else {
                r->bytes_out += frame->len;
            }
        }
        if (frame) bc_buf_unref(frame);
    }

    /* 4) novo keyframe: sincroniza quem estava esperando (novos e atrasados) */
    if (keyfound) {
        for (i = 0; i < r->mn; i++) {
            bc_conn *v = r->m[i];
            if (v == c || v->state != BC_ST_IN_ROOM || v->synced) continue;
            resync(r, v);
        }
    }

    if (!r->stream_ok) {
        cJSON *o = bc_json_msg("media.restart");
        cJSON_AddNumberToObject(o, "gen", r->gen);
        send_take(c, o);
    }
    pthread_mutex_unlock(&r->mu);
}

/* ================================================================ orador */

void bc_room_set_sub(bc_room *r, bc_conn *c, bc_sub to, const char *reason)
{
    cJSON *o;
    if (c->sub == to) return;
    if (bc_sub_next(c->sub, to) < 0 && !(to == BC_SUB_VIEWER)) {
        BC_LOG_WARN("transicao de fala invalida %s -> %s", bc_sub_name(c->sub), bc_sub_name(to));
        return;
    }
    if (to == BC_SUB_HAND) c->hand_since = bc_wall_ms();
    if (to != BC_SUB_HAND) c->hand_since = 0;
    if (to == BC_SUB_PENDING) c->pending_since = bc_now_ms();
    c->sub = to;
    bc_room_bump(r);
    o = bc_json_msg("participant.state");
    cJSON_AddItemToObject(o, "participant", participant_obj(c, 0));
    cJSON_AddStringToObject(o, "reason", reason ? reason : "");
    cJSON_AddNumberToObject(o, "state_version", r->version);
    all_take(r, o, 1, 0, 0);
}

void bc_room_set_speaker(bc_room *r, bc_conn *c, const char *reason)
{
    bc_conn *old = r->speaker;
    cJSON *o;
    if (old == c) return;
    if (old) {
        o = bc_json_msg("media.stop.request");
        cJSON_AddStringToObject(o, "reason", reason ? reason : "");
        send_take(old, o);
        r->speaker = NULL;
        if (old->state == BC_ST_IN_ROOM) bc_room_set_sub(r, old, BC_SUB_VIEWER, reason);
    }
    bc_room_stream_reset(r, (r->gen + 1) & 0xFF, "");
    r->speaker = c;
    if (c) {
        if (c->sub == BC_SUB_VIEWER || c->sub == BC_SUB_HAND) bc_room_set_sub(r, c, BC_SUB_PENDING, reason);
        o = bc_json_msg("speaker.you");
        cJSON_AddNumberToObject(o, "gen", r->gen);
        if (c->pref_w > 0) cJSON_AddItemToObject(o, "profile", profile_obj(c->pref_w, c->pref_h, c->pref_fps, c->pref_kbps));
        else cJSON_AddItemToObject(o, "profile", profile_obj(r->prof_w, r->prof_h, r->prof_fps, r->prof_kbps));
        send_take(c, o);
        if (c->pref_w > 0) { r->prof_w = c->pref_w; r->prof_h = c->pref_h; r->prof_fps = c->pref_fps; r->prof_kbps = c->pref_kbps; }
    }
    r->state = c ? BC_RS_OPEN_LIVE : BC_RS_OPEN_IDLE;
    bc_room_bump(r);
    o = bc_json_msg("speaker.changed");
    cJSON_AddItemToObject(o, "speaker", speaker_obj(r));
    cJSON_AddStringToObject(o, "reason", reason ? reason : "");
    cJSON_AddNumberToObject(o, "state_version", r->version);
    all_take(r, o, 1, 0, 0);
    bc_room_hand_queue_send(r);
    bc_room_persist_runtime(r);
}

/* ================================================================ transicoes */

static void send_goodbye(bc_conn *c, const char *reason)
{
    cJSON *o = bc_json_msg("goodbye");
    cJSON_AddStringToObject(o, "state", bc_state_name(c->state));
    cJSON_AddStringToObject(o, "reason", reason ? reason : "");
    send_take(c, o);
    bc_conn_close_soon(c, 1000, bc_state_name(c->state));
}

static void invite_set_status(bc_conn *c, const char *status)
{
    bc_db_job *j;
    if (!c->invite_id) return;
    j = bc_db_job_new(BC_JOB_INVITE_STATUS);
    j->n[0] = (bc_i64)c->invite_id;
    bc_db_job_set_s(j, 0, status);
    bc_db_submit(j);
}

int bc_room_fire(bc_room *r, bc_conn *c, bc_event ev, const char *reason)
{
    bc_state from = c->state;
    int to = bc_fsm_next(from, ev);
    cJSON *o;

    if (to < 0) {
        BC_LOG_WARN("sala %llu: transicao invalida %s + %s (pkey %.8s)", (unsigned long long)r->id,
                    bc_state_name(from), bc_event_name(ev), c->pkey);
        return -1;
    }
    if ((int)from == to) return 0;
    c->state = (bc_state)to;
    bc_room_bump(r);
    BC_LOG_INFO("sala %llu: %.8s %s -> %s (%s)", (unsigned long long)r->id, c->pkey,
                bc_state_name(from), bc_state_name((bc_state)to), reason ? reason : bc_event_name(ev));

    if (to == BC_ST_WAITING) {
        c->waiting_since = bc_now_ms();
        o = bc_json_msg("lobby.join");
        cJSON_AddItemToObject(o, "participant", participant_obj(c, 1));
        cJSON_AddNumberToObject(o, "state_version", r->version);
        all_take(r, o, 1, 0, 1);
        db_presence(r, c, 1);
        return 0;
    }

    if (to == BC_ST_IN_ROOM) {
        char *sync;
        c->sub = BC_SUB_VIEWER;
        c->joined_ms = bc_now_ms();
        c->synced = 0;
        if (from == BC_ST_WAITING) {
            o = bc_json_msg("lobby.leave");
            cJSON_AddStringToObject(o, "pkey", c->pkey);
            cJSON_AddStringToObject(o, "to", "in_room");
            all_take(r, o, 1, 0, 1);
            o = bc_json_msg("admitted");
            cJSON_AddStringToObject(o, "by", reason ? reason : "");
            send_take(c, o);
        }
        o = bc_json_msg("participant.joined");
        cJSON_AddItemToObject(o, "participant", participant_obj(c, 0));
        cJSON_AddNumberToObject(o, "state_version", r->version);
        {
            /* admins recebem com dados privados; demais, sem */
            char *pub = bc_json_take(o);
            int i;
            o = bc_json_msg("participant.joined");
            cJSON_AddItemToObject(o, "participant", participant_obj(c, 1));
            cJSON_AddNumberToObject(o, "state_version", r->version);
            {
                char *priv = bc_json_take(o);
                for (i = 0; i < r->mn; i++) {
                    bc_conn *x = r->m[i];
                    if (x == c || x->state != BC_ST_IN_ROOM) continue;
                    bc_conn_send_text(x, x->is_admin ? priv : pub);
                }
                free(priv);
            }
            free(pub);
        }
        sync = bc_room_state_json(r, c);
        bc_conn_send_text(c, sync);
        free(sync);
        resync(r, c);
        db_presence(r, c, 1);
        db_attendance(r, c, 1);
        return 0;
    }

    /* estados finais */
    if (from == BC_ST_WAITING) {
        o = bc_json_msg("lobby.leave");
        cJSON_AddStringToObject(o, "pkey", c->pkey);
        cJSON_AddStringToObject(o, "to", bc_state_name((bc_state)to));
        cJSON_AddStringToObject(o, "reason", reason ? reason : "");
        all_take(r, o, 1, 0, 1);
    } else if (from == BC_ST_IN_ROOM) {
        int was_hand = (c->sub == BC_SUB_HAND);
        if (r->speaker == c) {
            r->speaker = NULL;
            c->sub = BC_SUB_VIEWER;
            bc_room_stream_reset(r, (r->gen + 1) & 0xFF, "");
            r->state = BC_RS_OPEN_IDLE;
            o = bc_json_msg("speaker.changed");
            cJSON_AddItemToObject(o, "speaker", cJSON_CreateNull());
            cJSON_AddStringToObject(o, "reason", "speaker_left");
            cJSON_AddNumberToObject(o, "state_version", r->version);
            all_take(r, o, 1, 0, 0);
            bc_room_persist_runtime(r);
        }
        c->sub = BC_SUB_NONE;
        o = bc_json_msg("participant.left");
        cJSON_AddStringToObject(o, "pkey", c->pkey);
        cJSON_AddStringToObject(o, "name", c->name);
        cJSON_AddStringToObject(o, "to", bc_state_name((bc_state)to));
        cJSON_AddStringToObject(o, "reason", reason ? reason : "");
        cJSON_AddNumberToObject(o, "state_version", r->version);
        member_remove(r, c);
        all_take(r, o, 1, 0, 0);
        if (was_hand) bc_room_hand_queue_send(r);
        db_attendance(r, c, 0);
    }
    member_remove(r, c);
    db_presence(r, c, 0);
    if (to == BC_ST_KICKED || to == BC_ST_BANNED || to == BC_ST_DENIED) invite_set_status(c, "rejected");
    if (ev != BC_EV_SOCK_CLOSED) send_goodbye(c, reason);
    return 0;
}

/* ================================================================ worker */

static void random_pkey(char *out) { bc_random_hex(out, 32); }

static void reply_error_close(bc_conn *c, const char *code, const char *msg)
{
    bc_proto_error(c, NULL, code, msg);
    bc_conn_close_soon(c, 1008, code);
}

void bc_room_on_auth(bc_conn *c, bc_auth_res *res)
{
    bc_room *r;
    bc_conn *old;
    int to_room = 0, was_speaker = 0, i;
    cJSON *o;

    c->auth_pending = 0;
    if (c->fd < 0 || bc_conn_closing(c)) return;
    if (!res->ok) {
        c->state = (bc_state)bc_fsm_next(c->state, BC_EV_HELLO_BAD);
        reply_error_close(c, res->err[0] ? res->err : "bad_hello", "acesso recusado");
        return;
    }
    if (res->banned) {
        c->state = (bc_state)bc_fsm_next(c->state, BC_EV_HELLO_BANNED);
        o = bc_json_msg("error");
        cJSON_AddStringToObject(o, "code", "banned");
        cJSON_AddStringToObject(o, "msg", res->ban_reason[0] ? res->ban_reason : "acesso bloqueado nesta sala");
        send_take(c, o);
        bc_conn_close_soon(c, 1008, "banned");
        return;
    }
    r = bc_room_get(res->room_id, res->room_name, res->owner_user_id);
    if (!r) { reply_error_close(c, "server_full", "limite de salas atingido"); return; }

    pthread_mutex_lock(&r->mu);
    if (r->state == BC_RS_CLOSING || r->state == BC_RS_CLOSED) {
        pthread_mutex_unlock(&r->mu);
        bc_room_unref(r);
        reply_error_close(c, "room_not_open", "a reuniao foi encerrada");
        return;
    }
    if (r->mn >= g_cfg.max_clients_per_room) {
        pthread_mutex_unlock(&r->mu);
        bc_room_unref(r);
        reply_error_close(c, "server_full", "sala cheia");
        return;
    }
    if (r->locked && !res->has_invite && !res->is_admin) {
        pthread_mutex_unlock(&r->mu);
        bc_room_unref(r);
        reply_error_close(c, "room_locked", "sala trancada pelo organizador");
        return;
    }
    if (!r->chat_loaded) {
        for (i = 0; i < res->chat_n; i++) bc_room_chat_add(r, res->chat[i]);
        r->chat_loaded = 1;
    }

    c->is_admin = res->is_admin;
    c->is_owner = res->is_owner;
    c->invite_id = res->has_invite ? res->invite_id : 0;
    bc_strlcpy(c->email, res->invite_email, sizeof(c->email));
    if (res->has_invite && bc_is_hex(res->invite_pkey, 16, BC_PKEY_LEN)) bc_strlcpy(c->pkey, res->invite_pkey, sizeof(c->pkey));
    else random_pkey(c->pkey);
    if (!c->name[0]) bc_strlcpy(c->name, res->invite_name[0] ? res->invite_name : "Convidado", sizeof(c->name));

    /* reconexao: mesma identidade ja ativa -> a nova herda o lugar */
    old = bc_room_find(r, c->pkey);
    if (old && old != c) {
        if (old->state == BC_ST_IN_ROOM) to_room = 1;
        if (r->speaker == old) was_speaker = 1;
        bc_room_fire(r, old, BC_EV_REPLACED, "replaced");
    }

    if (res->is_admin) to_room = 1;
    else if (res->has_invite && g_cfg.invite_auto_admit && strcmp(res->invite_status, "approved") == 0) to_room = 1;

    c->room = r;          /* referencia de bc_room_get passa a ser da conexao */
    member_add(r, c);
    r->empty_since = 0;

    o = bc_json_msg("welcome");
    cJSON_AddNumberToObject(o, "session", (double)c->id);
    cJSON_AddStringToObject(o, "pkey", c->pkey);
    cJSON_AddStringToObject(o, "name", c->name);
    cJSON_AddStringToObject(o, "role", c->is_admin ? "admin" : "viewer");
    cJSON_AddStringToObject(o, "state", to_room ? "in_room" : "waiting");
    cJSON_AddNumberToObject(o, "room_id", (double)r->id);
    cJSON_AddStringToObject(o, "room_name", r->name);
    cJSON_AddNumberToObject(o, "state_version", r->version);
    cJSON_AddStringToObject(o, "server", "bcastd/" BC_VERSION);
    send_take(c, o);

    bc_room_fire(r, c, to_room ? BC_EV_HELLO_ROOM : BC_EV_HELLO_WAIT, to_room ? "auto" : "lobby");
    /* orador que reconectou retoma a palavra com uma nova geracao de stream */
    if (was_speaker && c->state == BC_ST_IN_ROOM && r->speaker == NULL)
        bc_room_set_speaker(r, c, "reconnect");
    pthread_mutex_unlock(&r->mu);
}

void bc_room_conn_gone(bc_conn *c, bc_event ev, const char *reason)
{
    bc_room *r = c->room;
    if (!r) return;
    pthread_mutex_lock(&r->mu);
    if (bc_fsm_is_active(c->state)) bc_room_fire(r, c, ev, reason);
    member_remove(r, c);
    pthread_mutex_unlock(&r->mu);
    if (ev == BC_EV_SOCK_CLOSED) {
        c->room = NULL;
        bc_room_unref(r);
    }
}

void bc_room_check_timeouts(bc_conn *c, bc_u64 now)
{
    bc_room *r = c->room;
    int expired = 0;
    pthread_mutex_lock(&r->mu);
    if (c->state == BC_ST_WAITING && now - c->waiting_since > g_cfg.waiting_timeout_ms) {
        bc_room_fire(r, c, BC_EV_TIMEOUT, "waiting_timeout");
        expired = 1;
    }
    pthread_mutex_unlock(&r->mu);
    (void)expired;
}

/* ================================================================ fechamento */

void bc_room_close(bc_room *r, const char *reason)
{
    if (r->state == BC_RS_CLOSED || r->state == BC_RS_CLOSING) return;
    r->state = BC_RS_CLOSING;
    if (r->speaker) {
        r->speaker->sub = BC_SUB_VIEWER;
        r->speaker = NULL;
    }
    while (r->mn > 0) {
        bc_conn *c = r->m[0];
        if (bc_fsm_is_active(c->state)) bc_room_fire(r, c, BC_EV_ROOM_CLOSED, reason);
        member_remove(r, c);
    }
    r->state = BC_RS_CLOSED;
    r->closing_since = bc_now_ms();
    bc_room_persist_runtime(r);
}

static void status_cb(bc_db_job *j)
{
    bc_room *r = NULL;
    int i;
    pthread_mutex_lock(&rooms_lock);
    for (i = 0; i < rooms_n; i++) if (rooms[i]->id == (bc_u64)j->n[0]) { r = rooms[i]; r->refs++; break; }
    pthread_mutex_unlock(&rooms_lock);
    if (!r) return;
    pthread_mutex_lock(&r->mu);
    r->poll_inflight = 0;
    if (j->rc == 0 && j->out && strcmp(j->out, "open") != 0 && r->state != BC_RS_CLOSED) {
        BC_LOG_INFO("sala %llu com status '%s' no banco: encerrando", (unsigned long long)r->id, j->out);
        bc_room_close(r, strcmp(j->out, "cancelled") == 0 ? "room_cancelled" : "room_closed");
    }
    pthread_mutex_unlock(&r->mu);
    bc_room_unref(r);
}

void bc_rooms_maint(void)
{
    bc_room **snap;
    int n, i;
    bc_u64 now = bc_now_ms();

    pthread_mutex_lock(&rooms_lock);
    n = rooms_n;
    snap = (bc_room **)bc_xcalloc((size_t)n + 1, sizeof(bc_room *));
    for (i = 0; i < n; i++) { snap[i] = rooms[i]; rooms[i]->refs++; }
    pthread_mutex_unlock(&rooms_lock);

    for (i = 0; i < n; i++) {
        bc_room *r = snap[i];
        int remove = 0;
        pthread_mutex_lock(&r->mu);
        if (r->speaker && r->speaker->sub == BC_SUB_PENDING &&
            now - r->speaker->pending_since > g_cfg.speaker_pending_ms) {
            cJSON *o = bc_json_msg("error");
            cJSON_AddStringToObject(o, "code", "speaker_timeout");
            cJSON_AddStringToObject(o, "msg", "o orador escolhido nao iniciou a transmissao");
            cJSON_AddStringToObject(o, "pkey", r->speaker->pkey);
            all_take(r, o, 1, 0, 1);
            bc_room_set_speaker(r, NULL, "speaker_timeout");
        }
        if (r->mn == 0 && r->empty_since && now - r->empty_since >= g_cfg.room_linger_ms) remove = 1;
        if (!remove && r->state != BC_RS_CLOSED && !r->poll_inflight && now - r->last_status_poll > 30000) {
            bc_db_job *j = bc_db_job_new(BC_JOB_ROOM_STATUS);
            j->n[0] = (bc_i64)r->id;
            j->cb = status_cb;
            r->poll_inflight = 1;
            r->last_status_poll = now;
            bc_db_submit(j);
        }
        pthread_mutex_unlock(&r->mu);
        if (remove) {
            int k;
            pthread_mutex_lock(&rooms_lock);
            pthread_mutex_lock(&r->mu);
            if (r->mn == 0 && r->in_registry) {
                for (k = 0; k < rooms_n; k++) {
                    if (rooms[k] == r) { rooms[k] = rooms[--rooms_n]; break; }
                }
                r->in_registry = 0;
                r->refs--;      /* referencia do registro */
                BC_LOG_INFO("sala %llu descarregada da memoria", (unsigned long long)r->id);
            }
            pthread_mutex_unlock(&r->mu);
            pthread_mutex_unlock(&rooms_lock);
        }
        bc_room_unref(r);
    }
    free(snap);
}

void bc_rooms_shutdown(const char *reason)
{
    int i;
    pthread_mutex_lock(&rooms_lock);
    for (i = 0; i < rooms_n; i++) {
        bc_room *r = rooms[i];
        int k;
        pthread_mutex_lock(&r->mu);
        r->speaker = NULL;
        for (k = 0; k < r->mn; k++) {
            cJSON *o = bc_json_msg("goodbye");
            cJSON_AddStringToObject(o, "state", "left");
            cJSON_AddStringToObject(o, "reason", reason);
            send_take(r->m[k], o);
            bc_conn_close_soon(r->m[k], 1001, reason);
        }
        pthread_mutex_unlock(&r->mu);
    }
    pthread_mutex_unlock(&rooms_lock);
}

void bc_rooms_free_all(void)
{
    int i;
    pthread_mutex_lock(&rooms_lock);
    for (i = 0; i < rooms_n; i++) {
        rooms[i]->in_registry = 0;
        if (--rooms[i]->refs == 0) room_free(rooms[i]);
    }
    rooms_n = 0;
    free(rooms);
    rooms = NULL;
    rooms_cap = 0;
    pthread_mutex_unlock(&rooms_lock);
}

char *bc_rooms_metrics(void)
{
    bc_bytes b;
    char line[512];
    int i, clients;
    bc_u64 bin, bout;
    bc_bytes_init(&b);
    bc_net_stats(&clients, &bin, &bout);
    snprintf(line, sizeof(line),
             "# bcastd " BC_VERSION "\nbcast_clients %d\nbcast_bytes_in_total %llu\nbcast_bytes_out_total %llu\n",
             clients, (unsigned long long)bin, (unsigned long long)bout);
    bc_bytes_append(&b, line, strlen(line));
    pthread_mutex_lock(&rooms_lock);
    snprintf(line, sizeof(line), "bcast_rooms %d\n", rooms_n);
    bc_bytes_append(&b, line, strlen(line));
    for (i = 0; i < rooms_n; i++) {
        bc_room *r = rooms[i];
        int k, w = 0, in = 0, lag = 0;
        pthread_mutex_lock(&r->mu);
        for (k = 0; k < r->mn; k++) {
            if (r->m[k]->state == BC_ST_WAITING) w++;
            if (r->m[k]->state == BC_ST_IN_ROOM) { in++; if (!r->m[k]->synced && r->have_key && r->m[k] != r->speaker) lag++; }
        }
        snprintf(line, sizeof(line),
                 "bcast_room_waiting{room=\"%llu\"} %d\nbcast_room_in_room{room=\"%llu\"} %d\n"
                 "bcast_room_live{room=\"%llu\"} %d\nbcast_room_media_in_bytes{room=\"%llu\"} %llu\n"
                 "bcast_room_media_out_bytes{room=\"%llu\"} %llu\nbcast_room_drops{room=\"%llu\"} %llu\n"
                 "bcast_room_unsynced{room=\"%llu\"} %d\n",
                 (unsigned long long)r->id, w, (unsigned long long)r->id, in,
                 (unsigned long long)r->id, r->speaker && r->speaker->sub == BC_SUB_SPEAKING,
                 (unsigned long long)r->id, (unsigned long long)r->bytes_in,
                 (unsigned long long)r->id, (unsigned long long)r->bytes_out,
                 (unsigned long long)r->id, (unsigned long long)r->drops,
                 (unsigned long long)r->id, lag);
        pthread_mutex_unlock(&r->mu);
        bc_bytes_append(&b, line, strlen(line));
    }
    pthread_mutex_unlock(&rooms_lock);
    bc_bytes_append(&b, "", 1);
    return (char *)b.p;
}

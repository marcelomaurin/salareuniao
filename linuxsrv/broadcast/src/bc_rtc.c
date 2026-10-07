/* WebRTC DataChannel transport for the authenticated bcastd session.
 * SDP/ICE stays on WebSocket. Callbacks only enqueue bounded owner-worker jobs.
 * This transports the existing JSON/WebM protocol; it is NOT an RTP SFU.
 */
#include "config.h"
#include "bc_rtc.h"
#include "bc_config.h"
#include "bc_proto.h"
#include "bc_room.h"
#include "bc_util.h"
#include "bc_ws.h"
#include <stdlib.h>
#include <string.h>

#if HAVE_WEBRTC
#include <rtc/rtc.h>

#define RTC_CHUNK 16384
#define RTC_HEADER 12
#define RTC_SIGNAL 1
#define RTC_MESSAGE 2
#define RTC_OPEN 3
#define RTC_END 4

typedef struct bc_rtc {
    int pc, dc;
    bc_bytes fragments;
    size_t expected;
} bc_rtc;

typedef struct rtc_event {
    int kind, binary;
    size_t size;
    char data[1];
} rtc_event;

static void signal_json(bc_conn *c, cJSON *o)
{
    char *s = bc_json_take(o);
    bc_conn_send_signal(c, s);
    free(s);
}

static unsigned read32(const unsigned char *p)
{
    return ((unsigned)p[0] << 24) | ((unsigned)p[1] << 16) |
           ((unsigned)p[2] << 8) | p[3];
}

static void write32(unsigned char *p, size_t n)
{
    p[0] = (unsigned char)(n >> 24); p[1] = (unsigned char)(n >> 16);
    p[2] = (unsigned char)(n >> 8); p[3] = (unsigned char)n;
}

static void deliver_binary(bc_conn *c, const char *p, size_t n)
{
    bc_rtc *r = c->rtc;
    if (n >= 4 && memcmp(p, "BCF1", 4) == 0) {
        size_t total, off, count;
        if (n < RTC_HEADER) goto bad;
        total = read32((const unsigned char *)p + 4);
        off = read32((const unsigned char *)p + 8);
        count = n - RTC_HEADER;
        if (!total || total > g_cfg.max_frame_bytes || !count ||
            count > RTC_CHUNK || off > total || count > total - off) goto bad;
        if (off == 0) {
            if (r->expected) goto bad;
            r->expected = total;
            bc_bytes_clear(&r->fragments);
        }
        if (total != r->expected || off != r->fragments.len) goto bad;
        bc_bytes_append(&r->fragments, p + RTC_HEADER, count);
        if (r->fragments.len == total) {
            bc_net_message(c, BC_WS_BIN, r->fragments.p, total);
            r->expected = 0;
            bc_bytes_clear(&r->fragments);
        }
    } else {
        if (r->expected) goto bad;
        bc_net_message(c, BC_WS_BIN, (bc_u8 *)p, n);
    }
    return;
bad:
    bc_conn_close_soon(c, 1002, "invalid_rtc_fragment");
}

static void event_run(bc_conn *c, void *arg)
{
    rtc_event *e = arg;
    pthread_mutex_lock(&c->mu);
    c->rtc_pending -= sizeof(*e) + e->size;
    pthread_mutex_unlock(&c->mu);
    if (!bc_conn_closing(c) && c->rtc) {
        if (e->kind == RTC_SIGNAL) bc_conn_send_signal(c, e->data);
        else if (e->kind == RTC_OPEN) {
            cJSON *msg = bc_json_msg("webrtc.ready");
            pthread_mutex_lock(&c->mu);
            c->rtc_ready = 1;
            pthread_mutex_unlock(&c->mu);
            cJSON_AddNumberToObject(msg, "chunk_bytes", RTC_CHUNK);
            cJSON_AddNumberToObject(msg, "max_frame_bytes", (double)g_cfg.max_frame_bytes);
            cJSON_AddStringToObject(msg, "media", "webm");
            signal_json(c, msg);
        } else if (e->kind == RTC_END) {
            bc_conn_close_soon(c, 1011, "webrtc_closed");
        } else if (e->kind == RTC_MESSAGE) {
            c->last_rx_ms = bc_now_ms();
            if (e->binary) deliver_binary(c, e->data, e->size);
            else bc_net_message(c, BC_WS_TEXT, (bc_u8 *)e->data, e->size);
        }
    }
    free(e);
}

static void post(bc_conn *c, int kind, int binary, const char *p, size_t n)
{
    rtc_event *e;
    int overloaded = 0;
    pthread_mutex_lock(&c->mu);
    if (c->closed || c->close_after_flush) {
        pthread_mutex_unlock(&c->mu); return;
    }
    if (n > g_cfg.max_frame_bytes ||
        c->rtc_pending + sizeof(*e) + n > g_cfg.max_queue_bytes) overloaded = 1;
    else c->rtc_pending += sizeof(*e) + n;
    pthread_mutex_unlock(&c->mu);
    if (overloaded) { bc_conn_close_soon(c, 1009, "rtc_queue_limit"); return; }
    e = bc_xmalloc(sizeof(*e) + n);
    e->kind = kind; e->binary = binary; e->size = n;
    if (n) memcpy(e->data, p, n);
    e->data[n] = '\0';
    bc_worker_post(c, event_run, e);
}

static void local_sdp(int pc, const char *sdp, const char *type, void *ptr)
{
    cJSON *o = bc_json_msg("webrtc.answer");
    char *s;
    (void)pc;
    cJSON_AddStringToObject(o, "sdp", sdp);
    cJSON_AddStringToObject(o, "type", type);
    s = bc_json_take(o); post(ptr, RTC_SIGNAL, 0, s, strlen(s)); free(s);
}

static void local_ice(int pc, const char *candidate, const char *mid, void *ptr)
{
    cJSON *o = bc_json_msg("webrtc.candidate");
    char *s;
    (void)pc;
    cJSON_AddStringToObject(o, "candidate", candidate);
    cJSON_AddStringToObject(o, "mid", mid);
    s = bc_json_take(o); post(ptr, RTC_SIGNAL, 0, s, strlen(s)); free(s);
}

static void on_open(int id, void *ptr) { (void)id; post(ptr, RTC_OPEN, 0, "", 0); }
static void on_closed(int id, void *ptr) { (void)id; post(ptr, RTC_END, 0, "", 0); }
static void on_state(int id, rtcState state, void *ptr)
{
    (void)id;
    if (state == RTC_FAILED || state == RTC_CLOSED) post(ptr, RTC_END, 0, "", 0);
}
static void on_message(int id, const char *p, int size, void *ptr)
{
    size_t n;
    (void)id;
    n = size < 0 ? strlen(p) : (size_t)size;
    post(ptr, RTC_MESSAGE, size >= 0, p, n);
}

static void on_channel(int pc, int dc, void *ptr)
{
    bc_conn *c = ptr;
    bc_rtc *r = c->rtc;
    char label[64];
    rtcReliability rel;
    int accept;
    (void)pc;
    memset(&rel, 0, sizeof(rel));
    accept = rtcGetDataChannelLabel(dc, label, sizeof(label)) >= 0 &&
             (strcmp(label, "bcastd") == 0 || strcmp(label, "chatgpt") == 0) &&
             rtcGetDataChannelReliability(dc, &rel) >= 0 && !rel.unordered && !rel.unreliable;
    pthread_mutex_lock(&c->mu);
    accept = accept && !c->closed && r->dc < 0;
    if (accept) r->dc = dc;
    pthread_mutex_unlock(&c->mu);
    if (!accept) { rtcDeleteDataChannel(dc); return; }
    rtcSetUserPointer(dc, c);
    rtcSetMessageCallback(dc, on_message);
    rtcSetClosedCallback(dc, on_closed);
    rtcSetOpenCallback(dc, on_open);
    if (rtcIsOpen(dc)) on_open(dc, c);
}

int bc_rtc_signal(bc_conn *c, const char *type, cJSON *o)
{
    const char *sdp, *candidate, *mid;
    bc_rtc *r;
    rtcConfiguration cfg;
    const char *ice[1];
    int active;
    if (strncmp(type, "webrtc.", 7) != 0) return 0;
    if (!c->room) { bc_proto_error(c, NULL, "not_authenticated", "envie hello primeiro"); return 1; }
    pthread_mutex_lock(&c->room->mu);
    active = c->state == BC_ST_IN_ROOM;
    pthread_mutex_unlock(&c->room->mu);
    if (!active) { bc_proto_error(c, NULL, "not_allowed", "aguarde admissao na sala"); return 1; }
    if (strcmp(type, "webrtc.offer") == 0) {
        sdp = bc_json_str(o, "sdp", 60000);
        if (!sdp || !strstr(sdp, "m=application ") || strstr(sdp, "m=audio ") || strstr(sdp, "m=video ")) {
            bc_proto_error(c, NULL, "unsupported_sdp", "use DataChannel; tracks RTP nao suportadas"); return 1;
        }
        if (c->rtc) { bc_proto_error(c, NULL, "webrtc_exists", "reconecte para renegociar"); return 1; }
        memset(&cfg, 0, sizeof(cfg));
        cfg.disableAutoNegotiation = true;
        cfg.maxMessageSize = (int)g_cfg.max_frame_bytes;
        cfg.portRangeBegin = (uint16_t)g_cfg.rtc_port_begin;
        cfg.portRangeEnd = (uint16_t)g_cfg.rtc_port_end;
        if (g_cfg.rtc_bind_address[0]) cfg.bindAddress = g_cfg.rtc_bind_address;
        if (g_cfg.rtc_ice_server[0]) {
            ice[0] = g_cfg.rtc_ice_server; cfg.iceServers = ice; cfg.iceServersCount = 1;
        }
        r = bc_xcalloc(1, sizeof(*r));
        r->pc = rtcCreatePeerConnection(&cfg); r->dc = -1;
        if (r->pc < 0) { free(r); bc_proto_error(c, NULL, "webrtc_failed", "criacao do peer falhou"); return 1; }
        c->rtc = r;
        rtcSetUserPointer(r->pc, c);
        rtcSetLocalDescriptionCallback(r->pc, local_sdp);
        rtcSetLocalCandidateCallback(r->pc, local_ice);
        rtcSetDataChannelCallback(r->pc, on_channel);
        rtcSetStateChangeCallback(r->pc, on_state);
        if (rtcSetRemoteDescription(r->pc, sdp, "offer") < 0 ||
            rtcSetLocalDescription(r->pc, "answer") < 0) {
            bc_conn_close_soon(c, 1002, "invalid_rtc_offer");
        }
    } else if (strcmp(type, "webrtc.candidate") == 0) {
        candidate = bc_json_str(o, "candidate", 2048);
        mid = bc_json_str(o, "mid", 64);
        r = c->rtc;
        if (!r || !candidate || !mid || rtcAddRemoteCandidate(r->pc, candidate, mid) < 0)
            bc_proto_error(c, NULL, "bad_candidate", "envie a oferta antes do candidato ICE");
    } else bc_proto_error(c, NULL, "unknown_type", type);
    return 1;
}

int bc_rtc_send_frame(bc_conn *c, const bc_buf *b)
{
    bc_rtc *r = c->rtc;
    size_t h = 2, n, off, take;
    unsigned char chunk[RTC_HEADER + RTC_CHUNK];
    int dc, op;
    char *text;
    if (!r || b->len < 2) return -1;
    pthread_mutex_lock(&c->mu); dc = r->dc; pthread_mutex_unlock(&c->mu);
    if (dc < 0 || !rtcIsOpen(dc)) return -1;
    if (rtcGetBufferedAmount(dc) < 0 || (size_t)rtcGetBufferedAmount(dc) + b->len + 1024 > g_cfg.max_queue_bytes) return -1;
    op = b->data[0] & 15;
    if ((b->data[1] & 127) == 126) h = 4;
    else if ((b->data[1] & 127) == 127) h = 10;
    if (b->len < h) return -1;
    n = b->len - h;
    if (op == BC_WS_TEXT) {
        text = bc_xmalloc(n + 1); memcpy(text, b->data + h, n); text[n] = 0;
        op = rtcSendMessage(dc, text, -1); free(text); return op < 0 ? -1 : 0;
    }
    if (op != BC_WS_BIN) return -1;
    for (off = 0; off < n; off += take) {
        take = n - off; if (take > RTC_CHUNK) take = RTC_CHUNK;
        memcpy(chunk, "BCF1", 4); write32(chunk + 4, n); write32(chunk + 8, off);
        memcpy(chunk + RTC_HEADER, b->data + h + off, take);
        if (rtcSendMessage(dc, (const char *)chunk, (int)(RTC_HEADER + take)) < 0) return -1;
    }
    return 0;
}

void bc_rtc_destroy(bc_conn *c)
{
    bc_rtc *r = c->rtc;
    if (!r) return;
    /* c->closed is already set; deletion joins outstanding library callbacks. */
    rtcDeletePeerConnection(r->pc);
    if (r->dc >= 0) rtcDeleteDataChannel(r->dc);
    bc_bytes_free(&r->fragments);
    free(r); c->rtc = NULL;
}

void bc_rtc_cleanup(void) { rtcCleanup(); }

#else
int bc_rtc_signal(bc_conn *c, const char *type, cJSON *o)
{
    (void)o;
    if (strncmp(type, "webrtc.", 7) != 0) return 0;
    bc_proto_error(c, NULL, "webrtc_unavailable", "compile com --with-webrtc=yes");
    return 1;
}
int bc_rtc_send_frame(bc_conn *c, const bc_buf *b) { (void)c; (void)b; return -1; }
void bc_rtc_destroy(bc_conn *c) { (void)c; }
void bc_rtc_cleanup(void) { }
#endif

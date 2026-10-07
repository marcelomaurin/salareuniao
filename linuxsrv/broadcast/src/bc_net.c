/*
 * bc_net.c - listener, threads de E/S (epoll/poll), handshake e quadros WS,
 * fila de saida com backpressure, ping e timeouts.
 */
#include "config.h"
#include "bc_net.h"
#include "bc_ws.h"
#include "bc_config.h"
#include "bc_log.h"
#include "bc_util.h"
#include "bc_room.h"
#include "bc_proto.h"
#include "bc_rtc.h"

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <errno.h>
#include <unistd.h>
#include <fcntl.h>
#include <poll.h>
#include <sys/types.h>
#include <sys/socket.h>
#include <sys/uio.h>
#include <netinet/in.h>
#include <netinet/tcp.h>
#include <arpa/inet.h>
#if HAVE_EPOLL
#include <sys/epoll.h>
#endif
#if HAVE_EVENTFD
#include <sys/eventfd.h>
#endif

#define READ_CHUNK 65536
#define MAX_IOV    64

static int          listen_fd = -1;
static int          stop_pipe[2] = { -1, -1 };
static pthread_t    acc_th;
static bc_worker   *workers = NULL;
static int          nworkers = 0;
static int net_stop = 0;          /* protegido por stats_mu */

static pthread_mutex_t stats_mu = PTHREAD_MUTEX_INITIALIZER;
static int     st_clients = 0;
static bc_u64  st_in = 0, st_out = 0, st_next_id = 1;

/* ------------------------------------------------------------ utilitarios */

static int set_nonblock(int fd)
{
    int fl = fcntl(fd, F_GETFL, 0);
    if (fl < 0) return -1;
    if (fcntl(fd, F_SETFL, fl | O_NONBLOCK) < 0) return -1;
    fcntl(fd, F_SETFD, FD_CLOEXEC);
    return 0;
}

static void wake(bc_worker *w)
{
#if HAVE_EVENTFD
    bc_u64 one = 1;
    ssize_t r = write(w->evfd_w, &one, sizeof(one));
#else
    char ch = 1;
    ssize_t r = write(w->evfd_w, &ch, 1);
#endif
    (void)r;
}

static void drain_wake(bc_worker *w)
{
    char buf[64];
    while (read(w->evfd, buf, sizeof(buf)) > 0) {
        /* esvazia */
    }
}

static void poller_add(bc_worker *w, int fd, bc_conn *c, int out)
{
#if HAVE_EPOLL
    struct epoll_event ev;
    memset(&ev, 0, sizeof(ev));
    ev.events = EPOLLIN | EPOLLRDHUP | (out ? EPOLLOUT : 0);
    ev.data.ptr = c;
    epoll_ctl(w->epfd, EPOLL_CTL_ADD, fd, &ev);
#else
    (void)w; (void)fd; (void)c; (void)out;
#endif
}

static void poller_mod(bc_worker *w, bc_conn *c, int out)
{
#if HAVE_EPOLL
    struct epoll_event ev;
    memset(&ev, 0, sizeof(ev));
    ev.events = EPOLLIN | EPOLLRDHUP | (out ? EPOLLOUT : 0);
    ev.data.ptr = c;
    epoll_ctl(w->epfd, EPOLL_CTL_MOD, c->fd, &ev);
#else
    (void)w; (void)c; (void)out;
#endif
}

static void poller_del(bc_worker *w, bc_conn *c)
{
#if HAVE_EPOLL
    struct epoll_event ev;
    memset(&ev, 0, sizeof(ev));
    epoll_ctl(w->epfd, EPOLL_CTL_DEL, c->fd, &ev);
#else
    (void)w; (void)c;
#endif
}

static void arr_push(bc_conn ***arr, int *n, int *cap, bc_conn *c)
{
    if (*n == *cap) {
        *cap = *cap ? *cap * 2 : 16;
        *arr = (bc_conn **)bc_xrealloc(*arr, sizeof(bc_conn *) * (size_t)*cap);
    }
    (*arr)[(*n)++] = c;
}

/* ------------------------------------------------------------ conexao */

bc_conn *bc_conn_ref(bc_conn *c)
{
    pthread_mutex_lock(&c->mu);
    c->refs++;
    pthread_mutex_unlock(&c->mu);
    return c;
}

void bc_conn_unref(bc_conn *c)
{
    int left;
    if (!c) return;
    pthread_mutex_lock(&c->mu);
    left = --c->refs;
    pthread_mutex_unlock(&c->mu);
    if (left == 0) {
        bc_outq *q = c->oq_head;
        while (q) {
            bc_outq *n = q->next;
            bc_buf_unref(q->b);
            free(q);
            q = n;
        }
        bc_bytes_free(&c->in);
        bc_bytes_free(&c->frag);
        pthread_mutex_destroy(&c->mu);
        free(c);
    }
}

/* Marca a conexao para o worker tentar escrever (conn->mu presa). */
static void mark_dirty_locked(bc_conn *c)
{
    bc_worker *w = c->w;
    if (c->dirty_listed) return;
    c->dirty_listed = 1;
    c->refs++;
    pthread_mutex_lock(&w->mu);
    arr_push(&w->dirty, &w->dirty_n, &w->dirty_cap, c);
    pthread_mutex_unlock(&w->mu);
    wake(w);
}

static int enqueue(bc_conn *c, bc_buf *b, int media)
{
    bc_outq *q;
    pthread_mutex_lock(&c->mu);
    if (c->closed || c->close_after_flush) {
        pthread_mutex_unlock(&c->mu);
        bc_buf_unref(b);
        return media ? 0 : -1;
    }
    if (media > 0 && c->out_bytes + b->len > g_cfg.max_queue_bytes) {
        pthread_mutex_unlock(&c->mu);
        bc_buf_unref(b);
        return -1;
    }
    q = (bc_outq *)bc_xmalloc(sizeof(bc_outq));
    q->b = b;
    q->off = 0;
    q->rtc = media >= 0 && c->rtc_ready;
    q->next = NULL;
    if (c->oq_tail) c->oq_tail->next = q; else c->oq_head = q;
    c->oq_tail = q;
    c->out_bytes += b->len;
    mark_dirty_locked(c);
    pthread_mutex_unlock(&c->mu);
    return 0;
}

int bc_conn_send(bc_conn *c, bc_buf *b) { return enqueue(c, b, -1); }

int bc_conn_send_signal(bc_conn *c, const char *json)
{
    return enqueue(c, bc_ws_make_text(json), -1);
}

int bc_conn_send_text(bc_conn *c, const char *json)
{
    return enqueue(c, bc_ws_make_text(json), 0);
}

int bc_conn_send_media(bc_conn *c, bc_buf *b) { return enqueue(c, b, 1); }

size_t bc_conn_out_bytes(bc_conn *c)
{
    size_t n;
    pthread_mutex_lock(&c->mu);
    n = c->out_bytes;
    pthread_mutex_unlock(&c->mu);
    return n;
}

int bc_conn_closing(bc_conn *c)
{
    int v;
    pthread_mutex_lock(&c->mu);
    v = c->closed || c->close_after_flush;
    pthread_mutex_unlock(&c->mu);
    return v;
}

static int stopping(void)
{
    int v;
    pthread_mutex_lock(&stats_mu);
    v = net_stop;
    pthread_mutex_unlock(&stats_mu);
    return v;
}

void bc_conn_close_soon(bc_conn *c, int code, const char *reason)
{
    bc_buf *b = c->ws_open ? bc_ws_make_close(code, reason) : NULL;
    pthread_mutex_lock(&c->mu);
    if (c->closed || c->close_after_flush) {
        pthread_mutex_unlock(&c->mu);
        if (b) bc_buf_unref(b);
        return;
    }
    if (b) {
        bc_outq *q = (bc_outq *)bc_xmalloc(sizeof(bc_outq));
        q->b = b; q->off = 0; q->rtc = 0; q->next = NULL;
        if (c->oq_tail) c->oq_tail->next = q; else c->oq_head = q;
        c->oq_tail = q;
        c->out_bytes += b->len;
    }
    c->close_after_flush = 1;
    mark_dirty_locked(c);
    pthread_mutex_unlock(&c->mu);
}

void bc_worker_post(bc_conn *c, bc_task_fn fn, void *arg)
{
    bc_worker *w = c->w;
    bc_task *t = (bc_task *)bc_xmalloc(sizeof(bc_task));
    t->fn = fn;
    t->c = bc_conn_ref(c);
    t->arg = arg;
    t->next = NULL;
    pthread_mutex_lock(&w->mu);
    if (w->task_tail) w->task_tail->next = t; else w->task_head = t;
    w->task_tail = t;
    pthread_mutex_unlock(&w->mu);
    wake(w);
}

/* Destroi a conexao (somente na thread dona). */
static void conn_destroy(bc_worker *w, bc_conn *c, const char *why)
{
    int i;
    if (c->fd < 0) return;
    BC_LOG_DEBUG("conn %llu fechada (%s)", (unsigned long long)c->id, why);
    if (c->room) bc_room_conn_gone(c, BC_EV_SOCK_CLOSED, why);
    poller_del(w, c);
    close(c->fd);
    c->fd = -1;
    pthread_mutex_lock(&c->mu);
    c->closed = 1;
    pthread_mutex_unlock(&c->mu);
    bc_rtc_destroy(c);
    for (i = 0; i < w->conns_n; i++) {
        if (w->conns[i] == c) {
            w->conns[i] = w->conns[--w->conns_n];
            break;
        }
    }
    pthread_mutex_lock(&stats_mu);
    st_clients--;
    pthread_mutex_unlock(&stats_mu);
    bc_conn_unref(c);
}

/* Escreve o que puder. Retorna -1 se a conexao deve ser destruida. */
static int conn_flush(bc_worker *w, bc_conn *c)
{
    int want_close = 0, rc = 0;
    if (c->fd < 0) return 0;
    pthread_mutex_lock(&c->mu);
    while (c->oq_head) {
        struct iovec iov[MAX_IOV];
        int n = 0;
        bc_outq *q = c->oq_head;
        ssize_t wr;
        if (q->rtc) {
            int sent;
            pthread_mutex_unlock(&c->mu);
            sent = bc_rtc_send_frame(c, q->b);
            pthread_mutex_lock(&c->mu);
            if (sent < 0) { rc = -1; break; }
            c->oq_head = q->next;
            if (!c->oq_head) c->oq_tail = NULL;
            c->out_bytes -= q->b->len;
            pthread_mutex_lock(&stats_mu);
            st_out += q->b->len;
            pthread_mutex_unlock(&stats_mu);
            bc_buf_unref(q->b); free(q);
            continue;
        }
        while (q && !q->rtc && n < MAX_IOV) {
            iov[n].iov_base = q->b->data + q->off;
            iov[n].iov_len = q->b->len - q->off;
            n++;
            q = q->next;
        }
        wr = writev(c->fd, iov, n);
        if (wr < 0) {
            if (errno == EINTR) continue;
            if (errno == EAGAIN || errno == EWOULDBLOCK) break;
            rc = -1;
            break;
        }
        pthread_mutex_lock(&stats_mu);
        st_out += (bc_u64)wr;
        pthread_mutex_unlock(&stats_mu);
        while (wr > 0 && c->oq_head) {
            bc_outq *h = c->oq_head;
            size_t left = h->b->len - h->off;
            if ((size_t)wr >= left) {
                wr -= (ssize_t)left;
                c->out_bytes -= left;
                c->oq_head = h->next;
                if (!c->oq_head) c->oq_tail = NULL;
                bc_buf_unref(h->b);
                free(h);
            } else {
                h->off += (size_t)wr;
                c->out_bytes -= (size_t)wr;
                wr = 0;
            }
        }
    }
    if (rc == 0) {
        int need_out = c->oq_head != NULL;
        if (need_out != c->epollout) {
            c->epollout = need_out;
            poller_mod(w, c, need_out);
        }
        if (!c->oq_head && c->close_after_flush) want_close = 1;
    }
    pthread_mutex_unlock(&c->mu);
    if (rc < 0 || want_close) return -1;
    return 0;
}

/* ------------------------------------------------------------ handshake */

static void http_error(bc_conn *c, int code)
{
    char resp[256];
    const char *msg = code == 403 ? "Forbidden" : code == 426 ? "Upgrade Required" :
                      code == 431 ? "Request Header Fields Too Large" : "Bad Request";
    bc_buf *b;
    snprintf(resp, sizeof(resp),
             "HTTP/1.1 %d %s\r\nContent-Length: 0\r\nConnection: close\r\n%s\r\n",
             code, msg, code == 426 ? "Sec-WebSocket-Version: 13\r\n" : "");
    b = bc_buf_new(strlen(resp), 0);
    memcpy(b->data, resp, strlen(resp));
    bc_conn_send(c, b);
    c->state = BC_ST_CLOSED;
    pthread_mutex_lock(&c->mu);
    c->close_after_flush = 1;
    mark_dirty_locked(c);
    pthread_mutex_unlock(&c->mu);
}

static int origin_allowed(const char *origin)
{
    const char *p = g_cfg.allowed_origin;
    size_t ol;
    if (!*p) return 1;                 /* sem lista = qualquer origem */
    if (!*origin) return 0;
    ol = strlen(origin);
    while (*p) {
        const char *e;
        size_t n;
        while (*p == ' ' || *p == ',') p++;
        e = p;
        while (*e && *e != ',') e++;
        n = (size_t)(e - p);
        while (n > 0 && p[n - 1] == ' ') n--;
        if (n == ol && strncmp(p, origin, n) == 0) return 1;
        if (n == 1 && *p == '*') return 1;
        p = e;
    }
    return 0;
}

/* Responde /healthz e /metrics (somente conexoes locais, sem WebSocket). */
static int try_plain_http(bc_conn *c, const char *buf)
{
    const char *body = NULL;
    char *dyn = NULL;
    char hdr[256];
    bc_buf *b;
    size_t bl;
    if (strncmp(buf, "GET /healthz ", 13) == 0) body = "ok\n";
    else if (strncmp(buf, "GET /metrics ", 13) == 0) body = dyn = bc_rooms_metrics();
    else return 0;
    if (strcmp(c->ip, "127.0.0.1") != 0 && strcmp(c->ip, "::1") != 0) {
        free(dyn);
        http_error(c, 403);
        return 1;
    }
    bl = strlen(body);
    snprintf(hdr, sizeof(hdr), "HTTP/1.1 200 OK\r\nContent-Type: text/plain; charset=utf-8\r\n"
             "Content-Length: %lu\r\nConnection: close\r\n\r\n", (unsigned long)bl);
    b = bc_buf_new(strlen(hdr) + bl, 0);
    memcpy(b->data, hdr, strlen(hdr));
    memcpy(b->data + strlen(hdr), body, bl);
    free(dyn);
    bc_conn_send(c, b);
    c->state = BC_ST_CLOSED;
    pthread_mutex_lock(&c->mu);
    c->close_after_flush = 1;
    mark_dirty_locked(c);
    pthread_mutex_unlock(&c->mu);
    return 1;
}

static void pick_ip(bc_conn *c, const bc_http_req *r)
{
    if (!g_cfg.trust_proxy) return;
    if (r->real_ip[0]) {
        bc_strlcpy(c->ip, r->real_ip, sizeof(c->ip));
    } else if (r->xff[0]) {
        /* ultimo salto = endereco que o proxy confiavel viu */
        const char *last = strrchr(r->xff, ',');
        last = last ? last + 1 : r->xff;
        while (*last == ' ') last++;
        bc_strlcpy(c->ip, last, sizeof(c->ip));
    }
}

static int do_handshake(bc_conn *c)
{
    bc_http_req req;
    int r;
    bc_bytes_append(&c->in, "", 1);        /* garante terminador para strstr */
    c->in.len--;
    if (c->in.len >= 13 && try_plain_http(c, (const char *)c->in.p)) return 0;
    r = bc_http_parse_upgrade((const char *)c->in.p, c->in.len, &req);
    if (r == 0) return 0;
    if (r < 0) {
        http_error(c, -r);
        return 0;
    }
    pick_ip(c, &req);
    if (!origin_allowed(req.origin)) {
        BC_LOG_WARN("origem recusada: '%s' (ip %s)", req.origin, c->ip);
        http_error(c, 403);
        return 0;
    }
    bc_strlcpy(c->origin, req.origin, sizeof(c->origin));
    bc_strlcpy(c->ua, req.user_agent, sizeof(c->ua));
    {
        char resp[512];
        bc_buf *b;
        bc_ws_accept_response(req.key, resp, sizeof(resp));
        b = bc_buf_new(strlen(resp), 0);
        memcpy(b->data, resp, strlen(resp));
        bc_conn_send(c, b);
    }
    c->ws_open = 1;
    c->state = (bc_state)bc_fsm_next(c->state, BC_EV_WS_OK);
    bc_bytes_consume(&c->in, (size_t)r);
    return 1;
}

/* ------------------------------------------------------------ quadros */

static int rate_ok(bc_conn *c, bc_u64 now)
{
    if (now - c->rate_win_ms >= 1000) {
        c->rate_win_ms = now;
        c->rate_msgs = 0;
        c->rate_chat = 0;
    }
    c->rate_msgs++;
    return c->rate_msgs <= 40;
}

static void handle_message(bc_conn *c, int op, bc_u8 *p, size_t n, bc_u64 now)
{
    if (op == BC_WS_TEXT) {
        if (!bc_utf8_valid(p, n)) {
            bc_conn_close_soon(c, 1007, "invalid_utf8");
            return;
        }
        if (!rate_ok(c, now)) {
            bc_proto_error(c, NULL, "rate_limited", "muitas mensagens por segundo");
            return;
        }
        bc_proto_text(c, (const char *)p, n);
    } else {
        if (now - c->media_win_ms >= 1000) { c->media_win_ms = now; c->media_bytes = 0; }
        c->media_bytes += n;
        if (c->media_bytes > 1500000) return;     /* ~12 Mbit/s: descarta excesso */
        if (c->room) bc_room_on_media(c, p, n);
    }
}

void bc_net_message(bc_conn *c, int op, bc_u8 *p, size_t n)
{
    if (bc_conn_closing(c) || n > g_cfg.max_frame_bytes) return;
    pthread_mutex_lock(&stats_mu);
    st_in += (bc_u64)n;
    pthread_mutex_unlock(&stats_mu);
    handle_message(c, op, p, n, bc_now_ms());
}

static int process_frames(bc_conn *c, bc_u64 now)
{
    for (;;) {
        bc_ws_frame f;
        long r = bc_ws_parse(c->in.p, c->in.len, g_cfg.max_frame_bytes, &f);
        if (r == 0) break;
        if (r == -2) { bc_conn_close_soon(c, 1009, "frame_too_large"); return -1; }
        if (r < 0)   { bc_conn_close_soon(c, 1002, "protocol_error"); return -1; }
        switch (f.opcode) {
        case BC_WS_PING:
            bc_conn_send(c, bc_ws_make(BC_WS_PONG, f.payload, f.len, NULL, 0, 0));
            break;
        case BC_WS_PONG:
            break;
        case BC_WS_CLOSE:
            bc_conn_close_soon(c, 1000, "bye");
            if (c->room) bc_room_conn_gone(c, BC_EV_BYE, "closed_by_client");
            return -1;
        case BC_WS_TEXT:
        case BC_WS_BIN:
            if (c->frag_op) { bc_conn_close_soon(c, 1002, "unexpected_frame"); return -1; }
            if (f.fin) {
                handle_message(c, f.opcode, f.payload, f.len, now);
            } else {
                c->frag_op = f.opcode;
                bc_bytes_clear(&c->frag);
                bc_bytes_append(&c->frag, f.payload, f.len);
            }
            break;
        case BC_WS_CONT:
            if (!c->frag_op) { bc_conn_close_soon(c, 1002, "unexpected_continuation"); return -1; }
            if (c->frag.len + f.len > g_cfg.max_frame_bytes) {
                bc_conn_close_soon(c, 1009, "frame_too_large");
                return -1;
            }
            bc_bytes_append(&c->frag, f.payload, f.len);
            if (f.fin) {
                int op = c->frag_op;
                c->frag_op = 0;
                handle_message(c, op, c->frag.p, c->frag.len, now);
                bc_bytes_clear(&c->frag);
            }
            break;
        default:
            bc_conn_close_soon(c, 1002, "bad_opcode");
            return -1;
        }
        bc_bytes_consume(&c->in, (size_t)r);
        if (bc_conn_closing(c)) return -1;
    }
    return 0;
}

/* Retorna -1 se a conexao deve ser destruida agora. */
static int conn_read(bc_worker *w, bc_conn *c)
{
    bc_u8 buf[READ_CHUNK];
    bc_u64 now = bc_now_ms();
    (void)w;
    for (;;) {
        ssize_t r = read(c->fd, buf, sizeof(buf));
        if (r == 0) return -1;
        if (r < 0) {
            if (errno == EINTR) continue;
            if (errno == EAGAIN || errno == EWOULDBLOCK) break;
            return -1;
        }
        pthread_mutex_lock(&stats_mu);
        st_in += (bc_u64)r;
        pthread_mutex_unlock(&stats_mu);
        c->last_rx_ms = now;
        if (bc_conn_closing(c)) continue;      /* descarta apos pedido de fechamento */
        bc_bytes_append(&c->in, buf, (size_t)r);
        if (!c->ws_open) {
            if (!do_handshake(c)) {
                if (c->in.len >= BC_HTTP_MAX_HEADER && !bc_conn_closing(c)) http_error(c, 431);
                continue;
            }
        }
        if (c->ws_open && process_frames(c, now) < 0) {
            bc_bytes_clear(&c->in);
        }
        if (c->in.len > g_cfg.max_frame_bytes + 64 && !bc_conn_closing(c)) {
            bc_conn_close_soon(c, 1009, "frame_too_large");
        }
    }
    return 0;
}

/* ------------------------------------------------------------ timeouts */

static void check_timeouts(bc_worker *w, bc_u64 now)
{
    int i;
    for (i = w->conns_n - 1; i >= 0; i--) {
        bc_conn *c = w->conns[i];
        if (bc_conn_closing(c)) continue;
        if (!c->room && !c->auth_pending && now - c->created_ms > g_cfg.hello_timeout_ms) {
            if (c->ws_open) bc_proto_error(c, NULL, "hello_timeout", "hello nao recebido a tempo");
            bc_conn_close_soon(c, 1008, "hello_timeout");
            continue;
        }
        if (now - c->last_rx_ms > g_cfg.idle_timeout_ms) {
            if (c->room) bc_room_conn_gone(c, BC_EV_TIMEOUT, "idle_timeout");
            bc_conn_close_soon(c, 1001, "idle_timeout");
            continue;
        }
        if (c->ws_open && now - c->last_ping_ms >= g_cfg.ping_interval_ms) {
            c->last_ping_ms = now;
            bc_conn_send(c, bc_ws_make(BC_WS_PING, "bc", 2, NULL, 0, 0));
        }
        if (c->room) bc_room_check_timeouts(c, now);
    }
}

/* ------------------------------------------------------------ worker */

static void take_new(bc_worker *w)
{
    bc_conn **nc;
    int n, i;
    pthread_mutex_lock(&w->mu);
    nc = w->newc; n = w->newc_n;
    w->newc = NULL; w->newc_n = 0; w->newc_cap = 0;
    pthread_mutex_unlock(&w->mu);
    for (i = 0; i < n; i++) {
        arr_push(&w->conns, &w->conns_n, &w->conns_cap, nc[i]);
        poller_add(w, nc[i]->fd, nc[i], 0);
    }
    free(nc);
}

static void run_tasks(bc_worker *w)
{
    bc_task *t;
    pthread_mutex_lock(&w->mu);
    t = w->task_head;
    w->task_head = w->task_tail = NULL;
    pthread_mutex_unlock(&w->mu);
    while (t) {
        bc_task *n = t->next;
        t->fn(t->c, t->arg);
        bc_conn_unref(t->c);
        free(t);
        t = n;
    }
}

static void flush_dirty(bc_worker *w)
{
    bc_conn **d;
    int n, i;
    pthread_mutex_lock(&w->mu);
    d = w->dirty; n = w->dirty_n;
    w->dirty = NULL; w->dirty_n = 0; w->dirty_cap = 0;
    pthread_mutex_unlock(&w->mu);
    for (i = 0; i < n; i++) {
        bc_conn *c = d[i];
        pthread_mutex_lock(&c->mu);
        c->dirty_listed = 0;
        pthread_mutex_unlock(&c->mu);
        if (c->fd >= 0 && conn_flush(w, c) < 0) conn_destroy(w, c, "flush");
        bc_conn_unref(c);
    }
    free(d);
}

#if !HAVE_EPOLL
static void poll_loop_once(bc_worker *w, int timeout_ms)
{
    struct pollfd *pf;
    bc_conn **snap;
    int n = w->conns_n, i, r;
    pf = (struct pollfd *)bc_xcalloc((size_t)n + 1, sizeof(struct pollfd));
    snap = (bc_conn **)bc_xcalloc((size_t)n + 1, sizeof(bc_conn *));
    pf[0].fd = w->evfd; pf[0].events = POLLIN;
    for (i = 0; i < n; i++) {
        snap[i] = bc_conn_ref(w->conns[i]);
        pf[i + 1].fd = w->conns[i]->fd;
        pf[i + 1].events = POLLIN | (w->conns[i]->epollout ? POLLOUT : 0);
    }
    r = poll(pf, (nfds_t)n + 1, timeout_ms);
    if (r > 0) {
        if (pf[0].revents) drain_wake(w);
        for (i = 0; i < n; i++) {
            bc_conn *c = snap[i];
            if (c->fd < 0 || !pf[i + 1].revents) continue;
            if (pf[i + 1].revents & (POLLIN | POLLHUP | POLLERR)) {
                if (conn_read(w, c) < 0) { conn_destroy(w, c, "read"); continue; }
            }
            if (c->fd >= 0 && conn_flush(w, c) < 0) conn_destroy(w, c, "write");
        }
    }
    for (i = 0; i < n; i++) bc_conn_unref(snap[i]);
    free(pf);
    free(snap);
}
#endif

static void *worker_main(void *arg)
{
    bc_worker *w = (bc_worker *)arg;
    bc_u64 last_tick = 0;
    bc_log_set_thread_name(w->name);
    while (!stopping()) {
        bc_u64 now;
#if HAVE_EPOLL
        struct epoll_event evs[128];
        int n, i;
        n = epoll_wait(w->epfd, evs, 128, 250);
        for (i = 0; i < n; i++) {
            bc_conn *c = (bc_conn *)evs[i].data.ptr;
            if (c == NULL) { drain_wake(w); continue; }
            if (c->fd < 0) continue;
            bc_conn_ref(c);
            if (evs[i].events & (EPOLLIN | EPOLLRDHUP | EPOLLHUP | EPOLLERR)) {
                if (conn_read(w, c) < 0) { conn_destroy(w, c, "read"); bc_conn_unref(c); continue; }
            }
            if (c->fd >= 0 && conn_flush(w, c) < 0) conn_destroy(w, c, "write");
            bc_conn_unref(c);
        }
#else
        poll_loop_once(w, 250);
#endif
        take_new(w);
        run_tasks(w);
        flush_dirty(w);
        now = bc_now_ms();
        if (now - last_tick >= 250) {
            last_tick = now;
            check_timeouts(w, now);
            flush_dirty(w);
        }
    }
    /* desligamento: tarefas pendentes e fechamento */
    take_new(w);
    run_tasks(w);
    flush_dirty(w);
    while (w->conns_n > 0) conn_destroy(w, w->conns[w->conns_n - 1], "shutdown");
    run_tasks(w);
    return NULL;
}

/* ------------------------------------------------------------ aceitacao */

static void *acceptor_main(void *arg)
{
    int rr = 0;
    (void)arg;
    bc_log_set_thread_name("accept");
    while (!stopping()) {
        struct pollfd pf[2];
        struct sockaddr_storage ss;
        socklen_t sl = sizeof(ss);
        int fd, one = 1, full;
        bc_conn *c;
        bc_worker *w;

        pf[0].fd = listen_fd; pf[0].events = POLLIN;
        pf[1].fd = stop_pipe[0]; pf[1].events = POLLIN;
        if (poll(pf, 2, 1000) <= 0) continue;
        if (pf[1].revents) break;
        fd = accept(listen_fd, (struct sockaddr *)&ss, &sl);
        if (fd < 0) {
            if (errno == EMFILE || errno == ENFILE) bc_sleep_ms(100);
            continue;
        }
        pthread_mutex_lock(&stats_mu);
        full = st_clients >= g_cfg.max_clients;
        if (!full) st_clients++;
        pthread_mutex_unlock(&stats_mu);
        if (full) {
            BC_LOG_WARN("max_clients atingido; conexao recusada");
            close(fd);
            continue;
        }
        set_nonblock(fd);
        setsockopt(fd, IPPROTO_TCP, TCP_NODELAY, &one, sizeof(one));
        {
            int sb = 262144;
            setsockopt(fd, SOL_SOCKET, SO_SNDBUF, &sb, sizeof(sb));
        }
        c = (bc_conn *)bc_xcalloc(1, sizeof(bc_conn));
        pthread_mutex_init(&c->mu, NULL);
        c->refs = 1;
        c->fd = fd;
        pthread_mutex_lock(&stats_mu);
        c->id = st_next_id++;
        pthread_mutex_unlock(&stats_mu);
        c->created_ms = c->last_rx_ms = c->last_ping_ms = bc_now_ms();
        c->state = (bc_state)bc_fsm_next(BC_ST_NEW, BC_EV_TCP_OPEN);
        bc_bytes_init(&c->in);
        bc_bytes_init(&c->frag);
        if (ss.ss_family == AF_INET) {
            inet_ntop(AF_INET, &((struct sockaddr_in *)&ss)->sin_addr, c->ip, sizeof(c->ip));
        } else if (ss.ss_family == AF_INET6) {
            inet_ntop(AF_INET6, &((struct sockaddr_in6 *)&ss)->sin6_addr, c->ip, sizeof(c->ip));
        } else {
            bc_strlcpy(c->ip, "unknown", sizeof(c->ip));
        }
        w = &workers[rr];
        rr = (rr + 1) % nworkers;
        c->w = w;
        pthread_mutex_lock(&w->mu);
        arr_push(&w->newc, &w->newc_n, &w->newc_cap, c);
        pthread_mutex_unlock(&w->mu);
        wake(w);
    }
    return NULL;
}

/* ------------------------------------------------------------ inicio/fim */

static int open_listener(void)
{
    struct sockaddr_in a4;
    struct sockaddr_in6 a6;
    int one = 1, fd;
    if (strchr(g_cfg.listen_addr, ':')) {
        fd = socket(AF_INET6, SOCK_STREAM, 0);
        if (fd < 0) return -1;
        memset(&a6, 0, sizeof(a6));
        a6.sin6_family = AF_INET6;
        a6.sin6_port = htons((unsigned short)g_cfg.listen_port);
        if (inet_pton(AF_INET6, g_cfg.listen_addr, &a6.sin6_addr) != 1) { close(fd); return -1; }
        setsockopt(fd, SOL_SOCKET, SO_REUSEADDR, &one, sizeof(one));
        if (bind(fd, (struct sockaddr *)&a6, sizeof(a6)) < 0) { close(fd); return -1; }
    } else {
        fd = socket(AF_INET, SOCK_STREAM, 0);
        if (fd < 0) return -1;
        memset(&a4, 0, sizeof(a4));
        a4.sin_family = AF_INET;
        a4.sin_port = htons((unsigned short)g_cfg.listen_port);
        if (inet_pton(AF_INET, g_cfg.listen_addr, &a4.sin_addr) != 1) { close(fd); return -1; }
        setsockopt(fd, SOL_SOCKET, SO_REUSEADDR, &one, sizeof(one));
        if (bind(fd, (struct sockaddr *)&a4, sizeof(a4)) < 0) { close(fd); return -1; }
    }
    if (listen(fd, 512) < 0) { close(fd); return -1; }
    set_nonblock(fd);
    return fd;
}

int bc_net_start(void)
{
    int i;
    listen_fd = open_listener();
    if (listen_fd < 0) {
        BC_LOG_ERROR("nao foi possivel escutar em %s:%d: %s", g_cfg.listen_addr, g_cfg.listen_port, strerror(errno));
        return -1;
    }
    if (pipe(stop_pipe) != 0) return -1;
    pthread_mutex_lock(&stats_mu);
    net_stop = 0;
    pthread_mutex_unlock(&stats_mu);
    nworkers = g_cfg.io_threads;
    workers = (bc_worker *)bc_xcalloc((size_t)nworkers, sizeof(bc_worker));
    for (i = 0; i < nworkers; i++) {
        bc_worker *w = &workers[i];
        w->idx = i;
        snprintf(w->name, sizeof(w->name), "io-%d", i);
        pthread_mutex_init(&w->mu, NULL);
#if HAVE_EVENTFD
        w->evfd = w->evfd_w = eventfd(0, EFD_NONBLOCK | EFD_CLOEXEC);
        if (w->evfd < 0) return -1;
#else
        {
            int p[2];
            if (pipe(p) != 0) return -1;
            set_nonblock(p[0]); set_nonblock(p[1]);
            w->evfd = p[0]; w->evfd_w = p[1];
        }
#endif
#if HAVE_EPOLL
        w->epfd = epoll_create(1024);
        if (w->epfd < 0) return -1;
        {
            struct epoll_event ev;
            memset(&ev, 0, sizeof(ev));
            ev.events = EPOLLIN;
            ev.data.ptr = NULL;
            epoll_ctl(w->epfd, EPOLL_CTL_ADD, w->evfd, &ev);
        }
#endif
        if (pthread_create(&w->th, NULL, worker_main, w) != 0) return -1;
    }
    if (pthread_create(&acc_th, NULL, acceptor_main, NULL) != 0) return -1;
    BC_LOG_INFO("escutando em %s:%d com %d threads de E/S (%s)", g_cfg.listen_addr,
                g_cfg.listen_port, nworkers, HAVE_EPOLL ? "epoll" : "poll");
    return 0;
}

void bc_net_stop_accept(void)
{
    char ch = 1;
    ssize_t r;
    if (stop_pipe[1] < 0) return;
    r = write(stop_pipe[1], &ch, 1);
    (void)r;
    pthread_join(acc_th, NULL);
    close(listen_fd);
    listen_fd = -1;
}

void bc_net_stop(void)
{
    int i;
    if (!workers) return;
    if (listen_fd >= 0) bc_net_stop_accept();
    pthread_mutex_lock(&stats_mu);
    net_stop = 1;
    pthread_mutex_unlock(&stats_mu);
    for (i = 0; i < nworkers; i++) wake(&workers[i]);
    for (i = 0; i < nworkers; i++) {
        bc_worker *w = &workers[i];
        pthread_join(w->th, NULL);
#if HAVE_EPOLL
        close(w->epfd);
#endif
        close(w->evfd);
        if (w->evfd_w != w->evfd) close(w->evfd_w);
        free(w->conns);
        free(w->newc);
        free(w->dirty);
        pthread_mutex_destroy(&w->mu);
    }
    free(workers);
    workers = NULL;
    close(stop_pipe[0]);
    close(stop_pipe[1]);
    stop_pipe[0] = stop_pipe[1] = -1;
}

void bc_net_stats(int *clients, bc_u64 *bytes_in, bc_u64 *bytes_out)
{
    pthread_mutex_lock(&stats_mu);
    if (clients) *clients = st_clients;
    if (bytes_in) *bytes_in = st_in;
    if (bytes_out) *bytes_out = st_out;
    pthread_mutex_unlock(&stats_mu);
}

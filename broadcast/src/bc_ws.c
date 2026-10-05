/*
 * bc_ws.c - handshake HTTP Upgrade e quadros WebSocket (RFC 6455)
 */
#include "config.h"
#include "bc_ws.h"
#include "bc_crypto.h"
#include "bc_util.h"

#include <stdio.h>
#include <string.h>
#include <ctype.h>
#include <stdlib.h>

static const char *WS_GUID = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11";

static int ieq_prefix(const char *a, const char *b, size_t n)
{
    size_t i;
    for (i = 0; i < n; i++) {
        if (tolower((unsigned char)a[i]) != tolower((unsigned char)b[i])) return 0;
    }
    return 1;
}

/* Verifica se a lista separada por virgula contem o token (sem diferenciar maiusculas). */
static int list_has_token(const char *v, const char *tok)
{
    size_t tl = strlen(tok);
    const char *p = v;
    while (*p) {
        const char *e;
        size_t n;
        while (*p == ' ' || *p == ',' || *p == '\t') p++;
        e = p;
        while (*e && *e != ',') e++;
        n = (size_t)(e - p);
        while (n > 0 && (p[n - 1] == ' ' || p[n - 1] == '\t')) n--;
        if (n == tl && ieq_prefix(p, tok, tl)) return 1;
        p = e;
    }
    return 0;
}

static void copy_val(char *dst, size_t dmax, const char *v, size_t n)
{
    while (n > 0 && (*v == ' ' || *v == '\t')) { v++; n--; }
    while (n > 0 && (v[n - 1] == ' ' || v[n - 1] == '\t' || v[n - 1] == '\r')) n--;
    if (n >= dmax) n = dmax - 1;
    memcpy(dst, v, n);
    dst[n] = '\0';
}

int bc_http_parse_upgrade(const char *buf, size_t len, bc_http_req *req)
{
    const char *end = NULL, *p, *line_end;
    size_t i, hdr_len;
    char tmp[512];

    for (i = 3; i < len; i++) {
        if (buf[i - 3] == '\r' && buf[i - 2] == '\n' && buf[i - 1] == '\r' && buf[i] == '\n') {
            end = buf + i + 1;
            break;
        }
    }
    if (!end) {
        if (len >= BC_HTTP_MAX_HEADER) return -431;
        return 0;
    }
    hdr_len = (size_t)(end - buf);
    memset(req, 0, sizeof(*req));

    /* Linha de requisicao: GET <path> HTTP/1.1 */
    line_end = strstr(buf, "\r\n");
    if (!line_end || line_end - buf < 14 || strncmp(buf, "GET ", 4) != 0) return -400;
    {
        const char *sp = memchr(buf + 4, ' ', (size_t)(line_end - buf - 4));
        if (!sp) return -400;
        copy_val(req->path, sizeof(req->path), buf + 4, (size_t)(sp - buf - 4));
        if (strncmp(sp + 1, "HTTP/1.1", 8) != 0) return -400;
    }

    p = line_end + 2;
    while (p < end - 2) {
        const char *colon;
        size_t nlen, vlen;
        line_end = strstr(p, "\r\n");
        if (!line_end || line_end > end) return -400;
        colon = memchr(p, ':', (size_t)(line_end - p));
        if (!colon) return -400;
        nlen = (size_t)(colon - p);
        vlen = (size_t)(line_end - colon - 1);
#define HDR(name) (nlen == sizeof(name) - 1 && ieq_prefix(p, name, nlen))
        if (HDR("Upgrade")) {
            copy_val(tmp, sizeof(tmp), colon + 1, vlen);
            req->upgrade_ok = list_has_token(tmp, "websocket");
        } else if (HDR("Connection")) {
            copy_val(tmp, sizeof(tmp), colon + 1, vlen);
            req->connection_ok = list_has_token(tmp, "upgrade");
        } else if (HDR("Sec-WebSocket-Key")) {
            copy_val(req->key, sizeof(req->key), colon + 1, vlen);
        } else if (HDR("Sec-WebSocket-Version")) {
            copy_val(tmp, sizeof(tmp), colon + 1, vlen);
            req->version = atoi(tmp);
        } else if (HDR("Origin")) {
            copy_val(req->origin, sizeof(req->origin), colon + 1, vlen);
        } else if (HDR("X-Forwarded-For")) {
            copy_val(req->xff, sizeof(req->xff), colon + 1, vlen);
        } else if (HDR("X-Real-IP")) {
            copy_val(req->real_ip, sizeof(req->real_ip), colon + 1, vlen);
        } else if (HDR("User-Agent")) {
            copy_val(req->user_agent, sizeof(req->user_agent), colon + 1, vlen);
        }
#undef HDR
        p = line_end + 2;
    }

    if (!req->upgrade_ok || !req->connection_ok) return -400;
    if (req->version != 13) return -426;
    if (strlen(req->key) != 24) return -400;
    {
        bc_u8 raw[20];
        if (bc_base64_decode(req->key, 24, raw, sizeof(raw), 0) != 16) return -400;
    }
    return (int)hdr_len;
}

void bc_ws_accept_key(const char *key, char out[32])
{
    char cat[128];
    bc_u8 dig[20];
    size_t n = strlen(key);
    if (n > 64) n = 64;
    memcpy(cat, key, n);
    memcpy(cat + n, WS_GUID, 36);
    bc_sha1(cat, n + 36, dig);
    bc_base64_encode(dig, 20, out);
}

void bc_ws_accept_response(const char *key, char *out, size_t outlen)
{
    char acc[32];
    bc_ws_accept_key(key, acc);
    snprintf(out, outlen,
             "HTTP/1.1 101 Switching Protocols\r\n"
             "Upgrade: websocket\r\n"
             "Connection: Upgrade\r\n"
             "Sec-WebSocket-Accept: %s\r\n"
             "Server: bcastd/" BC_VERSION "\r\n\r\n", acc);
}

long bc_ws_parse(bc_u8 *buf, size_t len, size_t max, bc_ws_frame *f)
{
    size_t hl = 2, plen, i;
    bc_u8 *mask;
    int masked;

    if (len < 2) return 0;
    f->fin = (buf[0] & 0x80) != 0;
    if (buf[0] & 0x70) return -1;              /* RSV sem extensao */
    f->opcode = buf[0] & 0x0F;
    masked = (buf[1] & 0x80) != 0;
    if (!masked) return -1;                    /* cliente deve mascarar */
    plen = buf[1] & 0x7F;
    if (plen == 126) {
        if (len < 4) return 0;
        plen = ((size_t)buf[2] << 8) | buf[3];
        if (plen < 126) return -1;
        hl = 4;
    } else if (plen == 127) {
        bc_u64 v = 0;
        if (len < 10) return 0;
        for (i = 0; i < 8; i++) v = (v << 8) | buf[2 + i];
        if (v >> 63) return -1;
        if (v < 65536) return -1;
        if (v > (bc_u64)max) return -2;
        plen = (size_t)v;
        hl = 10;
    }
    if (f->opcode >= 0x8) {
        if (!f->fin || plen > 125) return -1;  /* controle: sem fragmentar, <=125 */
        if (f->opcode > 0xA) return -1;
    } else if (f->opcode > 0x2) {
        return -1;
    }
    if (plen > max) return -2;
    if (len < hl + 4 + plen) return 0;
    mask = buf + hl;
    f->payload = buf + hl + 4;
    f->len = plen;
    for (i = 0; i < plen; i++) f->payload[i] ^= mask[i & 3];
    return (long)(hl + 4 + plen);
}

bc_buf *bc_ws_make(int opcode, const void *a, size_t alen, const void *b, size_t blen, int media)
{
    size_t plen = alen + blen, hl;
    bc_buf *out;
    bc_u8 *d;
    hl = (plen < 126) ? 2 : (plen < 65536 ? 4 : 10);
    out = bc_buf_new(hl + plen, media);
    d = out->data;
    d[0] = (bc_u8)(0x80 | (opcode & 0x0F));
    if (plen < 126) {
        d[1] = (bc_u8)plen;
    } else if (plen < 65536) {
        d[1] = 126; d[2] = (bc_u8)(plen >> 8); d[3] = (bc_u8)plen;
    } else {
        int i;
        bc_u64 v = (bc_u64)plen;
        d[1] = 127;
        for (i = 0; i < 8; i++) d[9 - i] = (bc_u8)(v >> (8 * i));
    }
    if (alen) memcpy(d + hl, a, alen);
    if (blen) memcpy(d + hl + alen, b, blen);
    return out;
}

bc_buf *bc_ws_make_text(const char *s)
{
    return bc_ws_make(BC_WS_TEXT, s, strlen(s), NULL, 0, 0);
}

bc_buf *bc_ws_make_close(int code, const char *reason)
{
    bc_u8 p[125];
    size_t rl = reason ? strlen(reason) : 0;
    if (rl > 120) rl = 120;
    p[0] = (bc_u8)(code >> 8);
    p[1] = (bc_u8)code;
    if (rl) memcpy(p + 2, reason, rl);
    return bc_ws_make(BC_WS_CLOSE, p, 2 + rl, NULL, 0, 0);
}

size_t bc_ws_encode_client(int opcode, int fin, const void *pp, size_t n, const bc_u8 mask[4], bc_u8 *out)
{
    const bc_u8 *p = (const bc_u8 *)pp;
    size_t hl, i;
    out[0] = (bc_u8)((fin ? 0x80 : 0) | (opcode & 0x0F));
    if (n < 126) { out[1] = (bc_u8)(0x80 | n); hl = 2; }
    else if (n < 65536) { out[1] = 0x80 | 126; out[2] = (bc_u8)(n >> 8); out[3] = (bc_u8)n; hl = 4; }
    else {
        bc_u64 v = (bc_u64)n;
        out[1] = 0x80 | 127;
        for (i = 0; i < 8; i++) out[9 - i] = (bc_u8)(v >> (8 * i));
        hl = 10;
    }
    memcpy(out + hl, mask, 4);
    for (i = 0; i < n; i++) out[hl + 4 + i] = (bc_u8)(p[i] ^ mask[i & 3]);
    return hl + 4 + n;
}

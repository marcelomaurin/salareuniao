/*
 * test_main.c - testes unitarios do bcastd (sem dependencias externas)
 */
#include "config.h"
#include "bc_crypto.h"
#include "bc_util.h"
#include "bc_ws.h"
#include "bc_ebml.h"
#include "bc_fsm.h"
#include "bc_json.h"

#include <stdio.h>
#include <stdlib.h>
#include <string.h>

static int failures = 0, checks = 0;

#define CHECK(cond, msg) do { checks++; if (!(cond)) { failures++; \
    fprintf(stderr, "FALHOU %s:%d: %s\n", __FILE__, __LINE__, msg); } } while (0)

static void hexof(const bc_u8 *b, size_t n, char *out) { bc_hex(b, n, out); }

static void test_sha1(void)
{
    bc_u8 d[20];
    char h[41];
    bc_sha1("abc", 3, d); hexof(d, 20, h);
    CHECK(strcmp(h, "a9993e364706816aba3e25717850c26c9cd0d89d") == 0, "sha1(abc)");
    bc_sha1("", 0, d); hexof(d, 20, h);
    CHECK(strcmp(h, "da39a3ee5e6b4b0d3255bfef95601890afd80709") == 0, "sha1('')");
    bc_sha1("abcdbcdecdefdefgefghfghighijhijkijkljklmklmnlmnomnopnopq", 56, d); hexof(d, 20, h);
    CHECK(strcmp(h, "84983e441c3bd26ebaae4aa1f95129e5e54670f1") == 0, "sha1(56 bytes)");
}

static void test_sha256_hmac(void)
{
    bc_u8 d[32], key[20];
    char h[65];
    bc_sha256("abc", 3, d); hexof(d, 32, h);
    CHECK(strcmp(h, "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad") == 0, "sha256(abc)");
    memset(key, 0x0b, 20);
    bc_hmac_sha256(key, 20, "Hi There", 8, d); hexof(d, 32, h);
    CHECK(strcmp(h, "b0344c61d8db38535ca8afceaf0bf12b881dc200c9833da726e9376c2e32cff7") == 0, "hmac rfc4231 #1");
    bc_hmac_sha256("Jefe", 4, "what do ya want for nothing?", 28, d); hexof(d, 32, h);
    CHECK(strcmp(h, "5bdcc146bf60754e6a042426089575c75a003f089d2739839dec58b964ec3843") == 0, "hmac rfc4231 #2");
}

static void test_base64(void)
{
    char out[64];
    bc_u8 dec[64];
    long n;
    bc_base64_encode((const bc_u8 *)"foobar", 6, out);
    CHECK(strcmp(out, "Zm9vYmFy") == 0, "b64 foobar");
    bc_base64_encode((const bc_u8 *)"fo", 2, out);
    CHECK(strcmp(out, "Zm8=") == 0, "b64 fo");
    n = bc_base64_decode("Zm9vYg==", 8, dec, sizeof(dec), 0);
    CHECK(n == 4 && memcmp(dec, "foob", 4) == 0, "b64 decode");
    CHECK(bc_base64_decode("Zm9v*", 5, dec, sizeof(dec), 0) < 0, "b64 invalido");
}

static void test_ws_handshake(void)
{
    char acc[32];
    bc_http_req req;
    const char *ok =
        "GET /salareuniao/broadcast HTTP/1.1\r\nHost: x\r\nUpgrade: websocket\r\n"
        "Connection: keep-alive, Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
        "Sec-WebSocket-Version: 13\r\nOrigin: https://maurinsoft.com.br\r\n"
        "X-Forwarded-For: 10.0.0.1, 200.1.2.3\r\n\r\n";
    const char *bad_ver =
        "GET / HTTP/1.1\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
        "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 8\r\n\r\n";
    int r;
    bc_ws_accept_key("dGhlIHNhbXBsZSBub25jZQ==", acc);
    CHECK(strcmp(acc, "s3pPLMBiTxaQ9kYGzzhZRbK+xOo=") == 0, "Sec-WebSocket-Accept RFC 6455");
    r = bc_http_parse_upgrade(ok, strlen(ok), &req);
    CHECK(r == (int)strlen(ok), "upgrade valido");
    CHECK(strcmp(req.origin, "https://maurinsoft.com.br") == 0, "origin");
    CHECK(strcmp(req.path, "/salareuniao/broadcast") == 0, "path");
    CHECK(bc_http_parse_upgrade(ok, 40, &req) == 0, "upgrade incompleto");
    CHECK(bc_http_parse_upgrade(bad_ver, strlen(bad_ver), &req) == -426, "versao errada -> 426");
}

static void test_ws_frames(void)
{
    bc_u8 mask[4] = { 1, 2, 3, 4 };
    bc_u8 buf[70000], *big;
    bc_ws_frame f;
    size_t n;
    long r;
    bc_buf *b;

    n = bc_ws_encode_client(BC_WS_TEXT, 1, "hello", 5, mask, buf);
    r = bc_ws_parse(buf, n, 1024, &f);
    CHECK(r == (long)n && f.opcode == BC_WS_TEXT && f.fin && f.len == 5 && memcmp(f.payload, "hello", 5) == 0, "texto curto");
    n = bc_ws_encode_client(BC_WS_TEXT, 1, "hello", 5, mask, buf);
    CHECK(bc_ws_parse(buf, n - 1, 1024, &f) == 0, "quadro incompleto");

    big = (bc_u8 *)malloc(66000);
    memset(big, 'x', 66000);
    n = bc_ws_encode_client(BC_WS_BIN, 1, big, 66000, mask, buf);
    r = bc_ws_parse(buf, n, 70000, &f);
    CHECK(r == (long)n && f.len == 66000 && f.payload[65999] == 'x', "binario 64 bits");
    n = bc_ws_encode_client(BC_WS_BIN, 1, big, 66000, mask, buf);
    CHECK(bc_ws_parse(buf, n, 1000, &f) == -2, "quadro grande demais");
    free(big);

    /* quadro sem mascara e erro de protocolo */
    buf[0] = 0x81; buf[1] = 0x02; buf[2] = 'h'; buf[3] = 'i';
    CHECK(bc_ws_parse(buf, 4, 1024, &f) == -1, "sem mascara");
    /* ping com 126 bytes e erro */
    n = bc_ws_encode_client(BC_WS_PING, 1, buf + 100, 126, mask, buf);
    CHECK(bc_ws_parse(buf, n, 1024, &f) == -1, "controle > 125");
    /* controle fragmentado e erro */
    n = bc_ws_encode_client(BC_WS_PING, 0, "a", 1, mask, buf);
    CHECK(bc_ws_parse(buf, n, 1024, &f) == -1, "controle fragmentado");

    b = bc_ws_make(BC_WS_TEXT, "ab", 2, "cd", 2, 0);
    CHECK(b->len == 6 && b->data[0] == 0x81 && b->data[1] == 4 && memcmp(b->data + 2, "abcd", 4) == 0, "make servidor");
    bc_buf_unref(b);
}

/* ---- WebM sintetico ---- */

static size_t put_id(bc_u8 *o, bc_u32 id)
{
    if (id > 0xFFFFFF) { o[0] = (bc_u8)(id >> 24); o[1] = (bc_u8)(id >> 16); o[2] = (bc_u8)(id >> 8); o[3] = (bc_u8)id; return 4; }
    if (id > 0xFFFF)   { o[0] = (bc_u8)(id >> 16); o[1] = (bc_u8)(id >> 8); o[2] = (bc_u8)id; return 3; }
    if (id > 0xFF)     { o[0] = (bc_u8)(id >> 8); o[1] = (bc_u8)id; return 2; }
    o[0] = (bc_u8)id; return 1;
}

static size_t put_size(bc_u8 *o, size_t n) /* tamanho de 2 bytes */
{
    o[0] = (bc_u8)(0x40 | (n >> 8)); o[1] = (bc_u8)n; return 2;
}

static size_t put_el(bc_u8 *o, bc_u32 id, const bc_u8 *data, size_t n)
{
    size_t k = put_id(o, id);
    k += put_size(o + k, n);
    memcpy(o + k, data, n);
    return k + n;
}

static size_t simple_block(bc_u8 *o, int track, int key, size_t payload)
{
    bc_u8 tmp[256];
    size_t i;
    tmp[0] = (bc_u8)(0x80 | track);
    tmp[1] = 0; tmp[2] = 0;
    tmp[3] = key ? 0x80 : 0x00;
    for (i = 0; i < payload; i++) tmp[4 + i] = (bc_u8)i;
    return put_el(o, EBML_ID_SIMPLEBLOCK, tmp, 4 + payload);
}

static size_t build_webm(bc_u8 *o, size_t *init_end, size_t *cl_off)
{
    size_t k = 0, t, te;
    bc_u8 tmp[512], entry[64];
    bc_u8 tc[1] = { 0 };
    /* cabecalho EBML */
    k += put_el(o + k, EBML_ID_EBML, (const bc_u8 *)"\x42\x82\x84webm", 7);
    /* Segment de tamanho desconhecido */
    k += put_id(o + k, EBML_ID_SEGMENT);
    o[k++] = 0x01; memset(o + k, 0xFF, 7); k += 7;
    /* Info */
    k += put_el(o + k, EBML_ID_INFO, (const bc_u8 *)"\x2A\xD7\xB1\x83\x0F\x42\x40", 7);
    /* Tracks: trilha 1 audio (tipo 2), trilha 2 video (tipo 1) */
    t = 0;
    te = 0;
    entry[te++] = 0xD7; entry[te++] = 0x81; entry[te++] = 1;
    entry[te++] = 0x83; entry[te++] = 0x81; entry[te++] = 2;
    t += put_el(tmp + t, EBML_ID_TRACKENTRY, entry, te);
    te = 0;
    entry[te++] = 0xD7; entry[te++] = 0x81; entry[te++] = 2;
    entry[te++] = 0x83; entry[te++] = 0x81; entry[te++] = 1;
    t += put_el(tmp + t, EBML_ID_TRACKENTRY, entry, te);
    k += put_el(o + k, EBML_ID_TRACKS, tmp, t);
    *init_end = k;
    /* Cluster 1 (desconhecido): audio key + video key */
    cl_off[0] = k;
    k += put_id(o + k, EBML_ID_CLUSTER); o[k++] = 0xFF;
    k += put_el(o + k, EBML_ID_TIMECODE, tc, 1);
    k += simple_block(o + k, 1, 1, 30);
    k += simple_block(o + k, 2, 1, 100);
    k += simple_block(o + k, 2, 0, 50);
    /* Cluster 2: audio key + video NAO key */
    cl_off[1] = k;
    k += put_id(o + k, EBML_ID_CLUSTER); o[k++] = 0xFF;
    k += put_el(o + k, EBML_ID_TIMECODE, tc, 1);
    k += simple_block(o + k, 1, 1, 30);
    k += simple_block(o + k, 2, 0, 60);
    /* Cluster 3: video key */
    cl_off[2] = k;
    k += put_id(o + k, EBML_ID_CLUSTER); o[k++] = 0xFF;
    k += put_el(o + k, EBML_ID_TIMECODE, tc, 1);
    k += simple_block(o + k, 2, 1, 80);
    return k;
}

static void test_ebml_split(size_t step)
{
    bc_u8 data[4096];
    size_t init_end, cl[3], n, pos = 0;
    bc_ebml p;
    bc_ebml_ev ev[16];
    int clusters = 0, keys[3] = { -1, -1, -1 }, errors = 0, i;
    char msg[96];

    n = build_webm(data, &init_end, cl);
    bc_ebml_init(&p);
    while (pos < n) {
        size_t take = (n - pos < step) ? n - pos : step;
        int k = bc_ebml_feed(&p, data + pos, take, ev, 16);
        for (i = 0; i < k; i++) {
            if (ev[i].type == BC_EBML_EV_CLUSTER) {
                if (clusters < 3 && ev[i].off != cl[clusters]) errors++;
                clusters++;
            } else if (ev[i].type == BC_EBML_EV_KEY) {
                int c;
                for (c = 0; c < 3; c++) if (ev[i].off == cl[c]) keys[c] = ev[i].key;
            } else {
                errors++;
            }
        }
        pos += take;
    }
    sprintf(msg, "ebml passo %lu: 3 clusters", (unsigned long)step);
    CHECK(clusters == 3, msg);
    sprintf(msg, "ebml passo %lu: offsets/erros", (unsigned long)step);
    CHECK(errors == 0, msg);
    sprintf(msg, "ebml passo %lu: trilha de video", (unsigned long)step);
    CHECK(p.video_track == 2, msg);
    sprintf(msg, "ebml passo %lu: keyframes", (unsigned long)step);
    CHECK(keys[0] == 1 && keys[1] == 0 && keys[2] == 1, msg);
    CHECK(cl[0] == init_end, "init termina no primeiro cluster");
    bc_ebml_free(&p);
}

static void test_fsm(void)
{
    int s, e, terminal_exits = 0;
    CHECK(bc_fsm_next(BC_ST_NEW, BC_EV_TCP_OPEN) == BC_ST_HANDSHAKE, "novo -> handshake");
    CHECK(bc_fsm_next(BC_ST_HANDSHAKE, BC_EV_WS_OK) == BC_ST_AUTH, "handshake -> auth");
    CHECK(bc_fsm_next(BC_ST_AUTH, BC_EV_HELLO_WAIT) == BC_ST_WAITING, "auth -> espera");
    CHECK(bc_fsm_next(BC_ST_AUTH, BC_EV_HELLO_ROOM) == BC_ST_IN_ROOM, "auth -> sala");
    CHECK(bc_fsm_next(BC_ST_AUTH, BC_EV_HELLO_BANNED) == BC_ST_BANNED, "auth -> banido");
    CHECK(bc_fsm_next(BC_ST_WAITING, BC_EV_ADMIT) == BC_ST_IN_ROOM, "espera -> sala");
    CHECK(bc_fsm_next(BC_ST_WAITING, BC_EV_DENY) == BC_ST_DENIED, "espera -> negado");
    CHECK(bc_fsm_next(BC_ST_WAITING, BC_EV_BAN) == BC_ST_BANNED, "espera -> banido");
    CHECK(bc_fsm_next(BC_ST_WAITING, BC_EV_KICK) == -1, "espera nao pode ser retirado");
    CHECK(bc_fsm_next(BC_ST_IN_ROOM, BC_EV_KICK) == BC_ST_KICKED, "sala -> retirado");
    CHECK(bc_fsm_next(BC_ST_IN_ROOM, BC_EV_BAN) == BC_ST_BANNED, "sala -> banido");
    CHECK(bc_fsm_next(BC_ST_IN_ROOM, BC_EV_BYE) == BC_ST_LEFT, "sala -> saiu");
    CHECK(bc_fsm_next(BC_ST_IN_ROOM, BC_EV_ADMIT) == -1, "admitir quem ja esta na sala");
    /* nenhum estado final volta a ser ativo */
    for (s = BC_ST_DENIED; s <= BC_ST_CLOSED; s++)
        for (e = 0; e < BC_EV__COUNT; e++) {
            int t = bc_fsm_next((bc_state)s, (bc_event)e);
            if (t >= 0 && t != s) terminal_exits++;
        }
    CHECK(terminal_exits == 0, "estados finais sao absorventes");
    CHECK(bc_sub_next(BC_SUB_VIEWER, BC_SUB_HAND) == BC_SUB_HAND, "levantar mao");
    CHECK(bc_sub_next(BC_SUB_HAND, BC_SUB_PENDING) == BC_SUB_PENDING, "mao aceita");
    CHECK(bc_sub_next(BC_SUB_PENDING, BC_SUB_SPEAKING) == BC_SUB_SPEAKING, "comeca a falar");
    CHECK(bc_sub_next(BC_SUB_VIEWER, BC_SUB_SPEAKING) == -1, "ouvinte nao fala sem vez");
}

static void test_util(void)
{
    char u[37];
    CHECK(bc_utf8_valid((const bc_u8 *)"ol\xc3\xa1", 4), "utf8 valido");
    CHECK(!bc_utf8_valid((const bc_u8 *)"\xc3", 1), "utf8 truncado");
    CHECK(!bc_utf8_valid((const bc_u8 *)"\xed\xa0\x80", 3), "utf8 surrogate");
    CHECK(!bc_utf8_valid((const bc_u8 *)"\xc0\xaf", 2), "utf8 overlong");
    CHECK(bc_is_hex("abcdef0123456789abcdef0123456789", 32, 64), "hex ok");
    CHECK(!bc_is_hex("abcg", 1, 64), "hex invalido");
    bc_uuid4(u);
    CHECK(strlen(u) == 36 && u[14] == '4', "uuid v4");
    CHECK(bc_ct_equal("abc", "abc", 3) && !bc_ct_equal("abc", "abd", 3), "comparacao tempo constante");
}

static void test_json(void)
{
    cJSON *o = bc_json_parse("{\"t\":\"hello\",\"n\":5}", 19);
    CHECK(o != NULL, "json valido");
    if (o) {
        CHECK(strcmp(bc_json_str(o, "t", 10), "hello") == 0, "json str");
        CHECK(bc_json_int(o, "n", 0, NULL) == 5, "json int");
        CHECK(bc_json_str(o, "t", 3) == NULL, "json str grande demais");
        cJSON_Delete(o);
    }
    CHECK(bc_json_parse("[1]", 3) == NULL, "json nao-objeto");
    CHECK(bc_json_parse("{\"a\":[[[[[[[[[[1]]]]]]]]]]}", 27) == NULL, "json profundo");
}

int main(void)
{
    size_t step;
    test_sha1();
    test_sha256_hmac();
    test_base64();
    test_ws_handshake();
    test_ws_frames();
    for (step = 1; step <= 64; step++) test_ebml_split(step);
    test_ebml_split(4096);
    test_fsm();
    test_util();
    test_json();
    printf("%d verificacoes, %d falhas\n", checks, failures);
    return failures ? 1 : 0;
}

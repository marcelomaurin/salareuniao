/*
 * bc_crypto.c - SHA-1, SHA-256, HMAC-SHA256 e Base64 sem dependencias
 */
#include "config.h"
#include "bc_crypto.h"

#include <string.h>

/* ------------------------------------------------------------------ SHA-1 */

#define ROL32(x, n) ((((x) << (n)) | ((x) >> (32 - (n)))) & 0xFFFFFFFFUL)

typedef struct { bc_u32 h[5]; bc_u64 len; bc_u8 buf[64]; size_t blen; } sha1_ctx;

static void sha1_block(sha1_ctx *c, const bc_u8 *p)
{
    bc_u32 w[80], a, b, cc, d, e, t;
    int i;
    for (i = 0; i < 16; i++)
        w[i] = ((bc_u32)p[i * 4] << 24) | ((bc_u32)p[i * 4 + 1] << 16) |
               ((bc_u32)p[i * 4 + 2] << 8) | (bc_u32)p[i * 4 + 3];
    for (i = 16; i < 80; i++) w[i] = ROL32(w[i - 3] ^ w[i - 8] ^ w[i - 14] ^ w[i - 16], 1);
    a = c->h[0]; b = c->h[1]; cc = c->h[2]; d = c->h[3]; e = c->h[4];
    for (i = 0; i < 80; i++) {
        bc_u32 f, k;
        if (i < 20)      { f = (b & cc) | (~b & d);            k = 0x5A827999UL; }
        else if (i < 40) { f = b ^ cc ^ d;                      k = 0x6ED9EBA1UL; }
        else if (i < 60) { f = (b & cc) | (b & d) | (cc & d);   k = 0x8F1BBCDCUL; }
        else             { f = b ^ cc ^ d;                      k = 0xCA62C1D6UL; }
        t = (ROL32(a, 5) + f + e + k + w[i]) & 0xFFFFFFFFUL;
        e = d; d = cc; cc = ROL32(b, 30); b = a; a = t;
    }
    c->h[0] += a; c->h[1] += b; c->h[2] += cc; c->h[3] += d; c->h[4] += e;
}

void bc_sha1(const void *data, size_t len, bc_u8 out[20])
{
    sha1_ctx c;
    const bc_u8 *p = (const bc_u8 *)data;
    bc_u8 pad[72];
    size_t padlen, i;
    bc_u64 bits = (bc_u64)len * 8ULL;

    c.h[0] = 0x67452301UL; c.h[1] = 0xEFCDAB89UL; c.h[2] = 0x98BADCFEUL;
    c.h[3] = 0x10325476UL; c.h[4] = 0xC3D2E1F0UL;
    while (len >= 64) { sha1_block(&c, p); p += 64; len -= 64; }
    memset(pad, 0, sizeof(pad));
    memcpy(pad, p, len);
    pad[len] = 0x80;
    padlen = (len < 56) ? 64 : 128;
    {
        bc_u8 blk[128];
        memset(blk, 0, sizeof(blk));
        memcpy(blk, pad, len + 1);
        for (i = 0; i < 8; i++) blk[padlen - 1 - i] = (bc_u8)(bits >> (8 * i));
        sha1_block(&c, blk);
        if (padlen == 128) sha1_block(&c, blk + 64);
    }
    for (i = 0; i < 5; i++) {
        out[i * 4] = (bc_u8)(c.h[i] >> 24); out[i * 4 + 1] = (bc_u8)(c.h[i] >> 16);
        out[i * 4 + 2] = (bc_u8)(c.h[i] >> 8); out[i * 4 + 3] = (bc_u8)c.h[i];
    }
}

/* ---------------------------------------------------------------- SHA-256 */

static const bc_u32 K256[64] = {
    0x428a2f98UL,0x71374491UL,0xb5c0fbcfUL,0xe9b5dba5UL,0x3956c25bUL,0x59f111f1UL,0x923f82a4UL,0xab1c5ed5UL,
    0xd807aa98UL,0x12835b01UL,0x243185beUL,0x550c7dc3UL,0x72be5d74UL,0x80deb1feUL,0x9bdc06a7UL,0xc19bf174UL,
    0xe49b69c1UL,0xefbe4786UL,0x0fc19dc6UL,0x240ca1ccUL,0x2de92c6fUL,0x4a7484aaUL,0x5cb0a9dcUL,0x76f988daUL,
    0x983e5152UL,0xa831c66dUL,0xb00327c8UL,0xbf597fc7UL,0xc6e00bf3UL,0xd5a79147UL,0x06ca6351UL,0x14292967UL,
    0x27b70a85UL,0x2e1b2138UL,0x4d2c6dfcUL,0x53380d13UL,0x650a7354UL,0x766a0abbUL,0x81c2c92eUL,0x92722c85UL,
    0xa2bfe8a1UL,0xa81a664bUL,0xc24b8b70UL,0xc76c51a3UL,0xd192e819UL,0xd6990624UL,0xf40e3585UL,0x106aa070UL,
    0x19a4c116UL,0x1e376c08UL,0x2748774cUL,0x34b0bcb5UL,0x391c0cb3UL,0x4ed8aa4aUL,0x5b9cca4fUL,0x682e6ff3UL,
    0x748f82eeUL,0x78a5636fUL,0x84c87814UL,0x8cc70208UL,0x90befffaUL,0xa4506cebUL,0xbef9a3f7UL,0xc67178f2UL
};

#define ROR32(x, n) ((((x) >> (n)) | ((x) << (32 - (n)))) & 0xFFFFFFFFUL)

typedef struct { bc_u32 h[8]; bc_u64 len; bc_u8 buf[64]; size_t blen; } sha256_ctx;

static void sha256_block(sha256_ctx *c, const bc_u8 *p)
{
    bc_u32 w[64], a, b, cc, d, e, f, g, h, t1, t2;
    int i;
    for (i = 0; i < 16; i++)
        w[i] = ((bc_u32)p[i * 4] << 24) | ((bc_u32)p[i * 4 + 1] << 16) |
               ((bc_u32)p[i * 4 + 2] << 8) | (bc_u32)p[i * 4 + 3];
    for (i = 16; i < 64; i++) {
        bc_u32 s0 = ROR32(w[i - 15], 7) ^ ROR32(w[i - 15], 18) ^ (w[i - 15] >> 3);
        bc_u32 s1 = ROR32(w[i - 2], 17) ^ ROR32(w[i - 2], 19) ^ (w[i - 2] >> 10);
        w[i] = (w[i - 16] + s0 + w[i - 7] + s1) & 0xFFFFFFFFUL;
    }
    a = c->h[0]; b = c->h[1]; cc = c->h[2]; d = c->h[3];
    e = c->h[4]; f = c->h[5]; g = c->h[6]; h = c->h[7];
    for (i = 0; i < 64; i++) {
        bc_u32 S1 = ROR32(e, 6) ^ ROR32(e, 11) ^ ROR32(e, 25);
        bc_u32 ch = (e & f) ^ (~e & g);
        bc_u32 S0 = ROR32(a, 2) ^ ROR32(a, 13) ^ ROR32(a, 22);
        bc_u32 mj = (a & b) ^ (a & cc) ^ (b & cc);
        t1 = (h + S1 + ch + K256[i] + w[i]) & 0xFFFFFFFFUL;
        t2 = (S0 + mj) & 0xFFFFFFFFUL;
        h = g; g = f; f = e; e = (d + t1) & 0xFFFFFFFFUL;
        d = cc; cc = b; b = a; a = (t1 + t2) & 0xFFFFFFFFUL;
    }
    c->h[0] = (c->h[0] + a) & 0xFFFFFFFFUL; c->h[1] = (c->h[1] + b) & 0xFFFFFFFFUL;
    c->h[2] = (c->h[2] + cc) & 0xFFFFFFFFUL; c->h[3] = (c->h[3] + d) & 0xFFFFFFFFUL;
    c->h[4] = (c->h[4] + e) & 0xFFFFFFFFUL; c->h[5] = (c->h[5] + f) & 0xFFFFFFFFUL;
    c->h[6] = (c->h[6] + g) & 0xFFFFFFFFUL; c->h[7] = (c->h[7] + h) & 0xFFFFFFFFUL;
}

static void sha256_init(sha256_ctx *c)
{
    c->h[0] = 0x6a09e667UL; c->h[1] = 0xbb67ae85UL; c->h[2] = 0x3c6ef372UL; c->h[3] = 0xa54ff53aUL;
    c->h[4] = 0x510e527fUL; c->h[5] = 0x9b05688cUL; c->h[6] = 0x1f83d9abUL; c->h[7] = 0x5be0cd19UL;
    c->len = 0; c->blen = 0;
}

static void sha256_update(sha256_ctx *c, const void *data, size_t len)
{
    const bc_u8 *p = (const bc_u8 *)data;
    c->len += len;
    while (len > 0) {
        size_t take = 64 - c->blen;
        if (take > len) take = len;
        memcpy(c->buf + c->blen, p, take);
        c->blen += take; p += take; len -= take;
        if (c->blen == 64) { sha256_block(c, c->buf); c->blen = 0; }
    }
}

static void sha256_final(sha256_ctx *c, bc_u8 out[32])
{
    bc_u64 bits = c->len * 8ULL;
    bc_u8 one = 0x80, zero = 0, lenb[8];
    int i;
    sha256_update(c, &one, 1);
    while (c->blen != 56) sha256_update(c, &zero, 1);
    for (i = 0; i < 8; i++) lenb[7 - i] = (bc_u8)(bits >> (8 * i));
    sha256_update(c, lenb, 8);
    for (i = 0; i < 8; i++) {
        out[i * 4] = (bc_u8)(c->h[i] >> 24); out[i * 4 + 1] = (bc_u8)(c->h[i] >> 16);
        out[i * 4 + 2] = (bc_u8)(c->h[i] >> 8); out[i * 4 + 3] = (bc_u8)c->h[i];
    }
}

void bc_sha256(const void *data, size_t len, bc_u8 out[32])
{
    sha256_ctx c;
    sha256_init(&c);
    sha256_update(&c, data, len);
    sha256_final(&c, out);
}

void bc_hmac_sha256(const void *key, size_t klen, const void *msg, size_t mlen, bc_u8 out[32])
{
    bc_u8 k[64], ipad[64], opad[64], inner[32];
    sha256_ctx c;
    int i;
    memset(k, 0, sizeof(k));
    if (klen > 64) bc_sha256(key, klen, k);
    else memcpy(k, key, klen);
    for (i = 0; i < 64; i++) { ipad[i] = (bc_u8)(k[i] ^ 0x36); opad[i] = (bc_u8)(k[i] ^ 0x5c); }
    sha256_init(&c); sha256_update(&c, ipad, 64); sha256_update(&c, msg, mlen); sha256_final(&c, inner);
    sha256_init(&c); sha256_update(&c, opad, 64); sha256_update(&c, inner, 32); sha256_final(&c, out);
}

/* ----------------------------------------------------------------- Base64 */

static const char B64[] = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";

size_t bc_base64_encode(const bc_u8 *in, size_t n, char *out)
{
    size_t i, o = 0;
    for (i = 0; i + 2 < n; i += 3) {
        bc_u32 v = ((bc_u32)in[i] << 16) | ((bc_u32)in[i + 1] << 8) | in[i + 2];
        out[o++] = B64[(v >> 18) & 63]; out[o++] = B64[(v >> 12) & 63];
        out[o++] = B64[(v >> 6) & 63];  out[o++] = B64[v & 63];
    }
    if (n - i == 1) {
        bc_u32 v = (bc_u32)in[i] << 16;
        out[o++] = B64[(v >> 18) & 63]; out[o++] = B64[(v >> 12) & 63];
        out[o++] = '='; out[o++] = '=';
    } else if (n - i == 2) {
        bc_u32 v = ((bc_u32)in[i] << 16) | ((bc_u32)in[i + 1] << 8);
        out[o++] = B64[(v >> 18) & 63]; out[o++] = B64[(v >> 12) & 63];
        out[o++] = B64[(v >> 6) & 63];  out[o++] = '=';
    }
    out[o] = '\0';
    return o;
}

static int b64val(char ch, int url)
{
    if (ch >= 'A' && ch <= 'Z') return ch - 'A';
    if (ch >= 'a' && ch <= 'z') return ch - 'a' + 26;
    if (ch >= '0' && ch <= '9') return ch - '0' + 52;
    if (ch == '+' || (url && ch == '-')) return 62;
    if (ch == '/' || (url && ch == '_')) return 63;
    return -1;
}

long bc_base64_decode(const char *in, size_t n, bc_u8 *out, size_t outmax, int url)
{
    bc_u32 acc = 0;
    int bits = 0;
    size_t i, o = 0;
    for (i = 0; i < n; i++) {
        int v;
        if (in[i] == '=') break;
        v = b64val(in[i], url);
        if (v < 0) return -1;
        acc = (acc << 6) | (bc_u32)v;
        bits += 6;
        if (bits >= 8) {
            bits -= 8;
            if (o >= outmax) return -1;
            out[o++] = (bc_u8)((acc >> bits) & 0xFF);
        }
    }
    return (long)o;
}

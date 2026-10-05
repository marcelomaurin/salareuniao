/*
 * bc_util.c - tempo, memoria, aleatoriedade e utilitarios de texto
 */
#include "config.h"
#include "bc_util.h"
#include "bc_log.h"

#include <stdlib.h>
#include <string.h>
#include <stdio.h>
#include <time.h>
#include <errno.h>
#include <fcntl.h>
#include <unistd.h>
#include <pthread.h>

bc_u64 bc_now_ms(void)
{
    struct timespec ts;
    clock_gettime(CLOCK_MONOTONIC, &ts);
    return (bc_u64)ts.tv_sec * 1000ULL + (bc_u64)(ts.tv_nsec / 1000000L);
}

bc_u64 bc_wall_ms(void)
{
    struct timespec ts;
    clock_gettime(CLOCK_REALTIME, &ts);
    return (bc_u64)ts.tv_sec * 1000ULL + (bc_u64)(ts.tv_nsec / 1000000L);
}

void bc_sleep_ms(unsigned ms)
{
    struct timespec ts;
    ts.tv_sec = ms / 1000;
    ts.tv_nsec = (long)(ms % 1000) * 1000000L;
    while (nanosleep(&ts, &ts) == -1 && errno == EINTR) {
        /* continua dormindo o restante */
    }
}

static void oom(size_t n)
{
    fprintf(stderr, "bcastd: sem memoria (%lu bytes)\n", (unsigned long)n);
    abort();
}

void *bc_xmalloc(size_t n)
{
    void *p = malloc(n ? n : 1);
    if (!p) oom(n);
    return p;
}

void *bc_xcalloc(size_t n, size_t sz)
{
    void *p = calloc(n ? n : 1, sz ? sz : 1);
    if (!p) oom(n * sz);
    return p;
}

void *bc_xrealloc(void *p, size_t n)
{
    void *q = realloc(p, n ? n : 1);
    if (!q) oom(n);
    return q;
}

char *bc_xstrdup(const char *s)
{
    size_t n;
    char *d;
    if (!s) s = "";
    n = strlen(s);
    d = (char *)bc_xmalloc(n + 1);
    memcpy(d, s, n + 1);
    return d;
}

size_t bc_strlcpy(char *dst, const char *src, size_t size)
{
    size_t n;
    if (!src) src = "";
    n = strlen(src);
    if (size > 0) {
        size_t c = (n >= size) ? size - 1 : n;
        memcpy(dst, src, c);
        dst[c] = '\0';
    }
    return n;
}

static pthread_mutex_t rnd_mutex = PTHREAD_MUTEX_INITIALIZER;
static int rnd_fd = -1;

int bc_random_bytes(void *buf, size_t n)
{
    bc_u8 *p = (bc_u8 *)buf;
    size_t got = 0;
    pthread_mutex_lock(&rnd_mutex);
    if (rnd_fd < 0) {
        rnd_fd = open("/dev/urandom", O_RDONLY);
    }
    if (rnd_fd < 0) {
        pthread_mutex_unlock(&rnd_mutex);
        BC_LOG_ERROR("nao foi possivel abrir /dev/urandom");
        abort();
    }
    while (got < n) {
        ssize_t r = read(rnd_fd, p + got, n - got);
        if (r < 0) {
            if (errno == EINTR) continue;
            pthread_mutex_unlock(&rnd_mutex);
            abort();
        }
        got += (size_t)r;
    }
    pthread_mutex_unlock(&rnd_mutex);
    return 0;
}

void bc_hex(const bc_u8 *in, size_t n, char *out)
{
    static const char hx[] = "0123456789abcdef";
    size_t i;
    for (i = 0; i < n; i++) {
        out[i * 2] = hx[in[i] >> 4];
        out[i * 2 + 1] = hx[in[i] & 15];
    }
    out[n * 2] = '\0';
}

void bc_random_hex(char *out, size_t nbytes)
{
    bc_u8 tmp[64];
    if (nbytes > sizeof(tmp)) nbytes = sizeof(tmp);
    bc_random_bytes(tmp, nbytes);
    bc_hex(tmp, nbytes, out);
}

void bc_uuid4(char *out)
{
    bc_u8 b[16];
    char h[33];
    bc_random_bytes(b, 16);
    b[6] = (bc_u8)((b[6] & 0x0F) | 0x40);
    b[8] = (bc_u8)((b[8] & 0x3F) | 0x80);
    bc_hex(b, 16, h);
    memcpy(out, h, 8);       out[8] = '-';
    memcpy(out + 9, h + 8, 4);   out[13] = '-';
    memcpy(out + 14, h + 12, 4); out[18] = '-';
    memcpy(out + 19, h + 16, 4); out[23] = '-';
    memcpy(out + 24, h + 20, 12); out[36] = '\0';
}

int bc_ct_equal(const void *a, const void *b, size_t n)
{
    const bc_u8 *x = (const bc_u8 *)a;
    const bc_u8 *y = (const bc_u8 *)b;
    bc_u8 d = 0;
    size_t i;
    for (i = 0; i < n; i++) d = (bc_u8)(d | (x[i] ^ y[i]));
    return d == 0;
}

int bc_is_hex(const char *s, size_t minlen, size_t maxlen)
{
    size_t n = 0;
    if (!s) return 0;
    while (s[n]) {
        char c = s[n];
        if (!((c >= '0' && c <= '9') || (c >= 'a' && c <= 'f') || (c >= 'A' && c <= 'F')))
            return 0;
        n++;
        if (n > maxlen) return 0;
    }
    return n >= minlen;
}

int bc_utf8_valid(const bc_u8 *s, size_t n)
{
    size_t i = 0;
    while (i < n) {
        bc_u8 c = s[i];
        size_t need;
        bc_u32 cp;
        size_t k;
        if (c < 0x80) { i++; continue; }
        if ((c & 0xE0) == 0xC0) { need = 1; cp = c & 0x1F; }
        else if ((c & 0xF0) == 0xE0) { need = 2; cp = c & 0x0F; }
        else if ((c & 0xF8) == 0xF0) { need = 3; cp = c & 0x07; }
        else return 0;
        if (i + need >= n) return 0; /* sequencia truncada */
        for (k = 1; k <= need; k++) {
            bc_u8 cc = s[i + k];
            if ((cc & 0xC0) != 0x80) return 0;
            cp = (cp << 6) | (cc & 0x3F);
        }
        if ((need == 1 && cp < 0x80) || (need == 2 && cp < 0x800) ||
            (need == 3 && cp < 0x10000) || cp > 0x10FFFF ||
            (cp >= 0xD800 && cp <= 0xDFFF))
            return 0;
        i += need + 1;
    }
    return 1;
}

void bc_trim(char *s)
{
    size_t n, st = 0;
    if (!s) return;
    n = strlen(s);
    while (n > 0 && (s[n - 1] == ' ' || s[n - 1] == '\t' || s[n - 1] == '\r' || s[n - 1] == '\n'))
        s[--n] = '\0';
    while (s[st] == ' ' || s[st] == '\t' || s[st] == '\r' || s[st] == '\n') st++;
    if (st) memmove(s, s + st, n - st + 1);
}

size_t bc_utf8_len(const char *s)
{
    size_t n = 0;
    while (*s) {
        if (((bc_u8)*s & 0xC0) != 0x80) n++;
        s++;
    }
    return n;
}

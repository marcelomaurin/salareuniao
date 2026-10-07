#include "config.h"
#include "bc_buf.h"
#include "bc_util.h"

#include <stdlib.h>
#include <string.h>

bc_buf *bc_buf_new(size_t len, int media)
{
    bc_buf *b = (bc_buf *)bc_xmalloc(sizeof(bc_buf) + len);
    pthread_mutex_init(&b->mu, NULL);
    b->refs = 1;
    b->media = media;
    b->len = len;
    b->data = (bc_u8 *)(b + 1);
    return b;
}

bc_buf *bc_buf_ref(bc_buf *b)
{
    pthread_mutex_lock(&b->mu);
    b->refs++;
    pthread_mutex_unlock(&b->mu);
    return b;
}

void bc_buf_unref(bc_buf *b)
{
    int left;
    if (!b) return;
    pthread_mutex_lock(&b->mu);
    left = --b->refs;
    pthread_mutex_unlock(&b->mu);
    if (left == 0) {
        pthread_mutex_destroy(&b->mu);
        free(b);
    }
}

void bc_bytes_init(bc_bytes *b) { b->p = NULL; b->len = 0; b->cap = 0; }

void bc_bytes_free(bc_bytes *b)
{
    free(b->p);
    bc_bytes_init(b);
}

void bc_bytes_append(bc_bytes *b, const void *data, size_t n)
{
    if (n == 0) return;
    if (b->len + n > b->cap) {
        size_t nc = b->cap ? b->cap : 4096;
        while (nc < b->len + n) nc *= 2;
        b->p = (bc_u8 *)bc_xrealloc(b->p, nc);
        b->cap = nc;
    }
    memcpy(b->p + b->len, data, n);
    b->len += n;
}

void bc_bytes_consume(bc_bytes *b, size_t n)
{
    if (n >= b->len) { b->len = 0; return; }
    memmove(b->p, b->p + n, b->len - n);
    b->len -= n;
}

void bc_bytes_clear(bc_bytes *b) { b->len = 0; }

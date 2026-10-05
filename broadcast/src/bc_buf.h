#ifndef BC_BUF_H
#define BC_BUF_H

#include "bc_types.h"
#include <pthread.h>

/*
 * Bloco imutavel com contador de referencia. Um mesmo quadro WebSocket de
 * midia e enfileirado em todos os ouvintes sem copia.
 * C90 nao tem atomicos: o contador e protegido por mutex proprio.
 */
typedef struct bc_buf {
    pthread_mutex_t mu;
    int    refs;
    int    media;      /* 1 = midia (pode ser descartada), 0 = controle */
    size_t len;
    bc_u8 *data;
} bc_buf;

bc_buf *bc_buf_new(size_t len, int media);
bc_buf *bc_buf_ref(bc_buf *b);
void    bc_buf_unref(bc_buf *b);

/* Buffer crescente simples (uso local de uma thread). */
typedef struct bc_bytes {
    bc_u8 *p;
    size_t len;
    size_t cap;
} bc_bytes;

void bc_bytes_init(bc_bytes *b);
void bc_bytes_free(bc_bytes *b);
void bc_bytes_append(bc_bytes *b, const void *data, size_t n);
void bc_bytes_consume(bc_bytes *b, size_t n);  /* remove n bytes do inicio */
void bc_bytes_clear(bc_bytes *b);

#endif

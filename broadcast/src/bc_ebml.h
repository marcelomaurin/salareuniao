#ifndef BC_EBML_H
#define BC_EBML_H

#include "bc_types.h"
#include "bc_buf.h"

/*
 * Parser EBML/WebM incremental minimo. Nao decodifica midia: so localiza
 * o fim do cabecalho de inicializacao (inicio do 1o Cluster), o inicio de
 * cada Cluster e se o primeiro bloco de video do Cluster e keyframe.
 * Aceita elementos cortados entre chamadas e tamanhos desconhecidos em
 * Segment e Cluster (formato do MediaRecorder em modo ao vivo).
 */

#define EBML_ID_EBML        0x1A45DFA3UL
#define EBML_ID_SEGMENT     0x18538067UL
#define EBML_ID_SEEKHEAD    0x114D9B74UL
#define EBML_ID_INFO        0x1549A966UL
#define EBML_ID_TRACKS      0x1654AE6BUL
#define EBML_ID_TRACKENTRY  0xAEUL
#define EBML_ID_TRACKNUMBER 0xD7UL
#define EBML_ID_TRACKTYPE   0x83UL
#define EBML_ID_CLUSTER     0x1F43B675UL
#define EBML_ID_CUES        0x1C53BB6BUL
#define EBML_ID_TAGS        0x1254C367UL
#define EBML_ID_CHAPTERS    0x1043A770UL
#define EBML_ID_ATTACH      0x1941A469UL
#define EBML_ID_TIMECODE    0xE7UL
#define EBML_ID_SIMPLEBLOCK 0xA3UL
#define EBML_ID_BLOCKGROUP  0xA0UL
#define EBML_ID_VOID        0xECUL

enum { BC_EBML_EV_CLUSTER = 1, BC_EBML_EV_KEY = 2, BC_EBML_EV_ERROR = 3 };

typedef struct bc_ebml_ev {
    int    type;
    bc_u64 off;    /* deslocamento absoluto do inicio do Cluster */
    int    key;    /* BC_EBML_EV_KEY: 1 se o Cluster comeca com keyframe */
} bc_ebml_ev;

typedef struct bc_ebml {
    bc_u64   off;            /* bytes ja consumidos (absoluto) */
    int      level;          /* 0 topo, 1 filhos do Segment, 2 filhos do Cluster */
    bc_u8    hdr[16];
    size_t   hdr_len;
    bc_u64   skip;           /* bytes restantes do elemento atual a pular */
    int      capturing;      /* 1 = acumulando Tracks */
    bc_bytes tracks;
    int      peeking;        /* 1 = lendo inicio de SimpleBlock */
    bc_u8    peek[12];
    size_t   peek_have, peek_need;
    bc_u64   peek_rest;
    int      cluster_known;
    bc_u64   cluster_end;
    bc_u64   cluster_off;
    int      key_decided;
    int      video_track;    /* 0 = desconhecido / sem video */
    int      tracks_seen;
    int      error;
} bc_ebml;

void bc_ebml_init(bc_ebml *p);
void bc_ebml_free(bc_ebml *p);
/* Consome n bytes; grava ate maxev eventos em ev. Retorna numero de eventos. */
int  bc_ebml_feed(bc_ebml *p, const bc_u8 *data, size_t n, bc_ebml_ev *ev, int maxev);

/* Le um VINT; retorna tamanho em bytes (1..8) ou 0 se invalido/incompleto. */
int  bc_ebml_vint(const bc_u8 *b, size_t n, bc_u64 *val, int keep_marker, int *all_ones);

#endif

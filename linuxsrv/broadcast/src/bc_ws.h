#ifndef BC_WS_H
#define BC_WS_H

#include "bc_types.h"
#include "bc_buf.h"

/* ---- HTTP Upgrade (RFC 6455 secao 4) ---- */

#define BC_HTTP_MAX_HEADER 8192

typedef struct bc_http_req {
    char path[256];
    char key[64];
    char origin[256];
    char xff[256];
    char real_ip[64];
    char user_agent[256];
    int  upgrade_ok;
    int  connection_ok;
    int  version;
} bc_http_req;

/*
 * Analisa o cabecalho acumulado em buf.
 * Retorna: 0 = incompleto; >0 = bytes consumidos (cabecalho completo e valido);
 * <0 = -codigo HTTP de erro (-400, -426, -431).
 */
int  bc_http_parse_upgrade(const char *buf, size_t len, bc_http_req *req);
/* Monta a resposta 101 em out (>= 256 bytes). */
void bc_ws_accept_response(const char *key, char *out, size_t outlen);
void bc_ws_accept_key(const char *key, char out[32]);

/* ---- Quadros (RFC 6455 secao 5) ---- */

enum {
    BC_WS_CONT = 0x0, BC_WS_TEXT = 0x1, BC_WS_BIN = 0x2,
    BC_WS_CLOSE = 0x8, BC_WS_PING = 0x9, BC_WS_PONG = 0xA
};

typedef struct bc_ws_frame {
    int     fin;
    int     opcode;
    bc_u8  *payload;   /* aponta para dentro do buffer de entrada (ja desmascarado) */
    size_t  len;
} bc_ws_frame;

/*
 * Analisa um quadro de cliente (mascara obrigatoria) e desmascara no lugar.
 * Retorna: 0 incompleto; >0 bytes consumidos; -1 erro de protocolo;
 * -2 quadro maior que max.
 */
long bc_ws_parse(bc_u8 *buf, size_t len, size_t max, bc_ws_frame *f);

/* Quadro de servidor (sem mascara) com payload = a + b concatenados. */
bc_buf *bc_ws_make(int opcode, const void *a, size_t alen, const void *b, size_t blen, int media);
bc_buf *bc_ws_make_text(const char *s);
bc_buf *bc_ws_make_close(int code, const char *reason);

/* Codifica quadro de cliente (com mascara) - usado nos testes. */
size_t bc_ws_encode_client(int opcode, int fin, const void *p, size_t n, const bc_u8 mask[4], bc_u8 *out);

#endif

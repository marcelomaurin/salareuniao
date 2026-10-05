#ifndef BC_CRYPTO_H
#define BC_CRYPTO_H

#include "bc_types.h"

/* SHA-1 (RFC 3174) - usado apenas no handshake WebSocket. */
void bc_sha1(const void *data, size_t len, bc_u8 out[20]);

/* SHA-256 (FIPS 180-4) e HMAC-SHA256 (RFC 2104). */
void bc_sha256(const void *data, size_t len, bc_u8 out[32]);
void bc_hmac_sha256(const void *key, size_t klen, const void *msg, size_t mlen, bc_u8 out[32]);

/* Base64 (RFC 4648). out deve ter 4*((n+2)/3)+1 bytes. Retorna tamanho. */
size_t bc_base64_encode(const bc_u8 *in, size_t n, char *out);
/* Retorna bytes decodificados ou -1 em erro. url=1 aceita alfabeto URL. */
long   bc_base64_decode(const char *in, size_t n, bc_u8 *out, size_t outmax, int url);

#endif

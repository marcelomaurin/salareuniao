#ifndef BC_UTIL_H
#define BC_UTIL_H

#include "bc_types.h"

bc_u64 bc_now_ms(void);           /* relogio monotonico em ms */
bc_u64 bc_wall_ms(void);          /* relogio de parede (epoch) em ms */
void   bc_sleep_ms(unsigned ms);

void  *bc_xmalloc(size_t n);
void  *bc_xcalloc(size_t n, size_t sz);
void  *bc_xrealloc(void *p, size_t n);
char  *bc_xstrdup(const char *s);

size_t bc_strlcpy(char *dst, const char *src, size_t size);
int    bc_random_bytes(void *buf, size_t n);
void   bc_hex(const bc_u8 *in, size_t n, char *out); /* out: 2n+1 */
void   bc_random_hex(char *out, size_t nbytes);      /* out: 2*nbytes+1 */
void   bc_uuid4(char *out);                          /* out: 37 */
int    bc_ct_equal(const void *a, const void *b, size_t n);
int    bc_is_hex(const char *s, size_t minlen, size_t maxlen);
int    bc_utf8_valid(const bc_u8 *s, size_t n);
void   bc_trim(char *s);
size_t bc_utf8_len(const char *s);

#endif

#ifndef BC_JSON_H
#define BC_JSON_H

#include "third_party/cjson/cJSON.h"
#include <stddef.h>

#define BC_JSON_MAX_BYTES 65536
#define BC_JSON_MAX_DEPTH 8

/* Faz o parse com limites de tamanho e profundidade; NULL se invalido. */
cJSON      *bc_json_parse(const char *s, size_t n);
/* String do campo ou NULL (ausente, nao-string ou maior que maxlen bytes). */
const char *bc_json_str(const cJSON *o, const char *k, size_t maxlen);
/* Inteiro do campo; def se ausente. *ok = 0 se presente mas invalido. */
long        bc_json_int(const cJSON *o, const char *k, long def, int *ok);
int         bc_json_bool(const cJSON *o, const char *k, int def);
/* Serializa e libera o objeto. O chamador libera o retorno com free(). */
char       *bc_json_take(cJSON *o);
cJSON      *bc_json_msg(const char *type);   /* {"v":1,"t":type} */

#endif

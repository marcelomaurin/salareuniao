#include "config.h"
#include "bc_json.h"

#include <stdlib.h>
#include <string.h>
#include <math.h>

static int depth_ok(const char *s, size_t n)
{
    size_t i;
    int d = 0, in_str = 0, esc = 0;
    for (i = 0; i < n; i++) {
        char c = s[i];
        if (in_str) {
            if (esc) esc = 0;
            else if (c == '\\') esc = 1;
            else if (c == '"') in_str = 0;
            continue;
        }
        if (c == '"') in_str = 1;
        else if (c == '{' || c == '[') { if (++d > BC_JSON_MAX_DEPTH) return 0; }
        else if (c == '}' || c == ']') d--;
    }
    return 1;
}

cJSON *bc_json_parse(const char *s, size_t n)
{
    cJSON *o;
    if (n == 0 || n > BC_JSON_MAX_BYTES) return NULL;
    if (!depth_ok(s, n)) return NULL;
    o = cJSON_ParseWithLength(s, n);
    if (o && !cJSON_IsObject(o)) {
        cJSON_Delete(o);
        return NULL;
    }
    return o;
}

const char *bc_json_str(const cJSON *o, const char *k, size_t maxlen)
{
    const cJSON *v = cJSON_GetObjectItemCaseSensitive(o, k);
    if (!cJSON_IsString(v) || !v->valuestring) return NULL;
    if (strlen(v->valuestring) > maxlen) return NULL;
    return v->valuestring;
}

long bc_json_int(const cJSON *o, const char *k, long def, int *ok)
{
    const cJSON *v = cJSON_GetObjectItemCaseSensitive(o, k);
    if (ok) *ok = 1;
    if (!v || cJSON_IsNull(v)) return def;
    if (!cJSON_IsNumber(v) || v->valuedouble != floor(v->valuedouble) ||
        v->valuedouble > 2147483647.0 || v->valuedouble < -2147483647.0) {
        if (ok) *ok = 0;
        return def;
    }
    return (long)v->valuedouble;
}

int bc_json_bool(const cJSON *o, const char *k, int def)
{
    const cJSON *v = cJSON_GetObjectItemCaseSensitive(o, k);
    if (cJSON_IsBool(v)) return cJSON_IsTrue(v) ? 1 : 0;
    return def;
}

char *bc_json_take(cJSON *o)
{
    char *s = cJSON_PrintUnformatted(o);
    cJSON_Delete(o);
    return s;
}

cJSON *bc_json_msg(const char *type)
{
    cJSON *o = cJSON_CreateObject();
    cJSON_AddNumberToObject(o, "v", 1);
    cJSON_AddStringToObject(o, "t", type);
    return o;
}

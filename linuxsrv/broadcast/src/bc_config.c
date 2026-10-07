/*
 * bc_config.c - leitura do arquivo INI (chave = valor, comentarios # ou ;)
 */
#include "config.h"
#include "bc_config.h"
#include "bc_util.h"

#include <stdio.h>
#include <stdlib.h>
#include <string.h>

bc_config g_cfg;

void bc_config_defaults(bc_config *c)
{
    memset(c, 0, sizeof(*c));
    bc_strlcpy(c->listen_addr, "127.0.0.1", sizeof(c->listen_addr));
    c->listen_port = 8090;
    c->io_threads = 4;
    c->max_clients = 2000;
    c->max_rooms = 200;
    c->max_clients_per_room = 300;
    c->max_frame_bytes = 1048576;
    c->max_queue_bytes = 4194304;
    c->max_cache_bytes = 8388608;
    c->hello_timeout_ms = 5000;
    c->ping_interval_ms = 15000;
    c->idle_timeout_ms = 45000;
    c->waiting_timeout_ms = 900000;
    c->speaker_pending_ms = 15000;
    c->room_linger_ms = 60000;
    c->invite_auto_admit = 1;
    c->chat_history = 50;
    bc_strlcpy(c->allowed_origin, "", sizeof(c->allowed_origin));
    c->trust_proxy = 1;
    bc_strlcpy(c->db_host, "127.0.0.1", sizeof(c->db_host));
    c->db_port = 3306;
    bc_strlcpy(c->db_name, "salareuniao", sizeof(c->db_name));
    bc_strlcpy(c->db_user, "salareuniao_bcast", sizeof(c->db_user));
    bc_strlcpy(c->log_file, "-", sizeof(c->log_file));
    bc_strlcpy(c->log_level, "info", sizeof(c->log_level));
}

static int set_int(const char *v, int *out, int lo, int hi)
{
    char *end;
    long x = strtol(v, &end, 10);
    if (*v == '\0' || *end != '\0' || x < lo || x > hi) return -1;
    *out = (int)x;
    return 0;
}

static int set_uns(const char *v, unsigned *out, unsigned lo, unsigned hi)
{
    int t;
    if (set_int(v, &t, (int)lo, (int)hi) != 0) return -1;
    *out = (unsigned)t;
    return 0;
}

static int set_size(const char *v, size_t *out, size_t lo, size_t hi)
{
    char *end;
    unsigned long x = strtoul(v, &end, 10);
    if (*v == '\0' || *end != '\0' || x < lo || x > hi) return -1;
    *out = (size_t)x;
    return 0;
}

#define STR(field) else if (strcmp(k, #field) == 0) { bc_strlcpy(c->field, v, sizeof(c->field)); return 0; }

static int apply(bc_config *c, const char *k, const char *v)
{
    if (0) { return 0; }
    STR(listen_addr)
    STR(rtc_bind_address)
    STR(rtc_ice_server)
    STR(allowed_origin)
    STR(db_host)
    STR(db_name)
    STR(db_user)
    STR(db_pass)
    STR(db_socket)
    STR(db_fixture)
    STR(log_file)
    STR(log_level)
    STR(pid_file)
    STR(base_url)
    else if (strcmp(k, "rtc_port_begin") == 0) return set_int(v, &c->rtc_port_begin, 0, 65535);
    else if (strcmp(k, "rtc_port_end") == 0) return set_int(v, &c->rtc_port_end, 0, 65535);
    else if (strcmp(k, "listen_port") == 0) return set_int(v, &c->listen_port, 1, 65535);
    else if (strcmp(k, "io_threads") == 0) return set_int(v, &c->io_threads, 1, 64);
    else if (strcmp(k, "max_clients") == 0) return set_int(v, &c->max_clients, 1, 100000);
    else if (strcmp(k, "max_rooms") == 0) return set_int(v, &c->max_rooms, 1, 10000);
    else if (strcmp(k, "max_clients_per_room") == 0) return set_int(v, &c->max_clients_per_room, 2, 10000);
    else if (strcmp(k, "max_frame_bytes") == 0) return set_size(v, &c->max_frame_bytes, 4096, 16777216);
    else if (strcmp(k, "max_queue_bytes") == 0) return set_size(v, &c->max_queue_bytes, 65536, 268435456);
    else if (strcmp(k, "max_cache_bytes") == 0) return set_size(v, &c->max_cache_bytes, 65536, 268435456);
    else if (strcmp(k, "hello_timeout_ms") == 0) return set_uns(v, &c->hello_timeout_ms, 500, 60000);
    else if (strcmp(k, "ping_interval_ms") == 0) return set_uns(v, &c->ping_interval_ms, 1000, 300000);
    else if (strcmp(k, "idle_timeout_ms") == 0) return set_uns(v, &c->idle_timeout_ms, 2000, 600000);
    else if (strcmp(k, "waiting_timeout_ms") == 0) return set_uns(v, &c->waiting_timeout_ms, 1000, 86400000);
    else if (strcmp(k, "speaker_pending_ms") == 0) return set_uns(v, &c->speaker_pending_ms, 1000, 600000);
    else if (strcmp(k, "room_linger_ms") == 0) return set_uns(v, &c->room_linger_ms, 0, 3600000);
    else if (strcmp(k, "invite_auto_admit") == 0) return set_int(v, &c->invite_auto_admit, 0, 1);
    else if (strcmp(k, "chat_history") == 0) return set_int(v, &c->chat_history, 0, 500);
    else if (strcmp(k, "trust_proxy") == 0) return set_int(v, &c->trust_proxy, 0, 1);
    else if (strcmp(k, "db_port") == 0) return set_int(v, &c->db_port, 1, 65535);
    return -2;
}

int bc_config_load(bc_config *c, const char *path, char *err, size_t errlen)
{
    FILE *fp;
    char line[1024];
    int lineno = 0;

    fp = fopen(path, "r");
    if (!fp) {
        snprintf(err, errlen, "nao foi possivel abrir %s", path);
        return -1;
    }
    while (fgets(line, sizeof(line), fp)) {
        char *eq, *k, *v;
        int r;
        lineno++;
        bc_trim(line);
        if (line[0] == '\0' || line[0] == '#' || line[0] == ';' || line[0] == '[') continue;
        eq = strchr(line, '=');
        if (!eq) {
            snprintf(err, errlen, "%s:%d: linha sem '='", path, lineno);
            fclose(fp);
            return -1;
        }
        *eq = '\0';
        k = line;
        v = eq + 1;
        bc_trim(k);
        bc_trim(v);
        if (v[0] != '"') {
            /* comentario no fim da linha: "valor   # comentario" */
            char *h = v;
            while ((h = strpbrk(h, "#;")) != NULL) {
                if (h > v && (h[-1] == ' ' || h[-1] == '\t')) { *h = '\0'; bc_trim(v); break; }
                h++;
            }
        }
        if (v[0] == '"' && strlen(v) >= 2 && v[strlen(v) - 1] == '"') {
            v[strlen(v) - 1] = '\0';
            v++;
        }
        r = apply(c, k, v);
        if (r == -2) {
            snprintf(err, errlen, "%s:%d: chave desconhecida '%s'", path, lineno, k);
            fclose(fp);
            return -1;
        }
        if (r != 0) {
            snprintf(err, errlen, "%s:%d: valor invalido para '%s'", path, lineno, k);
            fclose(fp);
            return -1;
        }
    }
    fclose(fp);
    return bc_config_validate(c, err, errlen);
}

int bc_config_validate(const bc_config *c, char *err, size_t errlen)
{
    if ((c->rtc_port_begin == 0) != (c->rtc_port_end == 0) || c->rtc_port_begin > c->rtc_port_end) {
        snprintf(err, errlen, "intervalo de portas WebRTC invalido"); return -1;
    }
    if (c->max_queue_bytes < c->max_frame_bytes) {
        snprintf(err, errlen, "max_queue_bytes deve ser >= max_frame_bytes");
        return -1;
    }
    if (c->idle_timeout_ms <= c->ping_interval_ms) {
        snprintf(err, errlen, "idle_timeout_ms deve ser maior que ping_interval_ms");
        return -1;
    }
    return 0;
}

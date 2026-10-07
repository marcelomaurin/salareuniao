#ifndef BC_CONFIG_H
#define BC_CONFIG_H

#include "bc_types.h"

typedef struct bc_config {
    char     listen_addr[64];
    int      listen_port;
    int      io_threads;
    char rtc_bind_address[64];
    char rtc_ice_server[512];
    int rtc_port_begin, rtc_port_end;
    int      max_clients;
    int      max_rooms;
    int      max_clients_per_room;
    size_t   max_frame_bytes;
    size_t   max_queue_bytes;
    size_t   max_cache_bytes;
    unsigned hello_timeout_ms;
    unsigned ping_interval_ms;
    unsigned idle_timeout_ms;
    unsigned waiting_timeout_ms;
    unsigned speaker_pending_ms;
    unsigned room_linger_ms;
    int      invite_auto_admit;
    int      chat_history;
    char     allowed_origin[512];
    int      trust_proxy;
    char     db_host[128];
    int      db_port;
    char     db_name[64];
    char     db_user[64];
    char     db_pass[128];
    char     db_socket[256];
    char     db_fixture[512];   /* usado pelo stub sem MySQL */
    char     log_file[512];
    char     log_level[16];
    char     pid_file[512];
    char     base_url[256];     /* para montar links de convite */
} bc_config;

void bc_config_defaults(bc_config *c);
/* Retorna 0 ok; -1 erro (mensagem em err). */
int  bc_config_load(bc_config *c, const char *path, char *err, size_t errlen);
int  bc_config_validate(const bc_config *c, char *err, size_t errlen);

extern bc_config g_cfg;

#endif

#ifndef BC_LOG_H
#define BC_LOG_H

enum { BC_LOG_LVL_ERROR = 0, BC_LOG_LVL_WARN, BC_LOG_LVL_INFO, BC_LOG_LVL_DEBUG, BC_LOG_LVL_TRACE };

int  bc_log_init(const char *file, int level);
void bc_log_reopen(void);
void bc_log_set_level(int level);
int  bc_log_level(void);
int  bc_log_level_from_name(const char *name);
void bc_log_set_thread_name(const char *name);
void bc_log_write(int level, const char *fmt, ...);
void bc_log_close(void);

/* C90 nao tem macros variadicas: as macros apenas escolhem o nivel. */
#define BC_LOG_ERROR bc_log_error
#define BC_LOG_WARN  bc_log_warn
#define BC_LOG_INFO  bc_log_info
#define BC_LOG_DEBUG bc_log_debug
#define BC_LOG_TRACE bc_log_trace

void bc_log_error(const char *fmt, ...);
void bc_log_warn(const char *fmt, ...);
void bc_log_info(const char *fmt, ...);
void bc_log_debug(const char *fmt, ...);
void bc_log_trace(const char *fmt, ...);

/* Mostra so os primeiros 8 caracteres de um token. */
const char *bc_log_token(const char *tok, char *buf12);

#endif

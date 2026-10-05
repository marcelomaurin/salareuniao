/*
 * bc_log.c - log thread-safe com niveis
 * Formato: 2026-10-05T15:12:00.123Z LEVEL [thread] mensagem
 */
#include "config.h"
#include "bc_log.h"
#include "bc_util.h"

#include <stdio.h>
#include <stdarg.h>
#include <string.h>
#include <time.h>
#include <pthread.h>

static pthread_mutex_t log_mutex = PTHREAD_MUTEX_INITIALIZER;
static FILE *log_fp = NULL;
static char log_path[512];
static int  log_lvl = BC_LOG_LVL_INFO;
static pthread_key_t name_key;
static pthread_once_t name_once = PTHREAD_ONCE_INIT;

static const char *lvl_names[] = { "ERROR", "WARN ", "INFO ", "DEBUG", "TRACE" };

static void make_key(void) { pthread_key_create(&name_key, NULL); }

int bc_log_init(const char *file, int level)
{
    pthread_once(&name_once, make_key);
    log_lvl = level;
    log_path[0] = '\0';
    if (file && *file && strcmp(file, "-") != 0) {
        bc_strlcpy(log_path, file, sizeof(log_path));
        log_fp = fopen(log_path, "a");
        if (!log_fp) {
            log_fp = stderr;
            return -1;
        }
    } else {
        log_fp = stderr;
    }
    return 0;
}

void bc_log_reopen(void)
{
    pthread_mutex_lock(&log_mutex);
    if (log_path[0]) {
        FILE *n = fopen(log_path, "a");
        if (n) {
            if (log_fp && log_fp != stderr) fclose(log_fp);
            log_fp = n;
        }
    }
    pthread_mutex_unlock(&log_mutex);
}

void bc_log_set_level(int level) { log_lvl = level; }
int  bc_log_level(void) { return log_lvl; }

int bc_log_level_from_name(const char *n)
{
    if (!n) return BC_LOG_LVL_INFO;
    if (strcmp(n, "error") == 0) return BC_LOG_LVL_ERROR;
    if (strcmp(n, "warn") == 0) return BC_LOG_LVL_WARN;
    if (strcmp(n, "debug") == 0) return BC_LOG_LVL_DEBUG;
    if (strcmp(n, "trace") == 0) return BC_LOG_LVL_TRACE;
    return BC_LOG_LVL_INFO;
}

void bc_log_set_thread_name(const char *name)
{
    pthread_once(&name_once, make_key);
    pthread_setspecific(name_key, name);
}

static void vlog(int level, const char *fmt, va_list ap)
{
    char ts[32];
    bc_u64 ms;
    time_t secs;
    struct tm tmv;
    const char *tn;
    FILE *fp;

    if (level > log_lvl) return;
    pthread_once(&name_once, make_key);
    ms = bc_wall_ms();
    secs = (time_t)(ms / 1000);
    gmtime_r(&secs, &tmv);
    strftime(ts, sizeof(ts), "%Y-%m-%dT%H:%M:%S", &tmv);
    tn = (const char *)pthread_getspecific(name_key);
    if (!tn) tn = "?";

    pthread_mutex_lock(&log_mutex);
    fp = log_fp ? log_fp : stderr;
    fprintf(fp, "%s.%03uZ %s [%s] ", ts, (unsigned)(ms % 1000), lvl_names[level], tn);
    vfprintf(fp, fmt, ap);
    fputc('\n', fp);
    fflush(fp);
    pthread_mutex_unlock(&log_mutex);
}

void bc_log_write(int level, const char *fmt, ...)
{
    va_list ap; va_start(ap, fmt); vlog(level, fmt, ap); va_end(ap);
}
void bc_log_error(const char *fmt, ...) { va_list ap; va_start(ap, fmt); vlog(BC_LOG_LVL_ERROR, fmt, ap); va_end(ap); }
void bc_log_warn(const char *fmt, ...)  { va_list ap; va_start(ap, fmt); vlog(BC_LOG_LVL_WARN, fmt, ap); va_end(ap); }
void bc_log_info(const char *fmt, ...)  { va_list ap; va_start(ap, fmt); vlog(BC_LOG_LVL_INFO, fmt, ap); va_end(ap); }
void bc_log_debug(const char *fmt, ...) { va_list ap; va_start(ap, fmt); vlog(BC_LOG_LVL_DEBUG, fmt, ap); va_end(ap); }
void bc_log_trace(const char *fmt, ...) { va_list ap; va_start(ap, fmt); vlog(BC_LOG_LVL_TRACE, fmt, ap); va_end(ap); }

void bc_log_close(void)
{
    pthread_mutex_lock(&log_mutex);
    if (log_fp && log_fp != stderr) fclose(log_fp);
    log_fp = stderr;
    pthread_mutex_unlock(&log_mutex);
}

const char *bc_log_token(const char *tok, char *buf12)
{
    size_t n;
    if (!tok || !*tok) return "-";
    n = strlen(tok);
    if (n > 8) n = 8;
    memcpy(buf12, tok, n);
    buf12[n] = '\0';
    if (strlen(tok) > 8) { buf12[n] = '.'; buf12[n + 1] = '.'; buf12[n + 2] = '\0'; }
    return buf12;
}

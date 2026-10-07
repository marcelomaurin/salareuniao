/*
 * main.c - bcastd: servidor de broadcast da Sala Reuniao
 *
 * Ordem de inicializacao: configuracao -> log -> banco -> rede.
 * A thread principal faz a manutencao periodica (salas vazias, orador que
 * nao iniciou, consulta de status da sala) a cada 250 ms.
 */
#include "config.h"
#include "bc_config.h"
#include "bc_log.h"
#include "bc_util.h"
#include "bc_net.h"
#include "bc_db.h"
#include "bc_room.h"
#include "bc_rtc.h"

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <signal.h>
#include <unistd.h>

static volatile sig_atomic_t g_stop = 0;
static volatile sig_atomic_t g_hup = 0;

static void on_term(int s) { (void)s; g_stop = 1; }
static void on_hup(int s) { (void)s; g_hup = 1; }

static void usage(void)
{
    fprintf(stderr,
            "bcastd %s - servico de broadcast da Sala Reuniao\n"
            "uso: bcastd [-c arquivo.conf] [-f] [-t] [-v]\n"
            "  -c  arquivo de configuracao (padrao %s/broadcast.conf)\n"
            "  -f  primeiro plano (nao grava pid_file)\n"
            "  -t  valida a configuracao e sai\n"
            "  -v  mostra a versao\n", BC_VERSION, BC_SYSCONFDIR);
}

static void install_signals(void)
{
    struct sigaction sa;
    memset(&sa, 0, sizeof(sa));
    sigemptyset(&sa.sa_mask);
    sa.sa_handler = on_term;
    sigaction(SIGTERM, &sa, NULL);
    sigaction(SIGINT, &sa, NULL);
    sa.sa_handler = on_hup;
    sigaction(SIGHUP, &sa, NULL);
    sa.sa_handler = SIG_IGN;
    sigaction(SIGPIPE, &sa, NULL);
}

static void write_pid(void)
{
    FILE *fp;
    if (!g_cfg.pid_file[0]) return;
    fp = fopen(g_cfg.pid_file, "w");
    if (!fp) { BC_LOG_WARN("nao foi possivel gravar %s", g_cfg.pid_file); return; }
    fprintf(fp, "%ld\n", (long)getpid());
    fclose(fp);
}

int main(int argc, char **argv)
{
    const char *conf = BC_SYSCONFDIR "/broadcast.conf";
    int test_only = 0, i;
    char err[512];

    for (i = 1; i < argc; i++) {
        if (strcmp(argv[i], "-c") == 0 && i + 1 < argc) conf = argv[++i];
        else if (strcmp(argv[i], "-f") == 0) { /* primeiro plano e o padrao (systemd) */ }
        else if (strcmp(argv[i], "-t") == 0) test_only = 1;
        else if (strcmp(argv[i], "-v") == 0) { printf("bcastd %s (%s)\n", BC_VERSION, BC_DB_BACKEND); return 0; }
        else { usage(); return 2; }
    }

    bc_config_defaults(&g_cfg);
    err[0] = '\0';
    if (bc_config_load(&g_cfg, conf, err, sizeof(err)) != 0) {
        fprintf(stderr, "bcastd: %s\n", err);
        return 1;
    }
    if (test_only) {
        printf("configuracao ok: %s\n", conf);
        return 0;
    }

    bc_log_init(g_cfg.log_file, bc_log_level_from_name(g_cfg.log_level));
    bc_log_set_thread_name("main");
    install_signals();
    BC_LOG_INFO("bcastd %s iniciando (backend %s)", BC_VERSION, BC_DB_BACKEND);

    if (bc_db_start() != 0) { BC_LOG_ERROR("falha ao iniciar a thread de banco"); return 1; }
    if (bc_net_start() != 0) { bc_db_stop(); return 1; }
    write_pid();

    while (!g_stop) {
        bc_sleep_ms(250);
        if (g_hup) {
            g_hup = 0;
            bc_log_reopen();
            BC_LOG_INFO("SIGHUP: log reaberto");
        }
        bc_rooms_maint();
    }

    BC_LOG_INFO("desligando: avisando clientes");
    bc_net_stop_accept();
    bc_rooms_shutdown("server_shutdown");
    bc_sleep_ms(500);
    bc_db_stop();
    bc_net_stop();
    bc_rtc_cleanup();
    bc_rooms_free_all();
    if (g_cfg.pid_file[0]) unlink(g_cfg.pid_file);
    BC_LOG_INFO("bcastd encerrado");
    bc_log_close();
    return 0;
}

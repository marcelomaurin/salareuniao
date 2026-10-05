#ifndef BC_PROTO_H
#define BC_PROTO_H

#include "bc_net.h"

/* Mensagem de texto (JSON) recebida de um cliente; roda na thread worker. */
void bc_proto_text(bc_conn *c, const char *s, size_t n);
/* Envia {"t":"error","code":..,"msg":..,"ref":id}. */
void bc_proto_error(bc_conn *c, const char *ref, const char *code, const char *msg);
/* Envia {"t":"ack","ref":id,"status":..,"error":..}. */
void bc_proto_ack(bc_conn *c, const char *ref, const char *status, const char *error);

#endif

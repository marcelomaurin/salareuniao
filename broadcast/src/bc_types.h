/*
 * bc_types.h - tipos basicos do servico broadcast (C90 + POSIX)
 *
 * Todo uso de inteiros de 64 bits fica isolado aqui. O codigo e compilado
 * com -std=c90 -pedantic -Wno-long-long.
 */
#ifndef BC_TYPES_H
#define BC_TYPES_H

#include <stddef.h>

typedef unsigned char      bc_u8;
typedef unsigned short     bc_u16;
typedef unsigned int       bc_u32;
typedef unsigned long long bc_u64;
typedef long long          bc_i64;

#define BC_TRUE  1
#define BC_FALSE 0

#define BC_PKEY_LEN   64   /* participant_key: 64 caracteres hex */
#define BC_TOKEN_MAX  128
#define BC_NAME_MAX   120
#define BC_IP_MAX     64
#define BC_UA_MAX     255
#define BC_EMAIL_MAX  190

#endif

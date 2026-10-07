#ifndef BC_RTC_H
#define BC_RTC_H
#include "bc_net.h"
#include "bc_json.h"

/* Optional libdatachannel transport. All entry points run on the owner worker. */
int bc_rtc_signal(bc_conn *c, const char *type, cJSON *o);
int bc_rtc_send_frame(bc_conn *c, const bc_buf *b);
void bc_rtc_destroy(bc_conn *c);
void bc_rtc_cleanup(void);
#endif

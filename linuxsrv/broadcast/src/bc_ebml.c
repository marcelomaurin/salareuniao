/*
 * bc_ebml.c - parser incremental minimo de WebM (ver bc_ebml.h)
 */
#include "config.h"
#include "bc_ebml.h"

#include <string.h>

void bc_ebml_init(bc_ebml *p)
{
    memset(p, 0, sizeof(*p));
    bc_bytes_init(&p->tracks);
}

void bc_ebml_free(bc_ebml *p)
{
    bc_bytes_free(&p->tracks);
}

int bc_ebml_vint(const bc_u8 *b, size_t n, bc_u64 *val, int keep_marker, int *all_ones)
{
    int len = 1, i;
    bc_u8 mask = 0x80;
    bc_u64 v;
    int ones;
    if (n == 0) return 0;
    while (len <= 8 && !(b[0] & mask)) { mask >>= 1; len++; }
    if (len > 8) return 0;
    if ((size_t)len > n) return 0;
    v = keep_marker ? b[0] : (bc_u64)(b[0] & (mask - 1));
    ones = ((b[0] & (mask - 1)) == (bc_u8)(mask - 1));
    for (i = 1; i < len; i++) {
        v = (v << 8) | b[i];
        if (b[i] != 0xFF) ones = 0;
    }
    if (val) *val = v;
    if (all_ones) *all_ones = ones;
    return len;
}

static int is_segment_child(bc_u64 id)
{
    return id == EBML_ID_CLUSTER || id == EBML_ID_CUES || id == EBML_ID_TAGS ||
           id == EBML_ID_INFO || id == EBML_ID_TRACKS || id == EBML_ID_SEEKHEAD ||
           id == EBML_ID_CHAPTERS || id == EBML_ID_ATTACH;
}

static bc_u64 read_uint(const bc_u8 *b, bc_u64 n)
{
    bc_u64 v = 0, i;
    for (i = 0; i < n && i < 8; i++) v = (v << 8) | b[i];
    return v;
}

/* Le Tracks -> TrackEntry -> TrackNumber/TrackType e acha a trilha de video. */
static void parse_tracks(bc_ebml *p)
{
    const bc_u8 *b = p->tracks.p;
    size_t n = p->tracks.len, i = 0;
    p->tracks_seen = 1;
    while (i < n) {
        bc_u64 id, sz;
        int il, sl, ones;
        il = bc_ebml_vint(b + i, n - i, &id, 1, NULL);
        if (!il) return;
        sl = bc_ebml_vint(b + i + il, n - i - il, &sz, 0, &ones);
        if (!sl || ones) return;
        i += (size_t)(il + sl);
        if (sz > n - i) return;
        if (id == EBML_ID_TRACKENTRY) {
            size_t j = i, e = i + (size_t)sz;
            bc_u64 num = 0, type = 0;
            while (j < e) {
                bc_u64 cid, csz;
                int cl, csl, cones;
                cl = bc_ebml_vint(b + j, e - j, &cid, 1, NULL);
                if (!cl) break;
                csl = bc_ebml_vint(b + j + cl, e - j - cl, &csz, 0, &cones);
                if (!csl || cones) break;
                j += (size_t)(cl + csl);
                if (csz > e - j) break;
                if (cid == EBML_ID_TRACKNUMBER) num = read_uint(b + j, csz);
                else if (cid == EBML_ID_TRACKTYPE) type = read_uint(b + j, csz);
                j += (size_t)csz;
            }
            if (type == 1 && num > 0 && p->video_track == 0) p->video_track = (int)num;
        }
        i += (size_t)sz;
    }
}

static void push_ev(bc_ebml_ev *ev, int *nev, int maxev, int type, bc_u64 off, int key)
{
    if (*nev >= maxev) return;
    ev[*nev].type = type;
    ev[*nev].off = off;
    ev[*nev].key = key;
    (*nev)++;
}

static void decide_block(bc_ebml *p, bc_ebml_ev *ev, int *nev, int maxev)
{
    bc_u64 track;
    int tl;
    if (p->key_decided || p->level != 2) return;
    tl = bc_ebml_vint(p->peek, p->peek_have, &track, 0, NULL);
    if (!tl || p->peek_have < (size_t)tl + 3) return;
    if (p->video_track != 0 && (int)track != p->video_track) return;
    p->key_decided = 1;
    push_ev(ev, nev, maxev, BC_EBML_EV_KEY, p->cluster_off, (p->peek[tl + 2] & 0x80) ? 1 : 0);
}

/* Processa um cabecalho de elemento completo. */
static void on_element(bc_ebml *p, bc_u64 id, bc_u64 size, int unknown, bc_u64 start,
                       bc_ebml_ev *ev, int *nev, int maxev)
{
    if (p->level == 2) {
        if (is_segment_child(id) || (p->cluster_known && start >= p->cluster_end)) {
            p->level = 1;
        }
    }
    if (p->level == 0) {
        if (id == EBML_ID_SEGMENT) { p->level = 1; return; }
        if (unknown) { p->error = 1; return; }
        p->skip = size;
        return;
    }
    if (p->level == 1) {
        if (id == EBML_ID_CLUSTER) {
            p->level = 2;
            p->cluster_off = start;
            p->cluster_known = !unknown;
            p->cluster_end = unknown ? 0 : (p->off + size);
            p->key_decided = 0;
            push_ev(ev, nev, maxev, BC_EBML_EV_CLUSTER, start, 0);
            return;
        }
        if (unknown) { p->error = 1; return; }
        if (id == EBML_ID_TRACKS && size < 65536) {
            p->capturing = 1;
            bc_bytes_clear(&p->tracks);
        }
        p->skip = size;
        if (size == 0 && p->capturing) { p->capturing = 0; parse_tracks(p); }
        return;
    }
    /* level 2: filhos do Cluster */
    if (unknown) { p->error = 1; return; }
    if (id == EBML_ID_SIMPLEBLOCK && !p->key_decided) {
        p->peeking = 1;
        p->peek_have = 0;
        p->peek_need = (size < sizeof(p->peek)) ? (size_t)size : sizeof(p->peek);
        p->peek_rest = size - p->peek_need;
        if (p->peek_need == 0) { p->peeking = 0; p->skip = 0; }
        return;
    }
    p->skip = size;
}

int bc_ebml_feed(bc_ebml *p, const bc_u8 *data, size_t n, bc_ebml_ev *ev, int maxev)
{
    size_t i = 0;
    int nev = 0;
    if (p->error) return 0;
    while (i < n && !p->error) {
        if (p->peeking) {
            size_t take = p->peek_need - p->peek_have;
            if (take > n - i) take = n - i;
            memcpy(p->peek + p->peek_have, data + i, take);
            p->peek_have += take; i += take; p->off += take;
            if (p->peek_have == p->peek_need) {
                p->peeking = 0;
                decide_block(p, ev, &nev, maxev);
                p->skip = p->peek_rest;
            }
            continue;
        }
        if (p->skip > 0) {
            size_t take = (p->skip < (bc_u64)(n - i)) ? (size_t)p->skip : (n - i);
            if (p->capturing) bc_bytes_append(&p->tracks, data + i, take);
            p->skip -= take; i += take; p->off += take;
            if (p->skip == 0 && p->capturing) { p->capturing = 0; parse_tracks(p); }
            continue;
        }
        /* Acumula cabecalho do elemento (ID + tamanho). */
        p->hdr[p->hdr_len++] = data[i++];
        p->off++;
        {
            bc_u64 id, size;
            int il, sl, ones;
            il = bc_ebml_vint(p->hdr, p->hdr_len, &id, 1, NULL);
            if (!il) {
                if (p->hdr_len >= 4 && !(p->hdr[0] & 0xF0)) p->error = 1;
                continue;
            }
            if (il > 4) { p->error = 1; break; }
            if (p->hdr_len == (size_t)il) continue;
            sl = bc_ebml_vint(p->hdr + il, p->hdr_len - (size_t)il, &size, 0, &ones);
            if (!sl) {
                if (p->hdr_len >= sizeof(p->hdr)) p->error = 1;
                continue;
            }
            {
                bc_u64 start = p->off - p->hdr_len;
                p->hdr_len = 0;
                on_element(p, id, size, ones, start, ev, &nev, maxev);
            }
        }
    }
    if (p->error) push_ev(ev, &nev, maxev, BC_EBML_EV_ERROR, p->off, 0);
    return nev;
}

#!/bin/bash
# Attached drives detection — three methods in priority order:
#   1. lsiutil -a 42,0  (OS device name map — most reliable)
#   2. lsiutil -a 16,0  (device config page for SAS/PHY detail, flexible regex)
#   3. sysfs scan       (fallback when lsiutil returns no usable device rows)

LSIUTIL="/usr/local/emhttp/plugins/lsiutil/lsiutil.x86_64"
CFG="/boot/config/plugins/lsiutil/lsiutil.cfg"
PORT=1
[ -f "$CFG" ] && source "$CFG" && PORT="${HBA_PORT:-1}"

if [ ! -x "$LSIUTIL" ]; then
    echo '{"error":"lsiutil binary not found"}'
    exit 1
fi

# ── Method 1: OS device name mapping ────────────────────────────────────────
# -a 42,0 maps bus:target → OS device name (/dev/sdX)
# Known output formats:
#   " 0   0   0  /dev/sda"  (bus target lun device)
#   " 0   0  /dev/sda"      (bus target device)
OS_OUT=$("$LSIUTIL" -p"$PORT" -a 42,0 2>/dev/null)

TMPOS=$(mktemp)
echo "$OS_OUT" | awk '
/\/dev\/[a-z]/ {
    bus=0; tgt=0; dev=""; num=0
    for (i=1; i<=NF; i++) {
        if ($i ~ /^\/dev\//) { dev=$i }
        else if ($i ~ /^[0-9]+$/) {
            num++
            if      (num==1) bus=$i+0
            else if (num==2) tgt=$i+0
        }
    }
    if (dev != "") printf "%d_%d %s\n", bus, tgt, dev
}' > "$TMPOS"

# ── Method 2: Device config page (SAS address / PHY / enclosure) ─────────────
# -a 16,0 shows per-device config page rows
# Flexible regex: 8-16 hex chars, case-insensitive, any column order
DEV_OUT=$("$LSIUTIL" -p"$PORT" -a 16,0 2>/dev/null)

TMPDEV=$(mktemp)
echo "$DEV_OUT" | awk '
/^[[:space:]]+[0-9]+[[:space:]]+[0-9]+[[:space:]]+[0-9A-Fa-f]{8,16}/ {
    bus=$1+0; tgt=$2+0
    sas=tolower($3); handle=$4
    encl  = (NF>=5) ? $5 : "0000"
    slot  = (NF>=6) ? $6+0 : 0
    phy   = $NF+0
    printf "%d_%d %d %d %s %s %s %d %d\n", bus, tgt, bus, tgt, sas, handle, encl, slot, phy
}' > "$TMPDEV"

# ── Method 3: sysfs fallback ─────────────────────────────────────────────────
# If lsiutil OS-name map returned nothing, scan mpt3sas/mpt2sas hosts in sysfs
if [ ! -s "$TMPOS" ]; then
    for host_dir in /sys/class/scsi_host/host*/; do
        proc=$(cat "${host_dir}proc_name" 2>/dev/null)
        case "$proc" in mpt3sas|mpt2sas|mptsas) ;; *) continue ;; esac

        host_num=${host_dir%/}; host_num=${host_num##*host}

        for tgt_dir in "${host_dir}device/target${host_num}:"[0-9]*/; do
            [ -d "$tgt_dir" ] || continue
            IFS=':' read -r _ ch tg <<< "${tgt_dir##*/target}"

            for lun_dir in "${tgt_dir}"*/; do
                [ -d "$lun_dir" ] || continue
                blk=$(ls "${lun_dir}block/" 2>/dev/null | head -1)
                [ -n "$blk" ] || continue
                printf "%d_%d /dev/%s\n" "${ch:-0}" "${tg:-0}" "$blk" >> "$TMPOS"
            done
        done
    done
fi

# ── Build JSON from OS-name map joined with device detail ─────────────────────
awk '
BEGIN { first=1; printf "{\"drives\":[" }
NR==FNR {
    os[$1]=$2
    n++; order[n]=$1
    next
}
{ devinfo[$1]=$0 }
END {
    for (i=1; i<=n; i++) {
        key=order[i]; dev=os[key]
        split(key, p, "_")
        bus=p[1]+0; tgt=p[2]+0
        sas=""; handle=""; encl=""; slot=0; phy=0
        if (key in devinfo) {
            split(devinfo[key], f, " ")
            sas=f[5]; handle=f[6]; encl=f[7]; slot=f[8]+0; phy=f[9]+0
        }
        if (!first) printf ","
        first=0
        printf "{\"bus\":%d,\"target\":%d,\"sas_address\":\"%s\",\"handle\":\"%s\",\"encl\":\"%s\",\"slot\":%d,\"phy\":%d,\"os_name\":\"%s\"}",
            bus, tgt, sas, handle, encl, slot, phy, dev
    }
    printf "]}"
}
' "$TMPOS" "$TMPDEV"

rm -f "$TMPOS" "$TMPDEV"

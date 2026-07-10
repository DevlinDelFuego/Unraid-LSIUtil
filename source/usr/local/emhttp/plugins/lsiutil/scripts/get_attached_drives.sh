#!/bin/bash
# Attached drives.
#
# Primary source: /sys/class/scsi_device/H:C:T:L/ - the kernel's flat SCSI device
# registry, scoped to host numbers owned by the mpt2sas/mpt3sas/mptsas driver.
# This is used instead of lsiutil's own "-a 42,0" OS-name listing (which doesn't
# produce usable output on some SAS3 cards/firmware) and instead of hand-walking
# scsi_host's device tree (which only works for direct-attached drives - when a
# SAS expander is in the path, target directories sit many levels deeper, e.g.
# hostN/port-N:0/expander-N:0/port-N:0:X/end_device-N:0:X/targetN:C:T/..., not
# directly under hostN). /sys/class/scsi_device is flat regardless of topology
# depth, so it works the same for direct-attach and expander-attached drives.
#
# Enrichment: SAS address + PHY from sysfs sas_end_device (also flat/topology-agnostic).

HOSTS=()
for h in /sys/class/scsi_host/host*/; do
    proc=$(cat "${h}proc_name" 2>/dev/null)
    case "$proc" in mpt3sas|mpt2sas|mptsas) ;; *) continue ;; esac
    hn=${h%/}; hn=${hn##*host}
    HOSTS+=("$hn")
done

if [ ${#HOSTS[@]} -eq 0 ]; then
    echo '{"drives":[],"note":"No mpt2sas/mpt3sas/mptsas host adapter found"}'
    exit 0
fi

# ── OS device names, scoped to our hosts ─────────────────────────────────────
TMPOS=$(mktemp)
for sd in /sys/class/scsi_device/*:*:*:*/; do
    [ -d "$sd" ] || continue
    IFS=':' read -r hn ch tg lu <<< "$(basename "${sd%/}")"
    case " ${HOSTS[*]} " in *" $hn "*) ;; *) continue ;; esac
    [ "${lu:-0}" = "0" ] || continue
    blk=$(ls "${sd}device/block/" 2>/dev/null | head -1)
    [ -n "$blk" ] || blk=$(ls "${sd}block/" 2>/dev/null | head -1)
    [ -n "$blk" ] && printf "%d_%d /dev/%s\n" "${ch:-0}" "${tg:-0}" "$blk" >> "$TMPOS"
done

# ── SAS address + PHY from sysfs sas_end_device ───────────────────────────────
TMPSAS=$(mktemp)
if [ -d "/sys/class/sas_end_device" ]; then
    for ed in /sys/class/sas_end_device/end_device-*/; do
        [ -e "$ed" ] || continue
        sas=$(sed 's/0x//' "${ed}sas_address" 2>/dev/null | tr '[:lower:]' '[:upper:]' | tr -d ' \n')
        phy=$(tr -d ' \n' < "${ed}phy_identifier" 2>/dev/null)
        [ -n "$sas" ] || continue
        # Traverse from end_device/device into the SCSI+block device subtree
        blk_dir=$(find -L "${ed}device" -maxdepth 12 -type d -name 'block' 2>/dev/null | head -1)
        blk=$(ls "$blk_dir" 2>/dev/null | head -1)
        [ -n "$blk" ] || continue
        printf "/dev/%s %s %s\n" "$blk" "$sas" "${phy:-0}"
    done
fi > "$TMPSAS"

if [ ! -s "$TMPOS" ]; then
    echo '{"drives":[]}'
    rm -f "$TMPOS" "$TMPSAS"
    exit 0
fi

# ── Build JSON: join OS-name list with sysfs SAS/PHY map ─────────────────────
awk '
BEGIN { first=1; printf "{\"drives\":[" }
NR==FNR {
    # TMPOS: "bus_tgt /dev/sdX"
    os[$1]=$2; n++; ord[n]=$1
    next
}
{
    # TMPSAS: "/dev/sdX sas_addr phy"
    sasmap[$1]=$2; phymap[$1]=$3
}
END {
    for (i=1; i<=n; i++) {
        key=ord[i]; dev=os[key]
        split(key, p, "_"); bus=p[1]+0; tgt=p[2]+0
        sas=(dev in sasmap) ? sasmap[dev] : ""
        # For SATA drives sas_end_device has no entry; target == PHY for direct-attached HBAs
        phy=(dev in phymap) ? phymap[dev]+0 : tgt
        if (!first) printf ","
        first=0
        printf "{\"bus\":%d,\"target\":%d,\"sas_address\":\"%s\",\"handle\":\"\",\"encl\":\"\",\"slot\":0,\"phy\":%d,\"os_name\":\"%s\"}",
            bus, tgt, sas, phy, dev
    }
    printf "]}"
}
' "$TMPOS" "$TMPSAS"

rm -f "$TMPOS" "$TMPSAS"

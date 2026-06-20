#!/bin/bash
# Attached drives: SAS device list joined with OS device names
# Uses:  -a 16,0 → Display attached devices (reads config pages, no bus scan)
#        -a 42,0 → Display OS names for devices

LSIUTIL="/usr/local/emhttp/plugins/lsiutil/lsiutil.x86_64"
CFG="/boot/config/plugins/lsiutil/lsiutil.cfg"

PORT=1
[ -f "$CFG" ] && source "$CFG" && PORT="${HBA_PORT:-1}"

if [ ! -x "$LSIUTIL" ]; then
    echo '{"error":"lsiutil binary not found"}'
    exit 1
fi

DEVICE_OUT=$("$LSIUTIL" -p"$PORT" -a 16,0 2>/dev/null)
OS_OUT=$("$LSIUTIL"     -p"$PORT" -a 42,0 2>/dev/null)

if [ -z "$DEVICE_OUT" ]; then
    echo '{"error":"No device data returned from HBA."}'
    exit 1
fi

# Build OS-name lookup file:
#   OS_OUT lines:  " 0   0  /dev/sda"
TMPOS=$(mktemp)
echo "$OS_OUT" | awk '/^[[:space:]]+[0-9]+[[:space:]]+[0-9]+[[:space:]]+\/dev\// {
    printf "%d_%d %s\n", $1+0, $2+0, $3
}' > "$TMPOS"

# Build device data file.
# Device page lines with bus+target:
#   " 0   0  4433221107060504   0002    0000 00  00404011 0040 00   0   0000    7"
#   Fields: bus tgt SASAddr Handle Encl Slot DevInfo ... PhyNum(=$NF)
TMPDEV=$(mktemp)
echo "$DEVICE_OUT" | awk '/^[[:space:]]+[0-9]+[[:space:]]+[0-9]+[[:space:]]+[0-9a-f]{16}/ {
    printf "%d_%d %d %d %s %s %s %d %d\n", $1+0, $2+0, $1+0, $2+0, $3, $4, $5, $6+0, $NF+0
}' > "$TMPDEV"

# Join on "bus_target" key, emit JSON array
awk '
BEGIN { first=1; printf "{\"drives\":[" }
NR==FNR { os[$1]=$2; next }
{
    key=$1; bus=$2; tgt=$3; sas=$4; handle=$5; encl=$6; slot=$7; phy=$8
    osname = (key in os) ? os[key] : ""
    if (!first) printf ","
    first=0
    printf "{\"bus\":%d,\"target\":%d,\"sas_address\":\"%s\",\"handle\":\"%s\",\"encl\":\"%s\",\"slot\":%d,\"phy\":%d,\"os_name\":\"%s\"}",
        bus, tgt, sas, handle, encl, slot, phy, osname
}
END { printf "]}" }
' "$TMPOS" "$TMPDEV"

rm -f "$TMPOS" "$TMPDEV"

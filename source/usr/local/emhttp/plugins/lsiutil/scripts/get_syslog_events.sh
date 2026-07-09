#!/bin/bash
# Historical drive/HBA errors pulled from the syslog.
# The HBA's own firmware event log (get_event_log.sh) only holds LIVE entries -
# it does not survive a reboot or reflect drive failures that already happened.
# The syslog does, so this is the only place to see "what happened to that drive
# before I rebooted".
#
# Scoped two ways:
#   - lines naming the mpt2sas/mpt3sas/mptsas driver directly (IOC resets, faults)
#   - "sd H:C:T:L" kernel disk I/O errors, scoped to host numbers owned by that driver
#   - Unraid's own disk-health messages (MBR read error, disabled, dropped), which
#     don't carry a host number so they're matched unscoped

MAX_ENTRIES=200

HOSTS=()
for h in /sys/class/scsi_host/host*/; do
    proc=$(cat "${h}proc_name" 2>/dev/null)
    case "$proc" in mpt3sas|mpt2sas|mptsas) ;; *) continue ;; esac
    hn=${h%/}; hn=${hn##*host}
    HOSTS+=("$hn")
done

FILES=$(ls -tr /var/log/syslog* 2>/dev/null)
if [ -z "$FILES" ]; then
    echo '{"entries":[],"note":"No syslog files found at /var/log/syslog"}'
    exit 0
fi

PATTERN='mpt[23]?sas|MBR read error|is disabled|dropped|I/O error|Sense Key|ata[0-9]+.*exception|device offline'
if [ ${#HOSTS[@]} -gt 0 ]; then
    HOST_ALT=$(IFS='|'; echo "${HOSTS[*]}")
    PATTERN="$PATTERN|sd (${HOST_ALT}):"
fi

RAW=$(grep -hE "$PATTERN" $FILES 2>/dev/null | tail -n "$MAX_ENTRIES")

if [ -z "$RAW" ]; then
    echo '{"entries":[],"note":"No matching entries found in syslog"}'
    exit 0
fi

echo "$RAW" | awk '
BEGIN { first = 1; printf "{\"entries\":[" }
NF {
    line = $0
    gsub(/\\/, "\\\\", line)
    gsub(/"/, "\\\"", line)
    if (!first) printf ","
    first = 0
    printf "{\"line\":\"%s\"}", line
}
END { printf "]}" }
'

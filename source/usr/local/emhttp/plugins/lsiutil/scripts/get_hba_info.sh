#!/bin/bash
# Overview data: temperature, PCIe info, card model, firmware, board name.
#
# Reads every detected controller, not just the one selected in Settings -
# systems with multiple physical HBAs (issue #3) previously only ever saw the
# single port picked by HBA_PORT, with no way to check the others without
# repeatedly changing that setting. The HBA_PORT-selected controller's fields
# are still emitted at the top level (dashboard tile / alert notifications
# only ever watch one card), and the full set is under "controllers".

PLUGIN_DIR="/boot/config/plugins/lsiutil"
LSIUTIL="/usr/local/emhttp/plugins/lsiutil/lsiutil.x86_64"
CFG="$PLUGIN_DIR/lsiutil.cfg"

PORT=1
ALERT=80

if [ -f "$CFG" ]; then
    source "$CFG"
    PORT="${HBA_PORT:-1}"
    ALERT="${ALERT_THRESHOLD:-80}"
fi

if [ ! -x "$LSIUTIL" ]; then
    echo '{"error":"lsiutil binary not found. Re-install the plugin."}'
    exit 1
fi

# Banner lists every adapter found, one row per port (1-indexed). Read once
# and reuse per-port below instead of re-invoking lsiutil for each.
BANNER=$(printf "0\n" | "$LSIUTIL" 2>/dev/null)
NUM_PORTS=$(echo "$BANNER" | grep -oE '^[0-9]+ MPT Port' | grep -oE '^[0-9]+')
[ -n "$NUM_PORTS" ] || NUM_PORTS=1
[ "$NUM_PORTS" -le 16 ] || NUM_PORTS=16

BOARD_RAW=$("$LSIUTIL" -b 2>/dev/null)

parse_hex() { echo "$1" | grep "$2" | grep -oE '0x[0-9A-Fa-f]+' | head -1; }

# Emits one JSON object (no trailing newline) describing controller N.
read_controller() {
    local N="$1"
    local IOC_OUTPUT TEMP_HEX TEMP TEMP_SUPPORTED
    local PCIE_WIDTH_HEX PCIE_WIDTH PCIE_SPEED_HEX PCIE_SPEED POWER_HEX POWER_MODE
    local CARD_LINE MODEL PORT_NAME FW_RAW FW_VER
    local BOARD_LINE BOARD_NAME PCI_BUS PCI_DEV STATUS

    IOC_OUTPUT=$("$LSIUTIL" -p"$N" -a 25,2,0,0 2>/dev/null)
    TEMP_HEX=$(echo "$IOC_OUTPUT" | grep "IOCTemperature:" | grep -oE '0x[0-9A-Fa-f]+' | head -1)
    if [ -z "$TEMP_HEX" ]; then
        printf '{"port":%d,"error":"Temperature read failed on port %d."}' "$N" "$N"
        return
    fi
    TEMP=$((16#${TEMP_HEX#0x}))

    # MPI2_IOUNITPAGE7_IOC_TEMP_NOT_PRESENT: firmware reports 0 when the chip has no
    # onboard temperature sensor (e.g. SAS2008-based cards like the 9201-16e/9211-8i).
    if [ "$TEMP" -eq 0 ]; then TEMP_SUPPORTED=false; else TEMP_SUPPORTED=true; fi

    PCIE_WIDTH_HEX=$(parse_hex "$IOC_OUTPUT" "PCIeWidth:")
    case "${PCIE_WIDTH_HEX,,}" in
        0x01) PCIE_WIDTH="x1"  ;; 0x02) PCIE_WIDTH="x2"  ;;
        0x04) PCIE_WIDTH="x4"  ;; 0x08) PCIE_WIDTH="x8"  ;;
        0x10) PCIE_WIDTH="x16" ;; *)    PCIE_WIDTH=""     ;;
    esac

    PCIE_SPEED_HEX=$(parse_hex "$IOC_OUTPUT" "PCIeSpeed:")
    case "${PCIE_SPEED_HEX,,}" in
        0x01) PCIE_SPEED="Gen1 (2.5 GT/s)" ;;
        0x02) PCIE_SPEED="Gen2 (5.0 GT/s)" ;;
        0x04) PCIE_SPEED="Gen3 (8.0 GT/s)" ;;
        *)    PCIE_SPEED="" ;;
    esac

    POWER_HEX=$(parse_hex "$IOC_OUTPUT" "CurrentPowerMode:")
    case "${POWER_HEX,,}" in
        0x00) POWER_MODE="Full"    ;;
        0x08) POWER_MODE="Reduced" ;;
        0x10) POWER_MODE="Standby" ;;
        *)    POWER_MODE=""        ;;
    esac

    # Chip model, firmware revision, port name from the banner row for this port.
    CARD_LINE=$(echo "$BANNER" | grep -E "^\s+[0-9]+\.\s+ioc" | sed -n "${N}p")
    MODEL=$(echo "$CARD_LINE"    | grep -oE 'SAS[0-9]+[A-Za-z0-9]*' | head -1)
    PORT_NAME=$(echo "$CARD_LINE" | awk '{print $2}')

    # Firmware: "14000700" → "14.00.07.00"
    FW_RAW=$(echo "$CARD_LINE" | grep -oE '[0-9a-f]{8}' | head -1)
    if [ -n "$FW_RAW" ]; then
        FW_VER="${FW_RAW:0:2}.${FW_RAW:2:2}.${FW_RAW:4:2}.${FW_RAW:6:2}"
    else
        FW_VER="Unknown"
    fi

    # Board manufacturing info: product name, PCI location.
    BOARD_LINE=$(echo "$BOARD_RAW" | grep "ioc" | sed -n "${N}p")
    # Board name may contain spaces ("Avago SAS3216"), so plain word splitting
    # truncates it. The -b output is fixed-width; derive the Board Name column
    # boundaries from the header line and cut that range. Falls back to the old
    # single-word behaviour if the header is ever missing.
    local BOARD_HDR BN_START BA_START
    BOARD_HDR=$(echo "$BOARD_RAW" | grep "Board Name" | head -1)
    BN_START=$(awk -v h="$BOARD_HDR" 'BEGIN{print index(h,"Board Name")}')
    BA_START=$(awk -v h="$BOARD_HDR" 'BEGIN{print index(h,"Board Assembly")}')
    if [ "${BN_START:-0}" -gt 0 ] && [ "${BA_START:-0}" -gt "$BN_START" ]; then
        BOARD_NAME=$(echo "$BOARD_LINE" | cut -c"${BN_START}-$((BA_START - 1))" | sed 's/^[[:space:]]*//; s/[[:space:]]*$//')
    else
        BOARD_NAME=$(echo "$BOARD_LINE" | awk '{print $5}')
    fi
    PCI_BUS=$(echo "$BOARD_LINE"   | awk '{print $3}')
    PCI_DEV=$(echo "$BOARD_LINE"   | awk '{print $4}')

    # Sysfs corrections for SAS3+ chips (issue #6): lsiutil's own MPI2 IOUnit7
    # page is unreliable on SAS3008/3108 - firmware reflects an internal build
    # id rather than the user-facing version, and PCIe speed consistently reads
    # back Gen2 even when the link is actually running Gen3+. The kernel already
    # has correct values for both; use them when available and keep the lsiutil
    # reads as fallback so SAS2 cards (which lsiutil reads correctly) are unaffected.
    if [[ "$PCI_BUS" =~ ^[0-9]+$ ]] && [[ "$PCI_DEV" =~ ^[0-9]+$ ]]; then
        local PCI_ADDR SYSFS_PCI SYSFS_SPEED SYSFS_WIDTH FW_PATH SYSFS_FW
        PCI_ADDR=$(printf '0000:%02x:%02x.0' "$PCI_BUS" "$PCI_DEV")
        SYSFS_PCI="/sys/bus/pci/devices/$PCI_ADDR"
        if [ -d "$SYSFS_PCI" ]; then
            SYSFS_SPEED=$(cat "$SYSFS_PCI/current_link_speed" 2>/dev/null)
            SYSFS_WIDTH=$(cat "$SYSFS_PCI/current_link_width" 2>/dev/null)
            case "$SYSFS_SPEED" in
                *"2.5 GT/s"*)  PCIE_SPEED="Gen1 (2.5 GT/s)"  ;;
                *"5.0 GT/s"*)  PCIE_SPEED="Gen2 (5.0 GT/s)"  ;;
                *"8.0 GT/s"*)  PCIE_SPEED="Gen3 (8.0 GT/s)"  ;;
                *"16.0 GT/s"*) PCIE_SPEED="Gen4 (16.0 GT/s)" ;;
                *"32.0 GT/s"*) PCIE_SPEED="Gen5 (32.0 GT/s)" ;;
            esac
            [[ "$SYSFS_WIDTH" =~ ^[0-9]+$ ]] && PCIE_WIDTH="x$SYSFS_WIDTH"

            # Entries under /sys/bus/pci/devices/ are symlinks; find(1) does not
            # descend into a symlinked start point without -L, so version_fw was
            # never found and the raw lsiutil hex value leaked through (e.g.
            # "0f.00.00.00" instead of "15.00.00.00" on a SAS3216).
            FW_PATH=$(find -L "$SYSFS_PCI" -maxdepth 5 -name version_fw 2>/dev/null | head -1)
            if [ -n "$FW_PATH" ]; then
                SYSFS_FW=$(tr -d '[:space:]' < "$FW_PATH" 2>/dev/null)
                [ -n "$SYSFS_FW" ] && FW_VER="$SYSFS_FW"
            fi
        fi
    fi

    # Chip model fallback (SAS3216/3224 and others): lsiutil 1.70 predates these
    # chips and prints the raw PCI device id ("LSI Logic 00c9 01") in the banner,
    # so the SAS[0-9]+ match above comes up empty. The board name from lsiutil -b
    # / mpt3sas sysfs ("Avago SAS3216") still carries the chip name - reuse it.
    if [ -z "$MODEL" ]; then
        MODEL=$(echo "$BOARD_NAME" | grep -oE 'SAS[0-9]+[A-Za-z0-9]*' | head -1)
    fi

    if   [ "$TEMP_SUPPORTED" = false ];           then STATUS="unsupported"
    elif [ "$TEMP" -ge "$ALERT" ];                then STATUS="alert"
    elif [ "$TEMP" -ge $(( ALERT - 10 )) ];       then STATUS="warn"
    else STATUS="ok"; fi

    printf '{"port":%d,"temp":%d,"temp_supported":%s,"model":"%s","firmware":"%s","port_name":"%s","board_name":"%s","pci_location":"%s:%s","pcie_width":"%s","pcie_speed":"%s","power_mode":"%s","alert_threshold":%d,"status":"%s"}' \
        "$N" "$TEMP" "$TEMP_SUPPORTED" "${MODEL:-Unknown}" "$FW_VER" "${PORT_NAME:-ioc0}" "${BOARD_NAME:-}" \
        "${PCI_BUS:-0}" "${PCI_DEV:-0}" "$PCIE_WIDTH" "$PCIE_SPEED" "$POWER_MODE" "$ALERT" "$STATUS"
}

CONTROLLERS_JSON=""
PRIMARY_JSON=""
for ((i = 1; i <= NUM_PORTS; i++)); do
    OBJ=$(read_controller "$i")
    [ -n "$CONTROLLERS_JSON" ] && CONTROLLERS_JSON+=","
    CONTROLLERS_JSON+="$OBJ"
    [ "$i" -eq "$PORT" ] && PRIMARY_JSON="$OBJ"
done
[ -n "$PRIMARY_JSON" ] || PRIMARY_JSON=$(read_controller "$PORT")

if echo "$PRIMARY_JSON" | grep -q '"error"'; then
    echo "$PRIMARY_JSON"
    exit 1
fi

# PRIMARY_JSON is a flat single-line object (no nested braces), so splicing in
# the controller list ahead of its closing brace is safe without a JSON tool.
echo "${PRIMARY_JSON%\}},\"controllers\":[${CONTROLLERS_JSON}]}"

# Unraid LSIUtil Plugin

An Unraid plugin for monitoring LSI SAS Host Bus Adapters (HBA) — SAS2 (SAS2008/2308) and SAS3 (SAS3008/3108) generation cards — using the bundled `lsiutil` utility plus Linux `sysfs` corrections where `lsiutil` itself is unreliable. No internet access is required after the single package download.

## Features

- **Temperature gauge** — real-time HBA temperature with configurable alert threshold and Unraid notification
- **Multi-controller Overview** — systems with more than one physical HBA get an "All Detected Controllers" table listing every card's temperature, model, and firmware, not just the one selected in Settings
- **PCIe info** — link width, speed, power mode, and PCI location; on SAS3-generation chips, firmware version and PCIe speed are corrected via kernel `sysfs` since `lsiutil`'s own register reads are unreliable there
- **PHY Health** — per-port SAS link state and error counters (invalid DWords, disparity, loss-of-sync, resets)
- **Attached Drives** — drive list with SAS addresses, PHY assignment, and OS device names (`/dev/sdX`); a Controller column appears automatically when a card (e.g. a dual-IOC 9300-16i) or system presents more than one controller
- **Event Log** — HBA firmware event log entries (live only — cleared on reboot)
- **Syslog History** — historical drive/HBA errors pulled from `/var/log/syslog`, so events from before the last reboot aren't lost
- **Dashboard tile** — at-a-glance temperature on the Unraid dashboard (Unraid 7.2+), including in the tile header when collapsed
- **Settings** — lsiutil port selection, alert threshold, and panel toggles

All data is read directly from the HBA via `lsiutil` and Linux `sysfs` — no agents, no polling daemons, no external calls.

## Requirements

- Unraid 6.12 or newer (Unraid 7.2+ for the dashboard tile)
- LSI SAS controller supported by `mpt2sas` or `mpt3sas` kernel driver
  - Tested on: **LSI 9207-8i (SAS2308)**, **9300-8i / 9300-16i (SAS3008)**, **9305-24i (SAS3008 + expander)**
  - Should work on: 9211-8i, 9205-8i, 9201-16i, 9201-16e, other SAS2x08-family cards, and SAS3108-based cards
  - Multi-controller and multi-card systems are supported — see the Overview tab
- The `lsiutil` binary is bundled inside the `.txz` package — nothing else is downloaded

## Installation

1. In the Unraid web UI go to **Plugins → Install Plugin**
1. Paste the plugin URL:

```text
https://raw.githubusercontent.com/DevlinDelFuego/Unraid-LSIUtil/main/lsiutil.plg
```

1. Click **Install**

After installation, find the monitor under **Tools → LSIUtil → HBA Monitor**.

## Plugin Structure

```text
Tools
└── LSIUtil
    └── HBA Monitor   (tabs: Overview · PHY Health · Drives · Event Log · Syslog History)

Settings > System Settings
└── LSIUtil            (full settings page; also linked from the monitor's Settings icon)

Dashboard
└── HBA Temperature tile (Unraid 7.2+)
```

## Configuration

Open **Settings → System Settings → LSIUtil**, or click the ⚙ Settings link in the top-right of the HBA Monitor:

| Setting | Default | Description |
| --- | --- | --- |
| lsiutil Port | 1 | HBA port number (`lsiutil -p1`). Run `lsiutil` without arguments to list ports. |
| Alert Threshold | 80 °C | Unraid notification fires when temperature reaches this value. |
| Show PCIe Info | On | PCIe width/speed row in the Overview tab. |
| Show PHY Health | On | PHY error counters tab. |
| Show Attached Drives | On | Drive list tab. |
| Show Event Log | On | HBA firmware event log tab. |
| Show Syslog History | On | Historical drive/HBA errors from `/var/log/syslog` — survives reboots. |

## Building from Source

```bash
git clone https://github.com/DevlinDelFuego/Unraid-LSIUtil.git
cd Unraid-LSIUtil
bash build.sh [version]        # e.g. bash build.sh 2026.07.10.2
```

`build.sh` downloads the Linux x86-64 `lsiutil` binary (if not already present), packages everything under `source/` into `releases/lsiutil.txz` (using `makepkg` on a Slackware/Unraid box, or a plain `tar -cJf` fallback elsewhere), and prints the resulting MD5. After it finishes:

1. Update the `md5` and `version` entities in `lsiutil.plg` with the printed values
2. Tag the commit: `git tag <version> && git push --tags`
3. Upload `releases/lsiutil.txz` as a GitHub release asset for that tag

The `lsiutil.x86_64` binary inside the package is the original `lsiutil` v1.70 compiled for Linux x86-64.

## Credits

- **[Thomas Lovell — LSIUtil](https://github.com/thomaslovell/LSIUtil/)** — the `lsiutil` binary that makes this plugin possible. This project would not exist without his work preserving and maintaining the LSI utility.
- LSI Logic / Broadcom for the original `lsiutil` source.
- **ElUtku** — root-cause analysis and fix for the SAS3-generation firmware/PCIe reporting discrepancy ([#6](https://github.com/DevlinDelFuego/Unraid-LSIUtil/issues/6)).

## License

MIT — see [LICENSE](LICENSE) for details.

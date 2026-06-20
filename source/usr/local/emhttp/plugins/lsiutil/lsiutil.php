<?PHP
/* LSIUtil HBA Temperature Monitor — main plugin page */

$PLUGIN     = 'lsiutil';
$PLUGIN_DIR = "/boot/config/plugins/$PLUGIN";
$CFG_FILE   = "$PLUGIN_DIR/$PLUGIN.cfg";
$LSIUTIL    = "/usr/local/emhttp/plugins/$PLUGIN/lsiutil.x86_64";
$SCRIPT     = "/usr/local/emhttp/plugins/$PLUGIN/scripts/get_hba_info.sh";

$cfg = [
    'HBA_PORT'        => 1,
    'ALERT_THRESHOLD' => 80,
    'SHOW_PCIE'       => 1,
    'SHOW_PHY'        => 1,
    'SHOW_DRIVES'     => 1,
    'SHOW_EVENTS'     => 1,
];

if (file_exists($CFG_FILE)) {
    foreach (file($CFG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos($line, '=') !== false) {
            [$k, $v] = explode('=', $line, 2);
            $cfg[trim($k)] = trim($v);
        }
    }
}

$port      = (int)$cfg['HBA_PORT'];
$threshold = (int)$cfg['ALERT_THRESHOLD'];
$showPcie   = (int)$cfg['SHOW_PCIE'];
$showPhy    = (int)$cfg['SHOW_PHY'];
$showDrives = (int)$cfg['SHOW_DRIVES'];
$showEvents = (int)$cfg['SHOW_EVENTS'];

// Handle settings form POST
$saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_lsiutil'])) {
    $cfg['HBA_PORT']        = max(1, min(8,   (int)($_POST['port']        ?? 1)));
    $cfg['ALERT_THRESHOLD'] = max(1, min(150, (int)($_POST['threshold']   ?? 80)));
    $cfg['SHOW_PCIE']       = isset($_POST['show_pcie'])   ? 1 : 0;
    $cfg['SHOW_PHY']        = isset($_POST['show_phy'])    ? 1 : 0;
    $cfg['SHOW_DRIVES']     = isset($_POST['show_drives']) ? 1 : 0;
    $cfg['SHOW_EVENTS']     = isset($_POST['show_events']) ? 1 : 0;
    @mkdir($PLUGIN_DIR, 0755, true);
    $lines = array_map(fn($k, $v) => "$k=$v", array_keys($cfg), $cfg);
    file_put_contents($CFG_FILE, implode("\n", $lines) . "\n");
    $port      = (int)$cfg['HBA_PORT'];
    $threshold = (int)$cfg['ALERT_THRESHOLD'];
    $showPcie   = (int)$cfg['SHOW_PCIE'];
    $showPhy    = (int)$cfg['SHOW_PHY'];
    $showDrives = (int)$cfg['SHOW_DRIVES'];
    $showEvents = (int)$cfg['SHOW_EVENTS'];
    $saved      = true;
}

// Load overview data server-side on page load
$raw  = file_exists($SCRIPT) ? shell_exec('bash ' . escapeshellarg($SCRIPT) . ' 2>/dev/null') : null;
$data = $raw ? json_decode($raw, true) : null;
$error = $data['error'] ?? ($raw ? null : 'Backend script not found.');

function statusColor(string $s): string {
    return match($s) { 'alert' => '#e74c3c', 'warn' => '#f39c12', default => '#2ecc71' };
}
function statusLabel(string $s): string {
    return match($s) { 'alert' => 'ALERT', 'warn' => 'WARNING', default => 'NORMAL' };
}
?>

<style>
/* ── Layout ──────────────────────────────────────────────────────────────── */
#lu-wrap { font-family: inherit; max-width: 720px; margin: 20px auto; }

/* ── Tabs ────────────────────────────────────────────────────────────────── */
.lu-tabs { display: flex; gap: 2px; margin-bottom: 0; border-bottom: 2px solid #2a2a2a; }
.lu-tab-btn {
    padding: 8px 18px;
    background: #141414;
    border: 1px solid #2a2a2a;
    border-bottom: none;
    border-radius: 5px 5px 0 0;
    color: #666;
    cursor: pointer;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    transition: color 0.15s;
}
.lu-tab-btn:hover  { color: #bbb; }
.lu-tab-btn.active { background: #1c1c1c; border-bottom-color: #1c1c1c; color: #f5a623; }
.lu-tab-pane { display: none; }
.lu-tab-pane.active { display: block; }

/* ── Cards ───────────────────────────────────────────────────────────────── */
.lu-card {
    background: #1c1c1c;
    border: 1px solid #333;
    border-top: none;
    border-radius: 0 6px 6px 6px;
    padding: 20px 24px;
    margin-bottom: 16px;
}
.lu-card.first { border-radius: 0 0 6px 6px; }
.lu-card h3 {
    margin: 0 0 14px;
    color: #bbb;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    border-bottom: 1px solid #2a2a2a;
    padding-bottom: 8px;
}
.lu-divider { border: none; border-top: 1px solid #2a2a2a; margin: 16px 0; }

/* ── Temperature display ─────────────────────────────────────────────────── */
.lu-overview-row { display: flex; align-items: center; gap: 24px; }
.lu-circle {
    width: 96px; height: 96px;
    border-radius: 50%;
    border: 4px solid var(--tc, #2ecc71);
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    flex-shrink: 0;
    transition: border-color 0.3s;
}
.lu-circle .val  { font-size: 30px; font-weight: 700; color: var(--tc, #2ecc71); line-height: 1; }
.lu-circle .unit { font-size: 12px; color: #666; margin-top: 3px; }
.lu-meta p       { margin: 4px 0; font-size: 13px; color: #888; }
.lu-meta p span  { color: #ddd; font-weight: 500; }
.lu-badge {
    display: inline-block; margin-top: 6px;
    padding: 2px 12px; border-radius: 12px;
    font-size: 11px; font-weight: 700; letter-spacing: 0.05em;
    background: var(--tc, #2ecc71); color: #111;
    transition: background 0.3s;
}

/* ── PCIe row ────────────────────────────────────────────────────────────── */
.lu-pcie-row { display: flex; gap: 24px; flex-wrap: wrap; }
.lu-pcie-item { font-size: 13px; color: #888; }
.lu-pcie-item span { color: #ddd; font-weight: 500; }

/* ── Tables (shared between tabs) ────────────────────────────────────────── */
.lu-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.lu-table th {
    text-align: left; padding: 6px 10px;
    color: #777; font-size: 11px; font-weight: 600;
    text-transform: uppercase; letter-spacing: 0.05em;
    border-bottom: 1px solid #2a2a2a;
}
.lu-table td { padding: 7px 10px; color: #ccc; border-bottom: 1px solid #1a1a1a; }
.lu-table tr:last-child td { border-bottom: none; }
.lu-table code { color: #88aaff; font-size: 12px; }

/* ── Link badges ─────────────────────────────────────────────────────────── */
.lu-link-up   { color: #2ecc71; font-weight: 700; font-size: 11px; }
.lu-link-down { color: #e74c3c; font-weight: 700; font-size: 11px; }
.lu-err-val   { color: #f39c12; font-weight: 600; }

/* ── Misc ────────────────────────────────────────────────────────────────── */
.lu-error {
    background: #1e0e0e; border: 1px solid #7a2020;
    border-radius: 6px; padding: 14px 18px;
    color: #d88; font-size: 13px; margin-bottom: 12px;
}
.lu-muted  { color: #555; font-size: 13px; }
.lu-ts     { font-size: 11px; color: #444; text-align: right; margin-top: 10px; }
.lu-loading { color: #555; font-size: 13px; padding: 20px 0; text-align: center; }
.lu-tab-toolbar {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 12px;
}
.lu-refresh-btn {
    background: transparent; border: 1px solid #444;
    border-radius: 4px; color: #aaa;
    font-size: 11px; font-weight: 600; padding: 5px 12px;
    cursor: pointer; text-transform: uppercase; letter-spacing: 0.05em;
}
.lu-refresh-btn:hover { border-color: #888; color: #ddd; }
/* ── Settings form ───────────────────────────────────────────────────────── */
.lu-s-row { display: flex; align-items: flex-start; gap: 16px; margin-bottom: 14px; }
.lu-s-row:last-of-type { margin-bottom: 0; }
.lu-s-label { flex: 0 0 190px; font-size: 13px; color: #ccc; padding-top: 8px; }
.lu-s-label small { display: block; font-size: 11px; color: #555; margin-top: 3px; line-height: 1.4; }
.lu-s-control { flex: 1; }
.lu-s-control input[type=number] {
    width: 90px; background: #111; border: 1px solid #3a3a3a;
    border-radius: 4px; color: #ddd; padding: 7px 10px; font-size: 14px;
}
.lu-s-control input[type=number]:focus { outline: none; border-color: #f5a623; }
.lu-toggle {
    display: flex; align-items: center; gap: 10px; padding: 8px 0; cursor: pointer;
}
.lu-toggle input[type=checkbox] { width: 16px; height: 16px; accent-color: #f5a623; cursor: pointer; }
.lu-toggle span { font-size: 13px; color: #ddd; }
.lu-toggle small { font-size: 11px; color: #555; margin-left: auto; }
.lu-btn {
    background: #f5a623; border: none; border-radius: 4px; color: #111;
    font-size: 13px; font-weight: 700; padding: 9px 24px; cursor: pointer;
    letter-spacing: 0.03em;
}
.lu-btn:hover { background: #d9901a; }
</style>

<div id="lu-wrap">

<?php if ($error): ?>
  <div class="lu-error"><strong>Error:</strong> <?= htmlspecialchars($error) ?></div>
<?php else:
    $tc    = statusColor($data['status'] ?? 'ok');
    $badge = statusLabel($data['status'] ?? 'ok');
?>

<!-- ── Tab bar ───────────────────────────────────────────────────────────── -->
<div class="lu-tabs">
  <button class="lu-tab-btn active" data-tab="overview" onclick="luTab('overview')">Overview</button>
  <?php if ($showPhy):    ?><button class="lu-tab-btn" data-tab="phy"    onclick="luTab('phy')">PHY Health</button><?php endif; ?>
  <?php if ($showDrives): ?><button class="lu-tab-btn" data-tab="drives" onclick="luTab('drives')">Drives</button><?php endif; ?>
  <?php if ($showEvents): ?><button class="lu-tab-btn" data-tab="events" onclick="luTab('events')">Event Log</button><?php endif; ?>
  <button class="lu-tab-btn" data-tab="settings" onclick="luTab('settings')" style="margin-left:auto">⚙ Settings</button>
</div>

<!-- ── Overview tab ──────────────────────────────────────────────────────── -->
<div id="tab-overview" class="lu-tab-pane active">
  <div class="lu-card first" style="--tc:<?= $tc ?>">

    <div class="lu-overview-row">
      <div class="lu-circle" id="lu-circle">
        <span class="val" id="lu-val"><?= $data['temp'] ?></span>
        <span class="unit">°C</span>
      </div>
      <div class="lu-meta">
        <p>Model: <span><?= htmlspecialchars($data['board_name'] ?: ($data['model'] ?? 'Unknown')) ?></span></p>
        <p>Chip: <span><?= htmlspecialchars($data['model'] ?? 'Unknown') ?></span></p>
        <p>Firmware: <span><?= htmlspecialchars($data['firmware'] ?? 'Unknown') ?></span></p>
        <p>Port: <span><?= htmlspecialchars($data['port_name'] ?? 'ioc0') ?> (lsiutil -p<?= $port ?>)</span></p>
        <p>Alert Threshold: <span><?= $threshold ?>°C</span></p>
        <span class="lu-badge" id="lu-badge"><?= $badge ?></span>
      </div>
    </div>

    <?php if ($showPcie && ($data['pcie_width'] || $data['pcie_speed'])): ?>
    <hr class="lu-divider">
    <div class="lu-pcie-row">
      <?php if ($data['pcie_width']): ?><div class="lu-pcie-item">PCIe Width: <span><?= htmlspecialchars($data['pcie_width']) ?></span></div><?php endif; ?>
      <?php if ($data['pcie_speed']): ?><div class="lu-pcie-item">PCIe Speed: <span><?= htmlspecialchars($data['pcie_speed']) ?></span></div><?php endif; ?>
      <?php if ($data['power_mode']): ?><div class="lu-pcie-item">Power Mode: <span><?= htmlspecialchars($data['power_mode']) ?></span></div><?php endif; ?>
      <?php if ($data['pci_location']): ?><div class="lu-pcie-item">PCI Location: <span><?= htmlspecialchars($data['pci_location']) ?></span></div><?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="lu-ts" id="lu-ts">Last read: <?= date('H:i:s') ?></div>
  </div>

</div>

<!-- ── PHY Health tab ────────────────────────────────────────────────────── -->
<?php if ($showPhy): ?>
<div id="tab-phy" class="lu-tab-pane">
  <div class="lu-card first">
    <div class="lu-tab-toolbar">
      <span style="font-size:12px;color:#555;">SAS link status and error counters per physical port</span>
      <button class="lu-refresh-btn" onclick="luReloadTab('phy')">Refresh</button>
    </div>
    <div id="phy-content"><div class="lu-loading">Loading…</div></div>
  </div>
</div>
<?php endif; ?>

<!-- ── Drives tab ────────────────────────────────────────────────────────── -->
<?php if ($showDrives): ?>
<div id="tab-drives" class="lu-tab-pane">
  <div class="lu-card first">
    <div class="lu-tab-toolbar">
      <span style="font-size:12px;color:#555;">Devices attached to the HBA</span>
      <button class="lu-refresh-btn" onclick="luReloadTab('drives')">Refresh</button>
    </div>
    <div id="drives-content"><div class="lu-loading">Loading…</div></div>
  </div>
</div>
<?php endif; ?>

<!-- ── Event Log tab ─────────────────────────────────────────────────────── -->
<?php if ($showEvents): ?>
<div id="tab-events" class="lu-tab-pane">
  <div class="lu-card first">
    <div class="lu-tab-toolbar">
      <span style="font-size:12px;color:#555;">HBA firmware event log (newest first)</span>
      <button class="lu-refresh-btn" onclick="luReloadTab('events')">Refresh</button>
    </div>
    <div id="events-content"><div class="lu-loading">Loading…</div></div>
  </div>
</div>
<?php endif; ?>

<?php endif; // end !$error ?>

<!-- ── Settings tab ───────────────────────────────────────────────────────── -->
<div id="tab-settings" class="lu-tab-pane">
  <div class="lu-card first">

    <?php if ($saved): ?>
    <div style="background:#1a2a1a;border:1px solid #2a4a2a;border-radius:4px;
                color:#8c8;font-size:12px;padding:8px 14px;margin-bottom:14px">
      Settings saved.
    </div>
    <?php endif; ?>

    <form method="post">

      <h3>HBA Connection</h3>

      <div class="lu-s-row">
        <div class="lu-s-label">
          lsiutil Port
          <small>Run lsiutil with no args to list available ports. Usually 1.</small>
        </div>
        <div class="lu-s-control">
          <input type="number" name="port" value="<?= (int)$cfg['HBA_PORT'] ?>" min="1" max="8">
        </div>
      </div>

      <div class="lu-s-row">
        <div class="lu-s-label">
          Alert Threshold (°C)
          <small>Unraid notification fires when HBA temperature reaches this value.</small>
        </div>
        <div class="lu-s-control">
          <input type="number" name="threshold" value="<?= (int)$cfg['ALERT_THRESHOLD'] ?>" min="1" max="150">
        </div>
      </div>

      <hr class="lu-divider">
      <h3>Display Panels</h3>
      <p style="font-size:12px;color:#555;margin:0 0 14px">
        Temperature &amp; card info are always shown. Toggle additional panels below.
      </p>

      <label class="lu-toggle">
        <input type="checkbox" name="show_pcie" <?= (int)$cfg['SHOW_PCIE'] ? 'checked' : '' ?>>
        <span>PCIe Information</span>
        <small>Width &amp; speed in Overview</small>
      </label>
      <label class="lu-toggle">
        <input type="checkbox" name="show_phy" <?= (int)$cfg['SHOW_PHY'] ? 'checked' : '' ?>>
        <span>PHY Health</span>
        <small>SAS link state &amp; error counters</small>
      </label>
      <label class="lu-toggle">
        <input type="checkbox" name="show_drives" <?= (int)$cfg['SHOW_DRIVES'] ? 'checked' : '' ?>>
        <span>Attached Drives</span>
        <small>SAS addresses, enclosure/slot, OS names</small>
      </label>
      <label class="lu-toggle">
        <input type="checkbox" name="show_events" <?= (int)$cfg['SHOW_EVENTS'] ? 'checked' : '' ?>>
        <span>Event Log</span>
        <small>HBA firmware event log</small>
      </label>

      <hr class="lu-divider">
      <button class="lu-btn" type="submit" name="save_lsiutil" value="1">Save Settings</button>

    </form>
  </div>
</div>

</div><!-- #lu-wrap -->

<script>
(function () {
    var REFRESH_MS = 60000;
    var timer;
    var loaded = {};

    /* ── Tab switching ────────────────────────────────────────────────────── */
    window.luTab = function (name) {
        document.querySelectorAll('.lu-tab-btn').forEach(function (b) {
            b.classList.toggle('active', b.dataset.tab === name);
        });
        document.querySelectorAll('.lu-tab-pane').forEach(function (p) {
            p.classList.toggle('active', p.id === 'tab-' + name);
        });
        if (name !== 'overview' && !loaded[name]) {
            luReloadTab(name);
        }
    };

    /* ── Load / reload a tab's content via AJAX ───────────────────────────── */
    window.luReloadTab = function (name) {
        var el = document.getElementById(name + '-content');
        if (!el) return;
        el.innerHTML = '<div class="lu-loading">Loading…</div>';
        fetch('/plugins/lsiutil/ajax_info.php?type=' + name)
            .then(function (r) { return r.text(); })
            .then(function (html) {
                el.innerHTML = html;
                loaded[name] = true;
            })
            .catch(function () {
                el.innerHTML = '<div class="lu-error">Request failed.</div>';
            });
    };

    /* ── Overview auto-refresh (temperature only) ─────────────────────────── */
    function refreshOverview() {
        fetch('/plugins/lsiutil/ajax_info.php?type=overview')
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.error) return;
                var colors = { alert: '#e74c3c', warn: '#f39c12', ok: '#2ecc71' };
                var labels = { alert: 'ALERT',   warn: 'WARNING', ok: 'NORMAL'  };
                var c      = colors[d.status] || colors.ok;

                var circle = document.getElementById('lu-circle');
                var val    = document.getElementById('lu-val');
                var badge  = document.getElementById('lu-badge');
                var ts     = document.getElementById('lu-ts');

                if (circle) circle.style.setProperty('--tc', c);
                if (val)    val.textContent   = d.temp;
                if (badge)  { badge.textContent = labels[d.status] || 'NORMAL'; badge.style.background = c; }
                if (ts)     ts.textContent    = 'Last read: ' + new Date().toLocaleTimeString();

                // Unraid notification is handled server-side; check here for threshold breach only
                if (d.status === 'alert' && Notification && Notification.permission === 'granted') {
                    new Notification('LSIUtil HBA Alert', {
                        body: 'HBA temperature ' + d.temp + '°C — above threshold (' + d.alert_threshold + '°C)',
                        icon: '/plugins/lsiutil/images/icon.png'
                    });
                }
            })
            .catch(function () {});

        clearTimeout(timer);
        timer = setTimeout(refreshOverview, REFRESH_MS);
    }

    timer = setTimeout(refreshOverview, REFRESH_MS);

    // Auto-open tab from URL param (?tab=xxx) or after settings save
    var urlTab = new URLSearchParams(window.location.search).get('tab');
    <?php if ($saved): ?>urlTab = 'settings';<?php endif; ?>
    if (urlTab && urlTab !== 'overview') { luTab(urlTab); }
})();
</script>

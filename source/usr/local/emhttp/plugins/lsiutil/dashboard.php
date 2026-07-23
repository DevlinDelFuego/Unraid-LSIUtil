<?PHP
/* LSIUtil dashboard tile — Unraid 7.2+ tile format.
   Mirrors the Overview tab layout: circle gauge + card info + PCIe row.
   Result cached in /tmp for 60 s to avoid hardware reads on every page load. */

$pluginname = 'LSIUtil';
$CACHE   = '/tmp/lsiutil_dash.json';
$SCRIPT  = '/usr/local/emhttp/plugins/lsiutil/scripts/get_hba_info.sh';
$CFG     = '/boot/config/plugins/lsiutil/lsiutil.cfg';

// Read config for port + alert threshold
$port      = 1;
$threshold = 80;
if (file_exists($CFG)) {
    foreach (file($CFG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln) {
        if (strpos($ln, 'HBA_PORT=')        === 0) $port      = (int)substr($ln, 9);
        if (strpos($ln, 'ALERT_THRESHOLD=') === 0) $threshold = (int)substr($ln, 16);
    }
}

// Use cached data or run fresh
$data = null;
if (file_exists($CACHE) && (time() - filemtime($CACHE)) < 60) {
    $data = json_decode(file_get_contents($CACHE), true);
}
if (!$data || isset($data['error'])) {
    if (file_exists($SCRIPT)) {
        $raw  = shell_exec('bash ' . escapeshellarg($SCRIPT) . ' 2>/dev/null');
        $data = json_decode($raw ?? '', true);
        if ($data && !isset($data['error'])) file_put_contents($CACHE, $raw);
    }
}

$temp          = isset($data['temp'])       ? (int)$data['temp']    : null;
$status        = $data['status']            ?? 'ok';
$tempSupported = $data['temp_supported']    ?? true;
$error         = $data['error']             ?? ($temp === null ? 'lsiutil unavailable' : null);

// Multi-HBA systems (forum request, 2026.07): the tile only ever watched the
// HBA_PORT-selected card. get_hba_info.sh already emits every detected port
// under "controllers" for the Overview tab table - reuse it here as a
// per-card row list instead of forcing users to pick just one to monitor.
$controllers = $data['controllers'] ?? [];
$multi       = count($controllers) > 1;

if ($multi) {
    // Header color/badge/temp reflect the worst card, not just the selected
    // one, so a problem on the "other" card isn't hidden behind a collapsed tile.
    $rank      = ['ok' => 0, 'unsupported' => 0, 'warn' => 1, 'alert' => 2];
    $status    = 'ok';
    $worstRank = -1;
    $worstTemp = null;
    foreach ($controllers as $c) {
        $cs = $c['status'] ?? 'ok';
        $r  = $rank[$cs] ?? 0;
        if ($r > $worstRank) { $worstRank = $r; $status = $cs; }
        if (($c['temp_supported'] ?? true) && isset($c['temp'])) {
            $ct = (int)$c['temp'];
            if ($worstTemp === null || $ct > $worstTemp) $worstTemp = $ct;
        }
    }
    $tempSupported = $worstTemp !== null;
    $temp          = $worstTemp;
}

$tc       = match ($status) { 'alert' => '#e74c3c', 'warn' => '#f39c12', 'unsupported' => '#666', default => '#2ecc71' };
$badge    = match ($status) { 'alert' => 'ALERT',   'warn' => 'WARNING',  'unsupported' => 'N/A', default => 'NORMAL'  };
$tempDisplay = $tempSupported ? $temp : 'N/A';
$tempUnit    = $tempSupported ? '°C'  : '';

$boardName      = htmlspecialchars(!empty($data['board_name']) ? $data['board_name'] : ($data['model'] ?? 'Unknown'));
$headerSubtitle = $multi ? count($controllers) . ' Controllers' : $boardName;
$chip      = htmlspecialchars($data['model']    ?? '');
$firmware  = htmlspecialchars($data['firmware'] ?? '');
$portName  = htmlspecialchars($data['port_name'] ?? 'ioc0');
$ts        = date('H:i:s');

// Scoped styles — output directly so they appear in the page <head> area
echo <<<CSS
<style>
#tblLsiutil .lu-d-overview { display:flex; align-items:center; gap:20px; }
#tblLsiutil .lu-d-circle {
  width:90px; height:90px; border-radius:50%;
  border:4px solid {$tc};
  display:flex; flex-direction:column; align-items:center; justify-content:center;
  flex-shrink:0;
}
#tblLsiutil .lu-d-circle .v { font-size:28px; font-weight:700; color:{$tc}; line-height:1; }
#tblLsiutil .lu-d-circle .u { font-size:12px; opacity:.6; margin-top:3px; }
#tblLsiutil .lu-d-meta p    { margin:3px 0; font-size:13px; }
#tblLsiutil .lu-d-meta .lu-d-label { opacity:.65; }
#tblLsiutil .lu-d-meta span { font-weight:500; }
#tblLsiutil .lu-d-badge {
  display:inline-block; margin-top:6px;
  padding:2px 12px; border-radius:12px;
  font-size:11px; font-weight:700; letter-spacing:0.05em;
  background:{$tc}; color:#111;
}
#tblLsiutil .lu-d-pcie {
  display:flex; gap:18px; flex-wrap:wrap;
  font-size:13px;
  padding-top:12px; margin-top:8px;
  border-top:1px solid rgba(128,128,128,.3);
}
#tblLsiutil .lu-d-pcie .lu-d-label { opacity:.65; }
#tblLsiutil .lu-d-pcie span { font-weight:500; }
#tblLsiutil .lu-d-ts { font-size:11px; opacity:.5; text-align:right; margin-top:8px; }
#tblLsiutil .lu-d-header-temp {
  font-size:13px; font-weight:700; color:{$tc};
  border:1px solid {$tc}; border-radius:12px; padding:2px 10px;
  margin-right:8px; white-space:nowrap;
}
#tblLsiutil .lu-d-multi { display:flex; flex-direction:column; gap:9px; }
#tblLsiutil .lu-d-multi-row { display:flex; align-items:center; gap:10px; }
#tblLsiutil .lu-d-multi-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
#tblLsiutil .lu-d-multi-info { display:flex; flex-direction:column; flex:1; min-width:0; }
#tblLsiutil .lu-d-multi-name { font-size:13px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
#tblLsiutil .lu-d-multi-sub { font-size:11px; opacity:.6; }
#tblLsiutil .lu-d-multi-temp { font-size:15px; font-weight:700; flex-shrink:0; }
</style>
CSS;

// Build PCIe row (single-card layout only - each multi-card row is compact
// and has no room for a PCIe breakdown; that detail lives on the Overview tab)
$pcieRow = '';
if (!$error && !$multi) {
    $pcieParts = [];
    if (!empty($data['pcie_width']))   $pcieParts[] = '<span class="lu-d-label">PCIe Width:</span> <span>' . htmlspecialchars($data['pcie_width'])   . '</span>';
    if (!empty($data['pcie_speed']))   $pcieParts[] = '<span class="lu-d-label">PCIe Speed:</span> <span>' . htmlspecialchars($data['pcie_speed'])   . '</span>';
    if (!empty($data['power_mode']))   $pcieParts[] = '<span class="lu-d-label">Power Mode:</span> <span>' . htmlspecialchars($data['power_mode'])   . '</span>';
    if (!empty($data['pci_location'])) $pcieParts[] = '<span class="lu-d-label">PCI Location:</span> <span>' . htmlspecialchars($data['pci_location']) . '</span>';
    if ($pcieParts) $pcieRow = '<div class="lu-d-pcie">' . implode('', $pcieParts) . '</div>';
}

// Build one compact row per detected card for multi-HBA systems
$multiRows = '';
if (!$error && $multi) {
    foreach ($controllers as $c) {
        $cStatus        = $c['status'] ?? 'ok';
        $cColor         = match ($cStatus) { 'alert' => '#e74c3c', 'warn' => '#f39c12', 'unsupported' => '#666', default => '#2ecc71' };
        $cTempSupported = $c['temp_supported'] ?? true;
        $cTempDisplay   = $cTempSupported ? ((int)($c['temp'] ?? 0) . '°C') : 'N/A';
        $cBoard         = htmlspecialchars(!empty($c['board_name']) ? $c['board_name'] : ($c['model'] ?? 'Unknown'));
        $cPort          = (int)($c['port'] ?? 0);
        $cSub           = 'Port ' . $cPort . ($cPort === $port ? ' &middot; default' : '');
        $multiRows .= "
      <div class='lu-d-multi-row'>
        <span class='lu-d-multi-dot' style='background:{$cColor}'></span>
        <div class='lu-d-multi-info'>
          <span class='lu-d-multi-name'>{$cBoard}</span>
          <span class='lu-d-multi-sub'>{$cSub}</span>
        </div>
        <span class='lu-d-multi-temp' style='color:{$cColor}'>{$cTempDisplay}</span>
      </div>";
    }
}

// Tile body
if ($error) {
    $body = "<span style='color:#d88'>" . htmlspecialchars($error) . "</span>";
} elseif ($multi) {
    $body = "
    <div class='lu-d-multi'>{$multiRows}</div>
    <div class='lu-d-ts'>Last read: {$ts}</div>";
} else {
    $body = "
    <div class='lu-d-overview'>
      <div class='lu-d-circle'>
        <span class='v'>{$tempDisplay}</span>
        <span class='u'>{$tempUnit}</span>
      </div>
      <div class='lu-d-meta'>
        <p><span class='lu-d-label'>Model:</span> <span>{$boardName}</span></p>"
        . ($chip     ? "<p><span class='lu-d-label'>Chip:</span> <span>{$chip}</span></p>"                                : '')
        . ($firmware ? "<p><span class='lu-d-label'>Firmware:</span> <span>{$firmware}</span></p>"                        : '')
        . "        <p><span class='lu-d-label'>Port:</span> <span>{$portName} (lsiutil -p{$port})</span></p>"
        . ($tempSupported ? "<p><span class='lu-d-label'>Alert Threshold:</span> <span>{$threshold}°C</span></p>" : "<p><span class='lu-d-label'>No onboard temperature sensor</span></p>")
        . "        <span class='lu-d-badge'>{$badge}</span>
      </div>
    </div>
    {$pcieRow}
    <div class='lu-d-ts'>Last read: {$ts}</div>";
}

// Compact temp badge shown in the tile header itself, so it's visible even
// when the user has collapsed the tile body (issue #4)
$headerTempTitle = $multi ? ' title="Highest reading across ' . count($controllers) . ' cards"' : '';
$headerTemp = ($error || !$tempSupported) ? '' : "<span class=\"lu-d-header-temp\"{$headerTempTitle}>{$tempDisplay}{$tempUnit}</span>";

$mytiles[$pluginname]['column1'] = <<<EOT
<tbody id="tblLsiutil" title="HBA Temperature">
  <tr>
    <td>
      <span class="tile-header">
        <span class="tile-header-left">
          <i class="fa fa-thermometer-half f32" style="color:{$tc}"></i>
          <div class="section">
            <h3 class="tile-header-main">HBA Temperature</h3>
            <span>{$headerSubtitle}</span>
          </div>
        </span>
        <span class="tile-header-right">
          <span class="tile-header-right-controls">
            {$headerTemp}<a href="/Tools/LSIUtil_Monitor" title="Open LSIUtil">
              <i class="fa fa-fw fa-cog control"></i>
            </a>
          </span>
        </span>
      </span>
    </td>
  </tr>
  <tr>
    <td>
      {$body}
    </td>
  </tr>
</tbody>
EOT;

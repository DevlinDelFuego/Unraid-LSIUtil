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
$tc       = match ($status) { 'alert' => '#e74c3c', 'warn' => '#f39c12', 'unsupported' => '#666', default => '#2ecc71' };
$badge    = match ($status) { 'alert' => 'ALERT',   'warn' => 'WARNING',  'unsupported' => 'N/A', default => 'NORMAL'  };
$tempDisplay = $tempSupported ? $temp : 'N/A';
$tempUnit    = $tempSupported ? '°C'  : '';

$boardName = htmlspecialchars(!empty($data['board_name']) ? $data['board_name'] : ($data['model'] ?? 'Unknown'));
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
</style>
CSS;

// Build PCIe row
$pcieRow = '';
if (!$error) {
    $pcieParts = [];
    if (!empty($data['pcie_width']))   $pcieParts[] = '<span class="lu-d-label">PCIe Width:</span> <span>' . htmlspecialchars($data['pcie_width'])   . '</span>';
    if (!empty($data['pcie_speed']))   $pcieParts[] = '<span class="lu-d-label">PCIe Speed:</span> <span>' . htmlspecialchars($data['pcie_speed'])   . '</span>';
    if (!empty($data['power_mode']))   $pcieParts[] = '<span class="lu-d-label">Power Mode:</span> <span>' . htmlspecialchars($data['power_mode'])   . '</span>';
    if (!empty($data['pci_location'])) $pcieParts[] = '<span class="lu-d-label">PCI Location:</span> <span>' . htmlspecialchars($data['pci_location']) . '</span>';
    if ($pcieParts) $pcieRow = '<div class="lu-d-pcie">' . implode('', $pcieParts) . '</div>';
}

// Tile body
if ($error) {
    $body = "<span style='color:#d88'>" . htmlspecialchars($error) . "</span>";
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
$headerTemp = ($error || !$tempSupported) ? '' : "<span class=\"lu-d-header-temp\">{$tempDisplay}{$tempUnit}</span>";

$mytiles[$pluginname]['column1'] = <<<EOT
<tbody id="tblLsiutil" title="HBA Temperature">
  <tr>
    <td>
      <span class="tile-header">
        <span class="tile-header-left">
          <i class="fa fa-thermometer-half f32" style="color:{$tc}"></i>
          <div class="section">
            <h3 class="tile-header-main">HBA Temperature</h3>
            <span>{$boardName}</span>
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

<?PHP
/* LSIUtil dashboard tile — Unraid 7.2+ tile format.
   Uses $mytiles variable that Unraid reads when rendering the dashboard.
   Temperature result cached in /tmp for 60 s to avoid hardware reads on every page load. */

$pluginname = 'LSIUtil';
$CACHE  = '/tmp/lsiutil_dash.json';
$SCRIPT = '/usr/local/emhttp/plugins/lsiutil/scripts/get_hba_info.sh';

$data = null;
if (file_exists($CACHE) && (time() - filemtime($CACHE)) < 60) {
    $data = json_decode(file_get_contents($CACHE), true);
}
if (!$data || isset($data['error'])) {
    if (file_exists($SCRIPT)) {
        $raw  = shell_exec('bash ' . escapeshellarg($SCRIPT) . ' 2>/dev/null');
        $data = json_decode($raw ?? '', true);
        if ($data && !isset($data['error'])) {
            file_put_contents($CACHE, $raw);
        }
    }
}

$temp   = isset($data['temp']) ? (int)$data['temp'] : null;
$model  = !empty($data['board_name']) ? $data['board_name'] : ($data['model'] ?? 'Unknown HBA');
$status = $data['status'] ?? 'ok';
$error  = $data['error']  ?? ($temp === null ? 'lsiutil unavailable' : null);

$tc = match ($status) { 'alert' => '#e74c3c', 'warn' => '#f39c12', default => '#2ecc71' };

$subtitle = htmlspecialchars($model);

if ($error) {
    $body = "<span style='color:#d88'>" . htmlspecialchars($error) . "</span>";
} else {
    $body = "
    <dl>
      <dt>Temperature</dt>
      <dd style='color:{$tc};font-weight:700'>{$temp}°C</dd>
    </dl>
    <dl>
      <dt>Model</dt>
      <dd>" . htmlspecialchars($model) . "</dd>
    </dl>";
    if (!empty($data['pcie_width'])) {
        $pcie = htmlspecialchars(trim($data['pcie_width'] . ' ' . ($data['pcie_speed'] ?? '')));
        $body .= "<dl><dt>PCIe</dt><dd>{$pcie}</dd></dl>";
    }
}

$mytiles[$pluginname]['column1'] = <<<EOT
<tbody id="tblLsiutil" title="HBA Temperature">
  <tr>
    <td>
      <span class="tile-header">
        <span class="tile-header-left">
          <i class="fa fa-thermometer-half f32" style="color:{$tc}"></i>
          <div class="section">
            <h3 class="tile-header-main">HBA Temperature</h3>
            <span>{$subtitle}</span>
          </div>
        </span>
        <span class="tile-header-right">
          <span class="tile-header-right-controls">
            <a href="/Tools/LSIUtil_Monitor" title="Open LSIUtil">
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

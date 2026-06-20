<?PHP
/* LSIUtil dashboard widget — cached HBA temperature card.
   Cache file lives in /tmp so it resets on reboot; refreshed every 60 s. */

$PLUGIN = 'lsiutil';
$CACHE  = "/tmp/{$PLUGIN}_dash.json";
$SCRIPT = "/usr/local/emhttp/plugins/{$PLUGIN}/scripts/get_hba_info.sh";
$CFG    = "/boot/config/plugins/{$PLUGIN}/{$PLUGIN}.cfg";

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

$temp   = isset($data['temp'])       ? (int)$data['temp']    : null;
$model  = !empty($data['board_name']) ? $data['board_name']   : ($data['model'] ?? 'Unknown');
$status = $data['status']            ?? 'ok';
$error  = $data['error']             ?? ($temp === null ? 'lsiutil unavailable' : null);

$tc = match ($status) { 'alert' => '#e74c3c', 'warn' => '#f39c12', default => '#2ecc71' };
?>
<div id="lsiutil-dash-card">
  <div>
    <span class="left">
      <i class="fa fa-thermometer-half" style="color:<?= $tc ?>"></i>
      HBA TEMPERATURE
    </span>
    <span class="right">
      <a href="/Tools/LSIUtil" style="color:#555;font-size:11px" title="Open LSIUtil">
        <i class="fa fa-external-link"></i>
      </a>
    </span>
  </div>
  <div>
    <?php if ($error): ?>
      <dl>
        <dt>Status</dt>
        <dd style="color:#d88"><?= htmlspecialchars($error) ?></dd>
      </dl>
    <?php else: ?>
      <dl>
        <dt>Temperature</dt>
        <dd style="color:<?= $tc ?>;font-weight:700"><?= $temp ?>°C</dd>
      </dl>
      <dl>
        <dt>Model</dt>
        <dd><?= htmlspecialchars($model) ?></dd>
      </dl>
      <?php if (!empty($data['pcie_width'])): ?>
      <dl>
        <dt>PCIe</dt>
        <dd><?= htmlspecialchars($data['pcie_width'] . ' ' . ($data['pcie_speed'] ?? '')) ?></dd>
      </dl>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

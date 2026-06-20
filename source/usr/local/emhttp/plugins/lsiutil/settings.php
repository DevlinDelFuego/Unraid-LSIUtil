<?PHP
/* Settings entry — rendered inline on the Unraid Settings page grid.
   Outputs only a compact icon card; full settings live in the Settings tab
   of /Tools/LSIUtil (lsiutil.php handles the POST). */
?>
<a href="/Tools/LSIUtil?tab=settings"
   title="LSIUtil — HBA temperature monitor settings"
   style="display:inline-flex;flex-direction:column;align-items:center;
          justify-content:center;text-decoration:none;color:inherit;
          padding:14px 22px;gap:8px;min-width:90px">
  <i class="fa fa-thermometer-half" style="font-size:36px;color:#f5a623"></i>
  <span style="font-size:11px;font-weight:600;letter-spacing:0.06em;
               text-transform:uppercase;color:#ccc">LSIUtil</span>
</a>

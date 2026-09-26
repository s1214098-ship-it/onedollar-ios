/* Paste into asset-boot.php immediately after window.__LZ_ASSET_BOOT = true; */
(function installBlackButtons() {
  try {
    var path = String(location.pathname || '');
    if (/scanner\\.html/i.test(path) && path.indexOf('admin') === -1) return;
  } catch (error) {}
  if (document.getElementById('lz-admin-black-buttons')) return;
  var link = document.createElement('link');
  link.id = 'lz-admin-black-buttons';
  link.rel = 'stylesheet';
  var stamp = (manifest.files && manifest.files['admin-black-buttons.css']) || String(manifest.v || '0');
  link.href = './assets/admin-black-buttons.css?v=' + stamp;
  (document.head || document.documentElement).appendChild(link);
})();

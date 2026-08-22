(function () {
  "use strict";

  window.renderHardwareMarket = function () {
    const page = document.getElementById("page-hardwareMarket");
    if (!page) return;
    const now = new Date();
    const v = String(now.getFullYear())
      + String(now.getMonth() + 1).padStart(2, "0")
      + String(now.getDate()).padStart(2, "0")
      + "-"
      + String(now.getHours()).padStart(2, "0")
      + String(now.getMinutes()).padStart(2, "0");
    page.innerHTML = '<iframe src="hardware-market-intel.php?v=' + v + '" title="Memory and storage market intelligence" style="width:100%;min-height:calc(100vh - 118px);border:0;display:block;background:#f5f7fb"></iframe>';
  };
})();

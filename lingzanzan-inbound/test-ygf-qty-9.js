const fs = require('fs');
const js = fs.readFileSync('/workspace/lingzanzan-inbound/scanner-ygf-v59.js.new-ygf-qty-9', 'utf8');
const html = fs.readFileSync('/workspace/lingzanzan-inbound/scanner.html.new-ygf-qty-9', 'utf8');
const css = fs.readFileSync('/workspace/lingzanzan-inbound/scanner-ygf-v59.css.new-ygf-qty-9', 'utf8');
const checks = [
  ['confirm never disabled', js.includes("data-fast-confirm-stocktake>' + (submittingNow ? '再按確定寫入' : '確定')") && !js.includes("data-fast-confirm-stocktake' + (submittingNow ? ' disabled'")],
  ['retry helper', js.includes('function retryStocktakeWrite(options)')],
  ['manual confirm retries', js.includes("retryStocktakeWrite({ fromScan: false, auto: false })")],
  ['hide picker on commit', js.includes("choose(row, index, true, {skipLocation: true, keepPicker: false, hidePicker: true})") && js.includes('hideMatchPickerNow();')],
  ['same sku retries', js.includes('sameSku && (stocktakeCommitUi || stocktakeAutoSubmitting)')],
  ['fetch always rejects', js.includes("settled = true") && js.includes("reject(new Error('伺服器回應逾時，請確認網路後重試'))")],
  ['watchdog', js.includes('window.__lzStocktakeSaveWatchdog')],
  ['hard timeout', js.includes("reject(new Error('寫入逾時，請再按確定'))")],
  ['auto only debounce', js.includes('options.auto && stocktakeAutoSubmitting && Date.now() - lastStocktakeCommitAt < 600')],
  ['no forever duplicate block', !js.includes('這件盤點資料正在儲存，請勿重複送出')],
  ['marker', js.includes('LZ_YGF_QTY_SAVE9_20260927')],
  ['v170', html.includes('版本 v170')],
  ['bust', html.includes('20260927-ygf-qty-9')],
  ['css confirm visible', css.includes('button.primary[data-fast-confirm-stocktake]') && css.includes('pointer-events:auto!important')],
];
const failed = checks.filter(([, ok]) => !ok).map(([n]) => n);
if (failed.length) { console.error('FAIL', failed.join(',')); process.exit(1); }
console.log('ok');

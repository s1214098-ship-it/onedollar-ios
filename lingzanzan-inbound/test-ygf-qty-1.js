const fs = require('fs');
const js = fs.readFileSync('/workspace/lingzanzan-inbound/scanner-ygf-v59.js.new-ygf-qty-1', 'utf8');
const css = fs.readFileSync('/workspace/lingzanzan-inbound/scanner-ygf-v59.css.new-ygf-qty-1', 'utf8');
const html = fs.readFileSync('/workspace/lingzanzan-inbound/scanner.html.new-ygf-qty-1', 'utf8');
const checks = {
  jsHelpers: js.includes('function currentStocktakeQty') && js.includes('function setCurrentStocktakeQty'),
  jsLoc: js.includes('stocktake-fast-loc'),
  jsColor: js.includes('stocktake-fast-color'),
  jsNoAutoSubmit: !js.includes("setMessage('已加入本次盤點 ' + broughtCount + ' 件。', 'ok');\n      submit('stocktake');"),
  jsSubmitQty: js.includes('action === \'stocktake\' ? currentStocktakeQty()'),
  jsChooseUi: js.includes('if (isStocktakeUi() || currentTab === \'stocktake\')'),
  cssMarker: css.includes('LZ_YGF_QTY_SAVE_20260927'),
  cssQtyRow: css.includes('grid-row: 3 !important') && css.includes('.fast-qty'),
  htmlV162: html.includes('版本 v162'),
  htmlBust: html.includes('20260927-ygf-qty-1'),
};
const failed = Object.entries(checks).filter(([, ok]) => !ok).map(([k]) => k);
if (failed.length) {
  console.error('FAIL', failed.join(','));
  process.exit(1);
}
console.log('ok', Object.keys(checks).join(','));

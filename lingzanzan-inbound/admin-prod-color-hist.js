/* LZ_PROD_COLOR_HIST_20260927 excerpt */
var PRODUCT_COLOR_MEMORY_KEY = 'lingzanzan-v1-product-color-memory';
function productHistoryColorNames() {
  var names = [];
  (state.products || []).forEach(function (product) {
    (product.colors || []).forEach(function (color) { names.push(color && (color.name || color.colorName)); });
  });
  return names;
}
function bindProductHistoryColorSelect() {
  fillProductHistoryColorSelect();
}

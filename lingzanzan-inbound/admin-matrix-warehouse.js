/* LZ_MATRIX_WH_20260926 excerpt */
function selectedMatrixInboundWarehouse() {
  var matrix = document.querySelector('[data-matrix-inbound-warehouse]');
  var main = document.querySelector('[data-product-warehouse-select]');
  return String((matrix && matrix.value) || (main && main.value) || rememberedMatrixWarehouse() || '台灣倉').trim();
}

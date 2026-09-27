/* LZ_RECV_COLOR_FIRST_20260927
   入庫帶入顏色只找本款 SKU，配色格不用產品第一張主圖。 */
var resolvedProductId = String((product && product.id) || productId || '').trim();
var productSkus = (state.skus || []).filter(function (row) {
  return resolvedProductId && String(row.productId || '') === resolvedProductId;
});
var imageInfo = product ? freightExistingProductImageInfo(product, sku) : { src: '', isColorImage: false };
var image = imageInfo && imageInfo.isColorImage ? imageInfo.src : '';

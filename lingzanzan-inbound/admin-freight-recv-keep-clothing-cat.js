/* Live sidecar excerpt patched into assets/admin.js
 * Marker: LZ_RECV_KEEP_CLOTHING_CAT_20260926
 * 已選服裝時自動帶入服裝分類，不被 3C／生活用品誤判成電腦分類而清空。
 */
  // LZ_RECV_KEEP_CLOTHING_CAT_20260926: 已選服裝就帶入服裝分類；生活用品／3C 周邊不再被當成電腦分類清掉。
  function freightIsClothingCategoryName(value) {
    var raw = String(value || '').replace(/\s+/g, ' ').trim();
    if (!raw || /^(null|undefined)$/i.test(raw) || /^請(?:先)?選/.test(raw)) return false;
    var compact = raw.replace(/\s+/g, '').toLocaleLowerCase();
    if (freightStandardCategories().some(function (name) {
      return String(name || '').replace(/\s+/g, '').toLocaleLowerCase() === compact;
    })) return true;
    var normalized = freightNormalizeComputerCategory(raw);
    if (freightComputerCategories().indexOf(raw) !== -1 || freightComputerCategories().indexOf(normalized) !== -1) return false;
    return !/電腦|筆電|螢幕|顯示卡|主機板|記憶體|處理器|\bCPU\b|\bGPU\b|\bSSD\b|電源供應|機殼|散熱|網路設備|鍵盤|滑鼠|電子周邊|生活周邊/i.test(raw);
  }

  function freightIsStrictComputerCategoryName(value) {
    var raw = String(value || '').replace(/\s+/g, ' ').trim();
    if (!raw) return false;
    if (freightIsClothingCategoryName(raw)) return false;
    return freightIsComputerCategoryName(raw);
  }

  function freightClothingCategoryFromComputerAlias(value) {
    var raw = String(value || '').replace(/\s+/g, ' ').trim();
    if (raw === '生活周邊' || raw === '生活用品') return '生活用品';
    if (raw === '周邊設備' || raw === '電腦周邊' || raw === '3C 周邊' || raw === '3C周邊') return '3C 周邊';
    return '';
  }

  function freightReceivingCategoryCandidates(item, filed, product) {
    item = item || {};
    filed = filed || {};
    product = product || {};
    var seen = {};
    return [
      item.category, item.categoryName, item.productCategory,
      filed.category, filed.categoryName,
      product.category, product.categoryName, product.productCategory
    ].map(function (value) {
      return String(value || '').replace(/\s+/g, ' ').trim();
    }).filter(function (value) {
      if (!value || seen[value]) return false;
      seen[value] = true;
      return true;
    });
  }

  function freightReceivingLinkedProduct(item, filed) {
    item = item || {};
    filed = filed || {};
    var ids = [item.productId, item.productFiledProductId, filed.id]
      .map(function (value) { return String(value || '').trim(); })
      .filter(function (value) { return value && (typeof isFreightForecastProductId !== 'function' || !isFreightForecastProductId(value)); });
    var products = state.products || [];
    var byId = products.find(function (product) {
      return product && ids.indexOf(String(product.id || '').trim()) >= 0
        && product.archived !== true && product.active !== false
        && String(product.status || '').toLowerCase() !== 'inactive';
    });
    if (byId) return byId;
    var codes = [item.productCode, item.catalogProductCode, filed.code]
      .map(function (value) { return String(value || '').trim(); })
      .filter(Boolean);
    var matches = products.filter(function (product) {
      return product && codes.indexOf(String(product.code || product.productCode || '').trim()) >= 0
        && product.archived !== true && product.active !== false
        && String(product.status || '').toLowerCase() !== 'inactive';
    });
    return matches.length === 1 ? matches[0] : null;
  }

  function freightReceivingPreferredCategory(unit, item, filed, product, selected) {
    var computer = unit === 'baohui_computer';
    var selectedValue = String(selected || '').trim();
    var candidates = [selectedValue].concat(freightReceivingCategoryCandidates(item, filed, product));
    var unique = [];
    var seen = {};
    candidates.forEach(function (name) {
      if (!name || seen[name]) return;
      seen[name] = true;
      unique.push(name);
    });
    if (computer) return unique.filter(freightIsStrictComputerCategoryName)[0] || '';
    var clothing = unique.filter(freightIsClothingCategoryName)[0] || '';
    if (clothing) return clothing;
    for (var i = 0; i < unique.length; i++) {
      var alias = freightClothingCategoryFromComputerAlias(unique[i]);
      if (alias) return alias;
    }
    return '';
  }


'use strict';

function freightStandardCategories() {
  return ['短袖上衣', '長袖上衣', '襯衫', '背心', '外套', '套裝', '洋裝', '連身褲', '長褲', '短褲', '裙子', '內著', '睡衣', '男裝', '女裝', '童裝', '鞋類', '拖鞋', '包包', '帽子', '飾品', '服飾配件', '美妝', '保養', '生活用品', '廚房用品', '杯壺', '收納', '寢具', '玩具', '3C 周邊', '食品', '其他'];
}

function freightComputerCategories() {
  return ['筆記型電腦', '桌上型電腦', '一體式電腦', '伺服器／工作站', '螢幕', 'CPU處理器', '主機板', '記憶體', '顯示卡', '儲存裝置', '電源供應器', '機殼', '散熱器', '網路設備', '鍵盤／滑鼠', '音訊／視訊設備', '線材／轉接器', '電腦零組件', '電腦周邊', '周邊設備', '生活周邊', '工具相關', '電燈相關', '軟體相關', '汽車／配件／清潔', '其他電腦零組件', '其他電腦周邊', '其他電腦商品'];
}

function freightNormalizeComputerCategory(value) {
  var raw = String(value || '').replace(/\s+/g, ' ').trim();
  var compact = raw.replace(/\s+/g, '').toLocaleLowerCase();
  if (!raw || /^(null|undefined)$/i.test(raw) || /^請(?:先)?選/.test(raw)) return '';
  if (compact === '3c周邊') return '周邊設備';
  if (raw === '電腦周邊') return '周邊設備';
  if (raw === '枕頭類' || raw === '生活用品' || raw === '水壺/保溫杯' || raw === '水壺／保溫杯' || raw === '電子清潔用品') return '生活周邊';
  return raw;
}

var state = { computerCategories: [] };

function freightIsComputerCategoryName(value) {
  var raw = freightNormalizeComputerCategory(value);
  if (!raw) return false;
  if (freightComputerCategories().indexOf(raw) !== -1) return true;
  if ((state.computerCategories || []).some(function (item) {
    return String(item || '').trim().toLocaleLowerCase() === raw.toLocaleLowerCase();
  })) return true;
  return /電腦|筆電|螢幕|顯示卡|主機板|記憶體|處理器|CPU|GPU|SSD|電源供應|機殼|散熱|網路設備|鍵盤|滑鼠|3C|電子周邊|生活周邊/i.test(raw);
}

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

function oldWipeOnClothing(previousCategory) {
  if (previousCategory && freightIsComputerCategoryName(previousCategory)) return '';
  return previousCategory;
}

function resolveWorkbench(item, product, remembered) {
  var candidates = freightReceivingCategoryCandidates(item, {}, product);
  var clothingCategory = freightReceivingPreferredCategory('lingzanzan', item, {}, product, candidates[0] || '');
  if (!clothingCategory && freightIsClothingCategoryName(remembered)) clothingCategory = remembered;
  var storedBusinessUnit = item.inventoryBusinessUnit === 'baohui_computer' ? 'baohui_computer' : 'lingzanzan';
  var originalBusinessUnit = clothingCategory ? 'lingzanzan' : storedBusinessUnit;
  return {
    originalBusinessUnit: originalBusinessUnit,
    currentBusinessUnit: originalBusinessUnit,
    category: clothingCategory || oldWipeOnClothing(item.category)
  };
}

var failed = 0;
function assert(name, actual, expected) {
  if (actual !== expected) {
    failed += 1;
    console.error('FAIL', name, 'actual=', JSON.stringify(actual), 'expected=', JSON.stringify(expected));
  } else {
    console.log('ok', name, JSON.stringify(actual));
  }
}

assert('old wipe 生活用品', oldWipeOnClothing('生活用品'), '');
assert('old wipe 3C 周邊', oldWipeOnClothing('3C 周邊'), '');
assert('old wipe 短袖上衣', oldWipeOnClothing('短袖上衣'), '短袖上衣');

assert('clothing 生活用品', freightIsClothingCategoryName('生活用品'), true);
assert('strict computer 生活用品', freightIsStrictComputerCategoryName('生活用品'), false);
assert('clothing 3C 周邊', freightIsClothingCategoryName('3C 周邊'), true);
assert('clothing 短袖上衣', freightIsClothingCategoryName('短袖上衣'), true);
assert('clothing 鍵盤／滑鼠', freightIsClothingCategoryName('鍵盤／滑鼠'), false);
assert('strict computer 鍵盤／滑鼠', freightIsStrictComputerCategoryName('鍵盤／滑鼠'), true);
assert('alias 生活周邊', freightClothingCategoryFromComputerAlias('生活周邊'), '生活用品');

var life = resolveWorkbench(
  { inventoryBusinessUnit: 'baohui_computer', category: '生活用品' },
  { category: '生活用品' }
);
assert('生活用品 original unit', life.originalBusinessUnit, 'lingzanzan');
assert('生活用品 grabbed', life.category, '生活用品');

var tee = resolveWorkbench(
  { inventoryBusinessUnit: 'baohui_computer', category: '鍵盤／滑鼠' },
  { category: '短袖上衣' }
);
assert('product 短袖上衣 grabbed', tee.category, '短袖上衣');
assert('product 短袖上衣 unit', tee.originalBusinessUnit, 'lingzanzan');

var threeC = resolveWorkbench(
  { inventoryBusinessUnit: 'baohui_computer', category: '3C 周邊' },
  {}
);
assert('3C 周邊 grabbed', threeC.category, '3C 周邊');
assert('3C 周邊 unit', threeC.originalBusinessUnit, 'lingzanzan');

var alias = resolveWorkbench(
  { inventoryBusinessUnit: 'baohui_computer', category: '生活周邊' },
  {}
);
assert('生活周邊 remapped', alias.category, '生活用品');
assert('生活周邊 unit clothing', alias.originalBusinessUnit, 'lingzanzan');

var trueComputer = resolveWorkbench(
  { inventoryBusinessUnit: 'baohui_computer', category: '鍵盤／滑鼠' },
  { category: '鍵盤／滑鼠' }
);
assert('true computer stays computer', trueComputer.originalBusinessUnit, 'baohui_computer');
assert('true computer no fake clothing', trueComputer.category, '');

var remembered = resolveWorkbench(
  { inventoryBusinessUnit: 'baohui_computer', category: '' },
  {},
  '短袖上衣'
);
assert('remembered 短袖上衣 grabbed', remembered.category, '短袖上衣');
assert('remembered unit clothing', remembered.originalBusinessUnit, 'lingzanzan');

if (failed) {
  console.error('failed', failed);
  process.exit(1);
}
console.log('all passed');

const fs = require('fs');
const path = require('path');
const dir = __dirname;
const js = fs.readFileSync(path.join(dir, 'admin.js.new-cat-pick-1'), 'utf8');
const inv = fs.readFileSync(path.join(dir, 'admin-inventory-color-auto-10.js.new-cat-pick-1'), 'utf8');
const css = fs.readFileSync(path.join(dir, 'admin.css.new-cat-pick-1'), 'utf8');
const html = fs.readFileSync(path.join(dir, 'admin-inventory.html.new-cat-pick-1'), 'utf8');
const freight = fs.readFileSync(path.join(dir, 'admin-freight.html.new-cat-pick-1'), 'utf8');
const checks = {
  'admin.js marker': js.includes('LZ_CAT_PICK_20260927'),
  'admin.js bind': js.includes('bindCatalogCategoryPicker();'),
  'admin.js fifo button': js.includes('data-catalog-category-picker="fifo"'),
  'admin.js category searchable': js.includes('product.category, product.categoryName, product.productCategory, product.brand, product.brandName'),
  'inventory js marker': inv.includes('LZ_CAT_PICK_20260927'),
  'css marker': css.includes('LZ_CAT_PICK_20260927'),
  'inventory html button': html.includes('data-catalog-category-picker="inventory"'),
  'inventory html cache bust': html.includes('20260927-cat-pick-1'),
  'freight html button': freight.includes('data-catalog-category-picker="freight-existing"'),
  'freight html cache bust': freight.includes('admin.js?v=20260927-cat-pick-1')
};
const failed = Object.entries(checks).filter(([, ok]) => !ok).map(([name]) => name);
if (failed.length) {
  console.error('FAIL', failed.join(', '));
  process.exit(1);
}
console.log('cat-pick checks passed');

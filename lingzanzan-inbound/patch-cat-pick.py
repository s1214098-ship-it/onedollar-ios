#!/usr/bin/env python3
"""Patch live admin copies with category product picker."""
from pathlib import Path

ROOT = Path('/tmp/lz-catpick')
SNIP = Path('/workspace/lingzanzan-inbound/catalog-category-picker.snippet.js').read_text()
CSS = Path('/workspace/lingzanzan-inbound/catalog-category-picker.css').read_text()
MARKER = 'LZ_CAT_PICK_20260927'
TAG = '20260927-cat-pick-1'

OLD_FIFO_LABEL = '<label class="is-search">搜尋產品<input data-freight-fifo-priority-search placeholder="輸入產品編號、條碼或名稱"></label>'
NEW_FIFO_LABEL = '<label class="is-search">搜尋產品<input data-freight-fifo-priority-search placeholder="輸入產品編號、條碼、名稱或分類"></label><button type="button" class="ghost-button catalog-category-picker-open" data-catalog-category-picker="fifo">分類找產品</button>'

OLD_ADJUST = '<label class="is-search">搜尋新商品<input data-item-adjust-search placeholder="輸入產品編號、條碼或名稱"></label>'
NEW_ADJUST = '<label class="is-search">搜尋新商品<input data-item-adjust-search placeholder="輸入產品編號、條碼、名稱或分類"></label><button type="button" class="ghost-button catalog-category-picker-open" data-catalog-category-picker="item-adjust">分類找產品</button>'

OLD_SEARCHABLE_A = """        product.code, product.productLine, product.title, product.name, product.frontTitle,
        product.englishName, product.nameEn, product.titleEn, product.englishTitle,
        Array.isArray(product.nameAliases) ? product.nameAliases.join(' ') : '',
        sku.id, sku.sku, sku.companyBarcode, sku.barcode,
        sku.colorName, sku.color, sku.sizeName, sku.size"""

NEW_SEARCHABLE_A = """        product.code, product.productLine, product.title, product.name, product.frontTitle,
        product.englishName, product.nameEn, product.titleEn, product.englishTitle,
        product.category, product.categoryName, product.productCategory, product.brand, product.brandName,
        Array.isArray(product.nameAliases) ? product.nameAliases.join(' ') : '',
        sku.id, sku.sku, sku.companyBarcode, sku.barcode,
        sku.colorName, sku.color, sku.sizeName, sku.size"""

OLD_SEARCHABLE_B = """        product.code, product.productCode, product.title, product.name,
        sku.id, sku.sku, sku.companyBarcode, sku.barcode,
        sku.colorName, sku.color, sku.sizeName, sku.size"""

NEW_SEARCHABLE_B = """        product.code, product.productCode, product.title, product.name,
        product.category, product.categoryName, product.productCategory, product.brand, product.brandName,
        sku.id, sku.sku, sku.companyBarcode, sku.barcode,
        sku.colorName, sku.color, sku.sizeName, sku.size"""

OLD_SHIP_TEXT = """      row.product.spec,
      row.product.barcode, row.product.companyBarcode, row.product.productLine,"""

NEW_SHIP_TEXT = """      row.product.spec, row.product.category, row.product.categoryName, row.product.productCategory, row.product.brand,
      row.product.barcode, row.product.companyBarcode, row.product.productLine,"""

BIND = "    bindCatalogCategoryPicker();\n"


def insert_snippet(js: str) -> str:
    if MARKER in js:
        return js
    needle = '  function installOperationalPatches() {'
    idx = js.find(needle)
    if idx < 0:
        raise SystemExit('installOperationalPatches not found')
    js = js[:idx] + SNIP + '\n\n' + js[idx:]
    after = '    if (state._operationalPatchesInstalled) return;\n    state._operationalPatchesInstalled = true;\n'
    if after not in js:
        raise SystemExit('operational patch guard not found')
    js = js.replace(after, after + BIND, 1)
    return js


def patch_admin_js():
    p = ROOT / 'admin.js'
    t = p.read_text()
    t = insert_snippet(t)
    if OLD_SEARCHABLE_A not in t:
        raise SystemExit('fifo searchable A not found')
    t = t.replace(OLD_SEARCHABLE_A, NEW_SEARCHABLE_A, 1)
    if OLD_SEARCHABLE_B not in t:
        raise SystemExit('fifo searchable B not found')
    t = t.replace(OLD_SEARCHABLE_B, NEW_SEARCHABLE_B, 1)
    n = t.count(OLD_FIFO_LABEL)
    if n < 3:
        raise SystemExit(f'fifo labels found {n}, expected >=3')
    t = t.replace(OLD_FIFO_LABEL, NEW_FIFO_LABEL)
    if OLD_ADJUST not in t:
        raise SystemExit('item-adjust search not found')
    t = t.replace(OLD_ADJUST, NEW_ADJUST, 1)
    if OLD_SHIP_TEXT not in t:
        raise SystemExit('shipment search text not found')
    t = t.replace(OLD_SHIP_TEXT, NEW_SHIP_TEXT, 1)
    p.write_text(t)
    print('patched admin.js', p.stat().st_size)


def patch_inventory_js():
    p = ROOT / 'admin-inventory-color-auto-10.js'
    t = p.read_text()
    t = insert_snippet(t)
    p.write_text(t)
    print('patched inventory js', p.stat().st_size)


def patch_css():
    p = ROOT / 'admin.css'
    t = p.read_text()
    if MARKER not in t:
        t = t.rstrip() + '\n\n' + CSS + '\n'
        p.write_text(t)
    print('patched admin.css', p.stat().st_size)


def patch_inventory_html():
    p = ROOT / 'admin-inventory.html'
    t = p.read_text()
    old = '''          <label>搜尋品牌 / 產品名稱 / 編號 / SKU / 顏色 / 尺寸 / 倉位
            <input data-inventory-search placeholder="輸入商品、SKU、顏色、倉位、客戶姓名、電話或訂單編號" autocomplete="off">
          </label>'''
    new = '''          <label>搜尋品牌 / 產品名稱 / 編號 / SKU / 顏色 / 尺寸 / 倉位
            <span class="catalog-category-picker-search-row">
              <input data-inventory-search placeholder="輸入商品、SKU、顏色、倉位、客戶姓名、電話或訂單編號" autocomplete="off">
              <button type="button" class="ghost-button catalog-category-picker-open" data-catalog-category-picker="inventory">分類找產品</button>
            </span>
          </label>'''
    if old not in t:
        raise SystemExit('inventory search label not found')
    t = t.replace(old, new, 1)
    t = t.replace('asset-boot.php?v=20260927-print-wh-2', f'asset-boot.php?v={TAG}')
    t = t.replace('admin-inventory-color-auto-10.js?v=20260927-print-wh-2', f'admin-inventory-color-auto-10.js?v={TAG}')
    t = t.replace('admin.css?v=20260924-cn-prefix-1', f'admin.css?v={TAG}')
    if MARKER not in t:
        t = t.replace(
            '    .inventory-print-warehouse-pick em{width:100%;font-style:normal;color:#ffe7b3;font-size:13px}',
            '    .inventory-print-warehouse-pick em{width:100%;font-style:normal;color:#ffe7b3;font-size:13px}\n' + CSS.replace('\n', '\n    '),
            1,
        )
    p.write_text(t)
    print('patched inventory html')


def patch_freight_html():
    p = ROOT / 'admin-freight.html'
    t = p.read_text()
    old = '<div class="freight-existing-product-search-actions"><input type="search" data-freight-existing-product-search autocomplete="off" inputmode="search" placeholder="例如：套裝、外套、廠商、ADIDAS、OLAN68、既有條碼"><button type="button" class="ghost-button freight-existing-product-clear" data-freight-existing-product-clear hidden>清除已選產品</button></div>'
    new = '<div class="freight-existing-product-search-actions"><input type="search" data-freight-existing-product-search autocomplete="off" inputmode="search" placeholder="例如：套裝、外套、廠商、ADIDAS、OLAN68、既有條碼"><button type="button" class="ghost-button catalog-category-picker-open" data-catalog-category-picker="freight-existing">分類找產品</button><button type="button" class="ghost-button freight-existing-product-clear" data-freight-existing-product-clear hidden>清除已選產品</button></div>'
    if old not in t:
        raise SystemExit('freight existing search not found')
    t = t.replace(old, new, 1)
    t = t.replace('admin.js?v=20260927-recv-close-3', f'admin.js?v={TAG}')
    t = t.replace('admin.css?v=20260927-recv-close-3', f'admin.css?v={TAG}')
    if MARKER not in t:
        t = t.replace('<style>', '<style>\n' + CSS + '\n', 1)
    p.write_text(t)
    print('patched freight html')


if __name__ == '__main__':
    patch_admin_js()
    patch_inventory_js()
    patch_css()
    patch_inventory_html()
    patch_freight_html()
    print('ok')

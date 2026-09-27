  // LZ_CAT_PICK_20260927: 出貨單／庫存／其他單據用分類快捷找既有產品並帶入
  var CATALOG_CATEGORY_PICKER_CLOTHING = ['短袖上衣', '長袖上衣', '襯衫', '背心', '外套', '套裝', '洋裝', '連身褲', '長褲', '短褲', '裙子', '內著', '睡衣', '男裝', '女裝', '童裝', '鞋類', '拖鞋', '包包', '帽子', '飾品', '服飾配件', '美妝', '保養', '生活用品', '廚房用品', '杯壺', '收納', '寢具', '玩具', '3C 周邊', '食品', '其他'];
  var CATALOG_CATEGORY_PICKER_COMPUTER = ['筆記型電腦', '桌上型電腦', '一體式電腦', '伺服器／工作站', '螢幕', 'CPU處理器', '主機板', '記憶體', '顯示卡', '儲存裝置', '電源供應器', '機殼', '散熱器', '網路設備', '鍵盤／滑鼠', '音訊／視訊設備', '線材／轉接器', '電腦零組件', '電腦周邊', '周邊設備', '生活周邊', '工具相關', '電燈相關', '軟體相關', '汽車／配件／清潔', '其他電腦零組件', '其他電腦周邊', '其他電腦商品'];
  var catalogCategoryPickerState = { department: 'lingzanzan', category: '', query: '', context: '', search: null };

  function catalogCategoryPickerEscape(value) {
    if (typeof escapeHtml === 'function') return escapeHtml(value);
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function catalogCategoryPickerName(product) {
    return String(product && (product.category || product.categoryName || product.productCategory || product.categoryLabel || product.typeName) || '').replace(/\s+/g, ' ').trim();
  }

  function catalogCategoryPickerNorm(value) {
    return String(value || '').replace(/\s+/g, '').toLocaleLowerCase();
  }

  function catalogCategoryPickerDepartment(product) {
    if (typeof inventoryProductBusinessUnit === 'function') return inventoryProductBusinessUnit(product);
    product = product || {};
    var values = [product.inventoryBusinessUnit, product.department, product.category_scope, product.category_group]
      .map(function (value) { return String(value || '').trim().toLowerCase(); })
      .filter(Boolean);
    if (values.some(function (value) { return /^(baohui_computer|computer|電腦|電腦部門)$/.test(value); })) return 'baohui_computer';
    if (typeof isComputerDepartmentRecord === 'function' && isComputerDepartmentRecord(product)) return 'baohui_computer';
    if (values.some(function (value) { return /^(lingzanzan|clothing|apparel|fashion|服裝|服飾|服裝部門)$/.test(value); })) return 'lingzanzan';
    var category = catalogCategoryPickerName(product);
    if (CATALOG_CATEGORY_PICKER_COMPUTER.some(function (name) { return catalogCategoryPickerNorm(name) === catalogCategoryPickerNorm(category); })) return 'baohui_computer';
    if (CATALOG_CATEGORY_PICKER_CLOTHING.some(function (name) { return catalogCategoryPickerNorm(name) === catalogCategoryPickerNorm(category); })) return 'lingzanzan';
    return 'unassigned';
  }

  function catalogCategoryPickerImage(product) {
    if (typeof productImageSources === 'function') {
      var sources = productImageSources(product) || [];
      if (sources[0]) return sources[0];
    }
    product = product || {};
    if (product.mainImage) return product.mainImage;
    if (product.image) return product.image;
    if (product.cover) return product.cover;
    if (Array.isArray(product.images) && product.images[0]) return product.images[0];
    var color = Array.isArray(product.colors) ? product.colors.find(function (row) { return row && row.image; }) : null;
    return color && color.image || '';
  }

  function catalogCategoryPickerStock(product) {
    if (typeof productTotalStock === 'function' && product && product.id) {
      try { return Number(productTotalStock(product) || 0); } catch (error) {}
    }
    var id = String(product && product.id || '');
    return (state.skus || []).reduce(function (sum, sku) {
      if (String(sku && sku.productId || '') !== id) return sum;
      return sum + Math.max(0, Number(sku && sku.stock || 0));
    }, 0);
  }

  function catalogCategoryPickerColors(product) {
    var seen = {};
    var colors = [];
    function push(value) {
      var name = String(value || '').trim();
      if (!name || seen[name]) return;
      seen[name] = true;
      colors.push(name);
    }
    (product && product.colors || []).forEach(function (row) { push(row && (row.name || row.color || row)); });
    var id = String(product && product.id || '');
    (state.skus || []).forEach(function (sku) {
      if (String(sku && sku.productId || '') !== id) return;
      push(sku.colorName || sku.color);
    });
    return colors.slice(0, 8);
  }

  function catalogCategoryPickerHistoryTime(product) {
    if (typeof freightProductHistoryTime === 'function') {
      try { return Number(freightProductHistoryTime(product) || 0); } catch (error) {}
    }
    var raw = product && (product.updatedAt || product.createdAt || product.created_at || product.lastInboundAt || product.filedAt || '');
    var stamp = Date.parse(String(raw || ''));
    return isFinite(stamp) ? stamp : 0;
  }

  function catalogCategoryPickerCurrentDepartment() {
    var filter = document.querySelector('[data-inventory-business-filter]');
    if (filter && filter.value) return String(filter.value || 'lingzanzan');
    if (typeof freightIsComputerFilingMode === 'function' && freightIsComputerFilingMode()) return 'baohui_computer';
    return 'lingzanzan';
  }

  function catalogCategoryPickerProducts(department, categoryName) {
    var wanted = catalogCategoryPickerNorm(categoryName);
    return (state.products || []).filter(function (product) {
      if (!product) return false;
      var unit = catalogCategoryPickerDepartment(product);
      if (department && department !== 'all' && unit !== department && !(department === 'lingzanzan' && unit === 'unassigned' && wanted)) {
        if (department === 'unassigned') {
          if (unit !== 'unassigned') return false;
        } else if (unit !== department) return false;
      }
      if (!wanted) return true;
      return catalogCategoryPickerNorm(catalogCategoryPickerName(product)) === wanted;
    });
  }

  function catalogCategoryPickerGroups(department) {
    var counts = {};
    catalogCategoryPickerProducts(department, '').forEach(function (product) {
      var name = catalogCategoryPickerName(product) || '未填分類';
      if (!counts[name]) counts[name] = 0;
      counts[name] += 1;
    });
    var standard = department === 'baohui_computer' ? CATALOG_CATEGORY_PICKER_COMPUTER : CATALOG_CATEGORY_PICKER_CLOTHING;
    var seen = {};
    var rows = [];
    standard.forEach(function (name) {
      var count = counts[name] || 0;
      if (!count) return;
      seen[name] = true;
      rows.push({ name: name, count: count, standard: true });
    });
    Object.keys(counts).sort(function (a, b) { return counts[b] - counts[a] || a.localeCompare(b, 'zh-Hant'); }).forEach(function (name) {
      if (seen[name]) return;
      rows.push({ name: name, count: counts[name], standard: false });
    });
    return rows;
  }

  function catalogCategoryPickerFillSearch(input, value) {
    if (!input) return;
    input.value = String(value || '');
    try { input.dispatchEvent(new Event('input', { bubbles: true })); } catch (error) {}
    try { input.dispatchEvent(new KeyboardEvent('keyup', { bubbles: true, key: 'Enter' })); } catch (error) {}
    try { input.dispatchEvent(new Event('change', { bubbles: true })); } catch (error) {}
    try { input.focus(); } catch (error) {}
  }

  function catalogCategoryPickerHostModal(el) {
    if (!el || !el.closest) return document;
    return el.closest('.freight-fifo-modal')
      || el.closest('[data-freight-fifo-list-add-modal]')
      || el.closest('[data-freight-planned-merge-modal]')
      || el.closest('[data-item-adjust]')
      || document.querySelector('.freight-fifo-item-adjustment')
      || (el.closest('section') && el.closest('section').parentNode)
      || document;
  }

  function catalogCategoryPickerApply(product, fillCategory) {
    var code = String(product && (product.code || product.productCode || product.id) || '').trim();
    var title = String(product && (product.title || product.name || code) || '').trim();
    var category = catalogCategoryPickerName(product);
    var value = fillCategory ? category : (code || title);
    var context = catalogCategoryPickerState.context || '';
    var search = catalogCategoryPickerState.search;
    if (!search || !search.isConnected) {
      if (context === 'inventory') search = document.querySelector('[data-inventory-search]');
      else if (context === 'fifo') search = document.querySelector('[data-freight-fifo-priority-search]');
      else if (context === 'item-adjust') search = document.querySelector('[data-item-adjust-search]');
      else if (context === 'freight-existing') search = document.querySelector('[data-freight-existing-product-search]');
      else if (context === 'shipment') search = document.querySelector('[data-admin-shipment-search]');
      else if (context === 'preorder') search = document.querySelector('[data-manual-preorder-search]');
    }
    if (context === 'freight-existing' && product && product.id && typeof applyFreightExistingProduct === 'function' && !fillCategory) {
      applyFreightExistingProduct(product.id);
      if (typeof toast === 'function') toast('已帶入「' + (code || title) + '」');
      return;
    }
    catalogCategoryPickerFillSearch(search, value);
    if (context === 'inventory' || (search && search.hasAttribute && search.hasAttribute('data-inventory-search'))) {
      try { if (typeof inventoryPage === 'number') inventoryPage = 1; } catch (error) {}
      if (typeof renderInventoryLines === 'function') renderInventoryLines();
    } else if (context === 'fifo' || (search && search.hasAttribute && search.hasAttribute('data-freight-fifo-priority-search'))) {
      if (typeof freightFifoRenderPriorityProducts === 'function') {
        freightFifoRenderPriorityProducts(catalogCategoryPickerHostModal(search));
      }
    } else if (context === 'shipment' || (search && search.hasAttribute && search.hasAttribute('data-admin-shipment-search'))) {
      if (typeof renderAdminShipmentSuggest === 'function') renderAdminShipmentSuggest();
    } else if (context === 'preorder' || (search && search.hasAttribute && search.hasAttribute('data-manual-preorder-search'))) {
      if (typeof renderManualPreorderSuggest === 'function') renderManualPreorderSuggest();
    }
    if (typeof toast === 'function') {
      toast(fillCategory
        ? '已帶入分類「' + category + '」，可再從結果選產品'
        : '已帶入「' + (code || title) + '」' + (context === 'fifo' || context === 'item-adjust' || context === 'shipment' || context === 'preorder' ? '，請再選顏色／尺寸' : ''));
    }
  }

  function catalogCategoryPickerClose() {
    var overlay = document.querySelector('[data-catalog-category-picker-overlay]');
    if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
  }

  function catalogCategoryPickerRender() {
    var overlay = document.querySelector('[data-catalog-category-picker-overlay]');
    if (!overlay) return;
    var department = catalogCategoryPickerState.department;
    var category = catalogCategoryPickerState.category;
    var query = catalogCategoryPickerNorm(catalogCategoryPickerState.query);
    var body = overlay.querySelector('[data-catalog-category-picker-body]');
    var note = overlay.querySelector('[data-catalog-category-picker-note]');
    if (!body) return;
    if (!category) {
      var groups = catalogCategoryPickerGroups(department).filter(function (row) {
        if (!query) return true;
        return catalogCategoryPickerNorm(row.name).indexOf(query) !== -1;
      });
      if (note) note.textContent = '先選分類（例如套裝）。只顯示後台已建檔、以前買過／入過庫的產品。';
      body.innerHTML = groups.length
        ? '<div class="catalog-category-picker-cats">' + groups.map(function (row) {
          return '<button type="button" class="catalog-category-picker-cat" data-catalog-category-pick-cat="' + catalogCategoryPickerEscape(row.name) + '"><b>' + catalogCategoryPickerEscape(row.name) + '</b><small>' + row.count + ' 個產品</small></button>';
        }).join('') + '</div>'
        : '<p class="catalog-category-picker-empty">這個部門目前沒有已建檔分類產品。</p>';
      return;
    }
    var products = catalogCategoryPickerProducts(department, category).filter(function (product) {
      if (!query) return true;
      var hay = catalogCategoryPickerNorm([
        product.code, product.productCode, product.title, product.name, product.brand, product.brandName, catalogCategoryPickerColors(product).join(' ')
      ].filter(Boolean).join(' '));
      return hay.indexOf(query) !== -1;
    }).sort(function (a, b) {
      var time = catalogCategoryPickerHistoryTime(b) - catalogCategoryPickerHistoryTime(a);
      if (time) return time;
      return String(a.code || a.title || '').localeCompare(String(b.code || b.title || ''), 'zh-Hant', { numeric: true });
    });
    if (note) note.textContent = '「' + category + '」共 ' + products.length + ' 個既有產品，新的在前。按帶入即可填入目前單據搜尋。';
    body.innerHTML = '<div class="catalog-category-picker-toolbar">'
      + '<button type="button" class="ghost-button" data-catalog-category-pick-back>← 回分類</button>'
      + '<button type="button" class="ghost-button" data-catalog-category-pick-category="' + catalogCategoryPickerEscape(category) + '">帶入這個分類</button>'
      + '</div>'
      + (products.length
        ? '<div class="catalog-category-picker-products">' + products.map(function (product) {
          var image = catalogCategoryPickerImage(product);
          var code = product.code || product.productCode || product.id || '未填編號';
          var title = product.title || product.name || code;
          var colors = catalogCategoryPickerColors(product);
          var stock = catalogCategoryPickerStock(product);
          return '<article class="catalog-category-picker-card">'
            + (image ? '<img src="' + catalogCategoryPickerEscape(image) + '" alt="">' : '<span class="is-no-image">無圖</span>')
            + '<div><b>' + catalogCategoryPickerEscape(code) + '</b><em>' + catalogCategoryPickerEscape(title) + '</em>'
            + '<small>' + catalogCategoryPickerEscape(colors.length ? colors.join('、') : '未填顏色') + '／庫存 ' + stock + ' 件</small></div>'
            + '<button type="button" class="primary-button" data-catalog-category-pick-product="' + catalogCategoryPickerEscape(String(product.id || code)) + '">帶入</button>'
            + '</article>';
        }).join('') + '</div>'
        : '<p class="catalog-category-picker-empty">這個分類還沒有後台產品。</p>');
  }

  function openCatalogCategoryPicker(options) {
    options = options || {};
    catalogCategoryPickerState.context = String(options.context || 'inventory');
    catalogCategoryPickerState.search = options.search || null;
    catalogCategoryPickerState.department = options.department || catalogCategoryPickerCurrentDepartment();
    catalogCategoryPickerState.category = '';
    catalogCategoryPickerState.query = '';
    catalogCategoryPickerClose();
    var overlay = document.createElement('div');
    overlay.className = 'catalog-category-picker-overlay';
    overlay.setAttribute('data-catalog-category-picker-overlay', '');
    overlay.innerHTML = '<section>'
      + '<header><div><small>CATEGORY PICKER</small><h3>分類找產品</h3><p>忘記編號時先選套裝、外套等分類，再從以前建檔的產品帶入。</p></div>'
      + '<button type="button" class="ghost-button" data-catalog-category-pick-close>關閉</button></header>'
      + '<div class="catalog-category-picker-filters" role="group" aria-label="部門">'
      + '<button type="button" data-catalog-category-pick-dept="lingzanzan">服裝部</button>'
      + '<button type="button" data-catalog-category-pick-dept="baohui_computer">電腦部</button>'
      + '<button type="button" data-catalog-category-pick-dept="unassigned">未設定</button>'
      + '<button type="button" data-catalog-category-pick-dept="all">全部</button>'
      + '</div>'
      + '<label class="catalog-category-picker-search">再縮小範圍<input data-catalog-category-picker-filter placeholder="可輸入分類、產品編號或名稱" autocomplete="off"></label>'
      + '<p class="catalog-category-picker-note" data-catalog-category-picker-note></p>'
      + '<div class="catalog-category-picker-body" data-catalog-category-picker-body></div>'
      + '<footer><button type="button" class="ghost-button" data-catalog-category-pick-close>關閉</button></footer>'
      + '</section>';
    document.body.appendChild(overlay);
    Array.prototype.forEach.call(overlay.querySelectorAll('[data-catalog-category-pick-dept]'), function (button) {
      button.classList.toggle('is-active', button.getAttribute('data-catalog-category-pick-dept') === catalogCategoryPickerState.department);
    });
    catalogCategoryPickerRender();
    var filter = overlay.querySelector('[data-catalog-category-picker-filter]');
    if (filter) filter.focus();
  }

  function catalogCategoryPickerFindProduct(id) {
    var wanted = String(id || '');
    return (state.products || []).find(function (product) {
      return String(product && (product.id || product.code || product.productCode) || '') === wanted;
    }) || null;
  }

  function catalogCategoryPickerEnsureButton(input, context) {
    if (!input || input.getAttribute('data-catalog-category-picker-ready') === '1') return;
    input.setAttribute('data-catalog-category-picker-ready', '1');
    var host = input.closest('label') || input.parentNode;
    if (!host || !host.parentNode) return;
    if (host.parentNode.querySelector('[data-catalog-category-picker="' + context + '"]')) return;
    if (input.closest('.catalog-category-picker-search-row')) return;
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'ghost-button catalog-category-picker-open';
    btn.setAttribute('data-catalog-category-picker', context);
    btn.textContent = '分類找產品';
    if (input.parentNode === host && host.tagName === 'LABEL') {
      var row = document.createElement('span');
      row.className = 'catalog-category-picker-search-row';
      input.parentNode.insertBefore(row, input);
      row.appendChild(input);
      row.appendChild(btn);
      return;
    }
    if (host.nextSibling) host.parentNode.insertBefore(btn, host.nextSibling);
    else host.parentNode.appendChild(btn);
  }

  function catalogCategoryPickerScan() {
    [
      ['[data-inventory-search]', 'inventory'],
      ['[data-freight-fifo-priority-search]', 'fifo'],
      ['[data-item-adjust-search]', 'item-adjust'],
      ['[data-freight-existing-product-search]', 'freight-existing'],
      ['[data-admin-shipment-search]', 'shipment'],
      ['[data-manual-preorder-search]', 'preorder']
    ].forEach(function (pair) {
      Array.prototype.forEach.call(document.querySelectorAll(pair[0]), function (input) {
        catalogCategoryPickerEnsureButton(input, pair[1]);
      });
    });
  }

  function bindCatalogCategoryPicker() {
    if (window.__lzCatPickBound) return;
    window.__lzCatPickBound = true;
    document.addEventListener('click', function (event) {
      var target = event.target && event.target.closest ? event.target : null;
      if (!target || !target.closest) return;
      var openBtn = target.closest('[data-catalog-category-picker]');
      if (openBtn) {
        event.preventDefault();
        event.stopPropagation();
        var context = openBtn.getAttribute('data-catalog-category-picker') || 'inventory';
        var map = {
          inventory: '[data-inventory-search]',
          fifo: '[data-freight-fifo-priority-search]',
          'item-adjust': '[data-item-adjust-search]',
          'freight-existing': '[data-freight-existing-product-search]',
          shipment: '[data-admin-shipment-search]',
          preorder: '[data-manual-preorder-search]'
        };
        var root = openBtn.closest('.freight-fifo-modal, [data-freight-fifo-list-add-modal], [data-freight-planned-merge-modal], [data-item-adjust], form, section, body') || document;
        var search = (root && root.querySelector && map[context] ? root.querySelector(map[context]) : null) || document.querySelector(map[context] || '');
        if (!search && openBtn.previousElementSibling && openBtn.previousElementSibling.querySelector) {
          search = openBtn.previousElementSibling.querySelector('input');
        }
        openCatalogCategoryPicker({ context: context, search: search });
        return;
      }
      if (!document.querySelector('[data-catalog-category-picker-overlay]')) return;
      if (target.closest('[data-catalog-category-pick-close]')) {
        event.preventDefault();
        catalogCategoryPickerClose();
        return;
      }
      var deptBtn = target.closest('[data-catalog-category-pick-dept]');
      if (deptBtn) {
        event.preventDefault();
        catalogCategoryPickerState.department = deptBtn.getAttribute('data-catalog-category-pick-dept') || 'lingzanzan';
        catalogCategoryPickerState.category = '';
        Array.prototype.forEach.call(document.querySelectorAll('[data-catalog-category-pick-dept]'), function (button) {
          button.classList.toggle('is-active', button === deptBtn);
        });
        catalogCategoryPickerRender();
        return;
      }
      var backBtn = target.closest('[data-catalog-category-pick-back]');
      if (backBtn) {
        event.preventDefault();
        catalogCategoryPickerState.category = '';
        catalogCategoryPickerRender();
        return;
      }
      var catBtn = target.closest('[data-catalog-category-pick-cat]');
      if (catBtn) {
        event.preventDefault();
        catalogCategoryPickerState.category = catBtn.getAttribute('data-catalog-category-pick-cat') || '';
        catalogCategoryPickerState.query = '';
        var filter = document.querySelector('[data-catalog-category-picker-filter]');
        if (filter) filter.value = '';
        catalogCategoryPickerRender();
        return;
      }
      var fillCat = target.closest('[data-catalog-category-pick-category]');
      if (fillCat) {
        event.preventDefault();
        catalogCategoryPickerApply({
          category: fillCat.getAttribute('data-catalog-category-pick-category') || catalogCategoryPickerState.category,
          categoryName: fillCat.getAttribute('data-catalog-category-pick-category') || catalogCategoryPickerState.category
        }, true);
        catalogCategoryPickerClose();
        return;
      }
      var productBtn = target.closest('[data-catalog-category-pick-product]');
      if (productBtn) {
        event.preventDefault();
        var product = catalogCategoryPickerFindProduct(productBtn.getAttribute('data-catalog-category-pick-product'));
        if (!product) {
          if (typeof toast === 'function') toast('找不到這筆產品');
          return;
        }
        catalogCategoryPickerApply(product, false);
        catalogCategoryPickerClose();
      }
    }, true);
    document.addEventListener('input', function (event) {
      if (!event.target || !event.target.matches || !event.target.matches('[data-catalog-category-picker-filter]')) return;
      catalogCategoryPickerState.query = String(event.target.value || '');
      catalogCategoryPickerRender();
    });
    catalogCategoryPickerScan();
    if (!window.__lzCatPickObserver && window.MutationObserver) {
      window.__lzCatPickObserver = new MutationObserver(function () { catalogCategoryPickerScan(); });
      window.__lzCatPickObserver.observe(document.body, { childList: true, subtree: true });
    }
  }

'use strict';

/**
 * 1) Order cards were listing SKUs like warehouse stock (code + full name +
 *    NO SIZE). Show 本單購買：款／色／尺碼／買 N 件. NO SIZE → 均碼（此款無分尺寸）.
 *    Do not dump skuId as if it were a stock row.
 * 2) 「今天已有 N 張｜請確認是否重複打單」counted the card itself and also
 *    flagged already-shipped / already-picked orders that are a different basket.
 *
 * Cache-bust: admin.js / member-risk-v2.js / member-risk-v2.css /
 *             admin-order-tracking.css ?v=20260819-purchase-dup-1
 */

const fs = require('fs');
const path = require('path');

const root = process.env.LINGZANZAN_ROOT || 'F:/Web/lingzanzan-staging';
const BUST = '20260819-purchase-dup-1';

const adminJs = path.join(root, 'assets', 'admin.js');
const riskJs = path.join(root, 'assets', 'member-risk-v2.js');
const riskCss = path.join(root, 'assets', 'member-risk-v2.css');
const trackCss = path.join(root, 'assets', 'admin-order-tracking.css');
const riskApi = path.join(root, 'member-risk-api-v3.php');

function backup(file, tag) {
  const dir = path.join(root, 'data', 'audit');
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
  const dest = path.join(dir, path.basename(file) + '.' + tag + '-' + new Date().toISOString().replace(/[:.]/g, '-'));
  fs.copyFileSync(file, dest);
  return dest;
}

function replaceOnce(src, oldStr, newStr, label) {
  if (src.indexOf(newStr) !== -1 && src.indexOf(oldStr) === -1) {
    console.log('already:', label);
    return src;
  }
  const i = src.indexOf(oldStr);
  if (i < 0) throw new Error('missing snippet: ' + label);
  if (src.indexOf(oldStr, i + oldStr.length) !== -1) throw new Error('not unique: ' + label);
  console.log('patched:', label);
  return src.slice(0, i) + newStr + src.slice(i + oldStr.length);
}

const PURCHASE_HELPERS = `
  function orderTrackingPurchaseSizeLabel(size) {
    var raw = String(size == null ? '' : size).trim().replace(/\\s+/g, ' ');
    if (!raw || /^(NO SIZE|NOSIZE|N\\/A|無尺寸|無|—|-)$/i.test(raw)) return '均碼（此款無分尺寸）';
    return raw;
  }
  function orderPurchaseItemKey(items) {
    return (Array.isArray(items) ? items : []).map(function (item) {
      item = item || {};
      var code = String(item.code || item.productCode || '').trim().toUpperCase();
      var color = String(item.color || item.selectedColor || '').trim().toLowerCase().replace(/\\s+/g, '');
      var size = String(item.size || 'NO SIZE').trim().toUpperCase().replace(/\\s+/g, ' ') || 'NO SIZE';
      if (/^NO\\s*SIZE$/.test(size)) size = 'NO SIZE';
      return [code, color, size].join('|');
    }).filter(function (part) { return part && part !== '||NO SIZE' && part !== '||'; }).sort().join('+');
  }
  function orderTrackingPurchaseLineText(item) {
    item = item || {};
    var code = String(item.code || item.productCode || '').trim();
    var name = String(item.title || item.name || item.productName || '').trim();
    if (code && name.indexOf(code) === 0) name = name.slice(code.length).replace(/^[\\s\\-_/／]+/, '');
    var color = String(item.color || item.selectedColor || '').trim();
    var size = orderTrackingPurchaseSizeLabel(item.size);
    var qty = Math.max(0, Number(item.qty || item.quantity || 0));
    var bits = [];
    if (code) bits.push('款 ' + code);
    if (name && name !== code) bits.push(name);
    if (color) bits.push('色 ' + color);
    bits.push('尺碼 ' + size);
    bits.push('買 ' + qty + ' 件');
    return bits.join(' ／ ');
  }
  function orderTrackingPurchaseSummary(items, limit) {
    items = Array.isArray(items) ? items : [];
    limit = Math.max(1, Number(limit || 3));
    var lines = items.slice(0, limit).map(orderTrackingPurchaseLineText);
    if (items.length > limit) lines.push('另 ' + (items.length - limit) + ' 款未展開');
    return lines.join('\\n');
  }
  function orderTrackingCardClosedFlag(entry) {
    var stage = String(entry && entry.stage || '').toLowerCase();
    return ['delivered', 'completed', 'picked', 'pickup', 'cancelled', 'canceled', 'shipped', 'in_transit', 'returned'].indexOf(stage) !== -1 ? '1' : '0';
  }
  function orderTrackingCardMetaAttrs(entry, recordId, items) {
    var stage = String(entry && entry.stage || '');
    var dateKey = taipeiCalendarKey(orderTrackingRecordDate(entry));
    return ' data-order-id="' + escapeHtml(recordId || '') + '" data-order-stage="' + escapeHtml(stage) + '" data-order-date="' + escapeHtml(dateKey || '') + '" data-order-closed="' + orderTrackingCardClosedFlag(entry) + '" data-order-item-key="' + escapeHtml(orderPurchaseItemKey(items)) + '"';
  }

`;

const OLD_COMPACT_SUMMARY = `    var itemSummary = items.slice(0, 2).map(function (item) {
      return [item.code || item.sku || item.skuId || item.title || item.name || '產品', item.color || '', item.size || '', '×' + Number(item.qty || item.quantity || 0)].filter(Boolean).join(' ');
    }).join('；');`;

const NEW_COMPACT_SUMMARY = `    var itemSummary = orderTrackingPurchaseSummary(items, 2);`;

const OLD_COMPACT_ARTICLE = `      '<article class="order-tracking-card order-tracking-card--compact' + orderTrackingCardModifierClass(entry, customer, overdue) + '">',`;

const NEW_COMPACT_ARTICLE = `      '<article class="order-tracking-card order-tracking-card--compact' + orderTrackingCardModifierClass(entry, customer, overdue) + '"' + orderTrackingCardMetaAttrs(entry, recordId, items) + '>',`;

const OLD_COMPACT_PRODUCTS = `'<div class="order-tracking-products"><button type="button" class="order-tracking-product-thumb" data-order-photo="' + escapeHtml(image) + '" aria-label="放大查看商品圖片"><img src="' + escapeHtml(image) + '" alt="商品縮圖" loading="lazy"></button><span class="order-tracking-product-copy"><b>' + qty + ' 件／' + items.length + ' 項商品</b><small>' + escapeHtml(itemSummary || '商品資料待補') + (items.length > 2 ? '；另 ' + (items.length - 2) + ' 項' : '') + '</small></span></div>',`;

const NEW_COMPACT_PRODUCTS = `'<div class="order-tracking-products"><button type="button" class="order-tracking-product-thumb" data-order-photo="' + escapeHtml(image) + '" aria-label="放大查看商品圖片"><img src="' + escapeHtml(image) + '" alt="商品縮圖" loading="lazy"></button><span class="order-tracking-product-copy"><b>本單購買 ' + qty + ' 件／' + items.length + ' 款</b><small class="order-tracking-purchase-lines">' + escapeHtml(itemSummary || '本單商品資料待補') + '</small></span></div>',`;

const OLD_CARD_SUMMARY = `    var firstItems = items.slice(0, 3).map(function (item) {
      return [item.code || item.sku || item.skuId || '', item.title || item.name || '', item.color || '', item.size || '', '×' + Number(item.qty || item.quantity || 0)].filter(Boolean).join(' ');
    }).join('；');`;

const NEW_CARD_SUMMARY = `    var firstItems = orderTrackingPurchaseSummary(items, 3);`;

const OLD_CARD_ARTICLE = `      '<article class="order-tracking-card' + orderTrackingCardModifierClass(entry, customer, overdue) + '">',`;

const NEW_CARD_ARTICLE = `      '<article class="order-tracking-card' + orderTrackingCardModifierClass(entry, customer, overdue) + '"' + orderTrackingCardMetaAttrs(entry, recordId, items) + '>',`;

const OLD_CARD_PRODUCTS = `'<div class="order-tracking-products"><button type="button" class="order-tracking-product-thumb" data-order-photo="' + escapeHtml(firstItemImage) + '" aria-label="放大查看 ' + escapeHtml(firstItemLabel) + ' 商品圖片"><img src="' + escapeHtml(firstItemImage) + '" alt="' + escapeHtml(firstItemLabel) + ' 商品縮圖" loading="lazy"></button><span class="order-tracking-product-copy"><b>' + qty + ' 件／' + items.length + ' 項商品</b><small>' + escapeHtml(firstItems || '商品資料待補') + (items.length > 3 ? '；另 ' + (items.length - 3) + ' 項' : '') + '</small></span></div>',`;

const NEW_CARD_PRODUCTS = `'<div class="order-tracking-products"><button type="button" class="order-tracking-product-thumb" data-order-photo="' + escapeHtml(firstItemImage) + '" aria-label="放大查看 ' + escapeHtml(firstItemLabel) + ' 商品圖片"><img src="' + escapeHtml(firstItemImage) + '" alt="' + escapeHtml(firstItemLabel) + ' 商品縮圖" loading="lazy"></button><span class="order-tracking-product-copy"><b>本單購買 ' + qty + ' 件／' + items.length + ' 款</b><small class="order-tracking-purchase-lines">' + escapeHtml(firstItems || '本單商品資料待補') + '</small></span></div>',`;

const OLD_MINI = `      return '<section><button type="button" data-order-photo="' + escapeHtml(image) + '"><img src="' + escapeHtml(image) + '" alt="產品顏色圖片"></button><div><small class="order-mini-label">產品明細</small><b>' + escapeHtml(item.title || item.code || '-') + '</b><span>' + escapeHtml([item.code, item.sku || item.skuId, item.color, item.size].filter(Boolean).join(' / ')) + '</span>' + freightCost + '<div class="order-mini-price"><em>數量 ' + qty + '</em><em>單價 ' + orderMoney(price) + '</em><strong>小計 ' + orderMoney(price * qty) + '</strong></div></div></section>';`;

const NEW_MINI = `      return '<section><button type="button" data-order-photo="' + escapeHtml(image) + '"><img src="' + escapeHtml(image) + '" alt="產品顏色圖片"></button><div><small class="order-mini-label">本單購買</small><b>' + escapeHtml(item.title || item.name || item.code || '-') + '</b><span>' + escapeHtml(['款號 ' + (item.code || item.productCode || '-'), '顏色 ' + (item.color || item.selectedColor || '未填'), '尺碼 ' + orderTrackingPurchaseSizeLabel(item.size), '買 ' + qty + ' 件'].join(' ／ ')) + '</span>' + freightCost + '<div class="order-mini-price"><em>數量 ' + qty + '</em><em>單價 ' + orderMoney(price) + '</em><strong>小計 ' + orderMoney(price * qty) + '</strong></div></div></section>';`;

function patchAdmin(src) {
  if (src.indexOf('function orderTrackingPurchaseLineText(') === -1) {
    src = replaceOnce(src, '  function orderTrackingItems(entry) {', PURCHASE_HELPERS + '  function orderTrackingItems(entry) {', 'purchase helpers');
  } else {
    console.log('already: purchase helpers');
  }
  src = replaceOnce(src, OLD_COMPACT_SUMMARY, NEW_COMPACT_SUMMARY, 'compact purchase summary');
  src = replaceOnce(src, OLD_COMPACT_ARTICLE, NEW_COMPACT_ARTICLE, 'compact card meta');
  src = replaceOnce(src, OLD_COMPACT_PRODUCTS, NEW_COMPACT_PRODUCTS, 'compact products copy');
  src = replaceOnce(src, OLD_CARD_SUMMARY, NEW_CARD_SUMMARY, 'expanded purchase summary');
  src = replaceOnce(src, OLD_CARD_ARTICLE, NEW_CARD_ARTICLE, 'expanded card meta');
  src = replaceOnce(src, OLD_CARD_PRODUCTS, NEW_CARD_PRODUCTS, 'expanded products copy');
  src = replaceOnce(src, OLD_MINI, NEW_MINI, 'order mini items 本單購買');
  return src;
}

const OLD_ACTIVITY_LABEL = `  function orderActivityLabel(row) {
    var todayCount = Math.max(0, Number(row && row.todayCount || 0));
    var count = Math.max(todayCount, Number(row && row.count || 0));
    var sources = row && Array.isArray(row.sources) && row.sources.length ? text('｜來源 ', '｜Sumber ') + row.sources.map(sourceLabel).join(text('、', ', ')) : '';
    if (todayCount > 0) {
      return text(
        '⚠ 本客戶今天已有 ' + todayCount + ' 張單據' + sources + '｜請確認是否重複打單或需要合併出貨',
        '⚠ Pelanggan ini sudah memiliki ' + todayCount + ' pesanan hari ini' + sources + '｜Pastikan bukan pesanan ganda dan periksa apakah pengiriman perlu digabung'
      );
    }
    var latest = row && row.latestDate ? text('｜最近單據 ', '｜Pesanan terakhir ') + row.latestDate : '';
    return text(
      '本客戶過去已有 ' + count + ' 張單據' + latest + sources + '｜請留意舊欠款、退貨與合併出貨',
      'Pelanggan ini memiliki ' + count + ' pesanan sebelumnya' + latest + sources + '｜Periksa piutang lama, retur, dan kemungkinan pengiriman gabungan'
    );
  }`;

const NEW_ACTIVITY_LABEL = `  function todayTaipeiKey() {
    try {
      return new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Taipei' });
    } catch (error) {
      return new Date().toISOString().slice(0, 10);
    }
  }

  function orderRecordClosed(order) {
    if (!order) return false;
    if (order.open === false || order.open === 0 || order.open === '0') return true;
    if (order.open === true || order.open === 1 || order.open === '1') return false;
    var status = String(order.status || order.stage || '').toLowerCase();
    return ['delivered', 'completed', 'closed', 'cancelled', 'canceled', 'deleted', 'void', 'returned', 'shipped', 'in_transit', 'picked', 'pickup'].indexOf(status) !== -1;
  }

  function orderActivityContextFromTarget(target) {
    if (!target) return null;
    var card = (target.closest && target.closest('.order-tracking-card, [data-order-card], [data-preorder-card], .order-card, .order-first-card')) || target;
    if (!card || !card.getAttribute) return null;
    var id = String(card.getAttribute('data-order-id') || '').trim();
    if (!id) {
      var sourceId = card.querySelector && card.querySelector('.order-tracking-card-source b, [data-order-id]');
      id = String((sourceId && (sourceId.getAttribute && sourceId.getAttribute('data-order-id') || sourceId.textContent)) || '').trim();
    }
    var stage = String(card.getAttribute('data-order-stage') || '').trim();
    var date = String(card.getAttribute('data-order-date') || '').trim();
    var itemKey = String(card.getAttribute('data-order-item-key') || '').trim();
    var closed = card.getAttribute('data-order-closed') === '1' || orderRecordClosed({ status: stage });
    if (!id && !date && !itemKey) return null;
    return { currentId: id, closed: closed, itemKey: itemKey, date: date, stage: stage };
  }

  function orderActivityView(row, ctx) {
    row = row || {};
    var orders = Array.isArray(row.orders) ? row.orders : [];
    var today = todayTaipeiKey();
    var others = orders.filter(function (order) {
      if (!ctx || !ctx.currentId) return true;
      return String(order.id || '') !== String(ctx.currentId);
    });
    var sources = Array.isArray(row.sources) ? row.sources : [];
    if (!orders.length) {
      var todayCount = Math.max(0, Number(row.todayCount || 0));
      var count = Math.max(0, Number(row.count || 0));
      if (ctx && ctx.currentId && count > 0) count -= 1;
      if (ctx && ctx.currentId && ctx.date === today && todayCount > 0) todayCount -= 1;
      return {
        todayCount: todayCount,
        todayOpenCount: ctx && ctx.closed ? 0 : todayCount,
        count: count,
        sources: sources,
        latestDate: row.latestDate || '',
        similar: false
      };
    }
    var otherToday = others.filter(function (order) { return String(order.date || '') === today; });
    var otherTodayOpen = otherToday.filter(function (order) { return !orderRecordClosed(order); });
    var similar = !!(ctx && ctx.itemKey && otherTodayOpen.some(function (order) {
      return String(order.itemKey || '') === ctx.itemKey;
    }));
    return {
      todayCount: otherToday.length,
      todayOpenCount: otherTodayOpen.length,
      count: others.length,
      sources: sources,
      latestDate: row.latestDate || '',
      similar: similar
    };
  }

  function orderActivityTone(view, ctx) {
    if (!view || view.count <= 0 && view.todayCount <= 0 && view.todayOpenCount <= 0) return '';
    if (ctx && ctx.closed) return 'info';
    if (view.todayOpenCount > 0 && view.similar) return 'duplicate';
    if (view.todayOpenCount > 0) return 'merge';
    return 'info';
  }

  function orderActivityLabel(row, ctx) {
    var view = orderActivityView(row, ctx);
    var count = Math.max(view.todayCount, Number(view.count || 0));
    var sources = view.sources && view.sources.length ? text('｜來源 ', '｜Sumber ') + view.sources.map(sourceLabel).join(text('、', ', ')) : '';
    var latest = view.latestDate ? text('｜最近單據 ', '｜Pesanan terakhir ') + view.latestDate : '';
    if (count <= 0 && view.todayOpenCount <= 0) return '';
    if (ctx && ctx.closed) {
      if (view.todayOpenCount > 0) {
        return text(
          '此客戶今天另有 ' + view.todayOpenCount + ' 張進行中單據' + sources + '｜與本單不是同一張；本單已出貨／已取件，不是重複打單',
          'Pelanggan ini punya ' + view.todayOpenCount + ' pesanan berjalan hari ini' + sources + '｜Bukan pesanan ini; yang ini sudah terkirim / diambil, bukan duplikat'
        );
      }
      return text(
        '本客戶另有 ' + count + ' 張單據' + latest + sources + '｜本單已完成，不是重複打單',
        'Pelanggan ini punya ' + count + ' pesanan lain' + latest + sources + '｜Pesanan ini sudah selesai, bukan duplikat'
      );
    }
    if (view.todayOpenCount > 0 && view.similar) {
      return text(
        '⚠ 此客戶今天另有進行中單據，商品相同' + sources + '｜請確認是否重複打單或需要合併出貨',
        '⚠ Pelanggan ini punya pesanan berjalan hari ini dengan barang sama' + sources + '｜Cek apakah duplikat atau perlu digabung'
      );
    }
    if (view.todayOpenCount > 0) {
      return text(
        '此客戶今天另有 ' + view.todayOpenCount + ' 張進行中單據（商品不同）' + sources + '｜可考慮合併出貨；不是同一張單重複打單',
        'Pelanggan ini punya ' + view.todayOpenCount + ' pesanan berjalan hari ini (barang berbeda)' + sources + '｜Bisa gabung kirim; bukan duplikat'
      );
    }
    if (!ctx && view.todayCount > 0) {
      return text(
        '⚠ 本客戶今天已有 ' + view.todayCount + ' 張單據' + sources + '｜請確認是否重複打單或需要合併出貨',
        '⚠ Pelanggan ini sudah memiliki ' + view.todayCount + ' pesanan hari ini' + sources + '｜Pastikan bukan pesanan ganda dan periksa apakah pengiriman perlu digabung'
      );
    }
    return text(
      '本客戶過去已有 ' + count + ' 張單據' + latest + sources + '｜已出貨／已取件的舊單不是今天重複打單',
      'Pelanggan ini punya ' + count + ' pesanan sebelumnya' + latest + sources + '｜Pesanan lama yang sudah terkirim / diambil bukan duplikat hari ini'
    );
  }`;

const OLD_SET_BADGE = `  function setRiskBadge(target, risk, returned, orderActivity) {
    var host = target.querySelector('.order-tracking-customer, .order-first-customer') || target;
    var badge = host.querySelector('[data-member-risk-badge]');
    if (!risk && !returned && !orderActivity) {
      target.classList.remove('member-risk-warning');
      target.classList.remove('member-order-activity');
      target.removeAttribute('data-risk-reason');
      target.removeAttribute('data-return-count');
      target.removeAttribute('data-order-count');
      target.removeAttribute('data-order-today-count');
      if (badge) badge.remove();
      return;
    }
    target.classList.toggle('member-risk-warning', Boolean(risk || returned));
    target.classList.toggle('member-order-activity', Boolean(orderActivity && !risk && !returned));
    if (risk) target.setAttribute('data-risk-reason', risk.reason || text('黑名單', 'Daftar hitam'));
    else target.removeAttribute('data-risk-reason');
    if (returned) target.setAttribute('data-return-count', String(Math.max(1, Number(returned.count || 1))));
    else target.removeAttribute('data-return-count');
    if (orderActivity) {
      target.setAttribute('data-order-count', String(Math.max(0, Number(orderActivity.count || 0))));
      target.setAttribute('data-order-today-count', String(Math.max(0, Number(orderActivity.todayCount || 0))));
    } else {
      target.removeAttribute('data-order-count');
      target.removeAttribute('data-order-today-count');
    }
    var labels = [];
    if (returned) labels.push(returnLabel(returned));
    if (risk) labels.push(riskLabel(risk));
    if (orderActivity) labels.push(orderActivityLabel(orderActivity));
    var label = labels.join('\\n');
    target.title = label;
    if (!badge) {
      badge = document.createElement('span');
      badge.setAttribute('data-member-risk-badge', '');
      host.appendChild(badge);
    }
    badge.className = 'member-risk-badge' + (risk || returned ? '' : ' member-order-activity-badge');
    if (badge.textContent !== label) badge.textContent = label;
  }`;

const NEW_SET_BADGE = `  function setRiskBadge(target, risk, returned, orderActivity, ctx) {
    var host = target.querySelector('.order-tracking-customer, .order-first-customer') || target;
    var badge = host.querySelector('[data-member-risk-badge]');
    ctx = ctx || orderActivityContextFromTarget(target);
    var view = orderActivity ? orderActivityView(orderActivity, ctx) : null;
    var activityLabel = orderActivity ? orderActivityLabel(orderActivity, ctx) : '';
    var tone = activityLabel ? orderActivityTone(view, ctx) : '';
    if (!risk && !returned && !activityLabel) {
      target.classList.remove('member-risk-warning');
      target.classList.remove('member-order-activity');
      target.classList.remove('member-order-activity-info');
      target.removeAttribute('data-risk-reason');
      target.removeAttribute('data-return-count');
      target.removeAttribute('data-order-count');
      target.removeAttribute('data-order-today-count');
      if (badge) badge.remove();
      return;
    }
    target.classList.toggle('member-risk-warning', Boolean(risk || returned));
    target.classList.toggle('member-order-activity', Boolean((tone === 'duplicate' || tone === 'merge') && !risk && !returned));
    target.classList.toggle('member-order-activity-info', Boolean(tone === 'info' && !risk && !returned));
    if (risk) target.setAttribute('data-risk-reason', risk.reason || text('黑名單', 'Daftar hitam'));
    else target.removeAttribute('data-risk-reason');
    if (returned) target.setAttribute('data-return-count', String(Math.max(1, Number(returned.count || 1))));
    else target.removeAttribute('data-return-count');
    if (orderActivity) {
      target.setAttribute('data-order-count', String(Math.max(0, Number((view && view.count) || orderActivity.count || 0))));
      target.setAttribute('data-order-today-count', String(Math.max(0, Number((view && view.todayOpenCount) || 0))));
    } else {
      target.removeAttribute('data-order-count');
      target.removeAttribute('data-order-today-count');
    }
    var labels = [];
    if (returned) labels.push(returnLabel(returned));
    if (risk) labels.push(riskLabel(risk));
    if (activityLabel) labels.push(activityLabel);
    var label = labels.join('\\n');
    target.title = label;
    if (!badge) {
      badge = document.createElement('span');
      badge.setAttribute('data-member-risk-badge', '');
      host.appendChild(badge);
    }
    var badgeClass = 'member-risk-badge';
    if (!risk && !returned) {
      badgeClass += ' member-order-activity-badge';
      if (tone === 'info') badgeClass += ' is-info';
      else if (tone === 'merge') badgeClass += ' is-merge';
      else if (tone === 'duplicate') badgeClass += ' is-duplicate';
    }
    badge.className = badgeClass;
    if (badge.textContent !== label) badge.textContent = label;
  }`;

const OLD_MARK = `      setRiskBadge(target, riskFromText(target.textContent), returnFromText(target.textContent), orderActivityFromText(target.textContent));`;

const NEW_MARK = `      setRiskBadge(target, riskFromText(target.textContent), returnFromText(target.textContent), orderActivityFromText(target.textContent), orderActivityContextFromTarget(target));`;

function patchRiskJs(src) {
  src = replaceOnce(src, OLD_ACTIVITY_LABEL, NEW_ACTIVITY_LABEL, 'orderActivityLabel + context');
  src = replaceOnce(src, OLD_SET_BADGE, NEW_SET_BADGE, 'setRiskBadge tone');
  src = replaceOnce(src, OLD_MARK, NEW_MARK, 'markPage pass card context');
  return src;
}

const CSS_BLOCK = `
.member-risk-badge.member-order-activity-badge.is-info {
  background: #4a5560;
  color: #eef3f7 !important;
  font-weight: 800;
}
.member-risk-badge.member-order-activity-badge.is-merge {
  background: #d7b56a;
  color: #21170a !important;
}
.member-risk-badge.member-order-activity-badge.is-duplicate {
  background: #e5b85c;
  color: #21170a !important;
}
.member-order-activity-info {
  box-shadow: none;
}
.order-tracking-product-copy .order-tracking-purchase-lines,
.order-mini-items .order-mini-label {
  display: block;
}
.order-tracking-product-copy b {
  color: #f0c65e;
}
.order-tracking-product-copy .order-tracking-purchase-lines {
  white-space: pre-line;
  line-height: 1.45;
  margin-top: 4px;
  color: #f3efe6;
}
`;

function patchCss(src, extra) {
  if (src.indexOf('order-tracking-purchase-lines') !== -1 || src.indexOf('is-duplicate') !== -1) {
    console.log('already css extra');
    return src;
  }
  console.log('patched css extra');
  return src.replace(/\s*$/, '\n') + extra;
}

const OLD_PHP_OPEN = `function order_history(string $baseDir): array {`;

const NEW_PHP_HELPERS = `function order_item_key(array $row): string {
    $items = is_array($row['items'] ?? null) ? $row['items'] : [];
    $parts = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $code = strtoupper(trim((string)($item['code'] ?? $item['productCode'] ?? '')));
        $color = mb_strtolower(preg_replace('/\\s+/u', '', trim((string)($item['color'] ?? $item['selectedColor'] ?? ''))), 'UTF-8');
        $size = strtoupper(trim((string)($item['size'] ?? 'NO SIZE')));
        if ($size === '') $size = 'NO SIZE';
        if (preg_match('/^NO\\s*SIZE$/', $size)) $size = 'NO SIZE';
        $parts[] = $code . '|' . $color . '|' . $size;
    }
    $parts = array_values(array_filter($parts, static function ($part) {
        return $part !== '' && $part !== '||NO SIZE' && $part !== '||';
    }));
    sort($parts);
    return implode('+', $parts);
}
function order_is_open(array $row): bool {
    $status = mb_strtolower(trim((string)($row['status'] ?? '')), 'UTF-8');
    $delivery = mb_strtolower(trim((string)($row['deliveryState'] ?? '')), 'UTF-8');
    $closed = ['delivered', 'completed', 'closed', 'cancelled', 'canceled', 'deleted', 'void', 'returned', 'shipped', 'in_transit', 'picked', 'pickup'];
    if (in_array($status, $closed, true) || in_array($delivery, $closed, true)) return false;
    $blob = $status . ' ' . $delivery . ' ' . mb_strtolower(trim((string)($row['statusLabel'] ?? '')), 'UTF-8');
    if (preg_match('/取件完成|已完成配送|已取件|已投遞|配送完成|已出貨|配送中|退貨|取消|作廢/u', $blob)) return false;
    return true;
}
function order_history(string $baseDir): array {`;

const OLD_PHP_SUMMARY_INIT = `            $summary[$key] = ['phone' => $phone, 'name' => $name, 'count' => 0, 'todayCount' => 0, 'latestDate' => '', 'sources' => []];`;

const NEW_PHP_SUMMARY_INIT = `            $summary[$key] = ['phone' => $phone, 'name' => $name, 'count' => 0, 'todayCount' => 0, 'todayOpenCount' => 0, 'latestDate' => '', 'sources' => [], 'orders' => []];`;

const OLD_PHP_INC = `        $summary[$key]['count']++;
        if ($date === $today) $summary[$key]['todayCount']++;
        if ($date > $summary[$key]['latestDate']) $summary[$key]['latestDate'] = $date;
        $sourceLabel = order_source_label($row, $sourceName);
        if (!in_array($sourceLabel, $summary[$key]['sources'], true)) $summary[$key]['sources'][] = $sourceLabel;
        if ($summary[$key]['name'] === '' && $name !== '') $summary[$key]['name'] = $name;
        if ($summary[$key]['phone'] === '' && $phone !== '') $summary[$key]['phone'] = $phone;
    }
    return array_values($summary);
}`;

const NEW_PHP_INC = `        $summary[$key]['count']++;
        $sourceLabel = order_source_label($row, $sourceName);
        $open = order_is_open($row);
        if ($date === $today) {
            $summary[$key]['todayCount']++;
            if ($open) $summary[$key]['todayOpenCount']++;
        }
        if ($date > $summary[$key]['latestDate']) $summary[$key]['latestDate'] = $date;
        if (!in_array($sourceLabel, $summary[$key]['sources'], true)) $summary[$key]['sources'][] = $sourceLabel;
        if ($summary[$key]['name'] === '' && $name !== '') $summary[$key]['name'] = $name;
        if ($summary[$key]['phone'] === '' && $phone !== '') $summary[$key]['phone'] = $phone;
        $summary[$key]['orders'][] = [
            'id' => $recordId,
            'date' => $date,
            'status' => $status,
            'source' => $sourceLabel,
            'itemKey' => order_item_key($row),
            'open' => $open,
        ];
    }
    foreach ($summary as &$historyRow) {
        usort($historyRow['orders'], static function ($left, $right) {
            return strcmp((string)($right['date'] ?? ''), (string)($left['date'] ?? ''));
        });
        $historyRow['orders'] = array_slice($historyRow['orders'], 0, 40);
    }
    unset($historyRow);
    return array_values($summary);
}`;

function patchPhp(src) {
  src = replaceOnce(src, OLD_PHP_OPEN, NEW_PHP_HELPERS, 'php order_item_key / order_is_open');
  src = replaceOnce(src, OLD_PHP_SUMMARY_INIT, NEW_PHP_SUMMARY_INIT, 'php summary init orders');
  src = replaceOnce(src, OLD_PHP_INC, NEW_PHP_INC, 'php per-order history');
  return src;
}

function bustHtml() {
  const names = fs.readdirSync(root).filter(function (name) {
    return /\.(html|php)$/i.test(name) && !/_backups|codex-backup|backup|拷貝/i.test(name);
  });
  let n = 0;
  names.forEach(function (name) {
    const file = path.join(root, name);
    let html = fs.readFileSync(file, 'utf8');
    const orig = html;
    html = html.replace(/assets\/admin\.js\?v=[^"'>\s]+/g, 'assets/admin.js?v=' + BUST);
    html = html.replace(/assets\/member-risk-v2\.js\?v=[^"'>\s]+/g, 'assets/member-risk-v2.js?v=' + BUST);
    html = html.replace(/assets\/member-risk-v2\.css\?v=[^"'>\s]+/g, 'assets/member-risk-v2.css?v=' + BUST);
    html = html.replace(/assets\/admin-order-tracking\.css\?v=[^"'>\s]+/g, 'assets/admin-order-tracking.css?v=' + BUST);
    if (html !== orig) {
      fs.writeFileSync(file, html);
      n++;
      console.log('cache-bust', name);
    }
  });
  const nav = path.join(root, 'assets', 'admin-navigation.js');
  if (fs.existsSync(nav)) {
    let src = fs.readFileSync(nav, 'utf8');
    const orig = src;
    src = src.replace(/member-risk-v2\.js\?v=[^"'>\s]+/g, 'member-risk-v2.js?v=' + BUST);
    src = src.replace(/member-risk-v2\.css\?v=[^"'>\s]+/g, 'member-risk-v2.css?v=' + BUST);
    src = src.replace(/admin\.js\?v=[^"'>\s]+/g, 'admin.js?v=' + BUST);
    if (src !== orig) {
      fs.writeFileSync(nav, src);
      console.log('cache-bust admin-navigation.js');
    }
  }
  console.log('html files busted', n);
}

if (!fs.existsSync(adminJs)) {
  console.log('Not on PHT-SR; live files are under F:\\\\Web\\\\lingzanzan-staging');
  process.exit(0);
}

console.log('backup admin', backup(adminJs, 'purchase-dup'));
console.log('backup risk js', backup(riskJs, 'purchase-dup'));
console.log('backup risk css', backup(riskCss, 'purchase-dup'));
console.log('backup track css', backup(trackCss, 'purchase-dup'));
console.log('backup risk api', backup(riskApi, 'purchase-dup'));

fs.writeFileSync(adminJs, patchAdmin(fs.readFileSync(adminJs, 'utf8')));
fs.writeFileSync(riskJs, patchRiskJs(fs.readFileSync(riskJs, 'utf8')));
fs.writeFileSync(riskCss, patchCss(fs.readFileSync(riskCss, 'utf8'), CSS_BLOCK.replace(/^\n/, '')));
fs.writeFileSync(trackCss, patchCss(fs.readFileSync(trackCss, 'utf8'), CSS_BLOCK.replace(/^\n/, '')));
fs.writeFileSync(riskApi, patchPhp(fs.readFileSync(riskApi, 'utf8')));
bustHtml();
console.log('done', BUST);

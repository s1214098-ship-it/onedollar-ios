'use strict';

/**
 * Add 其他 to FIFO/customer return reason, with a required fill-in
 * (e.g. 多打單故取消) so managers can evaluate new reasons later.
 *
 * Cache-bust: admin.js?v=20260819-return-other-1
 *             admin-returns.js?v=20260819-return-other-1
 */

const fs = require('fs');
const path = require('path');

const root = process.env.LINGZANZAN_ROOT || 'F:/Web/lingzanzan-staging';
const BUST = '20260819-return-other-1';
const PREV_ADMIN = '20260819-nita-no-tracking-1';
const PREV_RETURNS = '20260819-accountability-1';

function backup(file, tag) {
  const dir = path.join(root, 'data', 'audit');
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
  const dest = path.join(dir, path.basename(file) + '.' + tag + '-' + new Date().toISOString().replace(/[:.]/g, '-'));
  fs.copyFileSync(file, dest);
  return dest;
}

function replaceOnce(src, oldStr, newStr, label) {
  if (src.indexOf(newStr) !== -1) {
    console.log('already:', label);
    return src;
  }
  const i = src.indexOf(oldStr);
  if (i < 0) throw new Error('missing snippet: ' + label);
  if (src.indexOf(oldStr, i + oldStr.length) !== -1) throw new Error('not unique: ' + label);
  console.log('patched:', label);
  return src.slice(0, i) + newStr + src.slice(i + oldStr.length);
}

const adminJs = path.join(root, 'assets', 'admin.js');
const returnsJs = path.join(root, 'assets', 'admin-returns.js');
const returnsHtml = path.join(root, 'admin-customer-returns.html');
const lossPhp = path.join(root, 'shipping-loss.php');
const apiPhp = path.join(root, 'order-admin-api-v6.php');

if (!fs.existsSync(adminJs)) {
  console.log('Not on PHT-SR');
  process.exit(0);
}

const OLD_OPTIONS = `      { value: 'staff_goods_error', label: '7. 小姐出錯貨物（列入評分）', score: true, party: 'staff' }
    ];`;

const NEW_OPTIONS = `      { value: 'staff_goods_error', label: '7. 小姐出錯貨物（列入評分）', score: true, party: 'staff' },
      { value: 'other', label: '8. 其他（請填入內容）', score: false, party: 'customer' }
    ];`;

const OLD_MODAL = `        + '<label class="freight-pending-priority-reason"><span>退貨原因（出貨小姐必選）</span><select data-return-order-reason><option value="">請選擇原因</option>' + reasonOptions + '</select></label>'
        + '<p class="freight-fifo-return-score-hint">原因會自動對到責任：客戶不收、業務出錯、小姐出錯貨物。出錯地址／業務出錯／多出東西／小姐出錯貨物會列入評分。財務系統可再改歸屬並統計報損運費。</p>'`;

const NEW_MODAL = `        + '<label class="freight-pending-priority-reason"><span>退貨原因（出貨小姐必選）</span><select data-return-order-reason><option value="">請選擇原因</option>' + reasonOptions + '</select></label>'
        + '<label class="freight-pending-priority-reason" data-return-order-reason-other-wrap hidden><span>其他原因（必填）</span><input data-return-order-reason-other maxlength="80" placeholder="例如：多打單故取消"></label>'
        + '<p class="freight-fifo-return-score-hint">原因會自動對到責任：客戶不收、業務出錯、小姐出錯貨物。出錯地址／業務出錯／多出東西／小姐出錯貨物會列入評分。選「其他」請填入內容，先不列入評分，管理層再評估（例如多打單故取消）。財務系統可再改歸屬並統計報損運費。</p>'`;

const OLD_CONFIRM = `      checkbox.addEventListener('change', function () { confirmButton.disabled = !checkbox.checked; });
      confirmButton.addEventListener('click', function () {
        if (!checkbox.checked || confirmButton.disabled) return;
        var reasonCode = String((modal.querySelector('[data-return-order-reason]') || {}).value || '');
        var note = String((modal.querySelector('[data-return-order-note]') || {}).value || '').trim();
        if (!reasonCode) { status.textContent = '請先選擇退貨原因'; return; }
        if (!note) { status.textContent = '請填寫出貨備註，管理層會用來跟業務核對'; return; }
        confirmButton.disabled = true;
        status.textContent = '正在退貨、回庫並登記運費虧損…';
        var mutationId = 'return-order-' + orderId + '-' + Date.now() + '-' + Math.random().toString(36).slice(2, 9);
        callOrderAdmin({
          action: 'return-order',
          orderId: orderId,
          reasonCode: reasonCode,
          note: note,`;

const NEW_CONFIRM = `      var reasonSelect = modal.querySelector('[data-return-order-reason]');
      var otherWrap = modal.querySelector('[data-return-order-reason-other-wrap]');
      var otherInput = modal.querySelector('[data-return-order-reason-other]');
      var syncOther = function () {
        var isOther = String((reasonSelect && reasonSelect.value) || '') === 'other';
        if (otherWrap) otherWrap.hidden = !isOther;
        if (!isOther && otherInput) otherInput.value = '';
        status.textContent = isOther ? '請填其他原因、備註，並勾選確認' : '請選原因、填備註，並勾選確認';
      };
      if (reasonSelect) reasonSelect.addEventListener('change', syncOther);
      syncOther();
      checkbox.addEventListener('change', function () { confirmButton.disabled = !checkbox.checked; });
      confirmButton.addEventListener('click', function () {
        if (!checkbox.checked || confirmButton.disabled) return;
        var reasonCode = String((modal.querySelector('[data-return-order-reason]') || {}).value || '');
        var reasonDetail = String((otherInput && otherInput.value) || '').trim();
        var note = String((modal.querySelector('[data-return-order-note]') || {}).value || '').trim();
        if (!reasonCode) { status.textContent = '請先選擇退貨原因'; return; }
        if (reasonCode === 'other' && !reasonDetail) { status.textContent = '請填入其他原因，例如多打單故取消'; if (otherInput) otherInput.focus(); return; }
        if (!note) { status.textContent = '請填寫出貨備註，管理層會用來跟業務核對'; return; }
        confirmButton.disabled = true;
        status.textContent = '正在退貨、回庫並登記運費虧損…';
        var mutationId = 'return-order-' + orderId + '-' + Date.now() + '-' + Math.random().toString(36).slice(2, 9);
        callOrderAdmin({
          action: 'return-order',
          orderId: orderId,
          reasonCode: reasonCode,
          reasonDetail: reasonDetail,
          note: note,`;

const OLD_MAP = `        'staff_goods_error' => ['label' => '小姐出錯貨物', 'party' => 'staff', 'score' => true],
    ];`;

const NEW_MAP = `        'staff_goods_error' => ['label' => '小姐出錯貨物', 'party' => 'staff', 'score' => true],
        'other' => ['label' => '其他', 'party' => 'customer', 'score' => false],
    ];`;

const OLD_API = `    $reasonCode = trim((string)($payload['reasonCode'] ?? ''));
    $reasons = return_order_reason_map();
    if (!isset($reasons[$reasonCode])) respond(['ok' => false, 'error' => '請選擇退貨原因：客人取消、客人故意不領、家裡沒人、出錯地址、業務出錯、多出東西、小姐出錯貨物'], 400);
    $note = trim((string)($payload['note'] ?? ''));
    if ($note === '') respond(['ok' => false, 'error' => '請填寫出貨備註，管理層會用來跟業務核對'], 400);
    if (mb_strlen($note, 'UTF-8') > 500) respond(['ok' => false, 'error' => '退貨備註過長'], 400);
    $operator = trim((string)($payload['operator'] ?? '')) ?: '管理者';
    $now = date(DATE_ATOM);
    $reasonMeta = $reasons[$reasonCode];`;

const NEW_API = `    $reasonCode = trim((string)($payload['reasonCode'] ?? ''));
    $reasons = return_order_reason_map();
    if (!isset($reasons[$reasonCode])) respond(['ok' => false, 'error' => '請選擇退貨原因：客人取消、客人故意不領、家裡沒人、出錯地址、業務出錯、多出東西、小姐出錯貨物、其他'], 400);
    $reasonDetail = trim((string)($payload['reasonDetail'] ?? ''));
    if (mb_strlen($reasonDetail, 'UTF-8') > 80) respond(['ok' => false, 'error' => '其他原因過長'], 400);
    if ($reasonCode === 'other' && $reasonDetail === '') respond(['ok' => false, 'error' => '請填入其他原因，例如多打單故取消'], 400);
    $note = trim((string)($payload['note'] ?? ''));
    if ($note === '') respond(['ok' => false, 'error' => '請填寫出貨備註，管理層會用來跟業務核對'], 400);
    if (mb_strlen($note, 'UTF-8') > 500) respond(['ok' => false, 'error' => '退貨備註過長'], 400);
    $operator = trim((string)($payload['operator'] ?? '')) ?: '管理者';
    $now = date(DATE_ATOM);
    $reasonMeta = $reasons[$reasonCode];
    $reasonLabel = (string)$reasonMeta['label'];
    if ($reasonDetail !== '') {
        $reasonLabel = $reasonCode === 'other' ? ('其他：' . $reasonDetail) : ($reasonLabel . '／' . $reasonDetail);
    }`;

const OLD_REVIEW_LABEL = `        'reasonCode' => $reasonCode,
        'reasonLabel' => (string)$reasonMeta['label'],
        'party' => (string)($reasonMeta['party'] ?? shipping_loss_party_from_reason($reasonCode)),
        'countsTowardScore' => !empty($reasonMeta['score']),
        'staffNote' => $note,`;

const NEW_REVIEW_LABEL = `        'reasonCode' => $reasonCode,
        'reasonLabel' => $reasonLabel,
        'reasonDetail' => $reasonDetail,
        'party' => (string)($reasonMeta['party'] ?? shipping_loss_party_from_reason($reasonCode)),
        'countsTowardScore' => !empty($reasonMeta['score']),
        'staffNote' => $note,`;

const OLD_AUDIT_LABEL = `        'reasonCode' => $reasonCode,
        'reasonLabel' => (string)$reasonMeta['label'],
        'countsTowardScore' => !empty($reasonMeta['score']),
        'note' => $note,`;

const NEW_AUDIT_LABEL = `        'reasonCode' => $reasonCode,
        'reasonLabel' => $reasonLabel,
        'reasonDetail' => $reasonDetail,
        'countsTowardScore' => !empty($reasonMeta['score']),
        'note' => $note,`;

const OLD_HTML = `                  <option value="staff_goods_error">7. 小姐出錯貨物（列入評分）</option>
                </select>
                <small>原因會對到責任：客戶不收、業務出錯、小姐出錯貨物。財務系統可再改歸屬並統計報損運費。</small>
              </label>
              <label class="span-2">補充說明 / Alasan<input data-return-reason placeholder="尺寸不合、瑕疵、拒收、寄錯；出貨小姐請寫清楚給管理層核對"></label>`;

const NEW_HTML = `                  <option value="staff_goods_error">7. 小姐出錯貨物（列入評分）</option>
                  <option value="other">8. 其他（請填入內容）</option>
                </select>
                <small>原因會對到責任：客戶不收、業務出錯、小姐出錯貨物。選「其他」請填入內容，先不列入評分，管理層再評估。</small>
              </label>
              <label class="span-2" data-return-reason-other-wrap hidden>其他原因（必填）<input data-return-reason-other maxlength="80" placeholder="例如：多打單故取消"></label>
              <label class="span-2">補充說明 / Alasan<input data-return-reason placeholder="尺寸不合、瑕疵、拒收、寄錯；出貨小姐請寫清楚給管理層核對"></label>`;

const OLD_RETURNS_INIT = `  var root = document.querySelector('[data-return-page]');
  if (!root) return;

  var type = document.body.getAttribute('data-return-type') === 'vendor' ? 'vendor' : 'customer';`;

const NEW_RETURNS_INIT = `  var root = document.querySelector('[data-return-page]');
  if (!root) return;

  function syncCustomerReturnReasonOther() {
    var select = root.querySelector('[data-return-reason-code]');
    var wrap = root.querySelector('[data-return-reason-other-wrap]');
    if (!wrap) return;
    var isOther = !!(select && String(select.value || '') === 'other');
    wrap.hidden = !isOther;
    if (!isOther) {
      var input = wrap.querySelector('[data-return-reason-other]');
      if (input) input.value = '';
    }
  }
  var customerReasonSelect = root.querySelector('[data-return-reason-code]');
  if (customerReasonSelect) {
    customerReasonSelect.addEventListener('change', syncCustomerReturnReasonOther);
    syncCustomerReturnReasonOther();
  }

  var type = document.body.getAttribute('data-return-type') === 'vendor' ? 'vendor' : 'customer';`;

const OLD_RETURNS_PAYLOAD = `      reasonCode: type === 'customer' ? String((one('[data-return-reason-code]') || {}).value || '') : '',
      reasonLabel: type === 'customer' ? String(((one('[data-return-reason-code]') || {}).selectedOptions || [])[0] && ((one('[data-return-reason-code]') || {}).selectedOptions || [])[0].textContent || '') : '',`;

const NEW_RETURNS_PAYLOAD = `      reasonCode: type === 'customer' ? String((one('[data-return-reason-code]') || {}).value || '') : '',
      reasonDetail: type === 'customer' ? String((one('[data-return-reason-other]') || {}).value || '').trim() : '',
      reasonLabel: (function () {
        if (type !== 'customer') return '';
        var code = String((one('[data-return-reason-code]') || {}).value || '');
        var detail = String((one('[data-return-reason-other]') || {}).value || '').trim();
        if (code === 'other') return detail ? ('其他：' + detail) : '其他（請填入內容）';
        return String(((one('[data-return-reason-code]') || {}).selectedOptions || [])[0] && ((one('[data-return-reason-code]') || {}).selectedOptions || [])[0].textContent || '');
      })(),`;

const OLD_NORMALIZE = `        $row['reasonCode'] ?? '',
        $row['reasonLabel'] ?? '',
        $row['party'] ?? '',`;

const NEW_NORMALIZE = `        $row['reasonCode'] ?? '',
        $row['reasonLabel'] ?? '',
        $row['reasonDetail'] ?? '',
        $row['party'] ?? '',`;

console.log('backup js', backup(adminJs, 'return-other'));
let src = fs.readFileSync(adminJs, 'utf8');
src = replaceOnce(src, OLD_OPTIONS, NEW_OPTIONS, 'FIFO reason options add 其他');
src = replaceOnce(src, OLD_MODAL, NEW_MODAL, 'FIFO modal other input');
src = replaceOnce(src, OLD_CONFIRM, NEW_CONFIRM, 'FIFO confirm validates other text');
fs.writeFileSync(adminJs, src);

console.log('backup php map', backup(lossPhp, 'return-other'));
let map = fs.readFileSync(lossPhp, 'utf8');
map = replaceOnce(map, OLD_MAP, NEW_MAP, 'shipping_loss_reason_map other');
map = replaceOnce(map, OLD_NORMALIZE, NEW_NORMALIZE, 'normalize search includes reasonDetail');
fs.writeFileSync(lossPhp, map);

console.log('backup api', backup(apiPhp, 'return-other'));
let api = fs.readFileSync(apiPhp, 'utf8');
api = replaceOnce(api, OLD_API, NEW_API, 'return-order accepts other detail');
api = replaceOnce(api, OLD_REVIEW_LABEL, NEW_REVIEW_LABEL, 'review stores other label');
api = replaceOnce(api, OLD_AUDIT_LABEL, NEW_AUDIT_LABEL, 'audit stores other label');
fs.writeFileSync(apiPhp, api);

if (fs.existsSync(returnsHtml)) {
  console.log('backup html', backup(returnsHtml, 'return-other'));
  let html = fs.readFileSync(returnsHtml, 'utf8');
  html = replaceOnce(html, OLD_HTML, NEW_HTML, 'customer-returns other option');
  fs.writeFileSync(returnsHtml, html);
}

if (fs.existsSync(returnsJs)) {
  console.log('backup returns js', backup(returnsJs, 'return-other'));
  let rjs = fs.readFileSync(returnsJs, 'utf8');
  rjs = replaceOnce(rjs, OLD_RETURNS_INIT, NEW_RETURNS_INIT, 'customer-returns toggle other field');
  rjs = replaceOnce(rjs, OLD_RETURNS_PAYLOAD, NEW_RETURNS_PAYLOAD, 'customer-returns save other label');
  fs.writeFileSync(returnsJs, rjs);
}

const names = fs.readdirSync(root).filter(function (name) {
  return /\.(html|php)$/i.test(name) && !/_backups|codex-backup/i.test(name);
});
let n = 0;
names.forEach(function (name) {
  const file = path.join(root, name);
  let html = fs.readFileSync(file, 'utf8');
  const orig = html;
  html = html.split('admin.js?v=' + PREV_ADMIN).join('admin.js?v=' + BUST);
  html = html.split('admin-returns.js?v=' + PREV_RETURNS).join('admin-returns.js?v=' + BUST);
  if (html !== orig) {
    fs.writeFileSync(file, html);
    n++;
    console.log('cache-bust', name);
  }
});
console.log('html files busted', n, BUST);

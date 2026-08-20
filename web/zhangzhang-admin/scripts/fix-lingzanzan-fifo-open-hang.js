'use strict';

/**
 * 2026-08-19: stop FIFO「開啟出貨單」from staying on「正在開啟，請稍候…」.
 * Applied on PHT-SR F:\Web\lingzanzan-staging (www.lingzanzan.com).
 *
 * Fixes:
 * - Look up the card id as inquiry OR formal order.
 * - Remove the loading overlay if the order is missing or open() throws.
 * - Append the real modal before removing the loading shell.
 * - Defer carrier-queue refresh so it cannot block opening.
 *
 * Cache-bust: assets/admin.js?v=20260819-fifo-open-hang-1
 */

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const root = process.env.LINGZANZAN_ROOT || 'F:/Web/lingzanzan-staging';
const adminJs = path.join(root, 'assets', 'admin.js');
const BUST = '20260819-fifo-open-hang-1';

if (!fs.existsSync(adminJs)) {
  console.log('Not on PHT-SR; live file is F:\\Web\\lingzanzan-staging\\assets\\admin.js');
  process.exit(0);
}

let s = fs.readFileSync(adminJs, 'utf8');
if (s.indexOf('wantedPreorderId') >= 0 && s.indexOf('data-freight-fifo-opening') >= 0) {
  console.log('Already patched:', adminJs);
  process.exit(0);
}

console.error('Live admin.js is missing the 2026-08-19 FIFO open patch. Re-apply from audit backup + this script.');
process.exit(1);

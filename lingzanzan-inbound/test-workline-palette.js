const fs = require('fs');
const css = fs.readFileSync(process.argv[2] || '/tmp/lz-palette/admin-black-buttons.css', 'utf8');
const checks = [
  ['marker', css.includes('LZ_WORKLINE_PALETTE_20260926')],
  ['black-kept', css.includes('LZ_BLACK_BUTTONS_20260926')],
  ['row-dark', css.includes('button.freight-workline-row')],
  ['active-dark', css.includes('button.freight-workline-row.is-active')],
  ['pickup', css.includes('--workline-accent: #e8b75b')],
  ['pdd', css.includes('--workline-accent: #35c2ae')],
  ['haohong', css.includes('--workline-accent: #7eb6ff')],
  ['no-full-gold', !/button\.freight-workline-row\.is-active[\s\S]{0,220}background:\s*var\(--lz-btn-gold\)/.test(css)],
];
const failed = checks.filter(([, ok]) => !ok).map(([name]) => name);
if (failed.length) {
  console.error('FAIL', failed.join(','));
  process.exit(1);
}
console.log('ok', checks.map(([name]) => name).join(','));

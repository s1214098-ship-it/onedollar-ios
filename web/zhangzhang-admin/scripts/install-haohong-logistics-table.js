'use strict';

/**
 * Standalone 豪鴻集運對照表: search batches / tracking numbers and compare
 * HaoHong's list with what is already imported into 領讚讚 freight.
 * Does not change the existing 批號帶入 flow on admin-freight.html.
 *
 * Cache-bust: admin-haohong-logistics.js/css ?v=20260819-haohong-table-1
 *             admin-navigation.js ?v=20260819-haohong-table-1
 */

const fs = require('fs');
const path = require('path');

const root = process.env.LINGZANZAN_ROOT || 'F:/Web/lingzanzan-staging';
const pages = process.env.HH_PAGES || path.join(__dirname, '..', 'lingzanzan-pages');
const BUST = '20260819-haohong-table-1';
const PREV_NAV = '20260817-staff-no-finance-1';

function backup(file, tag) {
  const dir = path.join(root, 'data', 'audit');
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
  const dest = path.join(dir, path.basename(file) + '.' + tag + '-' + new Date().toISOString().replace(/[:.]/g, '-'));
  if (fs.existsSync(file)) fs.copyFileSync(file, dest);
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

function copyPage(name, destName) {
  const src = path.join(pages, name);
  const dest = path.join(root, destName || name);
  if (!fs.existsSync(src)) throw new Error('missing page ' + src);
  const destDir = path.dirname(dest);
  if (!fs.existsSync(destDir)) fs.mkdirSync(destDir, { recursive: true });
  fs.copyFileSync(src, dest);
  console.log('copied', destName || name);
}

const SNAPSHOT_HELPERS = `
function hh_snapshot_file(): string {
    return __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'haohong-logistics-snapshot.json';
}
function hh_save_catalog_snapshot(array $payload): void {
    $file = hh_snapshot_file();
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) return;
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) return;
    $tmp = $file . '.tmp-' . bin2hex(random_bytes(3));
    if (file_put_contents($tmp, $json, LOCK_EX) === false) return;
    if (!@rename($tmp, $file)) {
        @copy($tmp, $file);
        @unlink($tmp);
    }
}

`;

const CATALOG_BLOCK = `            'credentialsStored' => $credentialsStored,
        ]);
    }
    if ($action === 'catalog') {
        $listed = hh_fetch_packages($site, $login, $memberId);
        $packages = array_values($listed['byTracking']);
        usort($packages, static function (array $left, array $right): int {
            $dateCompare = strcmp((string)($right['receivedAt'] ?? ''), (string)($left['receivedAt'] ?? ''));
            return $dateCompare !== 0 ? $dateCompare : strcmp((string)($left['trackingNo'] ?? ''), (string)($right['trackingNo'] ?? ''));
        });
        $statusQueries = [];
        foreach ([0, 1] as $isConfirm) {
            foreach ([0, 1] as $isPayed) {
                foreach ([0, 1] as $isQs) {
                    foreach ([0, 1] as $isComment) {
                        $statusQueries[] = [
                            'IsConfirm' => $isConfirm,
                            'IsPayed' => $isPayed,
                            'IsQs' => $isQs,
                            'IsComment' => $isComment,
                        ];
                    }
                }
            }
        }
        $summaries = [];
        foreach ($statusQueries as $query) {
            $payload = array_merge(['MemberID' => $memberId, 'limit' => 80, 'offset' => 1], $query);
            foreach (hh_authenticated_post('OrderRecordPage', $payload, $site, $login) as $order) {
                if (!is_array($order)) continue;
                $id = hh_order_id($order);
                if ($id !== '') $summaries[$id] = $order;
            }
        }
        $orders = [];
        foreach ($summaries as $id => $summary) {
            $detail = hh_authenticated_post('QueryOrderDetail', ['MemberID' => $memberId, 'ID' => $id], $site, $login);
            $normalized = hh_normalize_order($detail, $summary);
            if ($normalized['rows']) $orders[] = $normalized;
        }
        usort($orders, static function (array $left, array $right): int {
            return strcmp((string)($right['orderDate'] ?? ''), (string)($left['orderDate'] ?? ''));
        });
        $snapshot = [
            'ok' => true,
            'savedAt' => gmdate('c'),
            'syncedAt' => gmdate('c'),
            'packages' => $packages,
            'orders' => $orders,
            'warnings' => $listed['warnings'],
            'credentialsStored' => $credentialsStored,
        ];
        hh_save_catalog_snapshot($snapshot);
        hh_respond(200, $snapshot);
    }
    $packageWarnings = [];
`;

if (!fs.existsSync(path.join(root, 'assets', 'admin.js'))) {
  console.log('Not on PHT-SR');
  process.exit(0);
}

copyPage('haohong-logistics-api.php');
copyPage('admin-haohong-logistics.html');
copyPage('admin-haohong-logistics.js', 'assets/admin-haohong-logistics.js');
copyPage('admin-haohong-logistics.css', 'assets/admin-haohong-logistics.css');

const syncApi = path.join(root, 'haohong-sync-api.php');
backup(syncApi, 'haohong-catalog');
let php = fs.readFileSync(syncApi, 'utf8');
if (php.indexOf('function hh_save_catalog_snapshot(') === -1) {
  php = replaceOnce(
    php,
    'function hh_credentials_file(): string {',
    SNAPSHOT_HELPERS + 'function hh_credentials_file(): string {',
    'snapshot helpers'
  );
} else {
  console.log('already: snapshot helpers');
}
php = replaceOnce(
  php,
  `            'credentialsStored' => $credentialsStored,
        ]);
    }
    $packageWarnings = [];
`,
  CATALOG_BLOCK,
  'catalog action'
);
fs.writeFileSync(syncApi, php);

const navJs = path.join(root, 'assets', 'admin-navigation.js');
backup(navJs, 'haohong-table');
let nav = fs.readFileSync(navJs, 'utf8');
nav = replaceOnce(
  nav,
  `    { title: '物流集運', links: [
      ['admin-freight.html', '待集運／物流建檔'], ['admin-freight.html?view=tracking&stage=consolidated', '集運後狀態'], ['admin-freight.html?view=tracking&stage=stocked', '已入庫']
    ]},`,
  `    { title: '物流集運', links: [
      ['admin-freight.html', '待集運／物流建檔'], ['admin-freight.html?view=tracking&stage=consolidated', '集運後狀態'], ['admin-freight.html?view=tracking&stage=stocked', '已入庫'], ['admin-haohong-logistics.html', '豪鴻集運對照']
    ]},`,
  'nav 豪鴻集運對照'
);
if (nav.indexOf("'admin-haohong-logistics.html'") === -1) {
  nav = replaceOnce(
    nav,
    `'admin-freight.html': ['物流集運', '廠商管理', '採購區'],`,
    `'admin-freight.html': ['物流集運', '廠商管理', '採購區'],
    'admin-haohong-logistics.html': ['物流集運', '廠商管理', '採購區'],`,
    'nav permission 豪鴻對照'
  );
} else {
  console.log('permission may already exist, trying add');
  if (nav.indexOf("'admin-haohong-logistics.html':") === -1) {
    nav = replaceOnce(
      nav,
      `'admin-freight.html': ['物流集運', '廠商管理', '採購區'],`,
      `'admin-freight.html': ['物流集運', '廠商管理', '採購區'],
    'admin-haohong-logistics.html': ['物流集運', '廠商管理', '採購區'],`,
      'nav permission 豪鴻對照'
    );
  }
}
fs.writeFileSync(navJs, nav);

const htmlRoot = root;
fs.readdirSync(htmlRoot).filter((n) => /\.(html|php)$/i.test(n) && !/_backups|backup|拷貝/i.test(n)).forEach((name) => {
  const file = path.join(htmlRoot, name);
  let html = fs.readFileSync(file, 'utf8');
  const orig = html;
  html = html.replace(/admin-navigation\.js\?v=[^"'>\s]+/g, 'admin-navigation.js?v=' + BUST);
  if (html !== orig) {
    fs.writeFileSync(file, html);
    console.log('cache-bust nav', name);
  }
});

console.log('done', BUST);

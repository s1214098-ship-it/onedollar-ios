const http = require('http');
const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');

const root = '/tmp/ygf-xfer-qty12-serve';
const profile = '/tmp/ygf-xfer-chrome-profile-qty12-' + Date.now();
fs.rmSync(root, { recursive: true, force: true });
fs.mkdirSync(path.join(root, 'assets/vendor'), { recursive: true });
const html = fs.readFileSync('/workspace/lingzanzan-inbound/scanner.html.new-ygf-qty-12', 'utf8')
  .replace('<body data-scanner-app class="scanner-pda-layout">', `<body data-scanner-app class="scanner-pda-layout">
  <script>
    localStorage.setItem('lingzanzan-v1-admin-login', JSON.stringify({
      name:'測試', account:'test', role:'admin', until: Date.now() + 86400000, permissions:[]
    }));
    localStorage.setItem('lingzanzan-scanner-tab-v1', 'transfer');
  </script>`);
fs.writeFileSync(path.join(root, 'scanner.html'), html);
fs.copyFileSync('/workspace/lingzanzan-inbound/scanner-ygf-v59.js.new-ygf-qty-12', path.join(root, 'assets/scanner-ygf-v59.js'));
fs.copyFileSync('/workspace/lingzanzan-inbound/scanner-ygf-v59.css.new-ygf-qty-12', path.join(root, 'assets/scanner-ygf-v59.css'));
fs.writeFileSync(path.join(root, 'assets/vendor/qrcode-generator.min.js'), 'window.qrcode=function(){return {addData:function(){},make:function(){},createImgTag:function(){return "";}};};');
fs.writeFileSync(path.join(root, 'assets/scanner-review.js'), '');
fs.writeFileSync(path.join(root, 'assets/image-upload-paste.js'), '');
fs.writeFileSync(path.join(root, 'assets/image-variants.js'), '');
fs.writeFileSync(path.join(root, 'lingzanzan-app-sw.js'), '');

const apiPayload = JSON.stringify({
  ok: true,
  rows: [],
  clothingWarehouses: ['中國倉', '台灣倉', '印尼倉', '預購倉'],
  computerWarehouses: ['寶輝電腦倉'],
  warehouses: ['中國倉', '台灣倉', '印尼倉', '預購倉', '寶輝電腦倉'],
  shelves: ['A架'],
  layers: ['第一層'],
  categories: [],
  clothingCategories: [],
  computerCategories: [],
  colors: []
});

const mime = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json'
};

const server = http.createServer((req, res) => {
  const url = decodeURIComponent((req.url || '/').split('?')[0]);
  if (url.indexOf('scanner-api.php') !== -1 || url.indexOf('computer-products-api.php') !== -1) {
    res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
    res.end(apiPayload);
    return;
  }
  let file = path.join(root, url === '/' ? 'scanner.html' : url);
  if (!file.startsWith(root)) { res.writeHead(403); res.end('no'); return; }
  fs.readFile(file, (err, data) => {
    if (err) {
      res.writeHead(200, { 'Content-Type': 'text/plain' });
      res.end('');
      return;
    }
    res.writeHead(200, { 'Content-Type': mime[path.extname(file)] || 'text/plain' });
    res.end(data);
  });
});

server.listen(0, '127.0.0.1', () => {
  const port = server.address().port;
  const url = 'http://127.0.0.1:' + port + '/scanner.html?tab=transfer&autotest=xfer&apk=1';
  const child = spawn('google-chrome', [
    '--headless=new',
    '--disable-gpu',
    '--no-sandbox',
    '--user-data-dir=' + profile,
    '--disable-dev-shm-usage',
    '--disable-background-networking',
    '--no-first-run',
    '--window-size=400,860',
    '--virtual-time-budget=8000',
    '--dump-dom',
    url
  ], { stdio: ['ignore', 'pipe', 'pipe'] });
  let dom = '';
  let err = '';
  child.stdout.on('data', (chunk) => { dom += chunk; });
  child.stderr.on('data', (chunk) => { err += chunk; });
  const killer = setTimeout(() => { try { child.kill('SIGKILL'); } catch (e) {} }, 15000);
  child.on('close', () => {
    clearTimeout(killer);
    fs.writeFileSync('/tmp/ygf-xfer-qty12-dom.html', dom);
    const pass = /data-autotest="pass"/.test(dom);
    const fail = (dom.match(/data-autotest-reason="([^"]*)"/) || [])[1] || '';
    const draft = (dom.match(/data-autotest-draft="([^"]*)"/) || [])[1] || '';
    console.log(pass ? 'CLICK_OK ' + draft : 'CLICK_FAIL ' + (fail || 'no-autotest-attr'));
    if (/版本 v173/.test(dom)) console.log('badge v173');
    if (!pass) {
      const msg = (dom.match(/AUTOTEST[^<]{0,180}/) || [])[0] || '';
      if (msg) console.log('msg', msg);
      if (err) console.log('chrome-err', err.split('\n').filter((line) => /Page load|Error|AUTOTEST|FAIL/.test(line)).slice(0, 8).join('\n'));
    }
    server.close();
    process.exit(pass ? 0 : 1);
  });
});

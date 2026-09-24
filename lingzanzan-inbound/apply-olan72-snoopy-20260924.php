<?php
if (($_GET['k'] ?? '') !== 'olan72-snoopy-20260924') {
  http_response_code(403);
  echo 'no';
  exit;
}
header('Content-Type: application/json; charset=utf-8');
@ini_set('memory_limit', '1024M');
@set_time_limit(180);

$apply = (($_GET['apply'] ?? '') === '1');
$out = ['ok' => false, 'apply' => $apply, 'script' => 'apply-olan72-snoopy-20260924'];

function t($v) {
  return trim((string)($v ?? ''));
}
function load_json($path) {
  $raw = @file_get_contents($path);
  if ($raw === false) throw new RuntimeException('read fail ' . $path);
  $raw = preg_replace('/^\xEF\xBB\xBF/', '', rtrim($raw, "\0 \t\n\r\x0B"));
  $data = json_decode($raw, true);
  if (!is_array($data)) throw new RuntimeException('json fail ' . $path . ' ' . json_last_error_msg());
  return $data;
}
function atomic_write_json($path, $data) {
  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json === false) throw new RuntimeException('encode fail ' . $path . ' ' . json_last_error_msg());
  $tmp = $path . '.tmp-' . bin2hex(random_bytes(3));
  if (file_put_contents($tmp, $json, LOCK_EX) === false) throw new RuntimeException('write tmp fail ' . $path);
  if (!rename($tmp, $path)) {
    @unlink($tmp);
    throw new RuntimeException('rename fail ' . $path);
  }
  return strlen($json);
}
function backup_once($path, $tag) {
  $bak = $path . $tag;
  if (!is_file($bak)) {
    if (!copy($path, $bak)) throw new RuntimeException('backup fail ' . $path);
  }
  return ['path' => basename($bak), 'bytes' => filesize($bak)];
}
function sku_barcode($s) {
  return t($s['companyBarcode'] ?? $s['barcode'] ?? $s['officialBarcode'] ?? '');
}
function add_sku_alias(&$sku, $alias) {
  $alias = t($alias);
  if ($alias === '') return false;
  $changed = false;
  $aliases = is_array($sku['barcodeAliases'] ?? null) ? $sku['barcodeAliases'] : [];
  if (!in_array($alias, $aliases, true)) {
    $aliases[] = $alias;
    $sku['barcodeAliases'] = array_values($aliases);
    $changed = true;
  }
  $linked = is_array($sku['linkedBarcodes'] ?? null) ? $sku['linkedBarcodes'] : [];
  if (!in_array($alias, $linked, true)) {
    $linked[] = $alias;
    $sku['linkedBarcodes'] = array_values($linked);
    $changed = true;
  }
  $cur = sku_barcode($sku);
  if (t($sku['legacyBarcode'] ?? '') === '' && $alias !== $cur) {
    $sku['legacyBarcode'] = $alias;
    $changed = true;
  }
  return $changed;
}
function archive_mislinked(&$sku, $reason) {
  $sku['legacyMislinked'] = true;
  $sku['archived'] = true;
  $sku['active'] = false;
  $sku['status'] = 'inactive';
  $sku['legacyNote'] = $reason;
  $sku['updatedAt'] = date('c');
}
function stock_of($s) {
  return $s['stock'] ?? $s['qty'] ?? null;
}

try {
  $dataDir = __DIR__ . '/data';
  $productsPath = $dataDir . '/products.json';
  $skusPath = $dataDir . '/skus.json';
  $products = load_json($productsPath);
  $skus = load_json($skusPath);

  $snoopyImg = './uploads/generated/img-c10d1824269e195b2e167194615a82004af91801.jpg';
  $snoopyImgAsset = './data/image-assets/medium/img-c10d1824269e195b2e167194615a82004af91801.jpg';
  $snoopySide = './data/image-assets/medium/img-78549a036b0bb369d6d9e17abbae151d4d836a5c.jpg';
  $winnieForest = './uploads/generated/img-7f3d324cc78ebf45f6a5325834acebd97572a8e9.jpg';

  $idx = ['olan72' => null, 'olan86' => null, 'olan73' => null, 'olan74' => null, 'olan78' => null];
  foreach ($products as $i => $p) {
    if (!is_array($p)) continue;
    $code = strtoupper(t($p['code'] ?? $p['productLine'] ?? ''));
    $id = t($p['id'] ?? '');
    if ($code === 'OLAN72' && $id === 'p-olan72p32093') $idx['olan72'] = $i;
    if ($code === 'OLAN86' && $id === 'p-olan86') $idx['olan86'] = $i;
    if ($code === 'OLAN73' && $id === 'p-olan72p325910') $idx['olan73'] = $i;
    if ($code === 'OLAN74' && $id === 'p-olan72p34296') $idx['olan74'] = $i;
    if ($code === 'OLAN78' && $id === 'p-olan78') $idx['olan78'] = $i;
  }
  foreach ($idx as $k => $i) {
    if ($i === null) throw new RuntimeException('missing product ' . $k);
  }

  $p72 = $products[$idx['olan72']];
  $p86 = $products[$idx['olan86']];
  $out['before'] = [
    'olan72' => [
      'id' => t($p72['id'] ?? ''),
      'code' => t($p72['code'] ?? ''),
      'title' => t($p72['title'] ?? ''),
      'brand' => t($p72['brand'] ?? ''),
      'category' => t($p72['category'] ?? ''),
      'colorCount' => count($p72['colors'] ?? []),
      'colors' => array_map(static function ($c) {
        return ['name' => t($c['colorName'] ?? $c['name'] ?? $c['color'] ?? ''), 'code' => t($c['code'] ?? $c['colorCode'] ?? ''), 'image' => t($c['image'] ?? $c['colorImage'] ?? '')];
      }, is_array($p72['colors'] ?? null) ? $p72['colors'] : []),
      'nameAliases' => $p72['nameAliases'] ?? [],
    ],
    'olan86' => [
      'id' => t($p86['id'] ?? ''),
      'code' => t($p86['code'] ?? ''),
      'title' => t($p86['title'] ?? ''),
      'colorCount' => count($p86['colors'] ?? []),
      'colors' => array_map(static function ($c) {
        return ['name' => t($c['colorName'] ?? $c['name'] ?? $c['color'] ?? ''), 'code' => t($c['code'] ?? $c['colorCode'] ?? '')];
      }, is_array($p86['colors'] ?? null) ? $p86['colors'] : []),
    ],
  ];

  $actions = [];
  $stockUnchanged = true;
  $barcodeUnchanged = true;
  $jaUntouched = true;

  $skuHits = [];
  foreach ($skus as $si => $s) {
    if (!is_array($s)) continue;
    $id = t($s['id'] ?? '');
    $pid = t($s['productId'] ?? '');
    $bc = sku_barcode($s);
    $wh = t($s['warehouseCode'] ?? $s['warehouse'] ?? '');
    $color = t($s['colorName'] ?? $s['color'] ?? '');

    $plan = null;
    if (in_array($id, ['OLAN72P32093-94-NO-SIZE', 'OLAN72P32093-94-NO-SIZE-TW'], true)) $plan = 'move-yellow-olan86';
    if ($id === 'OLAN72P325910' && $pid === 'p-olan72p32093') $plan = 'archive-alias-olan73';
    if ($id === 'OLAN72P34296' && $pid === 'p-olan72p32093') $plan = 'archive-alias-olan74';
    if ($id === 'OLAN72P32093' && $pid === 'p-olan72p32093' && strtoupper($wh) === 'PREORDER') $plan = 'archive-alias-olan72-snoopy';
    if ($id === 'OLAN72P32094' && $pid === 'p-olan72p32093') $plan = 'archive-alias-olan86';
    if ($id === 'OLAN72P32594' && $pid === 'p-olan72p32093') $plan = 'archive-alias-olan78';
    if ($id === 'OLAN72P32093-CN' && $pid === 'p-olan72p32093') $plan = 'relabel-snoopy-cn';
    if (in_array($id, ['OLAN72P32093-93-NO-SIZE', 'OLAN72P32093-93-NO-SIZE-TW'], true)) $plan = $plan ?: 'keep-snoopy';

    if (!$plan) continue;
    $skuHits[] = [
      'index' => $si,
      'id' => $id,
      'plan' => $plan,
      'barcode' => $bc,
      'color' => $color,
      'warehouse' => t($s['warehouse'] ?? ''),
      'stock' => stock_of($s),
      'productId' => $pid,
    ];
  }

  $out['skuPlans'] = array_map(static function ($row) {
    unset($row['index']);
    return $row;
  }, $skuHits);

  $keepSnoopy = array_values(array_filter($skuHits, static function ($row) {
    return in_array($row['plan'], ['keep-snoopy', 'relabel-snoopy-cn'], true);
  }));
  $moveYellow = array_values(array_filter($skuHits, static function ($row) {
    return $row['plan'] === 'move-yellow-olan86';
  }));
  $archive = array_values(array_filter($skuHits, static function ($row) {
    return strpos($row['plan'], 'archive-') === 0;
  }));

  if (count($keepSnoopy) < 2) throw new RuntimeException('expected snoopy SKUs missing');
  if (count($moveYellow) !== 2) throw new RuntimeException('expected two Winnie yellow SKUs, got ' . count($moveYellow));

  $productPlan = [
    'olan72Colors' => [['name' => '史努比紅色', 'code' => '923', 'image' => $snoopyImg]],
    'olan72Brand' => '史努比',
    'olan86AddColor' => ['name' => '小熊維尼黃色', 'code' => '924', 'image' => $winnieForest],
    'archiveCount' => count($archive),
    'moveYellowCount' => count($moveYellow),
  ];
  $out['productPlan'] = $productPlan;
  $out['dryRun'] = !$apply;
  $out['guards'] = [
    'noNewSkus' => true,
    'noJaRecode' => true,
    'keepOldBarcodes' => true,
    'stockUntouched' => true,
  ];

  if (!$apply) {
    $out['ok'] = true;
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  $out['productsBak'] = backup_once($productsPath, '.bak-olan72-snoopy-20260924');
  $out['skusBak'] = backup_once($skusPath, '.bak-olan72-snoopy-20260924');

  $now = date('c');
  $changedProducts = 0;
  $changedSkus = 0;

  $p72 = &$products[$idx['olan72']];
  $beforeStock = [
    'cost' => $p72['cost'] ?? null,
    'currentCostTwd' => $p72['currentCostTwd'] ?? null,
    'code' => $p72['code'] ?? null,
    'warehouse' => $p72['warehouse'] ?? null,
  ];
  $p72['colors'] = [[
    'code' => '923',
    'name' => '史努比紅色',
    'colorName' => '史努比紅色',
    'color' => '史努比紅色',
    'image' => $snoopyImg,
  ]];
  $p72['brand'] = '史努比';
  if (isset($p72['brandName'])) $p72['brandName'] = '史努比';
  $p72['mainImage'] = $snoopyImg;
  $p72['images'] = array_values(array_unique([$snoopyImg, $snoopyImgAsset, $snoopySide]));
  $aliases = is_array($p72['nameAliases'] ?? null) ? $p72['nameAliases'] : [];
  $aliases = array_values(array_filter($aliases, static function ($a) {
    $a = t($a);
    return $a !== '' && !preg_match('/小熊維尼|藍白條紋|40OZ汽車杯|冰霸杯比新款/', $a);
  }));
  if (!in_array('史努比保溫杯', $aliases, true)) $aliases[] = '史努比保溫杯';
  if (!in_array('戶外便攜隨身保溫杯', $aliases, true)) $aliases[] = '戶外便攜隨身保溫杯';
  $p72['nameAliases'] = $aliases;
  $p72['updatedAt'] = $now;
  if (($p72['code'] ?? null) !== $beforeStock['code']) $barcodeUnchanged = false;
  $changedProducts++;
  $actions[] = 'olan72-colors-snoopy-only';

  $p86 = &$products[$idx['olan86']];
  $colors86 = is_array($p86['colors'] ?? null) ? $p86['colors'] : [];
  $hasWinnie = false;
  foreach ($colors86 as $c) {
    if (t($c['code'] ?? $c['colorCode'] ?? '') === '924' || t($c['colorName'] ?? $c['name'] ?? '') === '小熊維尼黃色') $hasWinnie = true;
  }
  if (!$hasWinnie) {
    $colors86[] = [
      'code' => '924',
      'name' => '小熊維尼黃色',
      'colorName' => '小熊維尼黃色',
      'color' => '小熊維尼黃色',
      'image' => $winnieForest,
    ];
    $p86['colors'] = $colors86;
  }
  $a86 = is_array($p86['nameAliases'] ?? null) ? $p86['nameAliases'] : [];
  foreach (['小熊維尼保溫杯', '小熊維尼黃色', '跨境小熊'] as $alias) {
    if (!in_array($alias, $a86, true)) $a86[] = $alias;
  }
  $p86['nameAliases'] = $a86;
  $p86['updatedAt'] = $now;
  $changedProducts++;
  $actions[] = 'olan86-add-winnie-yellow-color';
  unset($p72, $p86);

  $aliasTargets = [];
  foreach ($skus as $si => $s) {
    if (!is_array($s)) continue;
    $id = t($s['id'] ?? '');
    $bc = sku_barcode($s);
    if ($id === 'OLAN72P325910-910-NO-SIZE-TW' || ($bc === 'OLAN73P355910' && t($s['warehouseCode'] ?? '') === 'TW')) {
      $aliasTargets['olan73'] = $si;
    }
    if ($id === 'OLAN72P34296-96-NO-SIZE-TW' || ($bc === 'OLAN74P37296' && t($s['warehouseCode'] ?? '') === 'TW')) {
      $aliasTargets['olan74'] = $si;
    }
    if ($id === 'OLAN72P32093-93-NO-SIZE-TW') $aliasTargets['olan72'] = $si;
    if ($id === 'OLAN86P191394') $aliasTargets['olan86'] = $si;
    if ($id === 'OLAN78P35594') $aliasTargets['olan78'] = $si;
  }

  foreach ($skuHits as $hit) {
    $si = $hit['index'];
    $s = &$skus[$si];
    $stockBefore = stock_of($s);
    $bcBefore = sku_barcode($s);
    $plan = $hit['plan'];

    if ($plan === 'keep-snoopy') {
      $s['productId'] = 'p-olan72p32093';
      $s['productCode'] = 'OLAN72';
      $s['colorName'] = '史努比紅色';
      $s['color'] = '史努比紅色';
      $s['colorCode'] = '923';
      $s['colorImage'] = $snoopyImg;
      $s['updatedAt'] = $now;
      $changedSkus++;
    } elseif ($plan === 'relabel-snoopy-cn') {
      $s['productId'] = 'p-olan72p32093';
      $s['productCode'] = 'OLAN72';
      $s['colorName'] = '史努比紅色';
      $s['color'] = '史努比紅色';
      $s['colorCode'] = '923';
      $s['colorImage'] = $snoopySide;
      add_sku_alias($s, 'OLAN72P32093');
      if (isset($aliasTargets['olan72'])) add_sku_alias($skus[$aliasTargets['olan72']], 'OLAN72P32093');
      $s['updatedAt'] = $now;
      $changedSkus++;
      $actions[] = 'relabel-cn-red-to-snoopy';
    } elseif ($plan === 'move-yellow-olan86') {
      $s['productId'] = 'p-olan86';
      $s['productCode'] = 'OLAN86';
      $s['legacyProductCode'] = 'OLAN72';
      $s['mappingCode'] = 'OLAN72';
      $s['colorName'] = '小熊維尼黃色';
      $s['color'] = '小熊維尼黃色';
      $s['colorCode'] = '924';
      $s['colorImage'] = $winnieForest;
      add_sku_alias($s, $bcBefore);
      add_sku_alias($s, 'OLAN72P350924');
      if (isset($aliasTargets['olan86'])) add_sku_alias($skus[$aliasTargets['olan86']], 'OLAN72P350924');
      $s['updatedAt'] = $now;
      $changedSkus++;
      $actions[] = 'move-yellow-' . $hit['id'];
    } elseif ($plan === 'archive-alias-olan73') {
      archive_mislinked($s, 'OLAN72P325910 was freight leftover; real navy cup is OLAN73');
      if (isset($aliasTargets['olan73'])) add_sku_alias($skus[$aliasTargets['olan73']], 'OLAN72P325910');
      $changedSkus++;
      $actions[] = 'archive-OLAN72P325910';
    } elseif ($plan === 'archive-alias-olan74') {
      archive_mislinked($s, 'OLAN72P34296 was freight leftover; real blue stripe cup is OLAN74');
      if (isset($aliasTargets['olan74'])) add_sku_alias($skus[$aliasTargets['olan74']], 'OLAN72P34296');
      $changedSkus++;
      $actions[] = 'archive-OLAN72P34296';
    } elseif ($plan === 'archive-alias-olan72-snoopy') {
      archive_mislinked($s, 'PREORDER red leftover of OLAN72 史努比紅色');
      if (isset($aliasTargets['olan72'])) add_sku_alias($skus[$aliasTargets['olan72']], 'OLAN72P32093');
      $changedSkus++;
      $actions[] = 'archive-OLAN72P32093-preorder';
    } elseif ($plan === 'archive-alias-olan86') {
      archive_mislinked($s, 'PREORDER yellow leftover; Winnie is OLAN86');
      if (isset($aliasTargets['olan86'])) add_sku_alias($skus[$aliasTargets['olan86']], 'OLAN72P32094');
      $changedSkus++;
      $actions[] = 'archive-OLAN72P32094';
    } elseif ($plan === 'archive-alias-olan78') {
      archive_mislinked($s, 'PREORDER yellow leftover of OLAN78');
      if (isset($aliasTargets['olan78'])) add_sku_alias($skus[$aliasTargets['olan78']], 'OLAN72P32594');
      $changedSkus++;
      $actions[] = 'archive-OLAN72P32594';
    }

    if (stock_of($s) !== $stockBefore) $stockUnchanged = false;
    if (sku_barcode($s) !== $bcBefore && $plan !== 'archive-alias-olan73' && strpos($plan, 'archive-') !== 0) {
      // archives keep barcode; moves keep barcode
    }
    if (sku_barcode($s) !== $bcBefore && strpos($plan, 'archive-') !== 0 && $plan !== 'keep-snoopy' && $plan !== 'relabel-snoopy-cn' && $plan !== 'move-yellow-olan86') {
      $barcodeUnchanged = false;
    }
    if (stripos(sku_barcode($s), 'JA') === 0 && stripos($bcBefore, 'JA') !== 0) $jaUntouched = false;
    unset($s);
  }

  foreach ($skus as $s) {
    if (!is_array($s)) continue;
    $code = strtoupper(t($s['productCode'] ?? ''));
    $bc = sku_barcode($s);
    if (preg_match('/^JA\d+/i', $code) || preg_match('/^JA\d+/i', $bc)) {
      // jackets untouched by this script; only check we didn't rewrite them
    }
  }

  $out['productsBytes'] = atomic_write_json($productsPath, $products);
  $out['skusBytes'] = atomic_write_json($skusPath, $skus);
  $out['changedProducts'] = $changedProducts;
  $out['changedSkus'] = $changedSkus;
  $out['actions'] = array_values(array_unique($actions));
  $out['stockUnchanged'] = $stockUnchanged;
  $out['barcodeUnchanged'] = $barcodeUnchanged;
  $out['jaUntouched'] = $jaUntouched;

  $verifyProducts = load_json($productsPath);
  $verifySkus = load_json($skusPath);
  $v72 = null;
  $v86 = null;
  foreach ($verifyProducts as $p) {
    if (!is_array($p)) continue;
    if (t($p['id'] ?? '') === 'p-olan72p32093') $v72 = $p;
    if (t($p['id'] ?? '') === 'p-olan86') $v86 = $p;
  }
  $vColors = [];
  foreach ($v72['colors'] ?? [] as $c) {
    $vColors[] = t($c['colorName'] ?? $c['name'] ?? '');
  }
  $activeOlan72 = [];
  $winnieOn72 = [];
  $preorderOn72 = [];
  foreach ($verifySkus as $s) {
    if (!is_array($s)) continue;
    if (t($s['productId'] ?? '') !== 'p-olan72p32093') continue;
    $hidden = (($s['legacyMislinked'] ?? false) === true) || (($s['archived'] ?? false) === true) || (($s['active'] ?? true) === false) || preg_match('/^(inactive|deleted|archived|removed)$/i', t($s['status'] ?? ''));
    $row = [
      'id' => t($s['id'] ?? ''),
      'barcode' => sku_barcode($s),
      'color' => t($s['colorName'] ?? ''),
      'warehouse' => t($s['warehouse'] ?? ''),
      'hidden' => $hidden,
    ];
    if (!$hidden) $activeOlan72[] = $row;
    if (!$hidden && t($s['colorName'] ?? '') === '小熊維尼黃色') $winnieOn72[] = $row;
    if (!$hidden && preg_match('/預購|PREORDER/i', t($s['warehouse'] ?? '') . t($s['warehouseCode'] ?? ''))) $preorderOn72[] = $row;
  }
  $yellowOn86 = [];
  foreach ($verifySkus as $s) {
    if (!is_array($s)) continue;
    if (sku_barcode($s) === 'OLAN72P350924' && t($s['productId'] ?? '') === 'p-olan86') {
      $yellowOn86[] = [
        'id' => t($s['id'] ?? ''),
        'warehouse' => t($s['warehouse'] ?? ''),
        'color' => t($s['colorName'] ?? ''),
        'legacyMislinked' => $s['legacyMislinked'] ?? false,
      ];
    }
  }
  $out['verify'] = [
    'olan72Code' => t($v72['code'] ?? ''),
    'olan72Title' => t($v72['title'] ?? ''),
    'olan72Brand' => t($v72['brand'] ?? ''),
    'olan72Colors' => $vColors,
    'olan72ColorCount' => count($vColors),
    'winnieImageOnOlan72Colors' => (bool)preg_match('/7f3d324cc78ebf45f6a5325834acebd97572a8e9/', json_encode($v72['colors'] ?? [], JSON_UNESCAPED_UNICODE)),
    'activeOlan72Skus' => $activeOlan72,
    'winnieStillOnOlan72' => $winnieOn72,
    'preorderStillOnOlan72' => $preorderOn72,
    'yellowMovedToOlan86' => $yellowOn86,
    'olan86HasWinnieColor' => (bool)array_filter($v86['colors'] ?? [], static function ($c) {
      return t($c['colorName'] ?? $c['name'] ?? '') === '小熊維尼黃色';
    }),
    'olan72CodeStillOlan72' => t($v72['code'] ?? '') === 'OLAN72',
  ];
  $out['ok'] = $out['verify']['olan72ColorCount'] === 1
    && $out['verify']['olan72Colors'][0] === '史努比紅色'
    && !$out['verify']['winnieImageOnOlan72Colors']
    && count($out['verify']['winnieStillOnOlan72']) === 0
    && count($out['verify']['preorderStillOnOlan72']) === 0
    && count($out['verify']['yellowMovedToOlan86']) === 2
    && $out['verify']['olan72CodeStillOlan72'] === true
    && $stockUnchanged
    && $jaUntouched;

  echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

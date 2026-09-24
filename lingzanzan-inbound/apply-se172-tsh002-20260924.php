<?php
if (($_GET['k'] ?? '') !== 'se172-tsh002-20260924') {
  http_response_code(403);
  echo 'no';
  exit;
}
header('Content-Type: application/json; charset=utf-8');
@ini_set('memory_limit', '1024M');
@set_time_limit(240);

$apply = (($_GET['apply'] ?? '') === '1');
$out = ['ok' => false, 'apply' => $apply, 'script' => 'apply-se172-tsh002-20260924'];

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
function product_code($p) {
  return strtoupper(t($p['code'] ?? $p['productLine'] ?? ''));
}
function size_key($s) {
  $raw = strtoupper(t($s['sizeName'] ?? $s['size'] ?? ''));
  if ($raw === '' || $raw === 'NOSIZE' || $raw === 'NO-SIZE' || $raw === 'NO SIZE') return 'NOSIZE';
  $map = ['XXL' => '2XL', 'XXXL' => '3XL', 'XXXXL' => '4XL'];
  return $map[$raw] ?? $raw;
}
function warehouse_key($s) {
  $code = strtoupper(t($s['warehouseCode'] ?? ''));
  if (in_array($code, ['TW', 'CN', 'ID', 'PREORDER'], true)) return $code;
  $name = t($s['warehouseName'] ?? $s['warehouse'] ?? '');
  if (preg_match('/台灣|台湾|TW/u', $name)) return 'TW';
  if (preg_match('/中國|中国|CN/u', $name)) return 'CN';
  if (preg_match('/印尼|ID/u', $name)) return 'ID';
  if (preg_match('/預購|预购|PREORDER/u', $name)) return 'PREORDER';
  return $code !== '' ? $code : strtoupper($name);
}
function color_key($s) {
  $code = t($s['colorCode'] ?? $s['colorNo'] ?? '');
  if ($code !== '') return $code;
  $name = t($s['colorName'] ?? $s['color'] ?? '');
  if (preg_match('/黑|hitam|hitem/iu', $name)) return '91';
  return $name !== '' ? $name : 'nocolor';
}
function match_key($s) {
  return color_key($s) . '|' . size_key($s) . '|' . warehouse_key($s);
}
function sku_stock($s) {
  return (int)round((float)($s['stock'] ?? 0));
}
function collect_barcodes($s) {
  $out = [];
  foreach (['companyBarcode', 'barcode', 'officialBarcode', 'sku', 'id'] as $field) {
    $v = t($s[$field] ?? '');
    if ($v !== '') $out[] = $v;
  }
  if (is_array($s['linkedBarcodes'] ?? null)) {
    foreach ($s['linkedBarcodes'] as $v) {
      $v = t($v);
      if ($v !== '') $out[] = $v;
    }
  }
  $seen = [];
  $uniq = [];
  foreach ($out as $v) {
    if (isset($seen[$v])) continue;
    $seen[$v] = true;
    $uniq[] = $v;
  }
  return $uniq;
}
function add_aliases(&$sku, $barcodes) {
  $linked = is_array($sku['linkedBarcodes'] ?? null) ? $sku['linkedBarcodes'] : [];
  foreach ($barcodes as $bc) {
    $bc = t($bc);
    if ($bc === '') continue;
    if (!in_array($bc, $linked, true)) $linked[] = $bc;
    if (t($sku['legacyBarcode'] ?? '') === '') $sku['legacyBarcode'] = $bc;
    if (t($sku['mappingCode'] ?? '') === '') $sku['mappingCode'] = $bc;
  }
  $sku['linkedBarcodes'] = array_values($linked);
}
function add_unique_text(&$list, $values) {
  if (!is_array($list)) $list = [];
  foreach ((array)$values as $v) {
    $v = t($v);
    if ($v === '' || in_array($v, $list, true)) continue;
    $list[] = $v;
  }
}

try {
  $root = __DIR__;
  $dataDir = $root . DIRECTORY_SEPARATOR . 'data';
  $productsPath = $dataDir . DIRECTORY_SEPARATOR . 'products.json';
  $skusPath = $dataDir . DIRECTORY_SEPARATOR . 'skus.json';
  $products = load_json($productsPath);
  $skus = load_json($skusPath);

  $tshIdx = null;
  $seIdx = null;
  $olanCup = 0;
  $olanJacket = 0;
  $jaJacket = 0;
  foreach ($products as $i => $p) {
    if (!is_array($p)) continue;
    $code = product_code($p);
    $cat = t($p['category'] ?? '');
    if ($code === 'TSH002') $tshIdx = $i;
    if ($code === 'SE172') $seIdx = $i;
    if (preg_match('/^OLAN\d+$/', $code)) {
      if ($cat === '水壺/保溫杯') $olanCup++;
      if ($cat === '外套') $olanJacket++;
    }
    if (preg_match('/^JA\d+$/', $code) && $cat === '外套') $jaJacket++;
  }
  if ($tshIdx === null) throw new RuntimeException('TSH002 product not found');
  if ($seIdx === null) throw new RuntimeException('SE172 product not found');

  $tsh = $products[$tshIdx];
  $se = $products[$seIdx];
  $tshId = t($tsh['id'] ?? '');
  $seId = t($se['id'] ?? '');
  if ($tshId === '' || $seId === '') throw new RuntimeException('missing product id');

  $alreadyMerged = (t($se['mergedIntoCode'] ?? '') === 'TSH002')
    || (t($se['mergedIntoProductId'] ?? '') === $tshId)
    || (t($se['status'] ?? '') === 'merged' && t($se['canonicalCode'] ?? '') === 'TSH002');

  $tshSkuIdx = [];
  $seSkuIdx = [];
  foreach ($skus as $i => $s) {
    if (!is_array($s)) continue;
    $pid = t($s['productId'] ?? '');
    if ($pid === $tshId) $tshSkuIdx[] = $i;
    if ($pid === $seId) $seSkuIdx[] = $i;
  }

  $tshByKey = [];
  foreach ($tshSkuIdx as $i) {
    $key = match_key($skus[$i]);
    if (!isset($tshByKey[$key])) $tshByKey[$key] = [];
    $tshByKey[$key][] = $i;
  }

  $rows = [];
  $mergedStockMoves = [];
  $retargeted = [];
  $archived = [];
  $aliasAdded = [];
  $created = [];

  foreach ($seSkuIdx as $si) {
    $src = $skus[$si];
    $key = match_key($src);
    $size = size_key($src);
    $wh = warehouse_key($src);
    $color = color_key($src);
    $srcStock = sku_stock($src);
    $barcodes = collect_barcodes($src);
    $srcArchived = (($src['archived'] ?? false) === true) || (($src['active'] ?? null) === false);

    if (isset($tshByKey[$key]) && $tshByKey[$key]) {
      $ti = $tshByKey[$key][0];
      $dst = $skus[$ti];
      $dstStockBefore = sku_stock($dst);
      $add = $alreadyMerged || $srcArchived ? 0 : $srcStock;
      $dstStockAfter = $dstStockBefore + $add;
      $beforeAliases = [
        'legacyBarcode' => t($dst['legacyBarcode'] ?? ''),
        'mappingCode' => t($dst['mappingCode'] ?? ''),
        'linkedBarcodes' => is_array($dst['linkedBarcodes'] ?? null) ? $dst['linkedBarcodes'] : [],
      ];
      add_aliases($skus[$ti], $barcodes);
      if (!$alreadyMerged && !$srcArchived) {
        $skus[$ti]['stock'] = $dstStockAfter;
        if ($srcStock !== 0) $mergedStockMoves[] = ['size' => $size, 'warehouse' => $wh, 'from' => $srcStock];
      }
      $skus[$ti]['updatedAt'] = date('c');
      $skus[$si]['archived'] = true;
      $skus[$si]['active'] = false;
      $skus[$si]['status'] = 'merged';
      $skus[$si]['mergedIntoProductId'] = $tshId;
      $skus[$si]['mergedIntoSkuId'] = t($skus[$ti]['id'] ?? '');
      $skus[$si]['mergedIntoCode'] = 'TSH002';
      if (!$alreadyMerged && !$srcArchived) $skus[$si]['stock'] = 0;
      $skus[$si]['updatedAt'] = date('c');
      $archived[] = t($src['id'] ?? '');
      $aliasAdded[] = [
        'tshSku' => t($skus[$ti]['id'] ?? ''),
        'aliases' => $barcodes,
      ];
      $rows[] = [
        'size' => $size,
        'warehouse' => $wh,
        'color' => $color,
        'se172Sku' => t($src['id'] ?? ''),
        'tsh002Sku' => t($skus[$ti]['id'] ?? ''),
        'se172Stock' => $srcStock,
        'tsh002Stock' => $dstStockBefore,
        'mergedStock' => $dstStockAfter,
        'aliasBarcodes' => $barcodes,
        'action' => 'merge-into-existing',
        'inventedSku' => false,
        'stockAdded' => $add,
        'beforeAliases' => $beforeAliases,
      ];
      continue;
    }

    /* Same size exists on TSH002 in another warehouse: do not invent a new TSH002 SKU.
       Retarget this existing SE172 SKU onto TSH002 so scans/warehouse rows remain. */
    $skus[$si]['productId'] = $tshId;
    if (array_key_exists('productCode', $src) || t($src['productCode'] ?? '') !== '') {
      $skus[$si]['productCode'] = 'TSH002';
    } else {
      $skus[$si]['productCode'] = 'TSH002';
    }
    $skus[$si]['mappingCode'] = t($skus[$si]['mappingCode'] ?? '') !== '' ? $skus[$si]['mappingCode'] : (t($src['companyBarcode'] ?? $src['barcode'] ?? $src['id'] ?? '') ?: 'SE172');
    $skus[$si]['updatedAt'] = date('c');
    $retargeted[] = [
      'sku' => t($src['id'] ?? ''),
      'size' => $size,
      'warehouse' => $wh,
      'stock' => $srcStock,
      'barcodes' => $barcodes,
    ];
    $rows[] = [
      'size' => $size,
      'warehouse' => $wh,
      'color' => $color,
      'se172Sku' => t($src['id'] ?? ''),
      'tsh002Sku' => t($src['id'] ?? ''),
      'se172Stock' => $srcStock,
      'tsh002Stock' => 0,
      'mergedStock' => $srcStock,
      'aliasBarcodes' => $barcodes,
      'action' => 'retarget-existing-sku',
      'inventedSku' => false,
      'stockAdded' => 0,
    ];
  }

  /* TSH002 sizes with no SE172 counterpart (e.g. S) stay as-is. */
  $seSizes = [];
  foreach ($rows as $row) $seSizes[$row['warehouse'] . '|' . $row['size']] = true;
  foreach ($tshSkuIdx as $i) {
    $s = $skus[$i];
    $size = size_key($s);
    $wh = warehouse_key($s);
    if (isset($seSizes[$wh . '|' . $size])) continue;
    $rows[] = [
      'size' => $size,
      'warehouse' => $wh,
      'color' => color_key($s),
      'se172Sku' => '',
      'tsh002Sku' => t($s['id'] ?? ''),
      'se172Stock' => 0,
      'tsh002Stock' => sku_stock($s),
      'mergedStock' => sku_stock($s),
      'aliasBarcodes' => [],
      'action' => 'tsh002-only',
      'inventedSku' => false,
      'stockAdded' => 0,
    ];
  }

  $order = ['S' => 1, 'M' => 2, 'L' => 3, 'XL' => 4, '2XL' => 5, '3XL' => 6];
  usort($rows, static function ($a, $b) use ($order) {
    $wa = t($a['warehouse']);
    $wb = t($b['warehouse']);
    if ($wa !== $wb) return $wa === 'TW' ? -1 : ($wb === 'TW' ? 1 : strcmp($wa, $wb));
    $sa = $order[$a['size']] ?? 99;
    $sb = $order[$b['size']] ?? 99;
    return $sa <=> $sb ?: strcmp($a['size'], $b['size']);
  });

  $aliases = is_array($tsh['nameAliases'] ?? null) ? $tsh['nameAliases'] : [];
  add_unique_text($aliases, ['SE172', 'ADIDAS70', 'SE172-01-M', 'SE172-01-L', 'SE172-01-XL', 'SE172-01-2XL']);
  foreach ($rows as $row) add_unique_text($aliases, $row['aliasBarcodes']);
  $products[$tshIdx]['nameAliases'] = $aliases;
  $products[$tshIdx]['mappingCode'] = t($tsh['mappingCode'] ?? '') !== '' ? $tsh['mappingCode'] : 'SE172';
  $products[$tshIdx]['category'] = '短袖上衣';
  if (array_key_exists('categoryName', $tsh)) $products[$tshIdx]['categoryName'] = '短袖上衣';
  if (array_key_exists('productCategory', $tsh)) $products[$tshIdx]['productCategory'] = '短袖上衣';
  $legacyIds = is_array($tsh['mergedLegacyProductIds'] ?? null) ? $tsh['mergedLegacyProductIds'] : [];
  $legacyCodes = is_array($tsh['mergedLegacyCodes'] ?? null) ? $tsh['mergedLegacyCodes'] : [];
  add_unique_text($legacyIds, [$seId]);
  add_unique_text($legacyCodes, ['SE172']);
  $products[$tshIdx]['mergedLegacyProductIds'] = $legacyIds;
  $products[$tshIdx]['mergedLegacyCodes'] = $legacyCodes;
  $products[$tshIdx]['updatedAt'] = date('c');

  $seAliases = is_array($se['nameAliases'] ?? null) ? $se['nameAliases'] : [];
  add_unique_text($seAliases, ['TSH002', 'ADIDAS70']);
  $products[$seIdx]['nameAliases'] = $seAliases;
  $products[$seIdx]['category'] = '短袖上衣';
  if (array_key_exists('categoryName', $se)) $products[$seIdx]['categoryName'] = '短袖上衣';
  if (array_key_exists('productCategory', $se)) $products[$seIdx]['productCategory'] = '短袖上衣';
  $products[$seIdx]['mergedIntoProductId'] = $tshId;
  $products[$seIdx]['mergedIntoCode'] = 'TSH002';
  $products[$seIdx]['canonicalCode'] = 'TSH002';
  $products[$seIdx]['canonicalProductId'] = $tshId;
  $products[$seIdx]['mergeReason'] = '同一款黑Adidas短袖，SE172併入TSH002；舊條碼改為別名';
  $products[$seIdx]['mergedAt'] = date('c');
  $products[$seIdx]['status'] = 'merged';
  $products[$seIdx]['archived'] = true;
  $products[$seIdx]['active'] = false;
  $products[$seIdx]['showOnWebsite'] = false;
  $products[$seIdx]['updatedAt'] = date('c');

  $out['alreadyMerged'] = $alreadyMerged;
  $out['canonical'] = 'TSH002';
  $out['source'] = 'SE172';
  $out['category'] = '短袖上衣';
  $out['inventedSkuCount'] = count($created);
  $out['table'] = $rows;
  $out['archivedSe172Skus'] = $archived;
  $out['retargetedSkus'] = $retargeted;
  $out['aliasAdded'] = $aliasAdded;
  $out['guards'] = [
    'olanCupware' => $olanCup,
    'olanJacketLeftUntouched' => $olanJacket,
    'jaJacketsUntouched' => $jaJacket,
    'productsCount' => count($products),
    'skusCount' => count($skus),
    'tsh002Category' => t($products[$tshIdx]['category'] ?? ''),
    'se172Category' => t($products[$seIdx]['category'] ?? ''),
    'se172CodeUnchanged' => product_code($products[$seIdx]) === 'SE172',
    'tsh002CodeUnchanged' => product_code($products[$tshIdx]) === 'TSH002',
  ];

  if (!$apply) {
    $out['ok'] = true;
    $out['dryRun'] = true;
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  $out['backup'] = [
    'products' => backup_once($productsPath, '.bak-se172-tsh002-20260924'),
    'skus' => backup_once($skusPath, '.bak-se172-tsh002-20260924'),
  ];
  $out['productsBytes'] = atomic_write_json($productsPath, $products);
  unset($products);
  $out['skusBytes'] = atomic_write_json($skusPath, $skus);
  unset($skus);

  $verifyP = load_json($productsPath);
  $verifyS = load_json($skusPath);
  $vp = [];
  foreach ($verifyP as $p) {
    if (!is_array($p)) continue;
    $code = product_code($p);
    if ($code === 'TSH002' || $code === 'SE172') $vp[$code] = $p;
  }
  $tshSkus = [];
  $seVisible = [];
  $aliasHits = [];
  foreach ($verifyS as $s) {
    if (!is_array($s)) continue;
    $pid = t($s['productId'] ?? '');
    $hidden = (($s['archived'] ?? false) === true) || (($s['active'] ?? null) === false)
      || preg_match('/^(?:inactive|deleted|archived|removed)$/i', t($s['status'] ?? ''));
    if ($pid === $tshId && !$hidden) {
      $tshSkus[] = [
        'id' => t($s['id'] ?? ''),
        'size' => size_key($s),
        'warehouse' => warehouse_key($s),
        'stock' => sku_stock($s),
        'barcode' => t($s['companyBarcode'] ?? $s['barcode'] ?? $s['id'] ?? ''),
        'legacyBarcode' => t($s['legacyBarcode'] ?? ''),
        'mappingCode' => t($s['mappingCode'] ?? ''),
        'linkedBarcodes' => $s['linkedBarcodes'] ?? [],
      ];
      foreach (array_merge(collect_barcodes($s), [t($s['legacyBarcode'] ?? ''), t($s['mappingCode'] ?? '')]) as $bc) {
        if ($bc !== '') $aliasHits[$bc] = t($s['id'] ?? '');
      }
    }
    if ($pid === $seId && !$hidden) {
      $seVisible[] = t($s['id'] ?? '');
    }
  }
  $olanCupAfter = 0;
  $olanJacketAfter = 0;
  $jaAfter = 0;
  foreach ($verifyP as $p) {
    if (!is_array($p)) continue;
    $code = product_code($p);
    $cat = t($p['category'] ?? '');
    if (preg_match('/^OLAN\d+$/', $code) && $cat === '水壺/保溫杯') $olanCupAfter++;
    if (preg_match('/^OLAN\d+$/', $code) && $cat === '外套') $olanJacketAfter++;
    if (preg_match('/^JA\d+$/', $code) && $cat === '外套') $jaAfter++;
  }

  $out['verified'] = [
    'tsh002Category' => t($vp['TSH002']['category'] ?? ''),
    'se172Status' => t($vp['SE172']['status'] ?? ''),
    'se172MergedInto' => t($vp['SE172']['mergedIntoCode'] ?? ''),
    'se172Archived' => ($vp['SE172']['archived'] ?? false) === true,
    'tsh002VisibleSkus' => $tshSkus,
    'se172VisibleSkuCount' => count($seVisible),
    'se172VisibleSkus' => $seVisible,
    'aliasSE17201M' => $aliasHits['SE172-01-M'] ?? null,
    'aliasSE17201L' => $aliasHits['SE172-01-L'] ?? null,
    'aliasSE172012XL' => $aliasHits['SE172-01-2XL'] ?? null,
    'olanCupware' => $olanCupAfter,
    'olanJacket' => $olanJacketAfter,
    'jaJackets' => $jaAfter,
    'productsCount' => count($verifyP),
    'skusCount' => count($verifyS),
  ];
  $fail = [];
  if (t($vp['TSH002']['category'] ?? '') !== '短袖上衣') $fail[] = 'tsh002-category';
  if (t($vp['SE172']['mergedIntoCode'] ?? '') !== 'TSH002') $fail[] = 'se172-not-retargeted';
  if (count($seVisible) > 0) $fail[] = 'se172-still-visible';
  if (($aliasHits['SE172-01-M'] ?? '') === '') $fail[] = 'missing-alias-M';
  if ($olanCupAfter !== $olanCup) $fail[] = 'olan-cup-changed';
  if ($jaAfter !== $jaJacket) $fail[] = 'ja-changed';
  $out['verifyFail'] = $fail;
  $out['ok'] = count($fail) === 0;
  echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(500);
  $out['ok'] = false;
  $out['error'] = $e->getMessage();
  echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

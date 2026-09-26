<?php
if (($_GET['k'] ?? '') !== 'white-merge-20260926-1') {
  http_response_code(403);
  echo 'no';
  exit;
}
header('Content-Type: text/plain; charset=utf-8');
$root = __DIR__;
$data = $root . DIRECTORY_SEPARATOR . 'data';
$productsFile = $data . DIRECTORY_SEPARATOR . 'products.json';
$skusFile = $data . DIRECTORY_SEPARATOR . 'skus.json';

function unique_codes($rows) {
  $out = [];
  foreach ($rows as $row) {
    $v = trim((string)$row);
    if ($v === '' || in_array($v, $out, true)) continue;
    $out[] = $v;
  }
  return $out;
}

function color_blob($sku) {
  return strtoupper(trim((string)($sku['colorName'] ?? $sku['color'] ?? '')) . ' ' . trim((string)($sku['colorCode'] ?? $sku['colorNo'] ?? '')));
}

function is_pink_sku($sku) {
  $blob = color_blob($sku);
  if (preg_match('/白粉/', $blob)) return false;
  $code = trim((string)($sku['colorCode'] ?? $sku['colorNo'] ?? ''));
  return $code === '98' || (bool)preg_match('/粉紅|PINK/', $blob);
}

function is_white_sku($sku) {
  $blob = color_blob($sku);
  if (preg_match('/白粉|白藍|白綠|白黑|白紅/', $blob)) return false;
  $code = trim((string)($sku['colorCode'] ?? $sku['colorNo'] ?? ''));
  return $code === '92' || (bool)preg_match('/白色|PUTI/', $blob);
}

function is_baifen_sku($sku) {
  $blob = color_blob($sku);
  $id = strtoupper((string)($sku['id'] ?? $sku['sku'] ?? ''));
  return (bool)preg_match('/白粉/', $blob) || strpos($id, 'OLAN70928') !== false;
}

function warehouse_of($sku) {
  $code = strtoupper(trim((string)($sku['warehouseCode'] ?? '')));
  if ($code === 'CN' || $code === 'TW' || $code === 'ID' || $code === 'PREORDER') return $code;
  $name = (string)($sku['warehouseName'] ?? $sku['warehouse'] ?? '');
  if (preg_match('/中國|中国|CN/i', $name)) return 'CN';
  if (preg_match('/印尼|ID/i', $name)) return 'ID';
  if (preg_match('/預購|预购|PREORDER/i', $name)) return 'PREORDER';
  return 'TW';
}

function atomic_write($file, $json) {
  $tmp = $file . '.tmp-white-merge-' . bin2hex(random_bytes(3));
  if (file_put_contents($tmp, $json) === false) throw new Exception('write fail ' . $file);
  if (is_file($file) && !unlink($file)) { @unlink($tmp); throw new Exception('unlink fail ' . $file); }
  if (!rename($tmp, $file)) { @unlink($tmp); throw new Exception('rename fail ' . $file); }
  return filesize($file);
}

try {
  $products = json_decode(file_get_contents($productsFile), true);
  $skus = json_decode(file_get_contents($skusFile), true);
  if (!is_array($products) || !is_array($skus)) throw new Exception('catalog json invalid');

  $now = date('c');
  $product = null;
  $productIndex = -1;
  foreach ($products as $i => $row) {
    if (($row['id'] ?? '') === 'p-olan70' || strtoupper((string)($row['code'] ?? '')) === 'OLAN70') {
      $product = $row;
      $productIndex = $i;
      break;
    }
  }
  if (!$product) throw new Exception('OLAN70 product missing');

  $olanSkus = [];
  foreach ($skus as $i => $sku) {
    if (($sku['productId'] ?? '') === 'p-olan70' || strtoupper((string)($sku['productCode'] ?? '')) === 'OLAN70') {
      $olanSkus[$i] = $sku;
    }
  }

  $byWh = ['CN' => [], 'TW' => [], 'ID' => [], 'PREORDER' => []];
  foreach ($olanSkus as $i => $sku) {
    $byWh[warehouse_of($sku)][$i] = $sku;
  }

  $mergedPink = 0;
  $fixedBaifen = 0;
  $movedStock = 0;
  $archived = [];

  foreach ($byWh as $wh => $group) {
    $whiteIdx = null;
    $pinkIdxs = [];
    $baifenIdxs = [];
    foreach ($group as $i => $sku) {
      if (is_baifen_sku($sku)) $baifenIdxs[] = $i;
      elseif (is_white_sku($sku)) $whiteIdx = $whiteIdx ?? $i;
      elseif (is_pink_sku($sku)) $pinkIdxs[] = $i;
    }
    if ($whiteIdx === null && $pinkIdxs) {
      $whiteIdx = $pinkIdxs[0];
      array_shift($pinkIdxs);
      $skus[$whiteIdx]['color'] = '白色(PUTI)';
      $skus[$whiteIdx]['colorName'] = '白色(PUTI)';
      $skus[$whiteIdx]['colorCode'] = '92';
      $skus[$whiteIdx]['colorNo'] = '92';
    }
    if ($whiteIdx !== null) {
      foreach ($pinkIdxs as $pi) {
        $pink = $skus[$pi];
        $add = max(0, (int)($pink['stock'] ?? 0));
        $skus[$whiteIdx]['stock'] = max(0, (int)($skus[$whiteIdx]['stock'] ?? 0)) + $add;
        $movedStock += $add;
        $aliases = unique_codes(array_merge(
          (array)($skus[$whiteIdx]['barcodeAliases'] ?? []),
          (array)($pink['barcodeAliases'] ?? []),
          (array)($pink['linkedBarcodes'] ?? []),
          [
            $pink['barcode'] ?? '',
            $pink['companyBarcode'] ?? '',
            $pink['legacyBarcode'] ?? '',
            $pink['id'] ?? '',
            $pink['sku'] ?? '',
            $pink['officialBarcode'] ?? '',
            $pink['labelBarcode'] ?? ''
          ]
        ));
        $skus[$whiteIdx]['barcodeAliases'] = $aliases;
        $skus[$whiteIdx]['linkedBarcodes'] = unique_codes(array_merge((array)($skus[$whiteIdx]['linkedBarcodes'] ?? []), $aliases));
        $skus[$whiteIdx]['legacySkuIds'] = unique_codes(array_merge((array)($skus[$whiteIdx]['legacySkuIds'] ?? []), [(string)($pink['id'] ?? ''), (string)($pink['sku'] ?? '')]));
        $skus[$whiteIdx]['updatedAt'] = $now;
        $skus[$pi]['stock'] = 0;
        $skus[$pi]['status'] = 'inactive';
        $skus[$pi]['archived'] = true;
        $skus[$pi]['legacyMislinked'] = true;
        $skus[$pi]['removedAt'] = $now;
        $skus[$pi]['updatedAt'] = $now;
        $skus[$pi]['whiteMergedInto'] = $skus[$whiteIdx]['id'] ?? 'OLAN70-white';
        $archived[] = $skus[$pi]['id'] ?? $pi;
        $mergedPink += 1;
      }
    }
    foreach ($baifenIdxs as $bi) {
      $sku = $skus[$bi];
      $cost = max(1, (int)round((float)($sku['currentCostTwd'] ?? $sku['cost'] ?? 393)));
      $skus[$bi]['color'] = '白粉(PUTI PINK)';
      $skus[$bi]['colorName'] = '白粉(PUTI PINK)';
      $skus[$bi]['colorCode'] = '928';
      $skus[$bi]['colorNo'] = '928';
      $newBarcode = 'OLAN7092800P' . $cost;
      $oldBarcode = (string)($sku['barcode'] ?? $sku['companyBarcode'] ?? '');
      $skus[$bi]['legacyBarcode'] = $sku['legacyBarcode'] ?? $oldBarcode;
      $skus[$bi]['barcode'] = $newBarcode;
      $skus[$bi]['companyBarcode'] = $newBarcode;
      $skus[$bi]['barcodeAliases'] = unique_codes(array_merge((array)($sku['barcodeAliases'] ?? []), [$oldBarcode, (string)($sku['id'] ?? ''), $newBarcode]));
      $skus[$bi]['updatedAt'] = $now;
      $fixedBaifen += 1;
    }
  }

  $colors = is_array($product['colors'] ?? null) ? $product['colors'] : [];
  $kept = [];
  foreach ($colors as $row) {
    $name = (string)($row['name'] ?? $row['color'] ?? $row['colorName'] ?? '');
    $code = trim((string)($row['code'] ?? $row['colorCode'] ?? ''));
    if ($code === '98' || (preg_match('/粉紅/', $name) && !preg_match('/白粉/', $name))) {
      continue;
    }
    if (preg_match('/白粉/', $name)) {
      $row['code'] = '928';
      $row['name'] = '白粉(PUTI PINK)';
      $row['color'] = '白粉(PUTI PINK)';
      $row['colorName'] = '白粉(PUTI PINK)';
    }
    if (preg_match('/白藍/', $name)) {
      $row['code'] = $row['code'] ?: '929';
      $row['name'] = '白藍(PUTI BIRU)';
      $row['color'] = '白藍(PUTI BIRU)';
      $row['colorName'] = '白藍(PUTI BIRU)';
    }
    $kept[] = $row;
  }
  $products[$productIndex]['colors'] = $kept;
  $products[$productIndex]['updatedAt'] = $now;

  $stamp = date('YmdHis');
  copy($productsFile, $productsFile . '.bak-white-merge-' . $stamp);
  copy($skusFile, $skusFile . '.bak-white-merge-' . $stamp);
  echo 'products=' . atomic_write($productsFile, json_encode($products, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "\n";
  echo 'skus=' . atomic_write($skusFile, json_encode($skus, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "\n";
  echo 'mergedPink=' . $mergedPink . "\n";
  echo 'fixedBaifen=' . $fixedBaifen . "\n";
  echo 'movedStock=' . $movedStock . "\n";
  echo 'archived=' . implode(',', $archived) . "\n";
  echo "ok\n";
} catch (Exception $e) {
  http_response_code(500);
  echo 'ERR ' . $e->getMessage() . "\n";
}

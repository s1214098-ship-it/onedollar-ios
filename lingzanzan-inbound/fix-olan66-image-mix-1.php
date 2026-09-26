<?php
if (($_GET['k'] ?? '') !== 'image-mix-20260926-1') {
  http_response_code(403);
  echo 'no';
  exit;
}
header('Content-Type: text/plain; charset=utf-8');
@ini_set('memory_limit', '1024M');
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

function atomic_write($file, $json) {
  $tmp = $file . '.tmp-image-mix-' . bin2hex(random_bytes(3));
  if (file_put_contents($tmp, $json) === false) throw new Exception('write fail ' . $file);
  if (is_file($file) && !unlink($file)) { @unlink($tmp); throw new Exception('unlink fail ' . $file); }
  if (!rename($tmp, $file)) { @unlink($tmp); throw new Exception('rename fail ' . $file); }
  return filesize($file);
}

try {
  $products = json_decode(file_get_contents($productsFile), true);
  $skus = json_decode(file_get_contents($skusFile), true);
  if (!is_array($products) || !is_array($skus)) throw new Exception('catalog json invalid');

  $stamp = date('YmdHis');
  @copy($productsFile, $productsFile . '.bak-image-mix-' . $stamp);
  @copy($skusFile, $skusFile . '.bak-image-mix-' . $stamp);

  $whiteImg = './uploads/generated/img-c221b80fd4b34bd8b20f05705acd087f4b901d24.jpg';
  $blueImg = './uploads/generated/img-88faf4070b41c0e7a3919934363bdf9c8c7693ff.jpg';
  $canonicalWhite = 'OLAN669200P414';
  $now = date('c');
  $fixedWhiteSku = 0;
  $fixedBlueSku = 0;
  $fixedProductColors = 0;

  foreach ($products as $i => $product) {
    if (!is_array($product)) continue;
    if (strtoupper((string)($product['id'] ?? '')) !== 'P-OLAN66' && strtoupper((string)($product['code'] ?? '')) !== 'OLAN66') continue;
    $colors = is_array($product['colors'] ?? null) ? $product['colors'] : [];
    foreach ($colors as $ci => $color) {
      if (!is_array($color)) continue;
      $code = strtoupper(trim((string)($color['code'] ?? $color['colorCode'] ?? '')));
      $name = (string)($color['name'] ?? $color['color'] ?? $color['colorName'] ?? '');
      if ($code === '96' || preg_match('/藍|BIRU/u', $name)) {
        $products[$i]['colors'][$ci]['image'] = $blueImg;
        $fixedProductColors++;
      }
      if ($code === '92' || (preg_match('/白/u', $name) && !preg_match('/白粉|白藍|白綠|白黑|白紅/u', $name))) {
        $products[$i]['colors'][$ci]['image'] = $whiteImg;
        $fixedProductColors++;
      }
    }
  }

  foreach ($skus as $i => $sku) {
    if (!is_array($sku)) continue;
    $productId = strtoupper((string)($sku['productId'] ?? ''));
    $id = strtoupper((string)($sku['id'] ?? $sku['sku'] ?? ''));
    $barcode = strtoupper(trim((string)($sku['barcode'] ?? $sku['companyBarcode'] ?? '')));
    $colorCode = trim((string)($sku['colorCode'] ?? $sku['colorNo'] ?? ''));
    $wh = strtoupper((string)($sku['warehouseCode'] ?? $sku['warehouse'] ?? $sku['warehouseName'] ?? ''));
    if ($productId !== 'P-OLAN66' && strpos($id, 'OLAN66') !== 0) continue;

    $isWhiteCn = ($id === 'OLAN66P33092' || $barcode === 'K4129200P414') && (strpos($wh, 'CN') !== false || strpos($wh, '中國') !== false || strpos($wh, '中国') !== false);
    if ($isWhiteCn || $barcode === 'K4129200P414') {
      $aliases = unique_codes(array_merge(
        is_array($sku['barcodeAliases'] ?? null) ? $sku['barcodeAliases'] : [],
        is_array($sku['linkedBarcodes'] ?? null) ? $sku['linkedBarcodes'] : [],
        [$barcode, $canonicalWhite, 'K4129200P414']
      ));
      $skus[$i]['barcode'] = $canonicalWhite;
      $skus[$i]['companyBarcode'] = $canonicalWhite;
      $skus[$i]['officialBarcode'] = $canonicalWhite;
      $skus[$i]['labelBarcode'] = $canonicalWhite;
      $skus[$i]['legacyBarcode'] = 'K4129200P414';
      $skus[$i]['barcodeAliases'] = $aliases;
      $skus[$i]['linkedBarcodes'] = $aliases;
      $skus[$i]['colorImage'] = $whiteImg;
      $skus[$i]['lastArrivalImage'] = $whiteImg;
      $skus[$i]['updatedAt'] = $now;
      $fixedWhiteSku++;
    }

    $isBlueCn = ($id === 'OLAN66P33096' || $colorCode === '96') && (strpos($wh, 'CN') !== false || strpos($wh, '中國') !== false || strpos($wh, '中国') !== false);
    if ($isBlueCn) {
      $skus[$i]['colorImage'] = $blueImg;
      $skus[$i]['updatedAt'] = $now;
      $fixedBlueSku++;
    }
  }

  $productBytes = atomic_write($productsFile, json_encode($products, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  $skuBytes = atomic_write($skusFile, json_encode($skus, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  echo "fixedWhiteSku=$fixedWhiteSku\n";
  echo "fixedBlueSku=$fixedBlueSku\n";
  echo "fixedProductColors=$fixedProductColors\n";
  echo "products=$productBytes\n";
  echo "skus=$skuBytes\n";
  echo "ok\n";
} catch (Exception $e) {
  http_response_code(500);
  echo 'ERR ' . $e->getMessage() . "\n";
}

<?php
if (($_GET['k'] ?? '') !== 'color-photo-20260926-1') {
  http_response_code(403);
  echo 'no';
  exit;
}
header('Content-Type: text/plain; charset=utf-8');
@ini_set('memory_limit', '1024M');
$root = __DIR__;
$data = $root . DIRECTORY_SEPARATOR . 'data';
$skusFile = $data . DIRECTORY_SEPARATOR . 'skus.json';
$receiptsFile = $data . DIRECTORY_SEPARATOR . 'purchase-receiving-documents.json';

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
  $tmp = $file . '.tmp-color-photo-' . bin2hex(random_bytes(3));
  if (file_put_contents($tmp, $json) === false) throw new Exception('write fail ' . $file);
  if (is_file($file) && !unlink($file)) { @unlink($tmp); throw new Exception('unlink fail ' . $file); }
  if (!rename($tmp, $file)) { @unlink($tmp); throw new Exception('rename fail ' . $file); }
  return filesize($file);
}

function sku_blob($sku) {
  return strtoupper(trim((string)($sku['colorName'] ?? $sku['color'] ?? '')) . ' ' . trim((string)($sku['id'] ?? $sku['sku'] ?? '')) . ' ' . trim((string)($sku['barcode'] ?? $sku['companyBarcode'] ?? '')));
}

try {
  $skus = json_decode(file_get_contents($skusFile), true);
  $receipts = is_file($receiptsFile) ? json_decode(file_get_contents($receiptsFile), true) : [];
  if (!is_array($skus)) throw new Exception('skus json invalid');
  if (!is_array($receipts)) $receipts = [];

  $fixedBailan = 0;
  $fixedBaifenBarcode = 0;
  $fixedReceiptLines = 0;
  $now = date('c');

  foreach ($skus as $i => $sku) {
    if (!is_array($sku)) continue;
    $productId = (string)($sku['productId'] ?? '');
    $productCode = strtoupper((string)($sku['productCode'] ?? ''));
    $id = strtoupper((string)($sku['id'] ?? $sku['sku'] ?? ''));
    if ($productId !== 'p-olan70' && $productCode !== 'OLAN70' && strpos($id, 'OLAN70') !== 0) continue;
    $blob = sku_blob($sku);
    if (preg_match('/白藍|OLAN70929/', $blob)) {
      $skus[$i]['color'] = '白藍(PUTI BIRU)';
      $skus[$i]['colorName'] = '白藍(PUTI BIRU)';
      $skus[$i]['colorCode'] = '929';
      $skus[$i]['colorNo'] = '929';
      $skus[$i]['updatedAt'] = $now;
      $fixedBailan++;
    }
    if (preg_match('/白粉|OLAN70928/', $blob)) {
      $canonical = 'OLAN7092800P393';
      $old = strtoupper(trim((string)($sku['companyBarcode'] ?? $sku['barcode'] ?? '')));
      $aliases = unique_codes(array_merge(
        is_array($sku['barcodeAliases'] ?? null) ? $sku['barcodeAliases'] : [],
        is_array($sku['linkedBarcodes'] ?? null) ? $sku['linkedBarcodes'] : [],
        [$old, 'OLAN709800P355', $canonical]
      ));
      $skus[$i]['barcode'] = $canonical;
      $skus[$i]['companyBarcode'] = $canonical;
      $skus[$i]['officialBarcode'] = $canonical;
      $skus[$i]['labelBarcode'] = $canonical;
      $skus[$i]['barcodeAliases'] = $aliases;
      $skus[$i]['linkedBarcodes'] = $aliases;
      $skus[$i]['color'] = '白粉(PUTI PINK)';
      $skus[$i]['colorName'] = '白粉(PUTI PINK)';
      $skus[$i]['colorCode'] = '928';
      $skus[$i]['colorNo'] = '928';
      $skus[$i]['updatedAt'] = $now;
      $fixedBaifenBarcode++;
    }
  }

  foreach ($receipts as $ri => $receipt) {
    if (!is_array($receipt) || !isset($receipt['receiptLines']) || !is_array($receipt['receiptLines'])) continue;
    foreach ($receipt['receiptLines'] as $li => $line) {
      if (!is_array($line)) continue;
      $skuId = strtoupper((string)($line['skuId'] ?? $line['sku'] ?? ''));
      $color = (string)($line['color'] ?? $line['colorName'] ?? '');
      $barcode = strtoupper((string)($line['barcode'] ?? $line['companyBarcode'] ?? ''));
      $isBaifen = preg_match('/白粉/', $color) || strpos($skuId, 'OLAN70928') !== false;
      if (!$isBaifen) continue;
      if ($barcode === 'OLAN7092800P393') continue;
      $aliases = unique_codes(array_merge(
        is_array($line['barcodeAliases'] ?? null) ? $line['barcodeAliases'] : [],
        [$barcode, 'OLAN709800P355', 'OLAN7092800P393']
      ));
      $receipts[$ri]['receiptLines'][$li]['legacyBarcode'] = $barcode !== '' ? $barcode : 'OLAN709800P355';
      $receipts[$ri]['receiptLines'][$li]['barcode'] = 'OLAN7092800P393';
      $receipts[$ri]['receiptLines'][$li]['companyBarcode'] = 'OLAN7092800P393';
      $receipts[$ri]['receiptLines'][$li]['barcodeAliases'] = $aliases;
      $fixedReceiptLines++;
    }
  }

  $skuBytes = atomic_write($skusFile, json_encode($skus, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  $recBytes = atomic_write($receiptsFile, json_encode($receipts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  echo "fixedBailan=$fixedBailan\n";
  echo "fixedBaifenBarcode=$fixedBaifenBarcode\n";
  echo "fixedReceiptLines=$fixedReceiptLines\n";
  echo "skus=$skuBytes\n";
  echo "receipts=$recBytes\n";
  echo "ok\n";
} catch (Exception $e) {
  http_response_code(500);
  echo 'ERR ' . $e->getMessage() . "\n";
}

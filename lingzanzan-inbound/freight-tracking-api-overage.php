<?php
// LZ_RECV_OVERAGE_20260927: 實收多於原預報時，應到跟著實收走，才可以正式入庫。
$actual = $lineVoided ? 0 : (int)$actualRaw;
if (!$lineVoided && $expected < $actual) {
    $expected = $actual;
}
$variantActual = max(0, (int)($savedLines[$variantIndex]['actualQty'] ?? 0));
if ($variantActual > $variantQty) {
    $variantQty = $variantActual;
    $variantList[$variantIndex]['quantity'] = $variantActual;
}

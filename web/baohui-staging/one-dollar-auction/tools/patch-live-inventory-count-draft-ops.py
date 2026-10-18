#!/usr/bin/env python3
"""Skip opportunistic product/unit writes while a 盤點草稿 is being saved.

save_inventory_count POST currently dies at startup because pricing/shipping
defaults and serial backfill still call write_data('products'), and write_data
throws「未核准盤點不得寫入正式庫存」. The scanner then shows the generic
safe-read/write stop page. Count drafts must not rewrite official stock.
"""

from __future__ import annotations

from pathlib import Path


PATCHES = [
    (
        """$products = read_data('products');
$productPricingRulesApplied = ops_apply_product_default_pricing_rules($products);
$productShippingDefaultsApplied = ops_apply_product_shipping_defaults($products);
if ($productPricingRulesApplied > 0 || $productShippingDefaultsApplied > 0) write_data('products', $products);
""",
        """$countDraftOnly = in_array((string)($_POST['action'] ?? ''), ['save_inventory_count', 'update_inventory_count_draft', 'return_inventory_count'], true);
$products = read_data('products');
$productPricingRulesApplied = ops_apply_product_default_pricing_rules($products);
$productShippingDefaultsApplied = ops_apply_product_shipping_defaults($products);
if (!$countDraftOnly && ($productPricingRulesApplied > 0 || $productShippingDefaultsApplied > 0)) write_data('products', $products);
""",
    ),
    (
        """if ($productMasterSerialsAdded > 0) {
    write_data('products', $products);
    if (function_exists('ops_write_product_index_cache')) ops_write_product_index_cache($products);
}
$inventorySerialsAdded = 0;
""",
        """if (!$countDraftOnly && $productMasterSerialsAdded > 0) {
    write_data('products', $products);
    if (function_exists('ops_write_product_index_cache')) ops_write_product_index_cache($products);
}
$inventorySerialsAdded = 0;
""",
    ),
    (
        """if ($inventorySerialsAdded > 0) write_data('inventory_units', $inventoryUnits);
$scheduleLifecycleSummary = schedule_lifecycle_normalize($schedules);
if (!empty($scheduleLifecycleSummary['changed'])) write_data('schedules', $schedules);
$countDraftOnly = in_array((string)($_POST['action'] ?? ''), ['save_inventory_count', 'update_inventory_count_draft', 'return_inventory_count'], true);
$stockReservationReconcile = $countDraftOnly ? ['changed' => 0] : ops_product_index_reconcile_stock_reserved($products, $schedules);
""",
        """if (!$countDraftOnly && $inventorySerialsAdded > 0) write_data('inventory_units', $inventoryUnits);
$scheduleLifecycleSummary = schedule_lifecycle_normalize($schedules);
if (!empty($scheduleLifecycleSummary['changed'])) write_data('schedules', $schedules);
$stockReservationReconcile = $countDraftOnly ? ['changed' => 0] : ops_product_index_reconcile_stock_reserved($products, $schedules);
""",
    ),
]


def apply(text: str) -> str:
    missing = []
    for old, new in PATCHES:
        if old not in text:
            missing.append(old[:180].replace("\n", " / "))
            continue
        if text.count(old) != 1:
            missing.append(f"count={text.count(old)} :: " + old[:180].replace("\n", " / "))
            continue
        text = text.replace(old, new, 1)
    if missing:
        raise SystemExit("patch targets missing or not unique:\n- " + "\n- ".join(missing))
    return text


def main() -> None:
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument("path")
    args = parser.parse_args()
    path = Path(args.path)
    original = path.read_text(encoding="utf-8", errors="surrogateescape")
    updated = apply(original)
    path.write_text(updated, encoding="utf-8", errors="surrogateescape")
    print(f"patched {path} bytes {len(original.encode('utf-8'))} -> {len(updated.encode('utf-8'))}")


if __name__ == "__main__":
    main()

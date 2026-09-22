<?php
declare(strict_types=1);

$failed = 0;
function expect($ok, string $msg): void
{
    global $failed;
    if ($ok) {
        echo "ok  $msg\n";
        return;
    }
    $failed++;
    echo "FAIL  $msg\n";
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'one-dollar-auction' . DIRECTORY_SEPARATOR . 'post-reply-sets.php';

$product = [
    'id' => 'SE269P869700',
    'title' => '秋葉原2026款金屬筆記本',
    'color' => '銀色',
    'size' => 'NO SIZE',
    'spec' => '',
    'description_source' => "🔥🔥🔥【SE269｜秋葉原 CHOSEAL 合金筆電支架｜7檔高度調節・折疊便攜・鏤空散熱・防滑穩固】🔥🔥🔥\n\n💻 **CHOSEAL 秋葉原 筆記型電腦支架**\n✨ **升級合金材質**\n📐 **7檔高度調節**\n🌀 **鏤空散熱設計**",
    'description' => "🔥🔥🔥【**SE269｜CHOSEAL・GIÁ ĐỠ LAPTOP HỢP KIM・7 MỨC ĐỘ CAO・GẤP GỌN・TẢN NHIỆT・CHỐNG TRƯỢT**】🔥🔥🔥\n\n💻 **Giá đỡ laptop**\n✨ **Chất liệu hợp kim nâng cấp theo hình sản phẩm**\n💻 Laptop\n⬇️\n🔥 **SE269**\n＋\n🌀 **Đáy thoáng**",
];
$schedule = [
    'id' => 'sch_se269_demo',
    'product_id' => 'SE269P869700',
    'product_title' => '秋葉原2026款金屬筆記本',
    'product_serial' => '2A0C5E',
    'product_serial_suffixes' => ['2A0C5E'],
    'product_description' => $product['description'],
    'publish_at' => '2026-09-22 16:30',
    'close_at' => '2026-09-22 23:59',
    'quantity' => 1,
    'post_set_id' => 'auction_pirate',
];
$sets = default_post_reply_sets();

$broken = post_reply_bilingual_parts($schedule['product_description']);
expect(
    strpos($broken['zh'], '合金筆電支架') === false,
    'legacy split of Vietnamese-only copy does not recover Traditional Chinese 解說'
);
expect(
    strpos($broken['zh'], 'Laptop') !== false || strpos($broken['zh'], 'SE269') !== false,
    'legacy split leaves English/emoji fragments in the Chinese bucket'
);

$parts = post_reply_pick_description_parts($schedule, $product);
expect(strpos($parts['zh'], '合金筆電支架') !== false, '繁體解說 comes from description_source even when the schedule only snapshotted Vietnamese');
expect(strpos($parts['zh'], 'Giá đỡ laptop') === false, 'Traditional Chinese section does not swallow Vietnamese body');
expect(strpos($parts['vi'], 'Giá đỡ laptop') !== false, '越南文 comes from the dedicated description field');
expect(strpos($parts['vi'], '合金筆電支架') === false, 'Vietnamese section does not swallow Traditional Chinese 解說');

$tokens = post_reply_tokens($schedule, $product);
expect(strpos($tokens['desc_zh'], '合金筆電支架') !== false, 'desc_zh keeps the Traditional Chinese 解說');
expect(strpos($tokens['desc_vi'], 'Giá đỡ laptop') !== false, 'desc_vi keeps the Vietnamese listing copy');
expect(strpos($tokens['desc_zh'], 'Laptop') === false || strpos($tokens['desc_zh'], '合金筆電支架') !== false, 'desc_zh is not just leftover Laptop fragments');

$listing = render_post_reply_listing($schedule, $product, $sets, 'auction_pirate');
expect(strpos($listing, '2A0C5E') !== false, '上架文案 still shows 產品序號後六碼');
expect(strpos($listing, '合金筆電支架') !== false, '上架文案 includes Traditional Chinese 解說');
expect(strpos($listing, 'Giá đỡ laptop') !== false, '上架文案 includes Vietnamese copy');
expect(strpos($listing, '7檔高度調節') !== false, '上架文案 keeps Chinese feature lines');
expect(strpos($listing, 'Chất liệu hợp kim') !== false, '上架文案 keeps Vietnamese feature lines');

$snapshotted = $schedule;
$snapshotted['product_description_source'] = "🔥 排程快照繁體解說：合金筆電支架特別版";
$snapParts = post_reply_pick_description_parts($snapshotted, $product);
expect(strpos($snapParts['zh'], '排程快照繁體解說') !== false, 'schedule product_description_source wins over live product');

$legacyMixed = post_reply_pick_description_parts(
    ['product_description' => "合金筆電支架，7檔高度調節\nGiá đỡ laptop hợp kim"],
    ['description' => '', 'description_source' => '']
);
expect(strpos($legacyMixed['zh'], '合金筆電支架') !== false, 'legacy mixed blob still yields Chinese when no dedicated ZH field exists');
expect(strpos($legacyMixed['vi'], 'Giá đỡ laptop') !== false, 'legacy mixed blob still yields Vietnamese');

$viOnly = post_reply_pick_description_parts(
    ['product_description' => "💻 Laptop\n⬇️\n🔥 **SE269**\n＋\n💻 **Giá đỡ laptop**"],
    ['description' => '', 'description_source' => '']
);
expect(!post_reply_has_chinese($viOnly['zh']), 'Vietnamese-only copy with English leftovers is not treated as 繁體解說');
expect(strpos($viOnly['vi'], 'Giá đỡ laptop') !== false, 'Vietnamese-only copy still keeps the Vietnamese body');

if ($failed > 0) {
    fwrite(STDERR, "$failed failed\n");
    exit(1);
}
echo "all bilingual listing tests passed\n";

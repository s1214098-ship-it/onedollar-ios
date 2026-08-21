<?php

function product_rule_description_marker(): string
{
    return '【規格說明】';
}

function product_rule_description_min_similarity(): float
{
    return 90.0;
}

function product_match_strpos($haystack, $needle)
{
    $haystack = (string)$haystack;
    $needle = (string)$needle;
    if ($needle === '') return 0;
    if (function_exists('mb_strpos')) return mb_strpos($haystack, $needle, 0, 'UTF-8');
    $pos = strpos($haystack, $needle);
    return $pos === false ? false : $pos;
}

function product_match_substr($value, $start, $length = null): string
{
    $value = (string)$value;
    if (function_exists('mb_substr')) {
        return $length === null ? (string)mb_substr($value, (int)$start, null, 'UTF-8') : (string)mb_substr($value, (int)$start, (int)$length, 'UTF-8');
    }
    return $length === null ? substr($value, (int)$start) : substr($value, (int)$start, (int)$length);
}

function product_match_strlen($value): int
{
    $value = (string)$value;
    if (function_exists('mb_strlen')) return (int)mb_strlen($value, 'UTF-8');
    return strlen($value);
}

function product_match_stripos($haystack, $needle)
{
    $haystack = (string)$haystack;
    $needle = (string)$needle;
    if ($needle === '') return 0;
    if (function_exists('mb_stripos')) return mb_stripos($haystack, $needle, 0, 'UTF-8');
    $pos = stripos($haystack, $needle);
    return $pos === false ? false : $pos;
}

function product_match_normalize($value): string
{
    $value = trim((string)$value);
    if ($value === '') return '';
    if (function_exists('mb_strtoupper')) $value = mb_strtoupper($value, 'UTF-8');
    else $value = strtoupper($value);
    $value = strtr($value, [
        '－' => '-', '—' => '-', '–' => '-', '＿' => '_', '　' => '',
        '（' => '(', '）' => ')',
    ]);
    $value = preg_replace('/\((?:S|N|全新|二手|新品|中古)\)/u', '', $value) ?? $value;
    $value = preg_replace('/\s+/u', '', $value) ?? $value;
    return $value;
}

function product_match_brand_aliases(): array
{
    return [
        '華碩' => 'ASUS',
        '技嘉' => 'GIGABYTE',
        '微星' => 'MSI',
        '英特爾' => 'INTEL',
        'INTEL' => 'INTEL',
        '金士頓' => 'KINGSTON',
        'KINGSTON' => 'KINGSTON',
        '威剛' => 'ADATA',
        'ADATA' => 'ADATA',
        '創見' => 'TRANSCEND',
        '美光' => 'MICRON',
        'MICRON' => 'MICRON',
        'CRUCIAL' => 'MICRON',
        '三星' => 'SAMSUNG',
        '十銓' => 'TEAMGROUP',
        'TEAM' => 'TEAMGROUP',
        'TEAMGROUP' => 'TEAMGROUP',
        '超微' => 'AMD',
        '巨蟒' => 'ANACOMDA',
        'ANACOMDA巨蟒' => 'ANACOMDA',
        'DELL戴爾' => 'DELL',
        '戴爾' => 'DELL',
        '微軟' => 'MICROSOFT',
        'SANDISK' => 'SANDISK',
        'EVGA' => 'EVGA',
        'LEMEL' => 'LEMEL',
        'GIGABYTE' => 'GIGABYTE',
        'ASUS' => 'ASUS',
        'MSI' => 'MSI',
        'AMD' => 'AMD',
        'NVIDIA' => 'NVIDIA',
    ];
}

function product_match_brand_key($value): string
{
    $raw = trim((string)$value);
    if ($raw === '' || $raw === '不指定品牌') return '';
    $normalized = product_match_normalize($raw);
    if ($normalized === '') return '';
    $aliases = product_match_brand_aliases();
    if (isset($aliases[$normalized])) return $aliases[$normalized];
    foreach ($aliases as $alias => $key) {
        $aliasNorm = product_match_normalize($alias);
        if ($aliasNorm !== '' && (strpos($normalized, $aliasNorm) !== false || strpos($aliasNorm, $normalized) !== false)) {
            return $key;
        }
    }
    return $normalized;
}

function product_match_similarity($left, $right): float
{
    $a = product_match_normalize($left);
    $b = product_match_normalize($right);
    if ($a === '' || $b === '') return 0.0;
    if ($a === $b) return 100.0;
    similar_text($a, $b, $percent);
    $percent = (float)$percent;
    $short = strlen($a) <= strlen($b) ? $a : $b;
    $long = strlen($a) > strlen($b) ? $a : $b;
    if (strlen($short) >= 6 && strpos($long, $short) !== false) {
        $extra = strlen($long) - strlen($short);
        $contain = $extra <= 2 ? 100.0 : max(90.0, 100.0 - ($extra * 0.4));
        if ($contain > $percent) $percent = $contain;
    }
    return round($percent, 2);
}

function product_match_strip_noise($value): string
{
    $value = trim((string)$value);
    if ($value === '') return '';
    $value = preg_replace('/\((?:S|N|全新|二手|新品|中古)\)/u', ' ', $value) ?? $value;
    $value = preg_replace('/上網註冊[^／\/\-]*保固?/u', '', $value) ?? $value;
    $value = preg_replace('/盒裝/u', '', $value) ?? $value;
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return trim($value, " \t\n\r\0\x0B-／/");
}

function product_match_extract_model_token($text): string
{
    $text = product_match_strip_noise($text);
    if ($text === '') return '';
    $patterns = [
        '/\b(?:DUAL|PRIME|TUF|ROG|STRIX|AORUS|GAMING|WINDFORCE|EAGLE|VENTUS|OC)?-?(?:GTX|RTX|RX)-?\d{3,4}[A-Z0-9\-]*\b/iu',
        '/\bGV-[A-Z0-9\-]+\b/iu',
        '/\b(?:I[3579]|R[3579]|RYZEN(?:THREADRIPPER)?)\s*-?\s*\d{3,5}[A-Z]?\b/iu',
        '/\b(?:DDR[2345])\s*\d{3,5}\s*\d+(?:\.\d+)?G(?:B)?(?:\(\d+G(?:B)?\*\d+\))?/iu',
        '/\b[A-Z]{2,}[A-Z0-9]*-[A-Z0-9\-]{4,}\b/iu',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $match)) {
            return trim((string)$match[0]);
        }
    }
    return '';
}

function product_match_haystack(array $row): string
{
    return trim(implode(' ', array_filter([
        $row['title'] ?? '',
        $row['product_name'] ?? '',
        $row['name'] ?? '',
        $row['model'] ?? '',
        $row['spec'] ?? '',
        $row['category_spec'] ?? '',
        $row['sku'] ?? '',
    ], function ($value) {
        return trim((string)$value) !== '';
    })));
}

function product_match_extract_part_number($text): string
{
    $text = product_match_strip_noise($text);
    if ($text === '') return '';
    if (preg_match('/\(([A-Z]{2,}[A-Z0-9.\/\-_]{4,})\)/i', $text, $match)) {
        return rtrim((string)$match[1], '.');
    }
    if (preg_match('/\b([A-Z]{2,}\d[A-Z0-9.\/\-_]{4,})\b/i', $text, $match)) {
        return rtrim((string)$match[1], '.');
    }
    return '';
}

function product_match_spec_fingerprint($text): array
{
    $raw = (string)$text;
    $norm = product_match_normalize($raw);
    $ddr = '';
    if (preg_match('/DDR([2345])/i', $norm, $match)) $ddr = 'DDR' . $match[1];
    $speed = 0;
    if (preg_match('/DDR[2345](\d{3,5})/i', $norm, $match)) $speed = (int)$match[1];
    elseif (preg_match('/\b(1066|1333|1600|1866|2133|2400|2666|2800|2933|3000|3200|3600|4000|4800|5200|5600|6000|6400|7200|8000)\b/', $raw, $match)) $speed = (int)$match[1];
    $size = 0;
    if (preg_match('/(\d+(?:\.\d+)?)G(?:B)?(?:\(\d+G(?:B)?\*\d+\))?/i', $norm, $match)) $size = (int)round((float)$match[1]);
    $nb = preg_match('/NB|筆電|筆記型|SODIMM|SO-DIMM/i', $raw) === 1;
    $desktop = preg_match('/桌上|PC用|PC RAM|DIMM/i', $raw) === 1 && !$nb;
    $gpu = '';
    if (preg_match('/(GTX|RTX|RX)-?(\d{3,4})/i', $norm, $match)) $gpu = strtoupper($match[1] . $match[2]);
    $cpu = '';
    if (preg_match('/(I[3579]|R[3579])-?(\d{4,5}[A-Z]?)/i', $norm, $match)) $cpu = strtoupper($match[1] . $match[2]);
    return [
        'ddr' => $ddr,
        'speed' => $speed,
        'size_g' => $size,
        'nb' => $nb,
        'desktop' => $desktop,
        'gpu' => $gpu,
        'cpu' => $cpu,
    ];
}

function product_match_spec_conflict($left, $right): bool
{
    $a = product_match_spec_fingerprint($left);
    $b = product_match_spec_fingerprint($right);
    if ($a['ddr'] !== '' && $b['ddr'] !== '' && $a['ddr'] !== $b['ddr']) return true;
    if ($a['speed'] > 0 && $b['speed'] > 0 && $a['speed'] !== $b['speed']) return true;
    if ($a['size_g'] > 0 && $b['size_g'] > 0 && $a['size_g'] !== $b['size_g']) return true;
    if ($a['ddr'] !== '' && $b['ddr'] !== '') {
        if ($a['nb'] !== $b['nb'] && ($a['nb'] || $b['nb'])) return true;
        if ($a['desktop'] !== $b['desktop'] && ($a['desktop'] || $b['desktop'])) return true;
    }
    if ($a['gpu'] !== '' && $b['gpu'] !== '' && $a['gpu'] !== $b['gpu']) return true;
    if ($a['cpu'] !== '' && $b['cpu'] !== '') {
        $aCore = preg_replace('/[A-Z]$/', '', $a['cpu']);
        $bCore = preg_replace('/[A-Z]$/', '', $b['cpu']);
        if ($aCore !== $bCore && product_match_similarity($a['cpu'], $b['cpu']) < product_rule_description_min_similarity()) return true;
    }
    return false;
}

function product_model_is_generic($model): bool
{
    $norm = product_match_normalize($model);
    if ($norm === '') return true;
    return preg_match('/^DDR[2345]\d{3,5}\d+G(?:B)?$/i', $norm) === 1
        || preg_match('/^(GTX|RTX|RX)\d{3,4}$/i', $norm) === 1;
}

function product_match_extract_model(array $row): string
{
    $explicit = trim((string)($row['model'] ?? ''));
    if ($explicit !== '') return $explicit;
    $hay = product_match_haystack($row);
    $part = product_match_extract_part_number($hay);
    $token = product_match_extract_model_token($hay);
    if ($part !== '' && (product_model_is_generic($token) || $token === '')) return $part;
    if ($token !== '') return $token;
    $cleaned = product_match_strip_noise($hay);
    $brand = trim((string)($row['category_brand'] ?? ($row['brand'] ?? '')));
    if ($brand !== '' && $cleaned !== '') {
        $brandPattern = '/' . preg_quote($brand, '/') . '/iu';
        $withoutBrand = trim((string)preg_replace($brandPattern, '', $cleaned));
        if ($withoutBrand !== '') $cleaned = $withoutBrand;
    }
    return $cleaned;
}

function product_match_extract_brand(array $row): string
{
    foreach (['category_brand', 'brand'] as $key) {
        $brand = product_match_brand_key($row[$key] ?? '');
        if ($brand !== '') return $brand;
    }
    $hay = trim((string)($row['title'] ?? ($row['name'] ?? ($row['product_name'] ?? ''))));
    if ($hay === '') return '';
    foreach (product_match_brand_aliases() as $alias => $key) {
        if ($alias === '') continue;
        if (product_match_stripos($hay, $alias) !== false) return $key;
    }
    return '';
}

function product_catalog_item_model(array $item): string
{
    $explicit = trim((string)($item['model'] ?? ''));
    if ($explicit !== '') return $explicit;
    $name = product_match_strip_noise($item['name'] ?? ($item['title'] ?? ''));
    $token = product_match_extract_model_token($name);
    if ($token !== '') return $token;
    foreach ((array)($item['specs'] ?? []) as $spec) {
        if (!is_array($spec)) continue;
        $label = trim((string)($spec['label'] ?? ''));
        $value = trim((string)($spec['value'] ?? ''));
        if ($value === '') continue;
        if (preg_match('/型號|處理器|繪圖處理器|品名/u', $label)) {
            $fromSpec = product_match_extract_model_token($value);
            if ($fromSpec !== '') return $fromSpec;
            if ($value !== '') return $value;
        }
    }
    $brand = trim((string)($item['brand'] ?? ''));
    if ($brand !== '' && $name !== '') {
        $withoutBrand = trim((string)preg_replace('/' . preg_quote($brand, '/') . '/iu', '', $name, 1));
        if ($withoutBrand !== '') $name = $withoutBrand;
    }
    $name = preg_replace('/(顯示卡|顯卡|主機板|桌上型記憶體|記憶體|固態硬碟|SSD|傳統硬碟|HDD|處理器|CPU|螢幕|機殼|散熱器|風扇)/u', '', $name) ?? $name;
    return trim($name, " \t\n\r\0\x0B-／/");
}

function product_model_match_score(array $product, array $candidate): float
{
    $productHay = product_match_haystack($product);
    $candidateHay = product_match_haystack($candidate);
    if ($candidateHay === '') {
        $candidateHay = trim((string)($candidate['name'] ?? ($candidate['title'] ?? '')));
    }
    if (product_match_spec_conflict($productHay, $candidateHay)) return 0.0;

    $productPart = product_match_extract_part_number($productHay);
    $candidatePart = product_match_extract_part_number($candidateHay);
    if ($productPart !== '' && $candidatePart !== '') {
        $partScore = product_match_similarity($productPart, $candidatePart);
        if ($partScore < product_rule_description_min_similarity()) return 0.0;
        return $partScore;
    }

    $productModel = product_match_extract_model($product);
    $candidateModel = product_catalog_item_model($candidate);
    if ($candidateModel === '') $candidateModel = product_match_extract_model($candidate);
    $score = product_match_similarity($productModel, $candidateModel);
    $candidateName = trim((string)($candidate['name'] ?? ($candidate['title'] ?? '')));
    if ($productModel !== '' && $candidateName !== '') {
        $contained = product_match_similarity($productModel, $candidateName);
        if ($contained > $score) $score = $contained;
    }
    $sku = trim((string)($candidate['sku'] ?? ($candidate['id'] ?? '')));
    if ($productModel !== '' && $sku !== '') {
        $skuScore = product_match_similarity($productModel, $sku);
        if ($skuScore > $score) $score = $skuScore;
    }
    if ($productPart !== '' && $candidateName !== '') {
        $partInName = product_match_similarity($productPart, $candidateName);
        if ($partInName > $score) $score = $partInName;
    }
    if (product_model_is_generic($productModel) && product_brand_match_score($product, $candidate) < product_rule_description_min_similarity()) {
        return 0.0;
    }
    return $score;
}

function product_brand_match_score(array $product, array $candidate): float
{
    return product_match_similarity(
        product_match_extract_brand($product),
        product_match_extract_brand($candidate)
    );
}

function product_can_attach_rule_description(array $product, array $candidate, $minSimilarity = null): bool
{
    $min = $minSimilarity === null ? product_rule_description_min_similarity() : (float)$minSimilarity;
    $model = product_match_extract_model($product);
    if (product_match_normalize($model) === '') return false;
    return product_model_match_score($product, $candidate) >= $min;
}

function product_format_catalog_rule_description(array $item, array $product = [], $similarity = 0): string
{
    $lines = [product_rule_description_marker()];
    $name = trim((string)($item['name'] ?? ($item['title'] ?? '')));
    $brand = trim((string)($item['brand'] ?? product_match_extract_brand($product)));
    $model = product_catalog_item_model($item);
    if ($model === '') $model = product_match_extract_model($product);
    if ($name !== '') $lines[] = '對照品名：' . $name;
    if ($brand !== '') $lines[] = '廠牌：' . $brand;
    if ($model !== '') $lines[] = '型號：' . $model;
    $type = trim((string)($item['category'] ?? ($item['subcategory'] ?? '')));
    if ($type !== '') $lines[] = '類別：' . $type;
    $specRows = [];
    foreach ((array)($item['specs'] ?? []) as $spec) {
        if (!is_array($spec)) continue;
        $label = trim((string)($spec['label'] ?? ''));
        $value = trim((string)($spec['value'] ?? ''));
        if ($label === '' || $value === '') continue;
        $specRows[] = $label . '：' . $value;
    }
    if (!$specRows) {
        $specText = trim((string)($item['spec'] ?? ''));
        if ($specText !== '') {
            foreach (preg_split('/\s*\/\s*/u', $specText) as $part) {
                $part = trim((string)$part);
                if ($part !== '') $specRows[] = $part;
            }
        }
    }
    foreach ($specRows as $row) $lines[] = $row;
    if (count($lines) <= 1) return '';
    return implode("\n", $lines);
}

function product_strip_rule_description($text): string
{
    $text = (string)$text;
    $marker = product_rule_description_marker();
    $pos = product_match_strpos($text, $marker);
    if ($pos === false) return trim($text);
    return trim(product_match_substr($text, 0, (int)$pos));
}

function product_merge_rule_description($existing, $block): string
{
    $block = trim((string)$block);
    if ($block === '') return trim((string)$existing);
    $kept = product_strip_rule_description($existing);
    if ($kept === '') return $block;
    return $kept . "\n\n" . $block;
}

function product_row_available_qty(array $product): int
{
    $total = (int)($product['stock_total'] ?? 0);
    $reserved = (int)($product['stock_reserved'] ?? 0) + (int)($product['cloud_auction_reserved'] ?? 0);
    $sold = (int)($product['stock_sold'] ?? 0);
    return max(0, $total - $reserved - $sold);
}

function product_rule_description_source_usable(array $row): bool
{
    $text = trim((string)($row['description'] ?? ''));
    if ($text === '') return false;
    if (product_match_strpos($text, product_rule_description_marker()) !== false) return true;
    return product_match_strlen($text) >= 12;
}

function product_best_catalog_rule_match(array $product, array $catalogItems, $minSimilarity = null): ?array
{
    $min = $minSimilarity === null ? product_rule_description_min_similarity() : (float)$minSimilarity;
    $best = null;
    $bestModel = -1.0;
    $bestBrand = -1.0;
    foreach ($catalogItems as $item) {
        if (!is_array($item)) continue;
        $modelScore = product_model_match_score($product, $item);
        if ($modelScore < $min) continue;
        $brandScore = product_brand_match_score($product, $item);
        if ($best === null || $modelScore > $bestModel || ($modelScore === $bestModel && $brandScore > $bestBrand)) {
            $best = $item;
            $bestModel = $modelScore;
            $bestBrand = $brandScore;
        }
    }
    if ($best === null) return null;
    return [
        'item' => $best,
        'model_similarity' => $bestModel,
        'brand_similarity' => $bestBrand,
        'block' => product_format_catalog_rule_description($best, $product, $bestModel),
        'source' => 'catalog',
    ];
}

function product_best_sibling_rule_match(array $product, array $products, $minSimilarity = null): ?array
{
    $min = $minSimilarity === null ? product_rule_description_min_similarity() : (float)$minSimilarity;
    $productId = (string)($product['id'] ?? '');
    $best = null;
    $bestModel = -1.0;
    $bestBrand = -1.0;
    foreach ($products as $candidate) {
        if (!is_array($candidate)) continue;
        if ((string)($candidate['id'] ?? '') === $productId) continue;
        if (!product_rule_description_source_usable($candidate)) continue;
        if (product_row_available_qty($candidate) <= 0) continue;
        $modelScore = product_model_match_score($product, $candidate);
        if ($modelScore < $min) continue;
        $brandScore = product_brand_match_score($product, $candidate);
        if ($best === null || $modelScore > $bestModel || ($modelScore === $bestModel && $brandScore > $bestBrand)) {
            $best = $candidate;
            $bestModel = $modelScore;
            $bestBrand = $brandScore;
        }
    }
    if ($best === null) return null;
    $block = trim((string)($best['description'] ?? ''));
    if (product_match_strpos($block, product_rule_description_marker()) === false) {
        $block = product_rule_description_marker() . "\n" . $block;
    }
    return [
        'item' => $best,
        'model_similarity' => $bestModel,
        'brand_similarity' => $bestBrand,
        'block' => $block,
        'source' => 'sibling',
    ];
}

function product_apply_rule_descriptions(array $products, array $catalogItems, array $options = []): array
{
    $min = isset($options['min_similarity']) ? (float)$options['min_similarity'] : product_rule_description_min_similarity();
    $onlyInStock = !array_key_exists('only_in_stock', $options) || !empty($options['only_in_stock']);
    $idFilter = [];
    foreach ((array)($options['product_ids'] ?? []) as $id) {
        $id = trim((string)$id);
        if ($id !== '') $idFilter[$id] = true;
    }
    $updated = 0;
    $skippedNoStock = 0;
    $skippedNoModel = 0;
    $skippedBelow = 0;
    $unchanged = 0;
    $inspected = 0;
    $now = date('c');
    $originalProducts = $products;

    foreach ($products as $index => $product) {
        if (!is_array($product)) continue;
        $id = (string)($product['id'] ?? '');
        if ($idFilter && ($id === '' || !isset($idFilter[$id]))) continue;
        if ($onlyInStock && product_row_available_qty($product) <= 0) {
            $skippedNoStock++;
            continue;
        }
        $inspected++;
        $model = product_match_extract_model($product);
        if (product_match_normalize($model) === '') {
            $skippedNoModel++;
            continue;
        }
        $match = product_best_catalog_rule_match($product, $catalogItems, $min);
        if ($match === null || trim((string)($match['block'] ?? '')) === '') {
            $match = product_best_sibling_rule_match($product, $originalProducts, $min);
        }
        if ($match === null || trim((string)($match['block'] ?? '')) === '') {
            $skippedBelow++;
            continue;
        }
        $block = trim((string)$match['block']);
        $nextDescription = product_merge_rule_description($product['description'] ?? '', $block);
        $currentDescription = trim((string)($product['description'] ?? ''));
        if ($nextDescription === $currentDescription) {
            $unchanged++;
            continue;
        }
        $products[$index]['description'] = $nextDescription;
        if (trim((string)($products[$index]['description_source'] ?? '')) === '') {
            $products[$index]['description_source'] = $block;
        }
        $products[$index]['rule_description_source'] = (string)($match['source'] ?? '');
        $products[$index]['rule_description_similarity'] = (float)($match['model_similarity'] ?? 0);
        $products[$index]['rule_description_matched_name'] = (string)($match['item']['name'] ?? ($match['item']['title'] ?? ''));
        $products[$index]['rule_description_applied_at'] = $now;
        $products[$index]['updated_at'] = $now;
        $updated++;
    }

    return [
        'products' => $products,
        'inspected' => $inspected,
        'updated' => $updated,
        'unchanged' => $unchanged,
        'skipped_no_stock' => $skippedNoStock,
        'skipped_no_model' => $skippedNoModel,
        'skipped_below_threshold' => $skippedBelow,
        'min_similarity' => $min,
    ];
}

function baohui_reference_catalog_items($data): array
{
    if (!is_array($data)) return [];
    if (isset($data['items']) && is_array($data['items'])) return array_values($data['items']);
    if (isset($data[0]) && is_array($data[0])) return array_values($data);
    return [];
}

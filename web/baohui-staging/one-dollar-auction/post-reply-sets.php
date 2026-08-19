<?php
declare(strict_types=1);

function post_reply_placeholder_help(): string
{
    return '{{title}} 品名、{{id}} 編號、{{barcode}} 條碼、{{spec}} 規格、{{desc}} 描述、{{close_at}} 截標、{{publish_at}} 上架、{{price}} 售價／得標、{{qty}} 數量、{{warehouse}} 倉位、{{company}} 公司名';
}

function default_post_reply_sets(): array
{
    return [
        [
            'id' => 'auction_pirate',
            'name' => '海賊團競標發文',
            'scene' => 'auction',
            'is_default' => true,
            'note' => 'Facebook 海賊團一元競標主文＋常見出價問法',
            'post_template' => "【寶輝電腦｜海賊團一元競標】\n{{title}}\n編號：{{id}}\n規格：{{spec}}\n\n{{desc}}\n\n板規（不可改）：最低出價 30；每次至少加 30；單次最高 2000。\n最後一分鐘有人喊就自動延長 3 分鐘。\n結標時間：{{close_at}}\n得標後兩天內匯款；產品可累計，整體上限一個禮拜。稅金外加 5%。\n保固產品七天個人保固。未取貨保留 2 個禮拜。\n只認純數字出價，有效標會回在你留言下面。留言排序請改「最新」或「所有留言」。",
            'qa' => [
                ['ask' => '有現貨嗎？', 'aliases' => '現貨,有貨,庫存,現在有嗎', 'answer' => '有，頭城門市現貨。得標並完成匯款後，兩天內可自取或出貨。'],
                ['ask' => '可以面交／自取嗎？', 'aliases' => '面交,自取,門市,來拿,頭城', 'answer' => '可以，宜蘭頭城門市自取。請先得標並完成匯款，再約取貨時間。'],
                ['ask' => '運費怎麼算？', 'aliases' => '運費,宅配,郵寄,寄送', 'answer' => '本島宅配另計；同一週可累計出貨。金額結標後依件數／地區告知。'],
                ['ask' => '有保固嗎？', 'aliases' => '保固,壞了,維修', 'answer' => '保固產品提供七天個人保固，以該篇產品說明為準。'],
                ['ask' => '可以議價或私訊嗎？', 'aliases' => '議價,聊聊,私訊,便宜,砍價', 'answer' => '競標不議價、不私訊成交。請在本篇用純數字出價，有效標會回在你留言下面。'],
                ['ask' => '最低要喊多少？', 'aliases' => '最低,起標,30,加多少', 'answer' => '最低出價 30，每次至少加 30，單次最高 2000。只認純數字。'],
                ['ask' => '最後一分鐘有人喊會怎樣？', 'aliases' => '延長,最後一分鐘,卡標', 'answer' => '最後一分鐘有人喊就自動延長 3 分鐘，直到沒人再喊。'],
                ['ask' => '得標後要多久匯款？', 'aliases' => '匯款,付款,幾天,轉帳', 'answer' => '得標後兩天內匯款。可累計同一週的標，上限一個禮拜。稅金外加 5%。'],
            ],
        ],
        [
            'id' => 'store_stock',
            'name' => '門市現貨直售',
            'scene' => 'retail',
            'is_default' => false,
            'note' => '門市有現貨、可自取／維修的發文',
            'post_template' => "【寶輝電腦｜門市現貨】\n{{title}}\n編號：{{id}}\n規格：{{spec}}\n售價：{{price}}\n倉位：{{warehouse}}\n\n{{desc}}\n\n宜蘭頭城門市現貨，歡迎自取。\n可搭配組裝、維修與估價。數量有限，售完為止。\n私訊或來電先問有沒有貨，避免空跑。",
            'qa' => [
                ['ask' => '現在還有貨嗎？', 'aliases' => '有貨,現貨,還在嗎,賣完', 'answer' => '請先報產品編號 {{id}}，我幫你看即時庫存。門市現貨售完為止。'],
                ['ask' => '可以來門市看嗎？', 'aliases' => '面交,自取,門市,營業,幾點', 'answer' => '可以，宜蘭頭城門市。建議先私訊確認庫存與營業時間再過來。'],
                ['ask' => '可以刷卡／轉帳嗎？', 'aliases' => '刷卡,轉帳,現金,付款', 'answer' => '門市可現金或轉帳；細節到店再確認。'],
                ['ask' => '可以幫忙組裝嗎？', 'aliases' => '組裝,裝機,安裝,系統', 'answer' => '可以，門市可代工組裝。工資依案件報價。'],
                ['ask' => '壞了可以修嗎？', 'aliases' => '維修,壞了,保固', 'answer' => '門市有承接維修。請帶機並說明狀況，先估價再修。'],
            ],
        ],
        [
            'id' => 'mall_service',
            'name' => '蝦皮／商城客服',
            'scene' => 'mall',
            'is_default' => false,
            'note' => '商城留言、聊聊常用問法',
            'post_template' => "【{{company}}】{{title}}\n編號：{{id}}／條碼：{{barcode}}\n規格：{{spec}}\n售價：{{price}}\n\n{{desc}}\n\n下單前請看庫存與出貨日。電子發票依結帳資料開立。",
            'qa' => [
                ['ask' => '今天買什麼時候出貨？', 'aliases' => '出貨,幾天到,物流,什麼時候寄', 'answer' => '現貨訂單確認後依工作日安排出貨。如遇缺貨會先私訊你。'],
                ['ask' => '可以指定物流嗎？', 'aliases' => '物流,黑貓,超商,7-11,全家', 'answer' => '可依賣場設定的物流出貨。若要超商取貨，下單時請選對應方式。'],
                ['ask' => '可以開統編嗎？', 'aliases' => '統編,發票,抬頭,電子發票', 'answer' => '可以，請在訂單備註統編與抬頭，我們開電子發票。'],
                ['ask' => '可以退換貨嗎？', 'aliases' => '退貨,換貨,鑑賞', 'answer' => '依平台規範辦理。商品需完整、配件齊全。客製／已拆封耗材可能無法退。'],
                ['ask' => '跟網頁規格一樣嗎？', 'aliases' => '規格,原廠,公司貨,全新', 'answer' => '以商品頁規格與照片為準。編號 {{id}}。有疑問請先問再下單。'],
            ],
        ],
        [
            'id' => 'winner_followup',
            'name' => '得標後催匯／取貨',
            'scene' => 'aftersale',
            'is_default' => false,
            'note' => '截標後私訊或留言回覆',
            'post_template' => "恭喜得標【{{title}}】\n編號：{{id}}\n規格：{{spec}}\n得標金額：{{price}}\n數量：{{qty}}\n\n請於兩天內完成匯款。稅金外加 5%。\n可與本週其他得標品累計，上限一個禮拜。\n匯款後回傳帳號後五碼，我們安排自取或出貨。",
            'qa' => [
                ['ask' => '匯款帳號多少？', 'aliases' => '帳號,匯款,轉帳,銀行', 'answer' => '請用得標通知裡的匯款資料。匯完請回後五碼與得標編號 {{id}}。'],
                ['ask' => '可以晚幾天匯嗎？', 'aliases' => '晚一點,來不及,明天,下週', 'answer' => '板規是得標後兩天內匯款。有特殊情況請先說，超過未取貨會保留 2 個禮拜。'],
                ['ask' => '可以跟其他標一起出嗎？', 'aliases' => '一起寄,累計,合併,同週', 'answer' => '可以，同一週得標品可累計出貨，整體上限一個禮拜。'],
                ['ask' => '要怎麼自取？', 'aliases' => '自取,面交,門市拿', 'answer' => '匯款確認後約宜蘭頭城門市自取。請帶得標編號 {{id}}。'],
                ['ask' => '還沒匯可以先留嗎？', 'aliases' => '先留,幫我留,不要賣別人', 'answer' => '未匯款無法無限期保留。請盡快處理，未取貨保留 2 個禮拜。'],
            ],
        ],
    ];
}

function post_reply_set_key(string $id): string
{
    $id = trim($id);
    return $id !== '' ? $id : '';
}

function normalize_post_reply_qa(array $items): array
{
    $out = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $ask = trim((string)($item['ask'] ?? $item['question'] ?? ''));
        $answer = trim((string)($item['answer'] ?? $item['reply'] ?? ''));
        if ($ask === '' && $answer === '') continue;
        $out[] = [
            'ask' => $ask,
            'aliases' => trim((string)($item['aliases'] ?? '')),
            'answer' => $answer,
        ];
    }
    return $out;
}

function normalize_post_reply_set(array $row, bool $keepId = true): array
{
    $id = $keepId ? trim((string)($row['id'] ?? '')) : '';
    if ($id === '') $id = 'prs_' . date('ymdHis') . substr(bin2hex(random_bytes(2)), 0, 4);
    return [
        'id' => $id,
        'name' => trim((string)($row['name'] ?? '')) ?: '未命名套組',
        'scene' => trim((string)($row['scene'] ?? 'auction')) ?: 'auction',
        'is_default' => !empty($row['is_default']),
        'note' => trim((string)($row['note'] ?? '')),
        'post_template' => (string)($row['post_template'] ?? ''),
        'qa' => normalize_post_reply_qa((array)($row['qa'] ?? [])),
        'updated_at' => trim((string)($row['updated_at'] ?? '')) ?: date('c'),
    ];
}

function normalize_post_reply_sets(array $sets): array
{
    $out = [];
    $seen = [];
    foreach ($sets as $row) {
        if (!is_array($row)) continue;
        $set = normalize_post_reply_set($row);
        if (isset($seen[$set['id']])) continue;
        $seen[$set['id']] = true;
        $out[] = $set;
    }
    if ($out === []) $out = array_map('normalize_post_reply_set', default_post_reply_sets());
    $hasDefault = false;
    foreach ($out as $set) {
        if (!empty($set['is_default'])) { $hasDefault = true; break; }
    }
    if (!$hasDefault) $out[0]['is_default'] = true;
    return array_values($out);
}

function post_reply_default_set(array $sets): array
{
    foreach ($sets as $set) {
        if (!empty($set['is_default'])) return $set;
    }
    return $sets[0] ?? normalize_post_reply_set(default_post_reply_sets()[0]);
}

function post_reply_find_set(array $sets, string $id): array
{
    $id = trim($id);
    if ($id !== '') {
        foreach ($sets as $set) {
            if (($set['id'] ?? '') === $id) return $set;
        }
    }
    return post_reply_default_set($sets);
}

function post_reply_tokens(array $schedule, array $product): array
{
    $title = trim((string)(($product['title'] ?? '') ?: ($schedule['product_title'] ?? '')));
    $id = trim((string)($schedule['product_id'] ?? ($product['id'] ?? '')));
    $spec = trim((string)(
        ($schedule['product_color'] ?? ($product['color'] ?? '')) . ' / '
        . ($schedule['product_size'] ?? ($product['size'] ?? '')) . ' / '
        . ($schedule['product_spec'] ?? ($product['spec'] ?? ''))
    ), ' /');
    $price = $schedule['winning_price'] ?? $schedule['current_bid'] ?? $product['sale_price'] ?? $product['selling_price'] ?? 0;
    $warehouse = trim(implode(' / ', array_filter([
        (string)($product['warehouse_name'] ?? ''),
        (string)($product['shelf_code'] ?? ''),
        (string)($product['warehouse_location'] ?? ''),
    ], static function ($v) { return trim($v) !== ''; })));
    return [
        'title' => $title,
        'id' => $id,
        'barcode' => trim((string)($schedule['product_barcode'] ?? ($product['barcode'] ?? ''))),
        'spec' => $spec,
        'desc' => trim((string)($schedule['product_description'] ?? ($product['description'] ?? ''))),
        'close_at' => trim((string)($schedule['close_at'] ?? '')) ?: '以本篇標示為準',
        'publish_at' => trim((string)($schedule['publish_at'] ?? '')),
        'price' => is_numeric($price) ? (string)(int)round((float)$price) : trim((string)$price),
        'qty' => (string)max(1, (int)($schedule['quantity'] ?? 1)),
        'warehouse' => $warehouse,
        'company' => '寶輝電腦',
    ];
}

function post_reply_fill(string $template, array $tokens): string
{
    $out = $template;
    foreach ($tokens as $key => $value) {
        $out = str_replace('{{' . $key . '}}', (string)$value, $out);
    }
    $out = preg_replace("/\n{3,}/", "\n\n", $out) ?? $out;
    return trim($out);
}

function render_post_reply_listing(array $schedule, array $product, array $sets, string $setId = ''): string
{
    $set = post_reply_find_set($sets, $setId !== '' ? $setId : (string)($schedule['post_set_id'] ?? ''));
    $text = post_reply_fill((string)($set['post_template'] ?? ''), post_reply_tokens($schedule, $product));
    if ($text !== '') return $text;
    $tokens = post_reply_tokens($schedule, $product);
    return trim($tokens['title'] . "\n編號：" . $tokens['id'] . "\n規格：" . $tokens['spec']);
}

function render_post_reply_qa_pack(array $schedule, array $product, array $sets, string $setId = ''): string
{
    $set = post_reply_find_set($sets, $setId !== '' ? $setId : (string)($schedule['post_set_id'] ?? ''));
    $tokens = post_reply_tokens($schedule, $product);
    $lines = ['【' . ($set['name'] ?? '問答') . '｜可當第一則留言置頂】'];
    foreach ((array)($set['qa'] ?? []) as $item) {
        $ask = post_reply_fill((string)($item['ask'] ?? ''), $tokens);
        $answer = post_reply_fill((string)($item['answer'] ?? ''), $tokens);
        if ($ask === '' && $answer === '') continue;
        $lines[] = '';
        $lines[] = 'Q：' . $ask;
        $lines[] = 'A：' . $answer;
    }
    return trim(implode("\n", $lines));
}

function post_reply_normalize_query(string $text): string
{
    $text = trim($text);
    $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    $text = preg_replace('/\s+/u', '', $text) ?? $text;
    return $text;
}

function match_post_reply(array $set, string $query, array $tokens = []): array
{
    $q = post_reply_normalize_query($query);
    if ($q === '') return [];
    $hits = [];
    $hasMb = function_exists('mb_strpos') && function_exists('mb_strlen');
    foreach ((array)($set['qa'] ?? []) as $index => $item) {
        $needles = array_filter(array_map('trim', preg_split('/[,，、\/|]+/u', (string)($item['ask'] ?? '') . ',' . (string)($item['aliases'] ?? '')) ?: []));
        $score = 0;
        foreach ($needles as $needle) {
            $n = post_reply_normalize_query($needle);
            if ($n === '') continue;
            if ($q === $n) { $score = 100; break; }
            $contains = $hasMb
                ? (mb_strpos($q, $n) !== false || mb_strpos($n, $q) !== false)
                : (strpos($q, $n) !== false || strpos($n, $q) !== false);
            if ($contains) {
                $len = $hasMb ? mb_strlen($n) : strlen($n);
                $score = max($score, min(90, 40 + $len));
            }
        }
        if ($score <= 0) continue;
        $hits[] = [
            'score' => $score,
            'ask' => post_reply_fill((string)($item['ask'] ?? ''), $tokens),
            'answer' => post_reply_fill((string)($item['answer'] ?? ''), $tokens),
            'index' => $index,
        ];
    }
    usort($hits, static function ($a, $b) { return $b['score'] <=> $a['score']; });
    return array_slice($hits, 0, 5);
}

function post_reply_scene_label(string $scene): string
{
    return [
        'auction' => '海賊團競標',
        'retail' => '門市現貨',
        'mall' => '商城客服',
        'aftersale' => '得標後',
    ][$scene] ?? $scene;
}

function post_reply_scene_options(): array
{
    return [
        'auction' => '海賊團競標',
        'retail' => '門市現貨',
        'mall' => '商城客服',
        'aftersale' => '得標後',
    ];
}

function post_reply_qa_from_parallel($asks, $aliases, $answers): array
{
    if (!is_array($asks)) $asks = [];
    if (!is_array($aliases)) $aliases = [];
    if (!is_array($answers)) $answers = [];
    $out = [];
    foreach ($asks as $i => $ask) {
        $out[] = [
            'ask' => (string)$ask,
            'aliases' => (string)($aliases[$i] ?? ''),
            'answer' => (string)($answers[$i] ?? ''),
        ];
    }
    return normalize_post_reply_qa($out);
}

function post_reply_mark_default(array $sets, string $id): array
{
    $id = trim($id);
    $found = false;
    foreach ($sets as &$set) {
        $isThis = $id !== '' && ($set['id'] ?? '') === $id;
        if ($isThis) $found = true;
        $set['is_default'] = $isThis;
    }
    unset($set);
    if (!$found && $sets) $sets[0]['is_default'] = true;
    return array_values($sets);
}

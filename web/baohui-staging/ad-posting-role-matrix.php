<?php
declare(strict_types=1);

/**
 * Live #adPosting / 寶輝發文助手 role matrix.
 * Historical Facebook display name 寶輝科技有限公司 頭城竹安店 stays valid for old rows.
 * Do not put Kuo Feng Cheng back into general advertising.
 */
function baohui_ad_posting_role_matrix(): array
{
    return [
        'ad_posting_identities' => [
            'YUKI LIU',
            '海賊團行政專員',
            '寶輝科技官方網站 粉絲團',
        ],
        'historical_ad_identities' => [
            '寶輝科技有限公司 頭城竹安店',
        ],
        'identity_aliases' => [
            '寶輝科技有限公司 頭城竹安店' => '寶輝科技官方網站 粉絲團',
            'KUO GFENG CHENG' => 'Kuo Feng Cheng',
            'KUO FENG CHENG' => 'Kuo Feng Cheng',
        ],
        'lanes' => [
            [
                'id' => 'yuki-liu',
                'identity' => 'YUKI LIU',
                'kind' => 'person',
                'publish_posts' => true,
                'work_columns' => ['adPosting', 'facebook-daily'],
                'notes' => '可上架文章；另負責通州截標（臉書當日日報／結標）。',
            ],
            [
                'id' => 'kuo-feng-cheng',
                'identity' => 'Kuo Feng Cheng',
                'kind' => 'person',
                'publish_posts' => false,
                'work_columns' => ['schedule', 'admin-product-posting'],
                'notes' => '負責上架產品與競標貼文提醒。沿用既有排程／提醒工作列，不重建、不進一般廣告。',
            ],
            [
                'id' => 'official-fan-page',
                'identity' => '寶輝科技官方網站 粉絲團',
                'kind' => 'page',
                'publish_posts' => true,
                'work_columns' => ['adPosting', 'admin-product-posting'],
                'notes' => 'Kuo Feng Cheng 的官方粉絲團，可上架文章。',
            ],
            [
                'id' => 'pirate-admin',
                'identity' => '海賊團行政專員',
                'kind' => 'role',
                'publish_posts' => true,
                'work_columns' => ['adPosting'],
                'notes' => '可上架文章；與官方粉絲團並列，不當一般廣告的替代帳號。',
            ],
        ],
        'auction_listing_identity' => 'Kuo Feng Cheng',
        'auction_reminder_identity' => 'Kuo Feng Cheng',
        'bid_close_identity' => 'YUKI LIU',
        'winner_notice_identity' => '海賊團行政專員',
        'fan_page_identity' => '寶輝科技官方網站 粉絲團',
    ];
}

function baohui_ad_identity_canonical(string $name): string
{
    $matrix = baohui_ad_posting_role_matrix();
    $name = preg_replace('/\s+/u', ' ', trim($name)) ?? '';
    return $matrix['identity_aliases'][$name] ?? $name;
}

function baohui_ad_identity_may_publish_posts(string $name): bool
{
    $matrix = baohui_ad_posting_role_matrix();
    $canonical = baohui_ad_identity_canonical($name);
    return in_array($canonical, $matrix['ad_posting_identities'], true)
        || in_array($name, $matrix['historical_ad_identities'], true);
}

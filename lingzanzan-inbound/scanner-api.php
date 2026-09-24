<?php
declare(strict_types=1);

// API responses must remain valid JSON even when PHP encounters a warning.
// Warnings are still written to the PHP error log for diagnosis.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$dataDir = __DIR__ . DIRECTORY_SEPARATOR . 'data';
$productsFile = $dataDir . DIRECTORY_SEPARATOR . 'products.json';
$skusFile = $dataDir . DIRECTORY_SEPARATOR . 'skus.json';
$stateFile = $dataDir . DIRECTORY_SEPARATOR . 'admin-state.json';
$ledgerFile = $dataDir . DIRECTORY_SEPARATOR . 'scanner-operations.json';
$stocktakesFile = $dataDir . DIRECTORY_SEPARATOR . 'scanner-stocktakes.json';
$stocktakeRecoveriesFile = $dataDir . DIRECTORY_SEPARATOR . 'scanner-stocktake-recoveries.json';
$pendingStockinFile = $dataDir . DIRECTORY_SEPARATOR . 'scanner-pending-stockin.json';
$initialStockModeFile = $dataDir . DIRECTORY_SEPARATOR . 'scanner-initial-stock-mode.json';
$ordersFile = $dataDir . DIRECTORY_SEPARATOR . 'orders.json';
$barcodeAliasesFile = $dataDir . DIRECTORY_SEPARATOR . 'scanner-barcode-aliases.json';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'image-storage.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'cn-tw-hold-lib.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'barcode-integrity-lib.php';

function scanner_read(string $file): array {
    if (!is_file($file)) return [];
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', (string)file_get_contents($file));
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}
function scanner_write(string $file, array $data): void {
    if (!is_dir(dirname($file))) @mkdir(dirname($file), 0775, true);
    $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, $file)) {
        @unlink($tmp); scanner_response(['ok'=>false,'error'=>'NAS 寫入失敗'], 500);
    }
}
function scanner_write_group(array $writes): void {
    // Encode and stage every member first. No live file is touched until every
    // payload can be written. If a later rename fails, restore the originals.
    $transactionId = date('YmdHis') . '-' . bin2hex(random_bytes(4));
    $staged = [];
    $backups = [];
    foreach ($writes as $file => $data) {
        if (!is_array($data)) scanner_response(['ok'=>false,'error'=>'交易資料格式錯誤，未寫入任何檔案'], 500);
        if (!is_dir(dirname($file))) @mkdir(dirname($file), 0775, true);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $tmp = $file . '.txn-' . $transactionId . '.tmp';
        if ($json === false || file_put_contents($tmp, $json, LOCK_EX) === false) {
            foreach ($staged as $path) @unlink($path);
            @unlink($tmp);
            scanner_response(['ok'=>false,'error'=>'NAS 交易暫存失敗，產品與庫存均未變更'], 500);
        }
        $staged[$file] = $tmp;
    }
    foreach (array_keys($writes) as $file) {
        if (!is_file($file)) continue;
        $backup = $file . '.txn-' . $transactionId . '.bak';
        if (!copy($file, $backup)) {
            foreach ($staged as $path) @unlink($path);
            foreach ($backups as $path) @unlink($path);
            scanner_response(['ok'=>false,'error'=>'NAS 交易備份失敗，產品與庫存均未變更'], 500);
        }
        $backups[$file] = $backup;
    }
    $committed = [];
    foreach ($staged as $file => $tmp) {
        if (@rename($tmp, $file)) { $committed[] = $file; continue; }
        foreach ($committed as $done) if (isset($backups[$done])) @copy($backups[$done], $done);
        foreach ($staged as $path) @unlink($path);
        foreach ($backups as $path) @unlink($path);
        scanner_response(['ok'=>false,'error'=>'NAS 交易提交失敗，已回復原產品與庫存'], 500);
    }
    foreach ($backups as $path) @unlink($path);
}
function scanner_response(array $payload, int $status=200): void { http_response_code($status); echo json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function scanner_backoffice_actor(array $state, array $requiredPermissions=[]): array {
    $token=trim((string)($_COOKIE['lz_admin_session']??''));
    if($token==='')scanner_response(['ok'=>false,'error'=>'請先登入後台，才能執行盤點或庫存作業'],401);
    $tokenHash=hash('sha256',$token);$actor=null;
    foreach(scanner_read($GLOBALS['dataDir'].DIRECTORY_SEPARATOR.'admin-sessions.json') as $candidate){
        if(!is_array($candidate)||(int)($candidate['expiresAt']??0)<=time())continue;
        if(hash_equals((string)($candidate['tokenHash']??''),$tokenHash)){$actor=$candidate;break;}
    }
    if(!is_array($actor)||!in_array((string)($actor['role']??''),['admin','staff'],true))scanner_response(['ok'=>false,'error'=>'後台登入已逾時，請重新登入後再執行盤點或庫存作業'],401);
    $role=(string)($actor['role']??'');$account=trim((string)($actor['account']??''));$permissions=is_array($actor['permissions']??null)?$actor['permissions']:[];$storedCredential='';
    if($role==='staff'){
        $staffRow=null;
        foreach(($state['staff']??[]) as $candidate){
            if(!is_array($candidate))continue;
            $identities=[(string)($candidate['account']??''),(string)($candidate['name']??'')];
            if(is_array($candidate['aliases']??null))$identities=array_merge($identities,$candidate['aliases']);
            if(is_array($candidate['loginAliases']??null))$identities=array_merge($identities,$candidate['loginAliases']);
            foreach($identities as $identity)if(trim((string)$identity)!==''&&strcasecmp(trim((string)$identity),$account)===0){$staffRow=$candidate;break 2;}
        }
        if(!is_array($staffRow)||($staffRow['active']??true)===false)scanner_response(['ok'=>false,'error'=>'此員工帳號目前不能修正盤點草稿'],403);
        $storedCredential=(string)($staffRow['password']??'');
        $permissions=is_array($staffRow['permissions']??null)?$staffRow['permissions']:$permissions;
        if($requiredPermissions&&!array_intersect($requiredPermissions,$permissions))scanner_response(['ok'=>false,'error'=>'此員工沒有盤點草稿修正權限'],403);
    }else{
        $storedCredential=(string)($state['adminPasswordHash']??'');
    }
    $expectedFingerprint=hash('sha256',strtolower($role)."\0".strtolower($account)."\0".$storedCredential);
    if(!hash_equals((string)($actor['credentialFingerprint']??''),$expectedFingerprint))scanner_response(['ok'=>false,'error'=>'登入憑證已更新，請重新登入後台'],401);
    $name=trim((string)($actor['name']??''));if($name==='')$name=$account!==''?$account:'管理者';
    return ['role'=>$role,'account'=>$account,'name'=>$name,'permissions'=>$permissions];
}
function scanner_norm($value): string { return strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string)$value) ?? ''); }
function scanner_operator_key($operator): string {
    if(!is_array($operator))$operator=['name'=>(string)$operator];
    return strtolower(trim((string)($operator['account']??$operator['name']??'')));
}
function scanner_numeric_barcode_hash($value): ?int {
    $text=scanner_norm($value);
    if($text==='')return null;
    $hash=2166136261;
    $length=strlen($text);
    for($i=0;$i<$length;$i++)$hash=(($hash^ord($text[$i]))*16777619)&0xffffffff;
    return $hash;
}
function scanner_numeric_barcode_key($value): string {
    $hash=scanner_numeric_barcode_hash($value);
    return $hash===null?'':'8'.str_pad((string)($hash%10000000),7,'0',STR_PAD_LEFT);
}
function scanner_legacy_numeric_barcode_key($value): string {
    $hash=scanner_numeric_barcode_hash($value);
    return $hash===null?'':'8'.str_pad((string)($hash%1000000000),9,'0',STR_PAD_LEFT);
}
function scanner_barcode_core($value): string {
    $code=scanner_norm($value);
    // Warehouse suffixes on SKU ids / old labels must not block sticker lookup.
    $code=preg_replace('/(?:CN|TW|ID)$/','',$code) ?? $code;
    return $code;
}
function scanner_barcode_key_matches(string $query,$value): bool {
    $q=scanner_norm($query);$code=scanner_norm($value);
    if($q===''||$code==='')return false;
    if($code===$q||scanner_numeric_barcode_key($code)===$q||scanner_legacy_numeric_barcode_key($code)===$q)return true;
    $qc=scanner_barcode_core($query);$cc=scanner_barcode_core($value);
    return $qc!==''&&$cc!==''&&($qc===$cc||scanner_numeric_barcode_key($cc)===$qc||scanner_legacy_numeric_barcode_key($cc)===$qc);
}
function scanner_pend_legacy_alias_forms(string $query): array {
    // Print {編號}{色碼}{尺碼}P{成本} ↔ stored PRODUCTP{cost}{color}{size}.
    $q=scanner_barcode_core($query);
    if($q==='') return [];
    $out=[$q];
    if(preg_match('/C[A-Z0-9]+S[A-Z0-9]+P\d+$/',$q)===1||preg_match('/P\d+C[A-Z0-9]+S[A-Z0-9]+$/',$q)===1) return $out;
    if(preg_match('/^([A-Z]+)(\d+)P(\d+)$/',$q,$pend)!==1) return $out;
    $letters=(string)$pend[1];$mid=(string)$pend[2];$cost=(string)$pend[3];
    foreach([4,3,2] as $colorLen){
        if(strlen($mid)<=$colorLen) continue;
        $color=substr($mid,-$colorLen);
        $left=substr($mid,0,-$colorLen);
        if($left===''||preg_match('/^\d+$/',$left)!==1) continue;
        $out[]=$letters.$left.'P'.$cost.$color;
        if(strlen($color)>=3){
            $colorOnly=substr($color,0,-1);
            $size=substr($color,-1);
            if($colorOnly!==''){
                $out[]=$letters.$left.'P'.$cost.'C'.$colorOnly.'S'.$size;
                $out[]=$letters.$left.'P'.$cost.$colorOnly.$size;
            }
        }
        if(strlen($color)>=4){
            $colorOnly=substr($color,0,-2);
            $size=substr($color,-2);
            if($colorOnly!==''&&($size==='00'||preg_match('/^[0-9]{2}$/',$size)===1)){
                $out[]=$letters.$left.'P'.$cost.'C'.$colorOnly.'S'.$size;
                $out[]=$letters.$left.'P'.$cost.$colorOnly.$size;
            }
        }
    }
    return array_values(array_unique($out));
}
function scanner_query_matches_code(string $query,$value): bool {
    foreach(scanner_pend_legacy_alias_forms($query) as $alias){
        if(scanner_barcode_key_matches($alias,$value)) return true;
    }
    return scanner_barcode_key_matches($query,$value);
}
function scanner_is_category_serial($code): bool {
    return preg_match('/^[A-Z]+\d+$/', scanner_norm($code)) === 1;
}
function scanner_legacy_size_code($value): string {
    $text=strtoupper(preg_replace('/[\s_]/','',trim((string)$value)) ?? '');
    $map=['XS'=>'1','S'=>'2','M'=>'3','L'=>'4','XL'=>'5','2XL'=>'6','XXL'=>'6','3XL'=>'7','XXXL'=>'7','4XL'=>'8','NOSIZE'=>'00','NO-SIZE'=>'00','NO SIZE'=>'00'];
    $compact=str_replace(' ','',$text);
    if(isset($map[$text])) return $map[$text];
    if(isset($map[$compact])) return $map[$compact];
    if(preg_match('/^[0-9]+$/',$text)===1) return $text;
    return '';
}
function scanner_quick_product_code($value): string {
    $normalized=scanner_norm($value);
    if (preg_match('/^([A-Z]+\d+)C[A-Z0-9]+S[A-Z0-9]+P\d+$/',$normalized,$match)) return (string)$match[1];
    if (preg_match('/^([A-Z]+\d+)P\d+C[A-Z0-9]+S[A-Z0-9]+$/',$normalized,$match)) return (string)$match[1];
    if (preg_match('/^([A-Z]+\d+)C[A-Z0-9]+S[A-Z0-9]+$/',$normalized,$match)) return (string)$match[1];
    if (preg_match('/^([A-Z]+\d+)P\d{4,}$/',$normalized,$match)) return (string)$match[1];
    if (preg_match('/^[A-Z]+\d+/',$normalized,$match)) return (string)$match[0];
    return $normalized;
}
function scanner_department_is_computer(array $product, array $sku): bool {
    $raw=strtolower(trim((string)($product['inventoryBusinessUnit']??$product['department']??$product['category_scope']??$sku['inventoryBusinessUnit']??$sku['department']??'')));
    return $raw==='computer' || $raw==='baohui_computer' || strpos($raw,'電腦')!==false;
}
function scanner_category_serial(array $product, array $sku): string {
    if (scanner_department_is_computer($product,$sku)) {
        return scanner_quick_product_code($product['code']??$product['barcode']??$product['id']??$sku['productCode']??'');
    }
    foreach ([
        $sku['mappingCode']??'',
        $product['mappingCode']??'',
        $product['categoryMappingCode']??'',
        $product['categorySerial']??'',
        $sku['officialBarcode']??'',
        $sku['companyBarcode']??'',
        $sku['barcode']??'',
        $sku['legacyBarcode']??'',
        $sku['labelBarcode']??'',
        $product['companyBarcode']??'',
        $product['barcode']??'',
        $product['productLine']??'',
        $product['code']??'',
        $sku['productCode']??'',
        $product['id']??''
    ] as $candidate) {
        $base=scanner_quick_product_code($candidate);
        if(scanner_is_category_serial($base)) return $base;
    }
    return scanner_quick_product_code($product['code']??$product['id']??'');
}
function scanner_generated_label_parts(array $product,array $sku): array {
    $base=scanner_category_serial($product,$sku);
    $color=scanner_norm($sku['colorCode']??$sku['colorNo']??$sku['legacyColorCode']??'');
    $size=scanner_norm($sku['sizeCode']??$sku['sizeNo']??$sku['legacySizeCode']??'');
    if($color===''){
        $wanted=trim((string)($sku['colorName']??$sku['color']??''));
        foreach(($product['colors']??[]) as $meta){
            if(!is_array($meta))continue;
            $name=trim((string)($meta['name']??$meta['colorName']??$meta['color']??''));
            if($wanted!==''&&$name!==''&&$wanted!==$name)continue;
            $candidate=scanner_norm($meta['code']??$meta['colorCode']??'');
            if($candidate!==''){$color=$candidate;break;}
        }
    }
    if($size===''||$size==='NOSIZE'||$size==='NO-SIZE'){
        $fromName=scanner_legacy_size_code($sku['sizeName']??$sku['size']??'');
        $size=$fromName!==''?$fromName:'00';
    }
    $cost=0;
    foreach ([$sku['officialBarcode']??'',$sku['companyBarcode']??'',$sku['legacyBarcode']??'',$sku['barcode']??'',$sku['mappingCode']??'',$sku['labelBarcode']??''] as $savedBarcode) {
        $saved=scanner_barcode_core($savedBarcode);
        if($saved==='')continue;
        if(preg_match('/C([A-Z0-9]+)S([A-Z0-9]+)P(\d+)$/',$saved,$v3)===1){
            if($color==='')$color=(string)$v3[1];
            if($size===''||$size==='NOSIZE')$size=(string)$v3[2];
            $cost=(int)$v3[3];break;
        }
        if(preg_match('/P(\d+)C([A-Z0-9]+)S([A-Z0-9]+)$/',$saved,$v2)===1){
            if($color==='')$color=(string)$v2[2];
            if($size===''||$size==='NOSIZE')$size=(string)$v2[3];
            $cost=(int)$v2[1];break;
        }
        if($color==='')continue;
        if(preg_match('/P(\d+)$/',$saved,$legacy)!==1)continue;
        $digits=(string)$legacy[1];
        if(strlen($digits)<=strlen($color)||substr($digits,-strlen($color))!==$color)continue;
        $legacyCost=substr($digits,0,strlen($digits)-strlen($color));
        if($legacyCost!==''&&(int)$legacyCost>0){$cost=(int)$legacyCost;break;}
    }
    if($cost<=0){
        $effective=scanner_effective_cost($product,$sku);
        $cost=(int)round((float)($effective['value']??0));
    }
    if($base===''||$cost<=0||$color==='') return ['base'=>'','cost'=>0,'color'=>'','size'=>''];
    return ['base'=>$base,'cost'=>$cost,'color'=>$color,'size'=>$size];
}
function scanner_generated_v2_label_barcode(array $product,array $sku): string {
    $parts=scanner_generated_label_parts($product,$sku);
    if(($parts['base']??'')===''||($parts['cost']??0)<=0||($parts['color']??'')==='') return '';
    return $parts['base'].'P'.$parts['cost'].'C'.$parts['color'].'S'.$parts['size'];
}
function scanner_product_serials(array $product,array $sku): array {
    $out=[];
    foreach ([
        $product['code']??'',$product['productLine']??'',$product['mappingCode']??'',
        $sku['productCode']??'',scanner_category_serial($product,$sku)
    ] as $candidate) {
        $base=scanner_quick_product_code($candidate);
        if(scanner_is_category_serial($base))$out[$base]=true;
    }
    foreach(($product['nameAliases']??[]) as $alias){
        $base=scanner_quick_product_code($alias);
        if(scanner_is_category_serial($base))$out[$base]=true;
    }
    return array_keys($out);
}
function scanner_generated_label_barcode(array $product,array $sku): string {
    // LZ_BARCODE_SERIAL_PEND_20260924 canonical: serial C color S size P cost
    $parts=scanner_generated_label_parts($product,$sku);
    if(($parts['base']??'')===''||($parts['cost']??0)<=0||($parts['color']??'')==='') return '';
    return $parts['base'].'C'.$parts['color'].'S'.$parts['size'].'P'.$parts['cost'];
}
function scanner_generated_pend_label_barcodes(array $product,array $sku): array {
    // LZ_SCAN_LOOKUP_20260924: inbound stickers print {編號}{色碼}{尺碼}P{成本}; NOSIZE omits 00.
    $parts=scanner_generated_label_parts($product,$sku);
    if(($parts['cost']??0)<=0||($parts['color']??'')==='') return [];
    $serials=scanner_product_serials($product,$sku);
    if(!$serials && ($parts['base']??'')!=='')$serials=[(string)$parts['base']];
    $color=(string)$parts['color'];$size=(string)($parts['size']??'');$cost=(string)$parts['cost'];
    $out=[];
    foreach($serials as $base){
        if($base==='')continue;
        $out[]=$base.$color.'P'.$cost;
        if($size!==''&&$size!=='00'){
            $out[]=$base.$color.$size.'P'.$cost;
            $padded=str_pad($size,2,'0',STR_PAD_LEFT);
            if($padded!==$size)$out[]=$base.$color.$padded.'P'.$cost;
        }else{
            $out[]=$base.$color.'00P'.$cost;
        }
    }
    return array_values(array_unique($out));
}
function scanner_sku_lookup_codes(array $product,array $sku): array {
    $codes=[
        $sku['id']??'',$sku['sku']??'',$sku['barcode']??'',$sku['companyBarcode']??'',
        $sku['legacyBarcode']??'',$sku['mappingCode']??'',$sku['officialBarcode']??'',
        $sku['labelBarcode']??'',$product['code']??'',$product['id']??'',$product['mappingCode']??'',
        scanner_generated_label_barcode($product,$sku),scanner_generated_v2_label_barcode($product,$sku)
    ];
    foreach(($product['nameAliases']??[]) as $aliasName)$codes[]=$aliasName;
    foreach(scanner_generated_pend_label_barcodes($product,$sku) as $pend)$codes[]=$pend;
    foreach(($sku['linkedBarcodes']??[]) as $linkedBarcode)$codes[]=$linkedBarcode;
    foreach(($sku['barcodeAliases']??[]) as $aliasCode)$codes[]=is_array($aliasCode)?($aliasCode['code']??''):$aliasCode;
    return $codes;
}
function scanner_quick_size($value): string {
    $normalized=strtoupper(trim(preg_replace('/\s+/u',' ',(string)$value)??''));
    if ($normalized===''||preg_match('/^(未填|未設定尺寸|未設定|未選尺寸|未帶尺寸|未帶|沒尺寸|無尺寸|没有尺寸|均碼|均一尺寸|單一尺寸|NO\\s*SIZE|NO[-_]?SIZE|FREE(?:\\s*SIZE)?|FREESIZE|NONE|UNKNOWN)$/iu',$normalized)) return 'NO SIZE';
    return $normalized;
}
function scanner_save_image(?string $dataUrl): string {
    if (!is_string($dataUrl) || $dataUrl === '' || !preg_match('/^data:image\/(png|jpe?g|webp);base64,/i', $dataUrl, $m)) return '';
    $raw = preg_replace('/^data:image\/(png|jpe?g|webp);base64,/i', '', $dataUrl);
    $bin = base64_decode((string)$raw, true);
    if ($bin === false || strlen($bin) > 6 * 1024 * 1024) return '';
    $stored = externalize_data_images($dataUrl, __DIR__);
    return is_string($stored) && strpos($stored, 'data:image/') !== 0 ? $stored : '';
}
function scanner_placeholder_image($value): bool {
    $image=strtolower(trim((string)$value));
    return $image===''||strpos($image,'brand-logo.')!==false||strpos($image,'brand-icon-')!==false;
}
function scanner_first_color_image(array $product): string {
    foreach (($product['colors']??[]) as $meta) {
        if (is_array($meta) && !empty($meta['image'])) return (string)$meta['image'];
    }
    foreach (($product['images']??[]) as $image) {
        if (!scanner_placeholder_image($image)) return (string)$image;
    }
    return '';
}
function scanner_image(array $product, array $sku): string {
    if (!empty($sku['colorImage'])) return (string)$sku['colorImage'];
    $color=(string)($sku['colorName']??$sku['color']??'');
    foreach (($product['colors']??[]) as $meta) if (is_array($meta) && ($meta['name']??'')===$color && !empty($meta['image'])) return (string)$meta['image'];
    $main=(string)($product['mainImage']??'');
    if (!scanner_placeholder_image($main)) return $main;
    $firstColor=scanner_first_color_image($product);
    if ($firstColor!=='') return $firstColor;
    return $main!==''?$main:'./assets/brand-logo.png';
}
function sku_warehouse(array $sku): string {
    $code = scanner_warehouse_code($sku['warehouseCode'] ?? '');
    if ($code === '') $code = scanner_warehouse_code($sku['warehouseName'] ?? $sku['warehouse'] ?? '');
    if ($code === 'TW') return '台灣倉';
    if ($code === 'CN') return '中國倉';
    if ($code === 'ID') return '印尼倉';
    $name = trim((string)($sku['warehouseName'] ?? $sku['warehouse'] ?? ''));
    return $name !== '' ? $name : '未設定倉庫';
}
function sku_key(array $sku): string { return implode('|', [(string)($sku['productId']??''),(string)($sku['colorName']??$sku['color']??''),(string)($sku['sizeName']??$sku['size']??'NO SIZE')]); }
function scanner_product_map(array $products): array {
    $map=[];foreach($products as $product)if(is_array($product)){foreach([(string)($product['id']??''),(string)($product['code']??'')] as $key)if($key!=='')$map[$key]=$product;}return $map;
}
function scanner_cost_number(array $row, array $keys): ?float {
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row) || $row[$key] === '' || $row[$key] === null || !is_numeric($row[$key])) continue;
        $value=(float)$row[$key];
        if (is_finite($value) && $value>0) return round($value, 2);
    }
    return null;
}
function scanner_cost_time(array $row, array $keys): array {
    foreach ($keys as $key) {
        $raw=trim((string)($row[$key]??''));
        if ($raw==='') continue;
        $stamp=strtotime($raw);
        if ($stamp!==false) return ['raw'=>$raw,'stamp'=>(int)$stamp];
    }
    return ['raw'=>'','stamp'=>0];
}
function scanner_cost_candidate(?float $value, string $source, array $time, int $priority): ?array {
    if ($value===null) return null;
    return [
        'available'=>true,
        'value'=>round($value, 2),
        'source'=>$source!==''?$source:'current_cost',
        'updatedAt'=>(string)($time['raw']??''),
        'timestamp'=>(int)($time['stamp']??0),
        'hasExplicitTimestamp'=>(int)($time['stamp']??0)>0,
        'priority'=>$priority
    ];
}
function scanner_latest_timed_cost(array $candidates): ?array {
    $timed=array_values(array_filter($candidates,static fn($candidate)=>(bool)($candidate['hasExplicitTimestamp']??false)));
    if (!$timed) return null;
    usort($timed,static function($a,$b){
        return ($b['timestamp']<=>$a['timestamp']) ?: ($b['priority']<=>$a['priority']);
    });
    return $timed[0];
}
function scanner_effective_cost(array $product, array $sku): array {
    $candidates=[];
    $manualTime=scanner_cost_time($sku,['manualCostUpdatedAt']);
    $manual=scanner_cost_candidate(scanner_cost_number($sku,['manualCostTwd']), (string)($sku['manualCostSource']??'manual_cost'), $manualTime, 50);
    if ($manual!==null) $candidates[]=$manual;

    $currentTime=scanner_cost_time($sku,['costEffectiveAt','currentCostUpdatedAt']);
    $current=scanner_cost_candidate(scanner_cost_number($sku,['currentCostTwd']), (string)($sku['costSource']??'current_cost'), $currentTime, 40);
    if ($current!==null) $candidates[]=$current;

    $costTime=scanner_cost_time($sku,['costUpdatedAt']);
    $explicitCost=scanner_cost_candidate(scanner_cost_number($sku,['cost']), (string)($sku['costSource']??'manual_cost'), $costTime, 45);
    if ($explicitCost!==null && $explicitCost['hasExplicitTimestamp']) $candidates[]=$explicitCost;

    $finalTime=scanner_cost_time($sku,['finalCostUpdatedAt']);
    $final=scanner_cost_candidate(scanner_cost_number($sku,['finalCostTwd']), (string)($sku['costSource']??'final_cost'), $finalTime, 35);
    if ($final!==null) $candidates[]=$final;

    $snapshot=is_array($sku['freightCostSnapshot']??null)?$sku['freightCostSnapshot']:[];
    $freightTime=scanner_cost_time($sku,['freightCostUpdatedAt']);
    if (!$freightTime['stamp']) $freightTime=scanner_cost_time($snapshot,['capturedAt','updatedAt']);
    $forwarder=trim((string)($sku['freightForwarder']??$snapshot['forwarder']??''));
    $freightSource=$forwarder!==''?'freight_receipt:'.$forwarder:'freight_receipt';
    $freight=scanner_cost_candidate(
        scanner_cost_number($sku,['freightFinalCostTwd']),
        $freightSource,
        $freightTime,
        60
    );
    if ($freight===null) $freight=scanner_cost_candidate(scanner_cost_number($snapshot,['finalCostTwd']),$freightSource,$freightTime,60);
    if ($freight!==null) $candidates[]=$freight;

    foreach (($sku['freightCostLots']??[]) as $lot) {
        if (!is_array($lot)) continue;
        $lotTime=scanner_cost_time($lot,['capturedAt','updatedAt','receivedAt','createdAt']);
        $lotForwarder=trim((string)($lot['forwarder']??$forwarder));
        $lotSource=$lotForwarder!==''?'freight_receipt:'.$lotForwarder:'freight_receipt';
        $lotCandidate=scanner_cost_candidate(scanner_cost_number($lot,['finalCostTwd','unitCostTwd','costTwd']),$lotSource,$lotTime,65);
        if ($lotCandidate!==null) $candidates[]=$lotCandidate;
    }

    $productCandidates=[];
    $productManualTime=scanner_cost_time($product,['manualCostUpdatedAt']);
    $productManual=scanner_cost_candidate(scanner_cost_number($product,['manualCostTwd']), (string)($product['manualCostSource']??'product_manual_cost'), $productManualTime, 50);
    if ($productManual!==null) $productCandidates[]=$productManual;
    $productCurrentTime=scanner_cost_time($product,['costEffectiveAt','currentCostUpdatedAt']);
    $productCurrent=scanner_cost_candidate(scanner_cost_number($product,['currentCostTwd']), (string)($product['costSource']??'product_current_cost'), $productCurrentTime, 40);
    if ($productCurrent!==null) $productCandidates[]=$productCurrent;
    $productFinalTime=scanner_cost_time($product,['finalCostUpdatedAt']);
    $productFinal=scanner_cost_candidate(scanner_cost_number($product,['finalCostTwd']), (string)($product['costSource']??'product_final_cost'), $productFinalTime, 35);
    if ($productFinal!==null) $productCandidates[]=$productFinal;
    $productFreightTime=scanner_cost_time($product,['freightCostUpdatedAt']);
    $productFreight=scanner_cost_candidate(scanner_cost_number($product,['freightFinalCostTwd']), (string)($product['freightCostSource']??'product_freight_cost'), $productFreightTime, 60);
    if ($productFreight!==null) $productCandidates[]=$productFreight;
    $productCostTime=scanner_cost_time($product,['costUpdatedAt']);
    $productCost=scanner_cost_candidate(scanner_cost_number($product,['cost']), (string)($product['costSource']??'product_master_cost'), $productCostTime, 45);
    if ($productCost!==null) $productCandidates[]=$productCost;
    $latestTimed=scanner_latest_timed_cost(array_merge($candidates,$productCandidates));
    if ($latestTimed!==null) return $latestTimed;

    foreach ([['currentCostTwd','product_current_cost'],['finalCostTwd','product_final_cost'],['freightFinalCostTwd','product_final_cost'],['cost','product_master_cost'],['purchaseCost','product_purchase_cost']] as $fallback) {
        $productValue=scanner_cost_number($product,[$fallback[0]]);
        if ($productValue===null) continue;
        return ['available'=>true,'value'=>round($productValue,2),'source'=>(string)($product['costSource']??$fallback[1]),'updatedAt'=>'','timestamp'=>0,'hasExplicitTimestamp'=>false,'priority'=>10];
    }

    $fallbackValue=scanner_cost_number($sku,['currentCostTwd','freightFinalCostTwd','finalCostTwd','manualCostTwd','cost','purchaseCost']);
    if ($fallbackValue!==null) {
        $codes=[(string)($sku['legacyBarcode']??''),(string)($sku['companyBarcode']??''),(string)($sku['barcode']??''),(string)($sku['sku']??''),(string)($sku['id']??'')];
        $legacy=false; foreach($codes as $code)if(preg_match('/P\d/i',$code)){$legacy=true;break;}
        return ['available'=>true,'value'=>round($fallbackValue,2),'source'=>$legacy?'legacy_barcode_cost':'sku_cost_fallback','updatedAt'=>'','timestamp'=>0,'hasExplicitTimestamp'=>false,'priority'=>0];
    }
    return ['available'=>false,'value'=>0.0,'source'=>'cost_unavailable','updatedAt'=>'','timestamp'=>0,'hasExplicitTimestamp'=>false,'priority'=>-1];
}
function scanner_newer_effective_cost(array $sourceCost, array $targetCost): array {
    if (!($sourceCost['available']??false)) { $targetCost['syncAcrossWarehouses']=true; return $targetCost; }
    if (!($targetCost['available']??false)) { $sourceCost['syncAcrossWarehouses']=true; return $sourceCost; }
    $sourceTimed=(bool)($sourceCost['hasExplicitTimestamp']??false);$targetTimed=(bool)($targetCost['hasExplicitTimestamp']??false);
    if ($sourceTimed!==$targetTimed) { $chosen=$sourceTimed?$sourceCost:$targetCost; $chosen['syncAcrossWarehouses']=true; return $chosen; }
    if ($sourceTimed) { $chosen=(int)$targetCost['timestamp']>(int)$sourceCost['timestamp']?$targetCost:$sourceCost; $chosen['syncAcrossWarehouses']=true; return $chosen; }
    if (abs((float)($sourceCost['value']??0)-(float)($targetCost['value']??0))<0.005) { $sourceCost['syncAcrossWarehouses']=true; return $sourceCost; }
    // Two different legacy/undated values do not tell us which one is newer.
    // Keep both SKU records unchanged and only snapshot the source unit cost in
    // the transfer ledger until a real receipt/manual/import cost event exists.
    $sourceCost['syncAcrossWarehouses']=false;
    $sourceCost['resolution']='ambiguous_undated_costs_kept_separate';
    return $sourceCost;
}
function scanner_apply_effective_cost(array &$sku, array $effective, string $syncedAt): void {
    if (!($effective['available']??false)) return;
    $sku['currentCostTwd']=round((float)$effective['value'],2);
    $sku['cost']=round((float)$effective['value'],2);
    $sku['costSource']=(string)($effective['source']??'current_cost');
    $sku['costEffectiveAt']=(string)($effective['updatedAt']??'');
    $sku['costSyncedAt']=$syncedAt;
}
function find_target_sku(array $skus, array $source, string $warehouse): ?int {
    $key=sku_key($source);
    foreach ($skus as $i=>$candidate) if (is_array($candidate) && sku_key($candidate)===$key && sku_warehouse($candidate)===$warehouse) return $i;
    return null;
}
function clone_to_warehouse(array &$skus, array $source, string $warehouse, int $stock, array $product=[], ?array $effectiveCost=null): int {
    $copy=$source; $base=(string)($source['id']??$source['sku']??'SKU');
    $code=scanner_warehouse_code($warehouse) ?: 'TW';
    $baseRoot=preg_replace('/-(TW|CN|ID)(?:-[A-Z0-9]+)?$/i','',$base) ?: $base;
    $copy['id']=scanner_unique_id($skus, $baseRoot.'-'.$code); $copy['sku']=$copy['id']; $copy['sourceSkuId']=$base; $copy['stock']=$stock;
    $copy['warehouse']=$warehouse; $copy['warehouseName']=$warehouse; $copy['warehouseCode']=$code;
    $copy['chinaSample']=false; $copy['productMode']='ready'; $copy['showOnWebsite']=$code==='TW'; $copy['sampleStock']=false; $copy['stockPurpose']='live_ready';
    $now=date(DATE_ATOM);$copy['createdAt']=$now;$copy['updatedAt']=$now;
    scanner_apply_effective_cost($copy,$effectiveCost??scanner_effective_cost($product,$source),$now);
    $skus[]=$copy; return count($skus)-1;
}
function warehouses_from_state(array $state, array $skus): array {
    // 盤點機只提供實際作業使用的四種庫存歸屬，順序固定，避免舊資料中的
    // 待判別倉／退貨倉擠掉主要選項。預購倉也必須能直接盤點與查詢。
    return ['中國倉','台灣倉','印尼倉','預購倉'];
}
function scanner_location_list_from_state(array $state, array $skus, string $key): array {
    $list=[];
    foreach (($state[$key]??[]) as $row) { $name=is_array($row)?($row['name']??$row['label']??$row['value']??''):$row; if($name!==''&&!in_array($name,$list,true))$list[]=$name; }
    $skuKey=$key==='shelves'?'shelf':'layer';
    foreach ($skus as $sku) if(is_array($sku)){ $name=trim((string)($sku[$skuKey]??'')); if($name!==''&&!in_array($name,$list,true))$list[]=$name; }
    return array_values(array_filter($list,fn($name)=>$name!==''));
}
function scanner_clean_location_name($value): string {
    return trim((string)$value);
}
function scanner_location_invalid_error(string $key, string $value): string {
    if($key==='shelves' && ($value==='D倉-R01' || preg_match('/[區区]/u',$value))) return '貨架名稱不能包含「區」，請輸入實際的「架」名稱。';
    if($key==='layers' && preg_match_all('/第[^層层]+[層层]/u',$value,$matches)>1) return '層位必須分開建立，不能把兩個層位放在同一項。';
    return '';
}
function scanner_add_location_values(array &$state, string $key, array $values): void {
    if(!isset($state[$key])||!is_array($state[$key]))$state[$key]=[];
    $existing=[];
    foreach($state[$key] as $row){
        $name=is_array($row)?($row['name']??$row['label']??$row['value']??''):$row;
        $name=scanner_clean_location_name($name);
        if($name!=='')$existing[$name]=true;
    }
    foreach($values as $value){
        $name=scanner_clean_location_name($value);
        if($name===''||isset($existing[$name]))continue;
        $state[$key][]=['name'=>$name,'createdAt'=>date(DATE_ATOM)];
        $existing[$name]=true;
    }
}
function scanner_warehouse_code($value): string {
    $text=strtolower(trim((string)$value));
    if($text==='cn'||preg_match('/中國|中国|china/u',$text))return 'CN';
    if($text==='id'||preg_match('/印尼|indonesia|indo/u',$text))return 'ID';
    if($text==='tw'||preg_match('/台灣|台湾|taiwan/u',$text))return 'TW';
    return '';
}
function scanner_reallocation_lane(array $order): string {
    if (strtolower(trim((string)($order['reservedShippingStatus'] ?? ''))) !== 'reserved') return '';
    $status = strtolower(trim((string)($order['status'] ?? '')));
    if (in_array($status, ['shipped','in_transit','delivered','completed','returned','cancelled'], true)) return '';
    $date = trim((string)($order['reservedShippingDate'] ?? ''));
    $reason = trim((string)($order['reservedShippingHoldReason'] ?? ''));
    if ($date !== '' || $reason === 'scheduled_ship') return 'scheduled';
    return $reason !== '' ? 'hold' : '';
}
function scanner_reallocation_item_qty(array $item): int {
    return max(0, (int)($item['qty'] ?? $item['quantity'] ?? $item['requestedQty'] ?? 0));
}
function scanner_reallocation_text_key($value): string {
    return scanner_norm(preg_replace('/\([^)]*\)/u', '', (string)$value) ?? (string)$value);
}
function scanner_reallocation_item_matches(array $item, array $sku, array $product): bool {
    $skuId = scanner_norm($sku['id'] ?? $sku['sku'] ?? '');
    foreach ([$item['skuId'] ?? '', $item['sku'] ?? '', $item['barcode'] ?? ''] as $candidate) {
        if ($skuId !== '' && scanner_norm($candidate) === $skuId) return true;
    }
    $itemProduct = scanner_norm($item['productId'] ?? '');
    $skuProduct = scanner_norm($sku['productId'] ?? '');
    if ($itemProduct !== '' && $skuProduct !== '' && $itemProduct !== $skuProduct) return false;
    if ($itemProduct === '' || $skuProduct === '') {
        $itemCode = scanner_quick_product_code($item['code'] ?? $item['productCode'] ?? $item['skuId'] ?? $item['sku'] ?? '');
        $productCode = scanner_quick_product_code($product['code'] ?? $product['id'] ?? '');
        if ($itemCode === '' || $productCode === '' || scanner_norm($itemCode) !== scanner_norm($productCode)) return false;
    }
    $itemColor = scanner_reallocation_text_key($item['color'] ?? $item['colorName'] ?? '');
    $skuColor = scanner_reallocation_text_key($sku['colorName'] ?? $sku['color'] ?? '');
    $itemSize = scanner_norm(scanner_quick_size($item['size'] ?? $item['sizeName'] ?? 'NO SIZE'));
    $skuSize = scanner_norm(scanner_quick_size($sku['sizeName'] ?? $sku['size'] ?? 'NO SIZE'));
    return $itemColor !== '' && $itemColor === $skuColor && $itemSize === $skuSize;
}
function scanner_reset_taiwan_reallocation_queue(array &$orders, string $operator, string $now): array {
    $orderCount = 0; $itemCount = 0; $qty = 0;
    foreach ($orders as &$order) {
        if (!is_array($order) || scanner_reallocation_lane($order) === '') continue;
        $changed = false;
        foreach (($order['items'] ?? []) as $index => $item) {
            if (!is_array($item)) continue;
            $requested = scanner_reallocation_item_qty($item);
            if ($requested < 1) continue;
            $order['items'][$index]['temporaryStockAllocationRequestedQty'] = $requested;
            $order['items'][$index]['temporaryStockAllocatedQty'] = 0;
            $order['items'][$index]['temporaryStockAllocationStatus'] = 'waiting_scan';
            $order['items'][$index]['temporaryStockAllocationWarehouse'] = 'TW';
            $order['items'][$index]['temporaryStockAllocationResetAt'] = $now;
            $order['items'][$index]['priorityAllocationStatus'] = 'waiting_initial_stock_scan';
            $order['items'][$index]['waitingArrival'] = true;
            $itemCount++; $qty += $requested; $changed = true;
        }
        if ($changed) {
            $order['temporaryStockAllocationStatus'] = 'waiting_scan';
            $order['temporaryStockAllocationResetAt'] = $now;
            $order['temporaryStockAllocationResetBy'] = $operator;
            $order['updatedAt'] = $now;
            $orderCount++;
        }
    }
    unset($order);
    return ['orders' => $orderCount, 'items' => $itemCount, 'qty' => $qty];
}
function scanner_allocate_taiwan_receipt(array &$orders, array $sku, array $product, int $receiptQty, string $batchId, string $operator, string $now): array {
    if ($receiptQty < 1 || scanner_warehouse_code($sku['warehouseCode'] ?? $sku['warehouseName'] ?? $sku['warehouse'] ?? '') !== 'TW') return [];
    $candidates = [];
    foreach ($orders as $orderIndex => $order) {
        if (!is_array($order)) continue;
        $lane = scanner_reallocation_lane($order);
        if ($lane === '') continue;
        $orderItems = is_array($order['items'] ?? null) ? $order['items'] : [];
        foreach ($orderItems as $itemIndex => $item) {
            if (!is_array($item) || !scanner_reallocation_item_matches($item, $sku, $product)) continue;
            $requested = max(0, (int)($item['temporaryStockAllocationRequestedQty'] ?? scanner_reallocation_item_qty($item)));
            $allocated = max(0, (int)($item['temporaryStockAllocatedQty'] ?? 0));
            if ($requested <= $allocated) continue;
            $candidates[] = [
                'orderIndex' => $orderIndex, 'itemIndex' => $itemIndex, 'lane' => $lane,
                'date' => $lane === 'scheduled' ? (string)($order['reservedShippingDate'] ?? '') : '9999-12-31',
                'createdAt' => (string)($order['createdAt'] ?? $order['reservedShippingAt'] ?? ''),
                'requested' => $requested, 'allocated' => $allocated,
            ];
        }
    }
    usort($candidates, static function($a, $b) {
        $lane = ($a['lane'] === 'scheduled' ? 0 : 1) <=> ($b['lane'] === 'scheduled' ? 0 : 1);
        if ($lane !== 0) return $lane;
        return strcmp($a['date'], $b['date']) ?: strcmp($a['createdAt'], $b['createdAt']) ?: ($a['orderIndex'] <=> $b['orderIndex']);
    });
    $remaining = $receiptQty; $allocations = [];
    foreach ($candidates as $candidate) {
        if ($remaining < 1) break;
        $need = max(0, $candidate['requested'] - $candidate['allocated']);
        $take = min($remaining, $need);
        if ($take < 1) continue;
        $orderIndex = $candidate['orderIndex']; $itemIndex = $candidate['itemIndex'];
        $newAllocated = $candidate['allocated'] + $take;
        $orders[$orderIndex]['items'][$itemIndex]['temporaryStockAllocatedQty'] = $newAllocated;
        $orders[$orderIndex]['items'][$itemIndex]['temporaryStockAllocationStatus'] = $newAllocated >= $candidate['requested'] ? 'allocated' : 'partial';
        $orders[$orderIndex]['items'][$itemIndex]['temporaryStockAllocatedAt'] = $now;
        $orders[$orderIndex]['items'][$itemIndex]['temporaryStockAllocationBatchId'] = $batchId;
        $orders[$orderIndex]['items'][$itemIndex]['priorityAllocationStatus'] = $newAllocated >= $candidate['requested'] ? 'warehouse_reserved' : 'waiting_initial_stock_scan';
        $orders[$orderIndex]['items'][$itemIndex]['waitingArrival'] = $newAllocated < $candidate['requested'];
        $orders[$orderIndex]['updatedAt'] = $now;
        $orderDone = true;
        $checkItems = is_array($orders[$orderIndex]['items'] ?? null) ? $orders[$orderIndex]['items'] : [];
        foreach ($checkItems as $checkItem) {
            if (!is_array($checkItem)) continue;
            $checkRequested = max(0, (int)($checkItem['temporaryStockAllocationRequestedQty'] ?? 0));
            if ($checkRequested > max(0, (int)($checkItem['temporaryStockAllocatedQty'] ?? 0))) { $orderDone = false; break; }
        }
        $orders[$orderIndex]['temporaryStockAllocationStatus'] = $orderDone ? 'allocated' : 'waiting_scan';
        if (!isset($orders[$orderIndex]['temporaryStockAllocationHistory']) || !is_array($orders[$orderIndex]['temporaryStockAllocationHistory'])) $orders[$orderIndex]['temporaryStockAllocationHistory'] = [];
        array_unshift($orders[$orderIndex]['temporaryStockAllocationHistory'], [
            'at' => $now, 'batchId' => $batchId, 'skuId' => (string)($sku['id'] ?? $sku['sku'] ?? ''),
            'qty' => $take, 'lane' => $candidate['lane'], 'operator' => $operator,
        ]);
        $orders[$orderIndex]['temporaryStockAllocationHistory'] = array_slice($orders[$orderIndex]['temporaryStockAllocationHistory'], 0, 100);
        $allocations[] = ['orderId' => (string)($orders[$orderIndex]['id'] ?? ''), 'itemIndex' => $itemIndex, 'qty' => $take, 'lane' => $candidate['lane']];
        $remaining -= $take;
    }
    return $allocations;
}
function scanner_same_variant(array $sku, array $item): bool {
    $productId=(string)($sku['productId']??'');
    if($productId!==''&&(string)($item['productId']??'')!==''&&$productId===(string)$item['productId']){
        $color=scanner_norm($sku['colorName']??$sku['color']??'');
        $size=scanner_norm(scanner_quick_size($sku['sizeName']??$sku['size']??'NO SIZE'));
        return $color===scanner_norm($item['color']??'')&&$size===scanner_norm(scanner_quick_size($item['size']??'NO SIZE'));
    }
    return false;
}
function scanner_effective_category(array $product, array $sku=[]): string {
    foreach ([$product['category']??'', $sku['category']??''] as $category) {
        $category=trim((string)$category);
        if($category!==''&&$category!=='快速入庫待補'&&$category!=='請先選分類'&&$category!=='未設定分類')return $category;
    }
    $aliases=is_array($product['nameAliases']??null)?$product['nameAliases']:[];
    $haystack=implode(' ',array_merge([
        (string)($product['title']??''),(string)($product['description']??''),(string)($product['spec']??''),(string)($product['specification']??'')
    ],array_map('strval',$aliases)));
    $rules=[
        '水壺/保溫杯'=>'保溫杯|冰霸杯|隨身杯|水壺|水杯|杯子',
        '長褲'=>'長褲|牛仔褲|寬褲',
        '短褲'=>'短褲',
        '內褲'=>'內褲',
        '短袖上衣'=>'短袖|T恤|上衣',
        '長袖上衣'=>'長袖',
        '外套'=>'外套|夾克',
        '洋裝'=>'洋裝|連身裙',
        '鞋子'=>'鞋|拖鞋|涼鞋',
        '包包'=>'包包|手提包|肩背包|皮包|腰包',
        '帽子'=>'帽子|棒球帽',
        '餐具'=>'餐具|湯匙|筷子|叉子',
        '餐碗類'=>'餐碗|飯碗|碗盤',
        '化妝品'=>'化妝品|粉底|口紅|唇膏',
        '美髮'=>'洗髮|護髮|髮膜'
    ];
    foreach($rules as $category=>$pattern)if(preg_match('/'.$pattern.'/iu',$haystack))return $category;
    return '快速入庫待補';
}
function scanner_categories(array $products, array $skus = []): array {
    $seen=[];$rows=[];
    foreach ($products as $product) {
        if (!is_array($product)) continue;
        $category=trim((string)($product['category']??''));
        if ($category===''||$category==='快速入庫待補'||$category==='請先選分類'||isset($seen[$category])) continue;
        $seen[$category]=true;
        $rows[]=['name'=>$category,'updatedAt'=>(string)($product['updatedAt']??$product['createdAt']??'')];
    }
    foreach ($skus as $sku) {
        if (!is_array($sku)) continue;
        $category=trim((string)($sku['category']??''));
        if ($category===''||$category==='快速入庫待補'||$category==='請先選分類'||isset($seen[$category])) continue;
        $seen[$category]=true;
        $rows[]=['name'=>$category,'updatedAt'=>(string)($sku['updatedAt']??$sku['createdAt']??'')];
    }
    usort($rows,static fn($a,$b)=>strcmp((string)$b['updatedAt'],(string)$a['updatedAt']));
    return array_slice(array_column($rows,'name'),0,80);
}
function scanner_backend_colors(array $products, array $skus): array {
    $seen=[];$colors=[];
    $append=static function($value) use (&$seen,&$colors): void {
        $name=trim((string)$value);
        if($name===''||scanner_placeholder_color($name))return;
        $key=mb_strtoupper($name,'UTF-8');
        if(isset($seen[$key]))return;
        $seen[$key]=true;$colors[]=$name;
    };
    foreach($products as $product){
        if(!is_array($product))continue;
        foreach(($product['colors']??[]) as $color)$append(is_array($color)?($color['name']??$color['colorName']??$color['color']??''):$color);
    }
    foreach($skus as $sku)if(is_array($sku))$append($sku['colorName']??$sku['color']??'');
    return array_slice($colors,0,200);
}
function scanner_category_prefixes(array $products): array {
    $counts=[];
    foreach ($products as $product) {
        if (!is_array($product)) continue;
        $code=strtoupper(trim((string)($product['code']??'')));
        $category=trim((string)($product['category']??''));
        if ($category===''||$category==='快速入庫待補'||$category==='請先選分類'||!preg_match('/^[A-Z]+/',$code,$match)) continue;
        $prefix=$match[0];
        if (!isset($counts[$prefix])) $counts[$prefix]=[];
        $counts[$prefix][$category]=($counts[$prefix][$category]??0)+1;
    }
    $result=[];
    foreach ($counts as $prefix=>$categories) {
        arsort($categories);
        $category=(string)array_key_first($categories);
        $count=(int)($categories[$category]??0);
        $total=(int)array_sum($categories);
        $result[$prefix]=['category'=>$category,'count'=>$count,'total'=>$total,'confidence'=>$total>0?round($count/$total,3):0];
    }
    uksort($result,static fn($a,$b)=>strlen($b)<=>strlen($a)?:strcmp($a,$b));
    return $result;
}
function scanner_find_product_index(array $products, string $productId, string $productCode=''): ?int {
    foreach ($products as $i=>$product) {
        if (!is_array($product)) continue;
        if ($productId!==''&&((string)($product['id']??'')===$productId||(string)($product['code']??'')===$productId)) return $i;
        if ($productCode!==''&&(string)($product['code']??'')===$productCode) return $i;
    }
    return null;
}
function scanner_find_variant_warehouse(array $skus, string $productId, string $color, string $size, string $warehouse): ?int {
    $size=scanner_quick_size($size);
    foreach ($skus as $i=>$sku) {
        if (!is_array($sku)) continue;
        if ((string)($sku['productId']??'')!==$productId) continue;
        if (trim((string)($sku['colorName']??$sku['color']??''))!==$color) continue;
        if (scanner_quick_size($sku['sizeName']??$sku['size']??'NO SIZE')!==$size) continue;
        if (sku_warehouse($sku)===$warehouse) return $i;
    }
    return null;
}
function scanner_unique_id(array $rows, string $base): string {
    $used=[];foreach($rows as $row)if(is_array($row))foreach(['id','sku'] as $key){$value=(string)($row[$key]??'');if($value!=='')$used[$value]=true;}
    $clean=preg_replace('/[^A-Z0-9_-]/i','-',trim($base)) ?: 'QSI';
    if (!isset($used[$clean])) return $clean;
    do {$candidate=$clean.'-'.strtoupper(bin2hex(random_bytes(2)));} while(isset($used[$candidate]));
    return $candidate;
}
function scanner_placeholder_color(string $color): bool {
    return $color===''||in_array(strtoupper($color),['待補顏色','未設定顏色','NO COLOR'],true);
}
function last_stocktake_map(array $sessions): array {
    $map=[]; foreach ($sessions as $session) { if(!is_array($session)||($session['status']??'')!=='approved')continue; foreach(($session['lines']??[]) as $line){ if(!is_array($line))continue; $key=(string)($line['warehouse']??'').'|'.(string)($line['productId']??'').'|'.(string)($line['color']??'').'|'.(string)($line['size']??''); $time=(string)($session['approvedAt']??$session['submittedAt']??''); if(!isset($map[$key])||$time>$map[$key]['at'])$map[$key]=['at'=>$time,'qty'=>(int)($line['countedQty']??0)]; } }
    return $map;
}
function scanner_stocktake_live_compare(array $sessions, array $skus): array {
    global $productMap;
    $skuIndex=[];
    foreach($skus as $index=>$sku){
        if(!is_array($sku))continue;
        $id=(string)($sku['id']??$sku['sku']??'');
        if($id!=='')$skuIndex[$id]=$index;
    }
    foreach($sessions as &$session){
        if(!is_array($session))continue;
        foreach(($session['lines']??[]) as $lineIndex=>$line){
            if(!is_array($line))continue;
            $sourceId=(string)($line['sourceSkuId']??'');
            $sourceIndex=$skuIndex[$sourceId]??null;
            $currentStock=0;
            if($sourceIndex!==null){
                $target=find_target_sku($skus,$skus[$sourceIndex],(string)($line['warehouse']??$session['warehouse']??''));
                if($target!==null)$currentStock=(int)($skus[$target]['stock']??0);
            }
            $session['lines'][$lineIndex]['currentStock']=$currentStock;
            $sourceSku=$sourceIndex!==null?$skus[$sourceIndex]:[];
            $lineProduct=$productMap[(string)($sourceSku['productId']??$line['productId']??'')]??[];
            $session['lines'][$lineIndex]['displayProductCode']=(string)($line['displayProductCode']??$line['productCode']??$lineProduct['code']??$lineProduct['id']??'');
            $session['lines'][$lineIndex]['displayProductName']=(string)($line['displayProductName']??$line['productName']??$lineProduct['title']??$lineProduct['name']??'未找到產品名稱');
            $session['lines'][$lineIndex]['displayImage']=trim((string)($line['displayImage']??$line['image']??''))?:scanner_image($lineProduct,$sourceSku);
            $session['lines'][$lineIndex]['currentVariance']=(int)($line['countedQty']??0)-$currentStock;
        }
    }
    unset($session);
    return $sessions;
}
function scanner_printed_barcode_matches(string $query, array $product, array $sku): bool {
    $q = scanner_norm($query);
    if ($q === '') return false;
    $rawProductCodes = [
        scanner_norm($product['code'] ?? ''),
        scanner_norm($product['barcode'] ?? ''),
        scanner_norm($product['companyBarcode'] ?? ''),
        scanner_norm($product['mappingCode'] ?? ''),
        scanner_norm($sku['mappingCode'] ?? ''),
        scanner_category_serial($product, $sku)
    ];
    $productCodes = [];
    foreach ($rawProductCodes as $rawProductCode) {
        if ($rawProductCode === '') continue;
        $productCodes[] = $rawProductCode;
        // Some legacy product masters accidentally stored a full printed barcode
        // (for example OLAN75P285902) as the product code.  Keep accepting the
        // stable product-code portion when the cost embedded in a new label changes.
        $baseProductCode = scanner_quick_product_code($rawProductCode);
        if ($baseProductCode !== '') $productCodes[] = $baseProductCode;
    }
    foreach (($product['nameAliases'] ?? []) as $alias) {
        $baseProductCode = scanner_quick_product_code($alias);
        if ($baseProductCode !== '') $productCodes[] = $baseProductCode;
    }
    $productCodes = array_values(array_unique($productCodes));
    $colorCode = scanner_norm($sku['colorCode'] ?? ($sku['colorNo'] ?? ($sku['legacyColorCode'] ?? '')));
    $colorCodes = $colorCode === '' ? [] : [$colorCode];
    foreach ([$sku['officialBarcode']??'',$sku['companyBarcode']??'',$sku['legacyBarcode']??'',$sku['barcode']??'',$sku['labelBarcode']??''] as $savedLabel) {
        $saved=scanner_barcode_core($savedLabel);
        if($saved==='')continue;
        if(preg_match('/C([A-Z0-9]+)S([A-Z0-9]+)P\d+$/',$saved,$fromV3)===1)$colorCodes[]=(string)$fromV3[1];
        if(preg_match('/P\d+C([A-Z0-9]+)S([A-Z0-9]+)$/',$saved,$fromV2)===1)$colorCodes[]=(string)$fromV2[1];
    }
    $sizeCode = scanner_norm($sku['sizeCode'] ?? ($sku['sizeNo'] ?? ($sku['legacySizeCode'] ?? '')));
    $name=strtoupper(trim((string)($sku['colorName']??$sku['color']??'')));
    // Barcode lookup only trusts the color code stored on the new product/SKU
    // records (or their explicit product-color metadata). Never infer a code
    // from the retired legacy color chart.
    // Product cards may retain the exact code printed on an older label even
    // when the SKU itself only has a generic palette code (for example CE16 906).
    $productColors=$product['colors']??[];
    if(isset($productColors['name'])||isset($productColors['colorName']))$productColors=[$productColors];
    if(is_array($productColors))foreach($productColors as $productColor){
        if(!is_array($productColor))continue;
        $productColorName=(string)($productColor['name']??$productColor['colorName']??$productColor['color']??'');
        if(scanner_color_key($productColorName)!==scanner_color_key($name))continue;
        foreach(['code','colorCode','no','colorNo','legacyColorCode','barcodeColorCode'] as $key){
            $candidate=scanner_norm($productColor[$key]??'');
            if($candidate!=='')$colorCodes[]=$candidate;
        }
    }
    $colorCodes=array_values(array_unique(array_filter($colorCodes)));
    if($sizeCode===''){
        $legacySizes=['XS'=>'1','S'=>'2','M'=>'3','L'=>'4','XL'=>'5','2XL'=>'6','3XL'=>'7','4XL'=>'8','NO SIZE'=>'00'];
        $quickSize=scanner_quick_size($sku['sizeName']??$sku['size']??'');
        $sizeCode=$legacySizes[$quickSize]??(preg_match('/^[0-9]+$/',$quickSize)===1?$quickSize:'');
    }
    // V2 labels separate color and size explicitly, so a color code such as 90
    // plus size 6 can never be mistaken for the independent color code 906.
    // Cost is intentionally not part of identity: an already printed label must
    // continue resolving to the same variant after the latest cost changes.
    $expectedSize=$sizeCode;
    if ($expectedSize==='' && scanner_quick_size($sku['sizeName']??$sku['size']??'')==='NO SIZE') $expectedSize='00';
    if ($expectedSize==='') $expectedSize=scanner_legacy_size_code($sku['sizeName']??$sku['size']??'');
    if (preg_match('/^(.+?)C([A-Z0-9]+)S([A-Z0-9]+)P\d+$/', $q, $v3) === 1) {
        $v3Product=(string)$v3[1];
        $v3Color=(string)$v3[2];
        $v3Size=(string)$v3[3];
        return in_array($v3Product,$productCodes,true) && in_array($v3Color,$colorCodes,true) && $expectedSize!=='' && $v3Size===$expectedSize;
    }
    if (preg_match('/^(.+?)P\d+C([A-Z0-9]+)S([A-Z0-9]+)$/', $q, $v2) === 1) {
        $v2Product=(string)$v2[1];
        $v2Color=(string)$v2[2];
        $v2Size=(string)$v2[3];
        return in_array($v2Product,$productCodes,true) && in_array($v2Color,$colorCodes,true) && $expectedSize!=='' && $v2Size===$expectedSize;
    }
    // LZ_SCAN_LOOKUP_20260924: inbound print {編號}{色碼}{尺碼}P{成本}. Cost after P is not identity.
    $isCsForm=preg_match('/C[A-Z0-9]+S[A-Z0-9]+P\d+$/',$q)===1||preg_match('/P\d+C[A-Z0-9]+S[A-Z0-9]+$/',$q)===1;
    if (!$isCsForm && preg_match('/^(.+)P(\d+)$/', $q, $pend) === 1) {
        $head=(string)$pend[1];
        $sizeCandidates=[];
        if($expectedSize!==''){
            $sizeCandidates[]=$expectedSize;
            $padded=str_pad($expectedSize,2,'0',STR_PAD_LEFT);
            if($padded!==$expectedSize)$sizeCandidates[]=$padded;
            if(ltrim($expectedSize,'0')!==''&&ltrim($expectedSize,'0')!==$expectedSize)$sizeCandidates[]=ltrim($expectedSize,'0');
        }
        if($expectedSize===''||$expectedSize==='00'){
            $sizeCandidates[]='';
            $sizeCandidates[]='00';
        }
        $sizeCandidates=array_values(array_unique($sizeCandidates));
        foreach ($productCodes as $productCode) {
            if ($productCode===''||strpos($head,$productCode)!==0) continue;
            $middle=substr($head,strlen($productCode));
            if($middle==='') continue;
            foreach ($colorCodes as $candidateColorCode) {
                if($candidateColorCode===''||strpos($middle,$candidateColorCode)!==0) continue;
                $rest=substr($middle,strlen($candidateColorCode));
                if(in_array($rest,$sizeCandidates,true)) return true;
            }
        }
    }
    // 舊資料有些只保存整串標籤（例如 BA55P4098），沒有獨立色碼。
    // 成本改為 70 後，新標籤 BA55P7098 的穩定部分仍是產品 BA55＋尾碼 98。
    if ($colorCode === '') {
        foreach ([$sku['barcode']??'', $sku['companyBarcode']??'', $sku['legacyBarcode']??''] as $savedLabel) {
            $saved=scanner_norm($savedLabel);
            foreach ($productCodes as $productCode) {
                if ($productCode===''||strpos($saved,$productCode.'P')!==0||strpos($q,$productCode.'P')!==0)continue;
                $savedBody=substr($saved,strlen($productCode)+1);$queryBody=substr($q,strlen($productCode)+1);
                if(preg_match('/^[0-9]+$/',$savedBody)!==1||preg_match('/^[0-9]+$/',$queryBody)!==1)continue;
                $legacySuffix=strlen($savedBody)>=2?substr($savedBody,-2):'';
                if($legacySuffix!==''&&strlen($queryBody)>strlen($legacySuffix)&&substr($queryBody,-strlen($legacySuffix))===$legacySuffix)return true;
            }
        }
        return false;
    }
    $suffixes = [];
    if ($sizeCode !== '') foreach($colorCodes as $candidateColorCode)$suffixes[] = $candidateColorCode . $sizeCode;
    $sizeName = scanner_quick_size($sku['sizeName'] ?? ($sku['size'] ?? ''));
    if ($sizeName === 'NO SIZE' || $sizeCode === '' || $sizeCode === '00') {
        foreach($colorCodes as $candidateColorCode)$suffixes[] = $candidateColorCode;
    }
    $suffixes = array_values(array_unique($suffixes));
    foreach ($productCodes as $productCode) {
        if ($productCode === '' || strpos($q, $productCode . 'P') !== 0) continue;
        $body = substr($q, strlen($productCode) + 1);
        if ($body === '' || preg_match('/^[0-9]+$/', $body) !== 1) continue;
        foreach ($suffixes as $suffix) {
            if ($suffix === '' || strlen($body) <= strlen($suffix)) continue;
            if (substr($body, -strlen($suffix)) === $suffix) return true;
        }
    }
    return false;
}
// Some labels were printed during the migration from the compact legacy format
// (PRODUCTP<cost><color><size>) to V2 (PRODUCTP<cost>C<color>S<size>).
// The legacy suffix is inherently ambiguous: JA316P6009064 was historically
// resolved as blue(96) + L(4), while a mechanical split produced C906S4.
// Only use this compatibility path when the explicit V2 identity matches no SKU
// at all; an exact V2 color/size always has priority.
function scanner_v2_legacy_compat_matches(string $query, array $product, array $sku): bool {
    $q=scanner_norm($query);
    if(preg_match('/^(.+?)P(\d+)C([A-Z0-9]+)S([A-Z0-9]+)$/',$q,$v2)!==1)return false;
    $legacy=(string)$v2[1].'P'.(string)$v2[2].(string)$v2[3].(string)$v2[4];
    return scanner_printed_barcode_matches($legacy,$product,$sku);
}
function scanner_color_key($value): string {
    $value=strtoupper(trim((string)$value));
    return preg_replace('/[\s\p{P}\p{S}]+/u','',$value)??$value;
}
function scanner_color_indonesian($value): string {
    $text=trim((string)$value);
    $pairs=[
        '/粉紅|粉色/u'=>'MERAH MUDA','/深藍|藏青/u'=>'BIRU TUA','/淺藍|天藍/u'=>'BIRU MUDA','/淺綠/u'=>'HIJAU MUDA',
        '/米白|象牙/u'=>'PUTIH GADING','/黑/u'=>'HITAM','/白/u'=>'PUTIH','/紅/u'=>'MERAH','/黃/u'=>'KUNING',
        '/綠/u'=>'HIJAU','/藍/u'=>'BIRU','/銀/u'=>'PERAK','/灰/u'=>'ABU-ABU','/紫/u'=>'UNGU','/咖|棕/u'=>'COKELAT',
        '/金/u'=>'EMAS','/橘/u'=>'ORANYE','/米/u'=>'KREM','/透明/u'=>'TRANSPARAN','/圖片|彩|混色/u'=>'WARNA-WARNI'
    ];
    foreach($pairs as $pattern=>$translation)if(preg_match($pattern,$text)===1)return $translation;
    return 'WARNA LAIN';
}
function scanner_product_historical_barcode_aliases(array $product): array {
    $aliases=[];
    $seen=[];
    $walk=function($node) use (&$walk,&$aliases,&$seen): void {
        if (!is_array($node)) return;
        $color=trim((string)($node['colorName']??$node['color']??''));
        $size=trim((string)($node['sizeName']??$node['size']??''));
        $colorCode=scanner_norm($node['barcodeColorCode']??$node['colorCode']??$node['colorNo']??'');
        $sizeCode=scanner_norm($node['barcodeSizeCode']??$node['sizeCode']??$node['sizeNo']??'');
        if ($color!==''||$colorCode!=='') {
            foreach (['sampleBarcode','taiwanBarcode','printedBarcode','legacyBarcode','barcode'] as $key) {
                $code=trim((string)($node[$key]??''));
                $normalized=scanner_norm($code);
                if ($normalized==='') continue;
                $aliasKey=implode('|',[$normalized,$colorCode,scanner_color_key($color),scanner_quick_size($size),$sizeCode]);
                if (isset($seen[$aliasKey])) continue;
                $seen[$aliasKey]=true;
                $aliases[]=[
                    'code'=>$code,
                    'normalized'=>$normalized,
                    'color'=>$color,
                    'colorCode'=>$colorCode,
                    'size'=>$size,
                    'sizeCode'=>$sizeCode
                ];
            }
        }
        foreach ($node as $child) if (is_array($child)) $walk($child);
    };
    foreach (['purchaseLogistics','logisticsRecords','supplierProcurementRecords'] as $key) {
        if (isset($product[$key])&&is_array($product[$key])) $walk($product[$key]);
    }
    return $aliases;
}
function scanner_alias_matches_sku(array $alias,array $sku): bool {
    $skuColorCode=scanner_norm($sku['colorCode']??$sku['colorNo']??$sku['legacyColorCode']??'');
    $aliasColorCode=(string)($alias['colorCode']??'');
    if ($aliasColorCode!==''&&$skuColorCode!=='') {
        if ($aliasColorCode!==$skuColorCode) return false;
    } else {
        $aliasColor=scanner_color_key($alias['color']??'');
        $skuColor=scanner_color_key($sku['colorName']??$sku['color']??'');
        if ($aliasColor===''||$skuColor===''||$aliasColor!==$skuColor) return false;
    }
    $aliasSize=trim((string)($alias['size']??''));
    if ($aliasSize!==''&&scanner_quick_size($aliasSize)!==scanner_quick_size($sku['sizeName']??$sku['size']??'NO SIZE')) return false;
    $aliasSizeCode=(string)($alias['sizeCode']??'');
    $skuSizeCode=scanner_norm($sku['sizeCode']??$sku['sizeNo']??$sku['legacySizeCode']??'');
    if ($aliasSizeCode!==''&&$skuSizeCode!==''&&$aliasSizeCode!==$skuSizeCode) return false;
    return true;
}
function scanner_indexed_alias_matches_sku(array $alias,array $product,array $sku): bool {
    $aliasSku=trim((string)($alias['skuId']??''));
    $skuId=trim((string)($sku['id']??$sku['sku']??''));
    if($aliasSku!=='') return $aliasSku===$skuId;
    $aliasProduct=trim((string)($alias['productId']??''));
    $productId=trim((string)($product['id']??''));
    if($aliasProduct!=='') return $aliasProduct===$productId;
    $aliasCode=scanner_quick_product_code($alias['productCode']??'');
    $productCode=scanner_quick_product_code($product['code']??$product['id']??'');
    if($aliasCode===''||$aliasCode!==$productCode) return false;
    return scanner_alias_matches_sku($alias,$sku);
}
function scanner_rows(array $products,array $skus,string $query,array $sessions,array $barcodeAliases=[]): array {
    $q=scanner_norm($query); $queryProductCode=scanner_quick_product_code($q); $productMap=scanner_product_map($products);
    $indexedAliases=[];
    foreach($barcodeAliases as $alias)if(is_array($alias)&&scanner_barcode_key_matches($q,$alias['code']??''))$indexedAliases[]=$alias;
    $v2LegacyIndexedAliases=[];
    if(preg_match('/^(.+?)P(\d+)C([A-Z0-9]+)S([A-Z0-9]+)$/',$q,$v2Parts)===1){
        $legacyEquivalent=(string)$v2Parts[1].'P'.(string)$v2Parts[2].(string)$v2Parts[3].(string)$v2Parts[4];
        foreach($barcodeAliases as $alias)if(is_array($alias)&&scanner_barcode_key_matches($legacyEquivalent,$alias['code']??''))$v2LegacyIndexedAliases[]=$alias;
    }
    $historicalAliases=[];
    foreach($products as $product) if(is_array($product)){
        $productId=(string)($product['id']??'');
        if($productId!=='')$historicalAliases[$productId]=scanner_product_historical_barcode_aliases($product);
    }
    $savedExactExists=false;
    foreach($skus as $savedSku) {
        if(!is_array($savedSku)||!empty($savedSku['archived'])||!empty($savedSku['legacyMislinked'])||($savedSku['status']??'active')==='inactive')continue;
        $savedProduct=$productMap[(string)($savedSku['productId']??'')]??[];
        if(!empty($savedProduct['archived'])||!empty($savedProduct['legacyMislinked'])||($savedProduct['status']??'active')==='inactive')continue;
        $savedCodes=scanner_sku_lookup_codes($savedProduct,$savedSku);
        foreach($savedCodes as $savedCode)if(scanner_query_matches_code($q,$savedCode)){$savedExactExists=true;break 2;}
    }
    $v2Query=preg_match('/^(.+?)P\d+C([A-Z0-9]+)S([A-Z0-9]+)$/',$q)===1;
    $v2ExactExists=false;
    if($v2Query){
        foreach($skus as $candidateSku){
            if(!is_array($candidateSku)||!empty($candidateSku['archived'])||!empty($candidateSku['legacyMislinked'])||($candidateSku['status']??'active')==='inactive')continue;
            $candidateProduct=$productMap[(string)($candidateSku['productId']??'')]??[];
            if(!empty($candidateProduct['archived'])||!empty($candidateProduct['legacyMislinked'])||($candidateProduct['status']??'active')==='inactive')continue;
            if(scanner_printed_barcode_matches($query,$candidateProduct,$candidateSku)){$v2ExactExists=true;break;}
        }
    }
    $last=last_stocktake_map($sessions); $rows=[];
    foreach($skus as $sku){ if(!is_array($sku))continue; $product=$productMap[(string)($sku['productId']??'')]??[];
        if(!empty($sku['archived'])||!empty($sku['legacyMislinked'])||($sku['status']??'active')==='inactive'||!empty($product['archived'])||!empty($product['legacyMislinked'])||($product['status']??'active')==='inactive')continue;
        $codes=scanner_sku_lookup_codes($product,$sku);
        $historicalExact=false;$indexedHistoricalExact=false;
        foreach($indexedAliases as $alias)if(scanner_indexed_alias_matches_sku($alias,$product,$sku)){$indexedHistoricalExact=true;break;}
        if(!$savedExactExists)foreach(($historicalAliases[(string)($product['id']??'')]??[]) as $alias){
            if(scanner_barcode_key_matches($q,$alias['code']??'')&&scanner_alias_matches_sku($alias,$sku)){$historicalExact=true;break;}
        }
        $productCode=scanner_quick_product_code($product['code']??$product['id']??'');
        $seriesMatch=$queryProductCode!==''&&$queryProductCode===$q&&$productCode===$queryProductCode;
        $hay=scanner_norm(implode(' ',$codes).' '.($product['title']??'').' '.($sku['colorName']??'').' '.($sku['sizeName']??''));
        $printedExact=scanner_printed_barcode_matches($query,$product,$sku);
        $printedLegacyCompat=$v2Query&&!$v2ExactExists&&scanner_v2_legacy_compat_matches($query,$product,$sku);
        if($v2Query&&!$v2ExactExists&&!$printedLegacyCompat){
            foreach($v2LegacyIndexedAliases as $alias)if(scanner_indexed_alias_matches_sku($alias,$product,$sku)){$printedLegacyCompat=true;break;}
        }
        $exact=$printedExact||$printedLegacyCompat||$historicalExact||$indexedHistoricalExact;
        foreach($codes as $code)if(scanner_query_matches_code($q,$code))$exact=true; if($q!==''&&!$seriesMatch&&!$exact&&strpos($hay,$q)===false)continue;
        $warehouse=sku_warehouse($sku);$color=(string)($sku['colorName']??$sku['color']??'未填顏色');$size=scanner_quick_size($sku['sizeName']??$sku['size']??'NO SIZE');
        $lastKey=$warehouse.'|'.(string)($product['id']??'').'|'.$color.'|'.$size;$effectiveCost=scanner_effective_cost($product,$sku);
        $rows[]=['productId'=>(string)($product['id']??''),'productCode'=>(string)($product['code']??$product['id']??'-'),'productSerial'=>(string)($product['productSerial']??$sku['productSerial']??''),'dedicatedSerialNumber'=>(string)($product['dedicatedSerialNumber']??$product['serial_number']??$sku['dedicatedSerialNumber']??''),'title'=>(string)($product['title']??''),'category'=>scanner_effective_category($product,$sku),'skuId'=>(string)($sku['id']??$sku['sku']??''),'barcode'=>(string)($sku['barcode']??$sku['companyBarcode']??$sku['id']??''),'labelBarcode'=>scanner_generated_label_barcode($product,$sku),'color'=>$color,'colorIndonesian'=>(string)($sku['colorNameId']??scanner_color_indonesian($color)),'legacyColor'=>(string)($sku['legacyColorName']??''),'colorConfirmationRequired'=>(string)($sku['colorMigrationStatus']??'')==='requires_admin_confirmation','size'=>$size,'warehouse'=>$warehouse,'shelf'=>(string)($sku['shelf']??$sku['shelfCode']??''),'layer'=>(string)($sku['layer']??$sku['warehouseLocation']??''),'stock'=>(int)($sku['stock']??0),'stockPurpose'=>(string)($sku['stockPurpose']??''),'sampleStock'=>(bool)($sku['sampleStock']??false),'productMode'=>(string)($sku['productMode']??''),'image'=>scanner_image($product,$sku),'exact'=>$exact,'seriesMatch'=>$seriesMatch,'matchedBy'=>$seriesMatch?'product_series':($indexedHistoricalExact?'indexed_historical_barcode':($historicalExact?'historical_variant_barcode':($printedExact?'printed_backend_barcode':($printedLegacyCompat?'printed_v2_legacy_compat':'saved_barcode')))),'lastCountedStock'=>isset($last[$lastKey])?$last[$lastKey]['qty']:null,'lastCountedAt'=>isset($last[$lastKey])?$last[$lastKey]['at']:null,'currentCostTwd'=>(float)($effectiveCost['value']??0),'costSource'=>(string)($effectiveCost['source']??'cost_unavailable'),'costSourceUpdatedAt'=>(string)($effectiveCost['updatedAt']??''),'costHasExplicitTimestamp'=>(bool)($effectiveCost['hasExplicitTimestamp']??false)];
    }
    usort($rows,static function($a,$b){return ((int)$b['exact']<=>(int)$a['exact'])?:($b['stock']<=>$a['stock']);}); return array_slice($rows,0,60);
}

function scanner_unique_exact_sku_index(array $products,array $skus,string $query,array $sessions,array $barcodeAliases,string $warehouse=''): ?int {
    $rows = scanner_rows($products, $skus, $query, $sessions, $barcodeAliases);
    $exactRows = array_values(array_filter($rows, static fn($row) => !empty($row['exact'])));
    if (!$exactRows) return null;
    $identities = [];
    foreach ($exactRows as $row) {
        $identity = scanner_norm($row['productId'] ?? '') . '|' . scanner_norm($row['labelBarcode'] ?? '') . '|'
            . scanner_color_key($row['color'] ?? '') . '|' . scanner_quick_size($row['size'] ?? 'NO SIZE');
        $identities[$identity] = true;
    }
    if (count($identities) !== 1) {
        scanner_response([
            'ok'=>false,
            'error'=>'此掃描碼同時對到不同產品或規格，已停止寫入庫存。請改掃 QR 完整條碼，或由管理者先修正條碼歸屬。',
            'code'=>'ambiguous_barcode',
            'barcode'=>scanner_norm($query),
            'matches'=>array_map(static fn($row)=>[
                'productCode'=>(string)($row['productCode']??''),
                'skuId'=>(string)($row['skuId']??''),
                'color'=>(string)($row['color']??''),
                'size'=>(string)($row['size']??''),
                'warehouse'=>(string)($row['warehouse']??'')
            ], array_slice($exactRows,0,12))
        ], 409);
    }
    $selected = $exactRows[0];
    if ($warehouse !== '') foreach ($exactRows as $row) {
        if (sku_warehouse(['warehouse'=>$row['warehouse']??'']) === $warehouse || (string)($row['warehouse']??'') === $warehouse) { $selected = $row; break; }
    }
    $wanted = (string)($selected['skuId'] ?? '');
    foreach ($skus as $index => $sku) if ((string)($sku['id'] ?? ($sku['sku'] ?? '')) === $wanted) return $index;
    return null;
}

function scanner_rows_have_identity_conflict(array $rows): bool {
    $identities=[];
    foreach($rows as $row){
        if(!is_array($row)||empty($row['exact']))continue;
        $identity=scanner_norm($row['productId']??'').'|'.scanner_norm($row['labelBarcode']??'').'|'
            .scanner_color_key($row['color']??'').'|'.scanner_quick_size($row['size']??'NO SIZE');
        $identities[$identity]=true;
    }
    return count($identities)>1;
}

// Serialize scanner mutations before reading stock or review status. PHP closes
// this handle on every exit, including validation failures.
if(defined('LINGZANZAN_SCANNER_API_LIB') && LINGZANZAN_SCANNER_API_LIB){ return; }
if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
    $scannerMutationLock=fopen($dataDir.'/scanner-mutation.lock','c');
    if(!$scannerMutationLock||!flock($scannerMutationLock,LOCK_EX))scanner_response(['ok'=>false,'error'=>'庫存作業忙碌，請稍後重試'],423);
    // Product editing and receiving must use the same lock. Previously the
    // scanner and back office could both read an old skus.json and the later
    // writer silently erased the other operation.
    $scannerInventoryLock=fopen($skusFile.'.inventory.lock','c+');
    if(!$scannerInventoryLock||!flock($scannerInventoryLock,LOCK_EX))scanner_response(['ok'=>false,'error'=>'產品或庫存正在儲存，請稍後重試'],423);
}
$products=scanner_read($productsFile); $productMap=scanner_product_map($products); $skus=scanner_read($skusFile); $state=scanner_read($stateFile); $sessions=scanner_read($stocktakesFile); $barcodeAliases=scanner_read($barcodeAliasesFile);
$skusBeforeMutation=$skus;
$inventoryRebuild=is_array($state['inventoryRebuild']??null)?$state['inventoryRebuild']:[];
$inventoryRebuildActive=($inventoryRebuild['active']??false)===true
    &&(string)($inventoryRebuild['id']??'')==='inventory-color-rebuild-20260918'
    &&($inventoryRebuild['excludedFromLossSurplus']??false)===true
    &&(string)($inventoryRebuild['batchStatus']??'open')!=='closed';
$initialStockMode=scanner_read($initialStockModeFile);
if(!array_key_exists('enabled',$initialStockMode))$initialStockMode=['enabled'=>true,'mode'=>'initial_inventory_setup','label'=>'臨時建檔'];
// 峰志日常使用「臨時入庫」：到期日不再自動關閉入口。只有明確的管理者關閉請求才生效，且 stayOpen 時拒絕關閉。
if(!empty($initialStockMode['stayOpen'])){
    $initialStockMode['enabled']=true;
    unset($initialStockMode['expired']);
}
if($_SERVER['REQUEST_METHOD']==='GET'){
    $mode=(string)($_GET['mode']??'');
    if($mode==='stocktake_recovery'){
        $wantedSession=trim((string)($_GET['sessionId']??''));
        $wantedOperator=strtolower(trim((string)($_GET['operator']??'')));
        $matches=[];
        foreach(scanner_read($stocktakeRecoveriesFile) as $candidate){
            if(!is_array($candidate)||!is_array($candidate['draft']??null))continue;
            if($wantedSession!==''&&(string)($candidate['sessionId']??'')===$wantedSession){$matches[]=$candidate;continue;}
            if($wantedSession===''&&$wantedOperator!==''&&scanner_operator_key($candidate['operator']??[])===$wantedOperator)$matches[]=$candidate;
        }
        usort($matches,static function($a,$b){return strcmp((string)($b['draft']['savedAt']??$b['updatedAt']??''),(string)($a['draft']['savedAt']??$a['updatedAt']??''));});
        $match=$matches[0]??null;
        $session=is_array($match)?['id'=>(string)($match['sessionId']??''),'status'=>'draft','warehouse'=>(string)($match['warehouse']??''),'operator'=>$match['operator']??[],'startedAt'=>(string)($match['startedAt']??'')]:null;
        scanner_response(['ok'=>true,'session'=>$session,'recovery'=>is_array($match)?($match['draft']??null):null,'revision'=>is_array($match)?(int)($match['revision']??0):0,'updatedAt'=>date(DATE_ATOM)]);
    }
    if($mode==='sessions') {
        $deleted=scanner_read($dataDir.'/scanner-deleted-sessions.json');
        $visible=array_values(array_filter($sessions,static function($s)use($deleted){return !isset($deleted[(string)($s['id']??'')]);}));
        scanner_response(['ok'=>true,'sessions'=>array_values(array_reverse(scanner_stocktake_live_compare($visible,$skus))),'initialStockMode'=>$initialStockMode,'inventoryRebuild'=>$inventoryRebuild,'updatedAt'=>date(DATE_ATOM)]);
    }
    if($mode==='last_missing_stocktake') {
        $wantedOperator=strtolower(trim((string)($_GET['operator']??'')));
        $wantedWarehouse=trim((string)($_GET['warehouse']??''));
        $latestMissing=null;
        foreach(scanner_read($ledgerFile) as $candidate){
            if(!is_array($candidate)||($candidate['action']??'')!=='scan_archive'||($candidate['scanStatus']??'')!=='not_found')continue;
            if($wantedOperator!==''&&scanner_operator_key($candidate['operator']??[])!==$wantedOperator)continue;
            if($wantedWarehouse!==''&&(string)($candidate['warehouse']??'')!==$wantedWarehouse)continue;
            $createdAt=strtotime((string)($candidate['createdAt']??''));
            if(!$createdAt||$createdAt<time()-6*3600)continue;
            $scanCode=trim((string)($candidate['scanCode']??$candidate['barcode']??''));
            if($scanCode===''||scanner_rows($products,$skus,$scanCode,$sessions,$barcodeAliases))continue;
            $latestMissing=['id'=>(string)($candidate['id']??''),'scanCode'=>$scanCode,'warehouse'=>(string)($candidate['warehouse']??''),'createdAt'=>(string)($candidate['createdAt']??'')];
            break;
        }
        scanner_response(['ok'=>true,'entry'=>$latestMissing,'updatedAt'=>date(DATE_ATOM)]);
    }
    if($mode==='quick_stockin_review') scanner_response(['ok'=>true,'drafts'=>array_values(array_reverse(scanner_read($pendingStockinFile))),'initialStockMode'=>$initialStockMode,'updatedAt'=>date(DATE_ATOM)]);
    $q=trim((string)($_GET['q']??''));
    $searchRows=$q===''?[]:scanner_rows($products,$skus,$q,$sessions,$barcodeAliases);
    scanner_response(['ok'=>true,'rows'=>$searchRows,'ambiguousBarcode'=>scanner_rows_have_identity_conflict($searchRows),'warehouses'=>warehouses_from_state($state,$skus),'shelves'=>scanner_location_list_from_state($state,$skus,'shelves'),'layers'=>scanner_location_list_from_state($state,$skus,'layers'),'categories'=>scanner_categories($products,$skus),'colors'=>$q===''?scanner_backend_colors($products,$skus):[],'categoryPrefixes'=>scanner_category_prefixes($products),'initialStockMode'=>$initialStockMode,'updatedAt'=>date(DATE_ATOM)]);
}

$payload=json_decode((string)file_get_contents('php://input'),true); if(!is_array($payload))scanner_response(['ok'=>false,'error'=>'資料格式錯誤'],400);
$action=(string)($payload['action']??''); $skuId=(string)($payload['skuId']??''); $warehouse=trim((string)($payload['warehouse']??'')); $qty=max(0,(int)($payload['qty']??0));
// Never trust a browser-supplied role/name for any write. Every scanner POST
// must belong to a live back-office session; later action-specific checks may
// further require admin or a particular permission.
$operator=scanner_backoffice_actor($state);
$quickStockApprovalIndex=null;
$quickStockApprovalId='';
// Deleted sheets remain archived but must not be edited or approved from stale clients.
$requestedSessionId=trim((string)($payload['sessionId']??''));
if($requestedSessionId!==''&&$action!=='stocktake_delete_old'){
    $deletedSessions=scanner_read($dataDir.'/scanner-deleted-sessions.json');
    if(isset($deletedSessions[$requestedSessionId]))scanner_response(['ok'=>false,'error'=>'這張盤點單已刪除封存，不能繼續修改或核准，請重新整理'],409);
}
// A browser-supplied role is not approval authority.
if(in_array($action,['stocktake_approve','stocktake_reject','quick_stockin_approve','quick_stockin_reject'],true)){
    $reviewToken=(string)($_COOKIE['lz_admin_session']??'');$reviewActor=null;
    foreach(scanner_read($dataDir.'/admin-sessions.json') as $candidate){
        if(($candidate['role']??'')!=='admin'||(int)($candidate['expiresAt']??0)<=time())continue;
        if($reviewToken!==''&&hash_equals((string)($candidate['tokenHash']??''),hash('sha256',$reviewToken))){$reviewActor=$candidate;break;}
    }
    if(!$reviewActor)scanner_response(['ok'=>false,'error'=>'只有已登入的管理者可以核准或退回草稿'],403);
    $reviewAccount=strtolower(trim((string)($reviewActor['account']??'')));
    $reviewFingerprint=hash('sha256','admin'."\0".$reviewAccount."\0".(string)($state['adminPasswordHash']??''));
    if(!hash_equals((string)($reviewActor['credentialFingerprint']??''),$reviewFingerprint))scanner_response(['ok'=>false,'error'=>'管理者登入已過期，請重新登入'],403);
    $operator=['role'=>'admin','account'=>$reviewAccount,'name'=>(string)($reviewActor['name']??$reviewAccount)];
}
// The owner explicitly authorizes this temporary initial-inventory entrance.
// Ordinary quick stock-in and all pending drafts still require review.
$directInitialSetup=$action==='quick_stockin_batch'&&($payload['entryType']??'')==='initial_inventory_setup';
if($directInitialSetup){
    if(empty($initialStockMode['enabled']))scanner_response(['ok'=>false,'error'=>'首次庫存臨時建檔入口已關閉，不能再直接入庫'],410);
    $initialRole=(string)($operator['role']??'');
    $initialPermissions=is_array($operator['permissions']??null)?$operator['permissions']:[];
    if($initialRole!=='admin'&&!($initialRole==='staff'&&count(array_intersect($initialPermissions,['盤點機','快速入庫','貨倉管理','庫存管理','公司庫存']))>0))scanner_response(['ok'=>false,'error'=>'此帳號沒有臨時建檔權限'],403);
}
if(in_array($action,['quick_stockin_batch','quick_stockin'],true)&&!$directInitialSetup)scanner_response(['ok'=>false,'error'=>'一般入庫必須先建立草稿並由管理者核准；直接入庫僅限已開放的首次庫存臨時建檔入口。','requiresApproval'=>true],409);
if($action==='reset_taiwan_stock_reallocation'){
    $token=(string)($_COOKIE['lz_admin_session']??'');$actor=null;
    foreach(scanner_read($dataDir.'/admin-sessions.json') as $candidate){
        if(($candidate['role']??'')!=='admin'||(int)($candidate['expiresAt']??0)<=time())continue;
        if($token!==''&&hash_equals((string)($candidate['tokenHash']??''),hash('sha256',$token))){$actor=$candidate;break;}
    }
    if(!$actor)scanner_response(['ok'=>false,'error'=>'請先用管理者帳號登入，才能重置台灣倉與待配佇列'],403);
    $account=strtolower(trim((string)($actor['account']??'')));
    $expected=hash('sha256','admin'."\0".$account."\0".(string)($state['adminPasswordHash']??''));
    if(!hash_equals((string)($actor['credentialFingerprint']??''),$expected))scanner_response(['ok'=>false,'error'=>'管理者登入已過期，請重新登入'],403);
    $orders=scanner_read($ordersFile);$now=date(DATE_ATOM);$zeroedSkus=0;$previousQty=0;
    foreach($skus as &$resetSku){
        if(!is_array($resetSku)||scanner_warehouse_code($resetSku['warehouseCode']??$resetSku['warehouseName']??$resetSku['warehouse']??'')!=='TW')continue;
        $old=max(0,(int)($resetSku['stock']??0));
        if($old>0){$zeroedSkus++;$previousQty+=$old;}
        $resetSku['stock']=0;$resetSku['updatedAt']=$now;$resetSku['inventoryResetAt']=$now;$resetSku['inventoryResetReason']='temporary_receiving_recount';
    }
    unset($resetSku);
    $queue=scanner_reset_taiwan_reallocation_queue($orders,(string)($actor['name']??$account),$now);
    $summary=['zeroedSkus'=>$zeroedSkus,'previousQty'=>$previousQty,'queuedOrders'=>$queue['orders'],'queuedItems'=>$queue['items'],'queuedQty'=>$queue['qty']];
    if(!empty($payload['dryRun']))scanner_response(['ok'=>true,'dryRun'=>true,'summary'=>$summary]);
    $ledger=scanner_read($ledgerFile);
    $resetId='TWRESET-'.date('Ymd-His').'-'.strtoupper(bin2hex(random_bytes(2)));
    $ledger[]=['id'=>$resetId,'action'=>'reset_taiwan_stock_reallocation','warehouse'=>'TW','summary'=>$summary,'operator'=>['role'=>'admin','account'=>$account,'name'=>(string)($actor['name']??$account)],'createdAt'=>$now];
    scanner_write($skusFile,$skus);scanner_write($ordersFile,$orders);scanner_write($ledgerFile,array_slice($ledger,-5000));
    $state['skus']=$skus;$state['orders']=$orders;$state['updatedAt']=$now;$state['revision']=max(0,(int)($state['revision']??0))+1;scanner_write($stateFile,$state);
    scanner_response(['ok'=>true,'resetId'=>$resetId,'summary'=>$summary]);
}
if(in_array($action,['stocktake_delete_old','stocktake_delete_many'],true)){
    $token=(string)($_COOKIE['lz_admin_session']??'');$actor=null;
    foreach(scanner_read($dataDir.'/admin-sessions.json') as $candidate){
        if(($candidate['role']??'')!=='admin'||(int)($candidate['expiresAt']??0)<=time())continue;
        if($token!==''&&hash_equals((string)($candidate['tokenHash']??''),hash('sha256',$token))){$actor=$candidate;break;}
    }
    if(!$actor)scanner_response(['ok'=>false,'error'=>'請用管理者帳號重新登入後刪除'],403);
    $account=strtolower(trim((string)($actor['account']??'')));
    $expected=hash('sha256','admin'."\0".$account."\0".(string)($state['adminPasswordHash']??''));
    if(!hash_equals((string)($actor['credentialFingerprint']??''),$expected))scanner_response(['ok'=>false,'error'=>'登入已過期，請重新登入'],403);
    $ids=$action==='stocktake_delete_many'&&is_array($payload['sessionIds']??null)?$payload['sessionIds']:[trim((string)($payload['sessionId']??''))];
    $ids=array_values(array_unique(array_filter(array_map(static fn($id):string=>trim((string)$id),$ids),static fn(string $id):bool=>$id!=='')));
    if(!$ids)scanner_response(['ok'=>false,'error'=>'請先選擇要刪除的盤點單'],400);
    if(count($ids)>100)scanner_response(['ok'=>false,'error'=>'一次最多刪除 100 張盤點單'],400);
    $records=[];
    foreach($ids as $id){
        $record=null;foreach($sessions as $s)if((string)($s['id']??'')===$id){$record=$s;break;}
        if(!$record)scanner_response(['ok'=>false,'error'=>'找不到盤點單 '.$id],404);
        if(!in_array((string)($record['status']??'draft'),['draft','pending_review','rejected','superseded'],true))scanner_response(['ok'=>false,'error'=>'盤點單 '.$id.' 已核准或正在入帳，不能刪除'],409);
        $records[$id]=$record;
    }
    $file=$dataDir.'/scanner-deleted-sessions.json';$lock=fopen($file.'.lock','c');
    if(!$lock||!flock($lock,LOCK_EX))scanner_response(['ok'=>false,'error'=>'請稍後重試'],423);
    $deleted=scanner_read($file);
    $deletedAt=date(DATE_ATOM);$deletedCount=0;
    foreach($records as $id=>$record){if(!isset($deleted[$id])){$deleted[$id]=['record'=>$record,'deletedAt'=>$deletedAt,'deletedBy'=>$actor['name']??$account];$deletedCount++;}}
    if($deletedCount>0)scanner_write($file,$deleted);
    flock($lock,LOCK_UN);fclose($lock);
    scanner_response(['ok'=>true,'archived'=>true,'deletedCount'=>$deletedCount,'sessionIds'=>$ids]);
}

if($action==='stocktake_recovery_save'||$action==='stocktake_recovery_clear'){
    $sessionId=trim((string)($payload['sessionId']??''));
    if($sessionId==='')scanner_response(['ok'=>false,'error'=>'盤點草稿缺少盤點單號'],400);
    $lockFile=$stocktakeRecoveriesFile.'.lock';
    $lockHandle=fopen($lockFile,'c');
    if($lockHandle===false||!flock($lockHandle,LOCK_EX))scanner_response(['ok'=>false,'error'=>'雲端草稿正在同步，請稍後再試'],423);
    $recoveries=scanner_read($stocktakeRecoveriesFile);
    $found=null;foreach($recoveries as $i=>$candidate)if((string)($candidate['sessionId']??'')===$sessionId){$found=$i;break;}
    if($found===null){
        if($action==='stocktake_recovery_clear'){flock($lockHandle,LOCK_UN);fclose($lockHandle);scanner_response(['ok'=>true,'cleared'=>true,'sessionId'=>$sessionId]);}
        $recoveries[]=['sessionId'=>$sessionId,'warehouse'=>$warehouse?:'台灣倉','operator'=>$operator,'startedAt'=>(string)($payload['startedAt']??date(DATE_ATOM)),'updatedAt'=>date(DATE_ATOM),'revision'=>0];$found=count($recoveries)-1;
    }
    $cloudRevision=(int)($recoveries[$found]['revision']??0);
    if($action==='stocktake_recovery_clear'){
        unset($recoveries[$found]['draft']);
        $recoveries[$found]['clearedAt']=date(DATE_ATOM);
        $recoveries[$found]['revision']=$cloudRevision+1;
        $recoveries[$found]['updatedAt']=date(DATE_ATOM);
        scanner_write($stocktakeRecoveriesFile,$recoveries);
        flock($lockHandle,LOCK_UN);fclose($lockHandle);
        scanner_response(['ok'=>true,'cleared'=>true,'sessionId'=>$sessionId,'revision'=>$cloudRevision+1]);
    }
    $draft=is_array($payload['draft']??null)?$payload['draft']:[];
    $required=['skuId','scanCode','warehouse'];foreach($required as $field)if(trim((string)($draft[$field]??''))===''){flock($lockHandle,LOCK_UN);fclose($lockHandle);scanner_response(['ok'=>false,'error'=>'雲端草稿缺少 '.$field],400);}
    $baseRevision=max(0,(int)($payload['baseRevision']??0));
    $deviceId=substr(trim((string)($payload['deviceId']??'')),0,80);
    $incomingSavedAt=trim((string)($draft['savedAt']??''));
    $clearedAt=trim((string)($recoveries[$found]['clearedAt']??''));
    if($cloudRevision>$baseRevision&&$clearedAt!==''&&$incomingSavedAt!==''&&(strtotime($incomingSavedAt)?:0)<=(strtotime($clearedAt)?:0)){
        flock($lockHandle,LOCK_UN);fclose($lockHandle);scanner_response(['ok'=>false,'error'=>'這筆盤點已完成，舊草稿不再回存','stale'=>true,'revision'=>$cloudRevision],409);
    }
    $cloudDevice=(string)($recoveries[$found]['draft']['deviceId']??'');
    if($cloudRevision>$baseRevision&&$cloudDevice!==''&&$deviceId!==''&&$cloudDevice!==$deviceId){
        $cloud=$recoveries[$found]['draft'];flock($lockHandle,LOCK_UN);fclose($lockHandle);
        scanner_response(['ok'=>false,'error'=>'另一台裝置已有較新的盤點草稿，已保留雲端版本','conflict'=>true,'recovery'=>$cloud,'revision'=>$cloudRevision],409);
    }
    $safeDraft=[
        'version'=>2,'savedAt'=>date(DATE_ATOM),'sessionId'=>$sessionId,'warehouse'=>trim((string)($draft['warehouse']??'')),
        'scanCode'=>scanner_norm($draft['scanCode']??''),'skuId'=>trim((string)($draft['skuId']??'')),'productCode'=>trim((string)($draft['productCode']??'')),
        'quantity'=>max(0,(int)($draft['quantity']??0)),'shelf'=>trim((string)($draft['shelf']??'')),'layer'=>trim((string)($draft['layer']??'')),
        'deviceId'=>$deviceId,'operator'=>$operator
    ];
    if(!empty($payload['dryRun'])){
        flock($lockHandle,LOCK_UN);fclose($lockHandle);
        scanner_response(['ok'=>true,'dryRun'=>true,'recovery'=>$safeDraft,'sessionId'=>$sessionId,'revision'=>$cloudRevision+1,'cloudSaved'=>false]);
    }
    $recoveries[$found]['draft']=$safeDraft;$recoveries[$found]['revision']=$cloudRevision+1;$recoveries[$found]['operator']=$operator;$recoveries[$found]['warehouse']=$safeDraft['warehouse'];$recoveries[$found]['updatedAt']=date(DATE_ATOM);unset($recoveries[$found]['clearedAt']);
    scanner_write($stocktakeRecoveriesFile,$recoveries);
    flock($lockHandle,LOCK_UN);fclose($lockHandle);
    scanner_response(['ok'=>true,'recovery'=>$safeDraft,'sessionId'=>$sessionId,'revision'=>$cloudRevision+1,'cloudSaved'=>true]);
}

if($action==='initial_stock_mode_close'){
    if((string)($operator['role']??'')!=='admin')scanner_response(['ok'=>false,'error'=>'只有管理者可以關閉臨時建檔入口'],403);
    $liveMode=scanner_read($initialStockModeFile);
    if(!empty($liveMode['stayOpen']) || !empty($initialStockMode['stayOpen'])){
        $initialStockMode=array_merge($liveMode,[
            'enabled'=>true,
            'mode'=>'initial_inventory_setup',
            'label'=>'臨時建檔',
            'stayOpen'=>true,
            'closeBlockedAt'=>date(DATE_ATOM),
            'closeBlockedBy'=>$operator,
            'closeBlockedReason'=>'臨時入庫是日常入口，依峰志指示保持開放，不列入報損或報益。'
        ]);
        unset($initialStockMode['expired'],$initialStockMode['expiresAt']);
        scanner_write($initialStockModeFile,$initialStockMode);
        scanner_response(['ok'=>false,'error'=>'臨時入庫是日常入口，已依峰志指示保持開放，不能關閉。','initialStockMode'=>$initialStockMode],409);
    }
    $entries=scanner_read($pendingStockinFile);$pendingCount=0;
    foreach($entries as $entry)if(is_array($entry)&&($entry['status']??'')==='pending_review')$pendingCount++;
    if($pendingCount>0)scanner_response(['ok'=>false,'error'=>'仍有 '.$pendingCount.' 張臨時建檔草稿待審，請全部核准或退回後再關閉入口','pendingCount'=>$pendingCount],409);
    $initialStockMode=['enabled'=>false,'mode'=>'initial_inventory_setup','label'=>'臨時建檔','closedAt'=>date(DATE_ATOM),'closedBy'=>$operator];
    scanner_write($initialStockModeFile,$initialStockMode);
    scanner_response(['ok'=>true,'initialStockMode'=>$initialStockMode]);
}

if($action==='location_settings'){
    $shelves=is_array($payload['shelves']??null)?$payload['shelves']:[];
    $layers=is_array($payload['layers']??null)?$payload['layers']:[];
    foreach(['shelves'=>$shelves,'layers'=>$layers] as $key=>$values){
        foreach($values as $value){
            $name=scanner_clean_location_name($value);
            if($name==='')continue;
            $error=scanner_location_invalid_error($key,$name);
            if($error!=='')scanner_response(['ok'=>false,'error'=>$error],400);
        }
    }
    if($shelves||$layers){
        scanner_add_location_values($state,'shelves',$shelves);
        scanner_add_location_values($state,'layers',$layers);
        $state['updatedAt']=date(DATE_ATOM);
        scanner_write($stateFile,$state);
    }
    scanner_response(['ok'=>true,'shelves'=>scanner_location_list_from_state($state,$skus,'shelves'),'layers'=>scanner_location_list_from_state($state,$skus,'layers'),'updatedAt'=>date(DATE_ATOM)]);
}

if($action==='bind_sku_location'){
    $bindSkuId=trim((string)($payload['skuId']??''));
    $bindWarehouse=trim((string)($payload['warehouse']??''));
    $shelf=trim((string)($payload['shelf']??''));
    $layer=trim((string)($payload['layer']??''));
    if($bindSkuId===''||$shelf===''||$layer==='')scanner_response(['ok'=>false,'error'=>'請選擇產品、貨架與層位'],400);
    $bindIndex=null;foreach($skus as $i=>$sku)if((string)($sku['id']??$sku['sku']??'')===$bindSkuId){$bindIndex=$i;break;}
    if($bindIndex===null)scanner_response(['ok'=>false,'error'=>'找不到要綁定倉位的 SKU'],404);
    if($bindWarehouse!==''){
        $sourceProduct=$productMap[(string)($skus[$bindIndex]['productId']??'')]??[];
        $targetIndex=find_target_sku($skus,$skus[$bindIndex],$bindWarehouse);
        if($targetIndex===null)$targetIndex=clone_to_warehouse($skus,$skus[$bindIndex],$bindWarehouse,0,$sourceProduct);
        $bindIndex=$targetIndex;
        $bindSkuId=(string)($skus[$bindIndex]['id']??$skus[$bindIndex]['sku']??$bindSkuId);
    }
    $skus[$bindIndex]['shelf']=$shelf;$skus[$bindIndex]['layer']=$layer;
    if(empty($skus[$bindIndex]['locationFirstRecordedAt']))$skus[$bindIndex]['locationFirstRecordedAt']=date(DATE_ATOM);
    $skus[$bindIndex]['locationUpdatedAt']=date(DATE_ATOM);$skus[$bindIndex]['locationUpdatedBy']=$operator;
    scanner_write($skusFile,$skus);
    scanner_response(['ok'=>true,'skuId'=>$bindSkuId,'shelf'=>$shelf,'layer'=>$layer,'updatedAt'=>date(DATE_ATOM)]);
}

if($action==='scan_archive'){
    $scanCode=scanner_norm($payload['scanCode']??$payload['barcode']??'');
    if($scanCode==='')scanner_response(['ok'=>false,'error'=>'掃描碼空白'],400);
    $match=is_array($payload['match']??null)?$payload['match']:[];
    $ledger=scanner_read($ledgerFile);
    $operation=[
        'id'=>'SCANARCH-'.date('Ymd-His').'-'.strtoupper(bin2hex(random_bytes(2))),
        'action'=>'scan_archive',
        'scanCode'=>$scanCode,
        'barcode'=>$scanCode,
        'scanStatus'=>(string)($payload['scanStatus']??''),
        'matchCount'=>max(0,(int)($payload['matchCount']??0)),
        'warehouse'=>$warehouse,
        'skuId'=>(string)($match['skuId']??''),
        'productCode'=>(string)($match['productCode']??''),
        'productTitle'=>(string)($match['title']??''),
        'color'=>(string)($match['color']??''),
        'size'=>(string)($match['size']??''),
        'matchedWarehouse'=>(string)($match['warehouse']??''),
        'stock'=>(int)($match['stock']??0),
        'note'=>(string)($payload['note']??''),
        'shelf'=>(string)($payload['shelf']??''),
        'layer'=>(string)($payload['layer']??''),
        'photoData'=>(string)($payload['photoData']??''),
        'operator'=>$operator,
        'createdAt'=>date(DATE_ATOM)
    ];
    array_unshift($ledger,$operation);
    scanner_write($ledgerFile,array_slice($ledger,0,5000));
    scanner_response(['ok'=>true,'operation'=>$operation]);
}

if($action==='cross_warehouse_receive'){
    $destinationWarehouse=trim((string)($payload['destinationWarehouse']??''));
    $destinationCode=scanner_warehouse_code($destinationWarehouse);
    $receiveQty=max(1,(int)($payload['qty']??1));
    if($destinationCode==='')scanner_response(['ok'=>false,'error'=>'請先選擇到貨目的倉'],400);
    $baseIndex=null;
    if($skuId!=='')foreach($skus as $i=>$candidate)if((string)($candidate['id']??$candidate['sku']??'')===$skuId){$baseIndex=$i;break;}
    if($baseIndex===null){
        $barcode=scanner_norm($payload['barcode']??'');
        foreach($skus as $i=>$candidate){
            foreach(['barcode','companyBarcode','legacyBarcode','id','sku'] as $field)if($barcode!==''&&scanner_norm($candidate[$field]??'')===$barcode){$baseIndex=$i;break 2;}
        }
    }
    if($baseIndex===null)scanner_response(['ok'=>false,'error'=>'找不到這個到貨商品的 SKU'],404);
    $baseSku=$skus[$baseIndex];
    $orders=scanner_read($ordersFile);
    $remaining=$receiveQty;$allocations=[];$now=date(DATE_ATOM);
    foreach($orders as $orderIndex=>&$order){
        if($remaining<1)break;
        $routeDestination=scanner_warehouse_code($order['destinationWarehouseCode']??$order['destinationWarehouse']??'');
        if($routeDestination===''){
            $legacyShipMode=(string)($order['shipMode']??'');
            if(in_array($legacyShipMode,['china_to_taiwan','indonesia_to_taiwan'],true))$routeDestination='TW';
            elseif($legacyShipMode==='indonesia_to_china')$routeDestination='CN';
        }
        $crossStatus=(string)($order['crossWarehouseStatus']??'');
        $orderStatus=(string)($order['status']??'');
        if($routeDestination!==$destinationCode||in_array($orderStatus,['shipped','delivered','completed','returned','cancelled'],true)||in_array($crossStatus,['ready_to_ship','completed','cancelled'],true))continue;
        $items=is_array($order['items']??null)?$order['items']:[];
        foreach($items as $itemIndex=>&$item){
            if($remaining<1)break;
            if(!scanner_same_variant($baseSku,$item))continue;
            $ordered=max(0,(int)($item['qty']??0));
            $received=max(0,(int)($item['crossWarehouseReceivedQty']??0));
            $open=max(0,$ordered-$received);
            if($open<1)continue;
            $take=min($remaining,$open);
            $item['crossWarehouseReceivedQty']=$received+$take;
            $item['crossWarehouseReceivedAt']=$now;
            $remaining-=$take;
            $customer=is_array($order['customer']??null)?$order['customer']:[];
            $allocations[]=[
                'orderId'=>(string)($order['id']??''),
                'customer'=>(string)($customer['name']??''),
                'phone'=>(string)($customer['phone']??''),
                'qty'=>$take,
                'productCode'=>(string)($item['code']??''),
                'color'=>(string)($item['color']??''),
                'size'=>(string)($item['size']??'')
            ];
        }
        unset($item);
        $allReady=true;
        foreach($items as $checkItem){
            if(max(0,(int)($checkItem['crossWarehouseReceivedQty']??0))<max(0,(int)($checkItem['qty']??0))){$allReady=false;break;}
        }
        $order['items']=$items;
        $order['crossWarehouseReceivedAt']=$now;
        $order['crossWarehouseStatus']=$allReady?'ready_to_ship':'partially_received';
        $order['crossWarehouseStatusLabel']=$allReady?'目的倉已到貨配客／可執行出貨':'目的倉部分到貨／持續配客';
        $order['fulfillmentStatus']=$allReady?'ready_to_ship':'cross_warehouse_partially_received';
        $order['fulfillmentStatusLabel']=$order['crossWarehouseStatusLabel'];
        if($allReady){
            $order['status']='accepted';
            $order['statusLabel']='跨倉到貨／待填物流單出貨';
            $order['shipmentApprovalStatus']='acknowledged';
            $order['shipmentApprovalStatusLabel']='跨倉到貨已自動配客／行政可執行出貨';
            if(empty($order['salesReleasedAt']))$order['salesReleasedAt']=$now;
            if(empty($order['adminAcknowledgedAt']))$order['adminAcknowledgedAt']=$now;
        }
        if(!isset($order['crossWarehouseArrivalHistory'])||!is_array($order['crossWarehouseArrivalHistory']))$order['crossWarehouseArrivalHistory']=[];
        array_unshift($order['crossWarehouseArrivalHistory'],['at'=>$now,'destinationWarehouse'=>$destinationWarehouse,'operator'=>$operator,'allocations'=>$allocations]);
        $order['crossWarehouseArrivalHistory']=array_slice($order['crossWarehouseArrivalHistory'],0,50);
        if(!isset($order['progressHistory'])||!is_array($order['progressHistory']))$order['progressHistory']=[];
        array_unshift($order['progressHistory'],['at'=>$now,'status'=>$order['status']??'accepted','statusLabel'=>$order['statusLabel']??'跨倉到貨','note'=>$order['crossWarehouseStatusLabel']]);
        $order['updatedAt']=$now;
    }
    unset($order);
    if(!$allocations)scanner_response(['ok'=>false,'error'=>'找不到此目的倉尚待到貨的客戶訂單；未變更庫存'],404);
    if($remaining>0)scanner_response(['ok'=>false,'error'=>'掃描數量超過待到貨客戶數量；本次未寫入'],409);
    $ledger=scanner_read($ledgerFile);
    $receiveId='ARR-'.date('Ymd-His').'-'.strtoupper(bin2hex(random_bytes(2)));
    $operation=['id'=>$receiveId,'action'=>'cross_warehouse_arrival_allocation','skuId'=>(string)($baseSku['id']??$baseSku['sku']??''),'barcode'=>(string)($payload['barcode']??$baseSku['barcode']??''),'sourceWarehouse'=>(string)($payload['sourceWarehouse']??''),'targetWarehouse'=>$destinationWarehouse,'qty'=>$receiveQty,'operator'=>$operator,'allocations'=>$allocations,'destinationFreeStockDelta'=>0,'note'=>'來源倉已於打單扣庫存；目的倉到貨後直接配客，不重複加減自由庫存','createdAt'=>$now];
    array_unshift($ledger,$operation);
    scanner_write($ordersFile,$orders);
    $state['orders']=$orders;$state['updatedAt']=$now;scanner_write($stateFile,$state);
    scanner_write($ledgerFile,array_slice($ledger,0,5000));
    scanner_response(['ok'=>true,'receiveId'=>$receiveId,'receivedQty'=>$receiveQty,'allocations'=>$allocations,'readyOrderIds'=>array_values(array_unique(array_column($allocations,'orderId'))),'operation'=>$operation]);
}

if($action==='pending_stockin'){
    $rawCode=trim((string)($payload['rawCode']??$payload['barcode']??''));
    if($rawCode==='')scanner_response(['ok'=>false,'error'=>'缺少原始掃描值'],400);
    if($warehouse==='')scanner_response(['ok'=>false,'error'=>'請選擇入庫倉庫'],400);
    $qty=max(1,(int)($payload['qty']??1));
    $entries=scanner_read($pendingStockinFile);
    $photo=scanner_save_image(is_string($payload['imageData']??null)?$payload['imageData']:null);
    $entry=[
        'id'=>'PENDING-'.date('Ymd-His').'-'.strtoupper(bin2hex(random_bytes(2))),
        'status'=>'pending',
        'rawCode'=>$rawCode,
        'barcode'=>trim((string)($payload['barcode']??$rawCode)),
        'warehouse'=>$warehouse,
        'qty'=>$qty,
        'photo'=>$photo,
        'operator'=>$operator,
        'note'=>trim((string)($payload['note']??'未建檔／重新入庫，需主管補商品資料')),
        'createdAt'=>date(DATE_ATOM),
        'updatedAt'=>date(DATE_ATOM)
    ];
    array_unshift($entries,$entry);
    scanner_write($pendingStockinFile,array_slice($entries,0,1000));
    scanner_response(['ok'=>true,'entry'=>$entry]);
}

if($action==='stocktake_import_count_sheet'){
    $actor=scanner_backoffice_actor($state,['盤點','盤點機','貨倉管理','庫存管理','公司庫存']);
    $rows=is_array($payload['rows']??null)?$payload['rows']:[];
    if(!$rows)scanner_response(['ok'=>false,'error'=>'請先在核對表填寫實盤數量'],400);
    if(count($rows)>1000)scanner_response(['ok'=>false,'error'=>'一次最多匯入 1,000 筆盤點數量'],400);
    $skuById=[];
    foreach($skus as $skuIndex=>$sku){$id=(string)($sku['id']??$sku['sku']??'');if($id!=='')$skuById[$id]=[$skuIndex,$sku];}
    $lines=[];$seen=[];$sessionWarehouse='';$now=date(DATE_ATOM);
    foreach($rows as $row){
        if(!is_array($row))continue;
        $skuId=trim((string)($row['skuId']??''));
        if($skuId===''||!isset($skuById[$skuId]))scanner_response(['ok'=>false,'error'=>'核對表中的 SKU 已不存在：'.($skuId?:'未填 SKU')],409);
        $counted=filter_var($row['countedQty']??null,FILTER_VALIDATE_INT);
        if($counted===false||$counted<0||$counted>1000000)scanner_response(['ok'=>false,'error'=>'實盤數量必須是 0 到 1,000,000 的整數'],400);
        [$skuIndex,$sku]=$skuById[$skuId];
        $product=$productMap[(string)($sku['productId']??'')]??[];
        $warehouse=trim((string)($row['warehouse']??$sku['warehouseName']??$sku['warehouse']??''));
        $shelf=trim((string)($row['shelf']??$sku['shelf']??''));
        $layer=trim((string)($row['layer']??$sku['layer']??''));
        $key=$skuId.'|'.$warehouse.'|'.$shelf.'|'.$layer;
        if(isset($seen[$key]))continue;$seen[$key]=true;
        if($sessionWarehouse==='')$sessionWarehouse=$warehouse;
        $previous=max(0,(int)($sku['stock']??0));
        $lines[]=[
            'sourceSkuId'=>$skuId,'productId'=>(string)($sku['productId']??''),
            'barcode'=>(string)($row['barcode']??$sku['companyBarcode']??$sku['barcode']??$skuId),
            'displayProductCode'=>(string)($row['productCode']??$product['code']??$product['id']??''),
            'displayProductName'=>(string)($row['productName']??$product['title']??$product['name']??''),
            'color'=>(string)($row['color']??$sku['colorName']??$sku['color']??''),
            'size'=>(string)($row['size']??$sku['sizeName']??$sku['size']??'NO SIZE'),
            'warehouse'=>$warehouse,'shelf'=>$shelf,'layer'=>$layer,
            'previousStock'=>$previous,'countedQty'=>$counted,'variance'=>$counted-$previous,
            'image'=>scanner_image($product,$sku),'scannedAt'=>$now,'operator'=>$actor,
            'importSource'=>'inventory_count_sheet'
        ];
    }
    if(!$lines)scanner_response(['ok'=>false,'error'=>'核對表沒有可匯入的有效數量'],400);
    $sessionId='STK-WEB-'.date('Ymd-His').'-'.strtoupper(bin2hex(random_bytes(2)));
    $session=[
        'id'=>$sessionId,'status'=>'draft','warehouse'=>$sessionWarehouse?:'多倉位置核對',
        'operator'=>$actor,'startedAt'=>$now,'updatedAt'=>$now,'lines'=>$lines,
        'source'=>'inventory_count_sheet','filterContext'=>trim((string)($payload['filterContext']??'')),
        'requiresManagerReview'=>true,
        'inventoryRebuildId'=>$inventoryRebuildActive?(string)$inventoryRebuild['id']:'',
        'excludedFromLossSurplus'=>$inventoryRebuildActive,
        'baselineMode'=>$inventoryRebuildActive
    ];
    $stocktakeLock=fopen($stocktakesFile.'.file.lock','c+');
    if($stocktakeLock===false||!flock($stocktakeLock,LOCK_EX))scanner_response(['ok'=>false,'error'=>'盤點草稿正由其他人更新，請稍後重試'],503);
    $sessions=scanner_read($stocktakesFile);$sessions[]=$session;scanner_write($stocktakesFile,$sessions);
    flock($stocktakeLock,LOCK_UN);fclose($stocktakeLock);
    scanner_response(['ok'=>true,'sessionId'=>$sessionId,'lineCount'=>count($lines),'status'=>'draft','formalInventoryChanged'=>false]);
}

if($action==='stocktake_start'){
    $sessionId=(string)($payload['sessionId']??'');if($sessionId===''||$warehouse==='')scanner_response(['ok'=>false,'error'=>'盤點單號或倉庫遺失'],400);
    $found=null;foreach($sessions as $i=>$s)if((string)($s['id']??'')===$sessionId){$found=$i;break;}
    if($found===null){$sessions[]=['id'=>$sessionId,'status'=>'draft','warehouse'=>$warehouse,'operator'=>$operator,'startedAt'=>(string)($payload['startedAt']??date(DATE_ATOM)),'updatedAt'=>date(DATE_ATOM),'lines'=>[],'inventoryRebuildId'=>$inventoryRebuildActive?(string)$inventoryRebuild['id']:'','excludedFromLossSurplus'=>$inventoryRebuildActive,'baselineMode'=>$inventoryRebuildActive];$found=count($sessions)-1;}
    else{
        if(($sessions[$found]['status']??'draft')!=='draft')scanner_response(['ok'=>false,'error'=>'這張盤點已送審或已處理，不能重新開啟，請建立新草稿'],409);
        $sessions[$found]['operator']=$operator;$sessions[$found]['warehouse']=$warehouse;$sessions[$found]['updatedAt']=date(DATE_ATOM);
    }
    scanner_write($stocktakesFile,$sessions);scanner_response(['ok'=>true,'session'=>$sessions[$found]]);
}

if($action==='stocktake_finalize'){
    $sessionId=(string)($payload['sessionId']??'');$found=null;foreach($sessions as $i=>$s)if((string)($s['id']??'')===$sessionId){$found=$i;break;}
    if($found===null)scanner_response(['ok'=>false,'error'=>'找不到本次盤點單，請先掃描商品'],404);
    if(($sessions[$found]['status']??'draft')==='pending_review')scanner_response(['ok'=>true,'session'=>$sessions[$found],'alreadySubmitted'=>true]);
    if(($sessions[$found]['status']??'draft')!=='draft')scanner_response(['ok'=>false,'error'=>'這張盤點已處理，不能重新送審或再次寫入庫存'],409);
    $sessions[$found]['status']='pending_review';$sessions[$found]['submittedAt']=date(DATE_ATOM);$sessions[$found]['operator']=$operator;$sessions[$found]['warehouse']=$warehouse?:($sessions[$found]['warehouse']??'');scanner_write($stocktakesFile,$sessions);scanner_response(['ok'=>true,'session'=>$sessions[$found]]);
}

if($action==='stocktake_edit_draft'){
    $actor=scanner_backoffice_actor($state,['盤點','盤點機','貨倉管理','庫存管理','公司庫存']);
    $stocktakeLock=fopen($stocktakesFile.'.file.lock','c+');
    if($stocktakeLock===false||!flock($stocktakeLock,LOCK_EX))scanner_response(['ok'=>false,'error'=>'盤點草稿正由其他人更新，請稍後再試'],503);
    $sessions=scanner_read($stocktakesFile);
    $sessionId=trim((string)($payload['sessionId']??''));$lineIndex=filter_var($payload['lineIndex']??null,FILTER_VALIDATE_INT);
    if($sessionId===''||$lineIndex===false||$lineIndex<0)scanner_response(['ok'=>false,'error'=>'盤點草稿或商品索引遺失'],400);
    $found=null;foreach($sessions as $i=>$s)if((string)($s['id']??'')===$sessionId){$found=$i;break;}
    if($found===null)scanner_response(['ok'=>false,'error'=>'找不到盤點草稿'],404);
    $draftStatus=(string)($sessions[$found]['status']??'draft');
    if(!in_array($draftStatus,['draft','pending_review'],true))scanner_response(['ok'=>false,'error'=>'只有盤點中或待核准草稿可以修正；正式庫存未變更'],409);
    if(!isset($sessions[$found]['lines'][$lineIndex])||!is_array($sessions[$found]['lines'][$lineIndex]))scanner_response(['ok'=>false,'error'=>'草稿中找不到這筆商品，請重新整理'],404);
    $expectedSku=trim((string)($payload['sourceSkuId']??''));$line=&$sessions[$found]['lines'][$lineIndex];
    if($expectedSku!==''&&!hash_equals((string)($line['sourceSkuId']??''),$expectedSku))scanner_response(['ok'=>false,'error'=>'草稿內容已更新，請重新整理後再修正'],409);
    $countedQty=filter_var($payload['countedQty']??null,FILTER_VALIDATE_INT);
    if($countedQty===false||$countedQty<0||$countedQty>1000000)scanner_response(['ok'=>false,'error'=>'實盤數量必須是 0 到 1,000,000 的整數'],400);
    $note=trim((string)($payload['note']??''));if(mb_strlen($note)>500)$note=mb_substr($note,0,500);
    $beforeQty=max(0,(int)($line['countedQty']??0));$now=date(DATE_ATOM);$previousStock=max(0,(int)($line['previousStock']??0));
    $correction=['beforeQty'=>$beforeQty,'afterQty'=>$countedQty,'note'=>$note,'at'=>$now,'by'=>$actor];
    $history=is_array($line['draftCorrections']??null)?$line['draftCorrections']:[];$history[]=$correction;$line['draftCorrections']=array_slice($history,-50);
    $line['countedQty']=$countedQty;$line['variance']=$countedQty-$previousStock;$line['employeeCorrectionNote']=$note;$line['correctedAt']=$now;$line['correctedBy']=$actor;
    $sessions[$found]['updatedAt']=$now;$sessions[$found]['lastCorrectedAt']=$now;$sessions[$found]['lastCorrectedBy']=$actor;$sessions[$found]['employeeCorrectionPendingReview']=true;
    scanner_write($stocktakesFile,$sessions);
    flock($stocktakeLock,LOCK_UN);fclose($stocktakeLock);
    scanner_response(['ok'=>true,'session'=>$sessions[$found],'line'=>$line,'formalInventoryChanged'=>false]);
}

if($action==='stocktake_remove_entry'||$action==='stocktake_remove_entries'||$action==='stocktake_clear_entries'){
    $sessionId=trim((string)($payload['sessionId']??''));
    if($sessionId==='')scanner_response(['ok'=>false,'error'=>'盤點單號遺失'],400);
    $found=null;foreach($sessions as $i=>$s)if((string)($s['id']??'')===$sessionId){$found=$i;break;}
    if($found===null)scanner_response(['ok'=>false,'error'=>'找不到本次盤點單'],404);
    if(($sessions[$found]['status']??'draft')!=='draft')scanner_response(['ok'=>false,'error'=>'只有盤點中的草稿可以刪除；已送出或已核准紀錄必須保留'],409);
    $removed=[];
    if($action==='stocktake_clear_entries'){
        $removed=$sessions[$found]['lines']??[];
        $sessions[$found]['lines']=[];
    }elseif($action==='stocktake_remove_entries'){
        $removeEntries=is_array($payload['entries']??null)?$payload['entries']:[];
        if(!$removeEntries)scanner_response(['ok'=>false,'error'=>'請先選擇要刪除的盤點商品'],400);
        $kept=[];
        foreach(($sessions[$found]['lines']??[]) as $line){
            $lineShouldRemove=false;
            foreach($removeEntries as $removeEntry){
                if(!is_array($removeEntry))continue;
                $removeSku=trim((string)($removeEntry['skuId']??''));$removeBarcode=scanner_norm($removeEntry['barcode']??'');
                $removeWarehouse=trim((string)($removeEntry['warehouse']??''));$removeShelf=trim((string)($removeEntry['shelf']??''));$removeLayer=trim((string)($removeEntry['layer']??''));
                $skuMatches=$removeSku!==''&&(string)($line['sourceSkuId']??'')===$removeSku;
                $barcodeMatches=$removeBarcode!==''&&scanner_norm($line['barcode']??'')===$removeBarcode;
                $locationMatches=($removeWarehouse===''||(string)($line['warehouse']??'')===$removeWarehouse)&&($removeShelf===''||(string)($line['shelf']??'')===$removeShelf)&&($removeLayer===''||(string)($line['layer']??'')===$removeLayer);
                if(($skuMatches||$barcodeMatches)&&$locationMatches){$lineShouldRemove=true;break;}
            }
            if($lineShouldRemove){$removed[]=$line;continue;}
            $kept[]=$line;
        }
        if(!$removed)scanner_response(['ok'=>false,'error'=>'盤點草稿中找不到已選商品'],404);
        $sessions[$found]['lines']=$kept;
    }else{
        $removeSku=trim((string)($payload['skuId']??''));$removeBarcode=scanner_norm($payload['barcode']??'');
        $removeWarehouse=trim((string)($payload['warehouse']??''));$removeShelf=trim((string)($payload['shelf']??''));$removeLayer=trim((string)($payload['layer']??''));
        $kept=[];$didRemove=false;
        foreach(($sessions[$found]['lines']??[]) as $line){
            $skuMatches=$removeSku!==''&&(string)($line['sourceSkuId']??'')===$removeSku;
            $barcodeMatches=$removeBarcode!==''&&scanner_norm($line['barcode']??'')===$removeBarcode;
            $locationMatches=($removeWarehouse===''||(string)($line['warehouse']??'')===$removeWarehouse)&&($removeShelf===''||(string)($line['shelf']??'')===$removeShelf)&&($removeLayer===''||(string)($line['layer']??'')===$removeLayer);
            if(!$didRemove&&($skuMatches||$barcodeMatches)&&$locationMatches){$removed[]=$line;$didRemove=true;continue;}
            $kept[]=$line;
        }
        if(!$didRemove)scanner_response(['ok'=>false,'error'=>'盤點草稿中找不到這筆商品'],404);
        $sessions[$found]['lines']=$kept;
    }
    $sessions[$found]['updatedAt']=date(DATE_ATOM);$sessions[$found]['lastRemovedAt']=date(DATE_ATOM);$sessions[$found]['lastRemovedBy']=$operator;
    $ledger=scanner_read($ledgerFile);
    $ledger=array_values(array_filter($ledger,static function($entry)use($sessionId,$removed){
        if((string)($entry['sessionId']??'')!==$sessionId)return true;
        foreach($removed as $line){
            $skuMatches=(string)($entry['skuId']??'')===(string)($line['sourceSkuId']??'');
            $barcodeMatches=scanner_norm($entry['barcode']??'')!==''&&scanner_norm($entry['barcode']??'')===scanner_norm($line['barcode']??'');
            $locationMatches=(string)($entry['warehouse']??'')===(string)($line['warehouse']??'')&&(string)($entry['shelf']??'')===(string)($line['shelf']??'')&&(string)($entry['layer']??'')===(string)($line['layer']??'');
            if(($skuMatches||$barcodeMatches)&&$locationMatches)return false;
        }
        return true;
    }));
    scanner_write($stocktakesFile,$sessions);scanner_write($ledgerFile,$ledger);
    scanner_response(['ok'=>true,'session'=>$sessions[$found],'removedCount'=>count($removed)]);
}
if($action==='stocktake_reopen_baseline'){
    $actor=scanner_backoffice_actor($state,['盤點','盤點機','貨倉管理','庫存管理','公司庫存']);
    $stocktakeLock=fopen($stocktakesFile.'.file.lock','c+');
    if($stocktakeLock===false||!flock($stocktakeLock,LOCK_EX))scanner_response(['ok'=>false,'error'=>'盤點單正由其他人更新，請稍後再試'],503);
    $sessions=scanner_read($stocktakesFile);
    $sessionId=trim((string)($payload['sessionId']??''));
    $found=null;foreach($sessions as $i=>$s)if((string)($s['id']??'')===$sessionId){$found=$i;break;}
    if($found===null)scanner_response(['ok'=>false,'error'=>'找不到盤點單'],404);
    $session=$sessions[$found];
    if(($session['status']??'')!=='approved')scanner_response(['ok'=>false,'error'=>'只有已核准的基準盤點單可以重開修正數量'],409);
    if(empty($session['baselineMode'])&&empty($session['excludedFromLossSurplus']))scanner_response(['ok'=>false,'error'=>'一般盤點已入帳，不能重開覆蓋'],409);
    $now=date(DATE_ATOM);
    $sessions[$found]['status']='pending_review';
    $sessions[$found]['reopenedAt']=$now;
    $sessions[$found]['reopenedBy']=$actor;
    $sessions[$found]['reopenedReason']=trim((string)($payload['reason']??'基準帶入數量需修正'));
    $sessions[$found]['previousApprovedAt']=(string)($session['approvedAt']??'');
    $sessions[$found]['updatedAt']=$now;
    unset($sessions[$found]['approvedAt']);
    scanner_write($stocktakesFile,$sessions);
    flock($stocktakeLock,LOCK_UN);fclose($stocktakeLock);
    scanner_response(['ok'=>true,'session'=>$sessions[$found],'formalInventoryChanged'=>false]);
}
if($action==='stocktake_approve'||$action==='stocktake_reject'){
    $stocktakeLock=fopen($stocktakesFile.'.file.lock','c+');
    if($stocktakeLock===false||!flock($stocktakeLock,LOCK_EX))scanner_response(['ok'=>false,'error'=>'盤點草稿正由其他人更新，請稍後再核准'],503);
    $sessions=scanner_read($stocktakesFile);
    $sessionId=(string)($payload['sessionId']??'');$found=null;foreach($sessions as $i=>$s)if((string)($s['id']??'')===$sessionId){$found=$i;break;}
    if($found===null)scanner_response(['ok'=>false,'error'=>'找不到盤點單'],404);
    $reviewStatus=(string)($sessions[$found]['status']??'draft');
    $directDraftApproval=$action==='stocktake_approve'&&$reviewStatus==='draft'&&($payload['approveDraft']??false)===true;
    if($reviewStatus!=='pending_review'&&!$directDraftApproval)scanner_response(['ok'=>false,'error'=>'這張單已處理，或尚未確認直接核准盤點草稿，請重新整理'],409);
    if($action==='stocktake_approve'&&empty($sessions[$found]['lines']))scanner_response(['ok'=>false,'error'=>'空白盤點單沒有商品，不能核准入帳'],409);
    if($directDraftApproval){$sessions[$found]['directDraftApproval']=true;$sessions[$found]['draftClosedBy']=$operator;}
    if($action==='stocktake_reject'){$sessions[$found]['status']='rejected';$sessions[$found]['reviewedAt']=date(DATE_ATOM);$sessions[$found]['reviewer']=$operator;scanner_write($stocktakesFile,$sessions);flock($stocktakeLock,LOCK_UN);fclose($stocktakeLock);scanner_response(['ok'=>true,'session'=>$sessions[$found]]);}
    $sessionStartedAt=strtotime((string)($sessions[$found]['startedAt']??$sessions[$found]['submittedAt']??''))?:0;
    $staleAgainst=[];
    foreach(($sessions[$found]['lines']??[]) as $line){
        $lineSku=(string)($line['sourceSkuId']??'');$lineWarehouse=(string)($line['warehouse']??$sessions[$found]['warehouse']??'');
        foreach($sessions as $otherIndex=>$otherSession){
            if($otherIndex===$found||($otherSession['status']??'')!=='approved')continue;
            $otherApprovedAt=strtotime((string)($otherSession['approvedAt']??''))?:0;
            if($otherApprovedAt<=$sessionStartedAt)continue;
            foreach(($otherSession['lines']??[]) as $otherLine){
                if((string)($otherLine['sourceSkuId']??'')===$lineSku&&(string)($otherLine['warehouse']??$otherSession['warehouse']??'')===$lineWarehouse){
                    $staleAgainst[(string)($otherSession['id']??'較新盤點')]=$otherSession['approvedAt']??'';break;
                }
            }
        }
    }
    if($staleAgainst)scanner_response(['ok'=>false,'error'=>'這張盤點早於較新的已核准盤點，禁止覆蓋目前庫存','stale'=>true,'newerApprovedSessions'=>$staleAgainst],409);
    $approvedAt=date(DATE_ATOM);
    foreach(($sessions[$found]['lines']??[]) as $line){$sourceIndex=null;foreach($skus as $i=>$sku)if((string)($sku['id']??$sku['sku']??'')===(string)($line['sourceSkuId']??'')){$sourceIndex=$i;break;}if($sourceIndex===null)continue;$sourceProduct=$productMap[(string)($skus[$sourceIndex]['productId']??'')]??[];$target=find_target_sku($skus,$skus[$sourceIndex],(string)$line['warehouse']);if($target===null)$target=clone_to_warehouse($skus,$skus[$sourceIndex],(string)$line['warehouse'],0,$sourceProduct);$skus[$target]['stock']=(int)$line['countedQty'];$skus[$target]['updatedAt']=$approvedAt;$skus[$target]['stocktakeBaselineAt']=$approvedAt;$skus[$target]['stocktakeBaselineQty']=(int)$line['countedQty'];$skus[$target]['stocktakeSessionId']=(string)($sessions[$found]['id']??'');$approvedShelf=trim((string)($line['shelf']??''));$approvedLayer=trim((string)($line['layer']??''));if($approvedShelf!==''){$skus[$target]['shelf']=$approvedShelf;$skus[$target]['shelves']=[$approvedShelf];}if($approvedLayer!==''){$skus[$target]['layer']=$approvedLayer;$skus[$target]['layers']=[$approvedLayer];}}
    $productsChanged=false;
    foreach(($sessions[$found]['lines']??[]) as $photoLine){
        $lineImage=trim((string)($photoLine['image']??''));if($lineImage==='')continue;
        $sourceIndex=null;foreach($skus as $i=>$sku)if((string)($sku['id']??$sku['sku']??'')===(string)($photoLine['sourceSkuId']??'')){$sourceIndex=$i;break;}if($sourceIndex===null)continue;
        $photoRole=(string)($photoLine['imageRole']??'color');
        if($photoRole==='color'){$skus[$sourceIndex]['colorImage']=$lineImage;$skus[$sourceIndex]['image']=$lineImage;}
        $target=find_target_sku($skus,$skus[$sourceIndex],(string)($photoLine['warehouse']??''));if($target!==null&&$photoRole==='color'){$skus[$target]['colorImage']=$lineImage;$skus[$target]['image']=$lineImage;}
        $productId=(string)($photoLine['productId']??$skus[$sourceIndex]['productId']??'');$lineColor=(string)($photoLine['color']??'');
        foreach($products as &$photoProduct){if((string)($photoProduct['id']??'')!==$productId)continue;if($photoRole==='main'){$photoProduct['mainImage']=$lineImage;}else{if(!isset($photoProduct['colors'])||!is_array($photoProduct['colors']))$photoProduct['colors']=[];$matched=false;foreach($photoProduct['colors'] as &$colorMeta){if(is_array($colorMeta)&&(string)($colorMeta['name']??'')===$lineColor){$colorMeta['image']=$lineImage;$matched=true;break;}}unset($colorMeta);if(!$matched)$photoProduct['colors'][]=['name'=>$lineColor,'image'=>$lineImage];if(scanner_placeholder_image($photoProduct['mainImage']??''))$photoProduct['mainImage']=$lineImage;}$productsChanged=true;break;}unset($photoProduct);
    }
    $sessions[$found]['status']='approved';$sessions[$found]['approvedAt']=$approvedAt;$sessions[$found]['reviewer']=$operator;
    $approvedKeys=[];
    foreach(($sessions[$found]['lines']??[]) as $approvedLine){
        $approvedKeys[(string)($approvedLine['sourceSkuId']??'').'|'.(string)($approvedLine['warehouse']??$sessions[$found]['warehouse']??'')]=true;
    }
    foreach($sessions as $olderIndex=>&$olderSession){
        if($olderIndex===$found||!in_array((string)($olderSession['status']??'draft'),['draft','pending_review'],true))continue;
        $olderStartedAt=strtotime((string)($olderSession['startedAt']??$olderSession['submittedAt']??''))?:0;
        if($olderStartedAt>$sessionStartedAt)continue;
        $overlaps=false;
        foreach(($olderSession['lines']??[]) as $olderLine){
            $olderKey=(string)($olderLine['sourceSkuId']??'').'|'.(string)($olderLine['warehouse']??$olderSession['warehouse']??'');
            if(isset($approvedKeys[$olderKey])){$overlaps=true;break;}
        }
        if(!$overlaps)continue;
        $olderSession['status']='superseded';$olderSession['supersededAt']=$approvedAt;$olderSession['supersededBy']=(string)($sessions[$found]['id']??'');$olderSession['supersededReason']='較新的正式盤點已核准；保留原始內容但禁止覆蓋新庫存';
    }
    unset($olderSession);
    // Fail closed if the process stops between files: never retry stock writes
    // automatically from a partially applied approval.
    $approvalCheckpoint=scanner_read($stocktakesFile);
    $approvalCheckpoint[$found]['status']='applying';
    $approvalCheckpoint[$found]['reviewer']=$operator;
    $approvalCheckpoint[$found]['approvalStartedAt']=$approvedAt;
    scanner_write($stocktakesFile,$approvalCheckpoint);
    scanner_write($skusFile,$skus);if($productsChanged)scanner_write($productsFile,$products);$state['skus']=$skus;if($productsChanged)$state['products']=$products;$state['updatedAt']=$approvedAt;$state['revision']=max(0,(int)($state['revision']??0))+1;scanner_write($stateFile,$state);scanner_write($stocktakesFile,$sessions);flock($stocktakeLock,LOCK_UN);fclose($stocktakeLock);scanner_response(['ok'=>true,'session'=>$sessions[$found]]);
}

if($action==='quick_stockin_reject'){
    if((string)($operator['role']??'')!=='admin')scanner_response(['ok'=>false,'error'=>'只有管理者可以退回臨時建檔草稿'],403);
    $draftId=trim((string)($payload['batchId']??''));$entries=scanner_read($pendingStockinFile);$found=null;
    foreach($entries as $index=>$entry)if((string)($entry['id']??'')===$draftId){$found=$index;break;}
    if($found===null)scanner_response(['ok'=>false,'error'=>'找不到快速入庫草稿'],404);
    if(($entries[$found]['status']??'')!=='pending_review')scanner_response(['ok'=>false,'error'=>'此草稿已處理'],409);
    $entries[$found]['status']='rejected';$entries[$found]['reviewer']=$operator;$entries[$found]['reviewedAt']=date(DATE_ATOM);
    scanner_write($pendingStockinFile,$entries);scanner_response(['ok'=>true,'draft'=>$entries[$found]]);
}

if($action==='quick_stockin_approve'){
    if((string)($operator['role']??'')!=='admin')scanner_response(['ok'=>false,'error'=>'只有管理者可以核准臨時建檔草稿'],403);
    $quickStockApprovalId=trim((string)($payload['batchId']??''));$entries=scanner_read($pendingStockinFile);
    foreach($entries as $index=>$entry)if((string)($entry['id']??'')===$quickStockApprovalId){$quickStockApprovalIndex=$index;break;}
    if($quickStockApprovalIndex===null)scanner_response(['ok'=>false,'error'=>'找不到快速入庫草稿'],404);
    if(($entries[$quickStockApprovalIndex]['status']??'')!=='pending_review')scanner_response(['ok'=>false,'error'=>'此草稿已處理'],409);
    $payload['lines']=is_array($entries[$quickStockApprovalIndex]['lines']??null)?$entries[$quickStockApprovalIndex]['lines']:[];
    $payload['warehouse']=(string)($entries[$quickStockApprovalIndex]['warehouse']??'');
    $payload['entryType']=(string)($entries[$quickStockApprovalIndex]['entryType']??$entries[$quickStockApprovalIndex]['type']??'initial_inventory_setup');
    $warehouse=$payload['warehouse'];$action='quick_stockin_batch';
}

if($action==='quick_stockin_draft'){
    if(empty($initialStockMode['enabled']))scanner_response(['ok'=>false,'error'=>'首次庫存臨時建檔入口已關閉'],410);
    $operatorRole=(string)($operator['role']??'');
    $operatorPermissions=is_array($operator['permissions']??null)?$operator['permissions']:[];
    $stockinAllowed=$operatorRole==='admin'||($operatorRole==='staff'&&count(array_intersect($operatorPermissions,['盤點機','快速入庫','貨倉管理','庫存管理','公司庫存']))>0);
    if(!$stockinAllowed)scanner_response(['ok'=>false,'error'=>'此帳號沒有臨時建檔權限'],403);
    $lines=is_array($payload['lines']??null)?$payload['lines']:[];
    if(!$lines)scanner_response(['ok'=>false,'error'=>'快速入庫草稿沒有產品'],400);
    if(count($lines)>300)scanner_response(['ok'=>false,'error'=>'單次快速入庫最多 300 個品項'],400);
    $entries=scanner_read($pendingStockinFile);
    $batchId='INIT-DRAFT-'.date('Ymd-His').'-'.strtoupper(bin2hex(random_bytes(2)));
    $now=date(DATE_ATOM);$operations=[];$totalQty=0;$totalCost=0.0;
    foreach($lines as $index=>$line){
        if(!is_array($line))scanner_response(['ok'=>false,'error'=>'第 '.($index+1).' 筆格式錯誤'],400);
        $rawCode=trim((string)($line['rawCode']??$line['barcode']??$line['productCode']??''));
        $lineWarehouse=trim((string)($line['warehouse']??$warehouse));
        $lineQty=(int)($line['qty']??0);$unitCost=round((float)($line['unitCostTwd']??0),2);
        $shelf=trim((string)($line['shelf']??''));$layer=trim((string)($line['layer']??''));
        if($rawCode===''||$lineWarehouse===''||$lineQty<1||$unitCost<=0)scanner_response(['ok'=>false,'error'=>'第 '.($index+1).' 筆缺少條碼、倉庫、數量或成本'],400);
        $draftInventoryAction=(string)($line['inventoryAction']??'stockin');
        if(!in_array($draftInventoryAction,['stockin','transfer'],true))scanner_response(['ok'=>false,'error'=>'請先確認新貨入庫或跨倉調回，再儲存草稿'],409);
        $before=0;$skuId=trim((string)($line['skuId']??''));
        foreach($skus as $sku){
            if(!is_array($sku))continue;
            $sameId=$skuId!==''&&(string)($sku['id']??$sku['sku']??'')===$skuId;
            $sameBarcode=$skuId===''&&scanner_barcode_key_matches($rawCode,$sku['barcode']??$sku['sku']??'');
            if(($sameId||$sameBarcode)&&sku_warehouse($sku)===$lineWarehouse){$before+=(int)($sku['stock']??0);}
        }
        $photo=scanner_save_image(is_string($line['imageData']??null)?$line['imageData']:null);
        $operation=[
            'id'=>$batchId.'-'.str_pad((string)($index+1),3,'0',STR_PAD_LEFT),'batchId'=>$batchId,'status'=>'pending_review','action'=>'initial_inventory_setup_draft','entryType'=>'initial_inventory_setup',
            'rawCode'=>$rawCode,'barcode'=>trim((string)($line['barcode']??$rawCode)),'skuId'=>$skuId,'productCode'=>trim((string)($line['productCode']??$rawCode)),'catalogProductCode'=>trim((string)($line['catalogProductCode']??scanner_quick_product_code($rawCode))),
            'title'=>trim((string)($line['title']??'')),'category'=>trim((string)($line['category']??'')),'color'=>trim((string)($line['color']??'')),'size'=>scanner_quick_size($line['size']??'NO SIZE'),
            'warehouse'=>$lineWarehouse,'shelf'=>$shelf,'layer'=>$layer,'qty'=>$lineQty,'beforeStock'=>$before,'expectedAfterStock'=>$before+$lineQty,
            'unitCostTwd'=>$unitCost,'totalCostTwd'=>round($unitCost*$lineQty,2),'photo'=>$photo,'imageRole'=>(string)($line['imageRole']??'color'),
            'inventoryAction'=>$draftInventoryAction,'sourceSkuId'=>(string)($line['sourceSkuId']??''),'sourceWarehouse'=>(string)($line['sourceWarehouse']??''),
            'operator'=>$operator,'createdAt'=>$now,'updatedAt'=>$now
        ];
        $operations[]=$operation;$totalQty+=$lineQty;$totalCost+=$operation['totalCostTwd'];
    }
    array_unshift($entries,['id'=>$batchId,'status'=>'pending_review','type'=>'initial_inventory_setup','entryType'=>'initial_inventory_setup','operator'=>$operator,'warehouse'=>$warehouse,'lines'=>$operations,'totalQty'=>$totalQty,'totalCostTwd'=>round($totalCost,2),'createdAt'=>$now,'updatedAt'=>$now]);
    // Pending drafts must never disappear merely because more drafts arrive.
    scanner_write($pendingStockinFile,$entries);
    scanner_response(['ok'=>true,'batchId'=>$batchId,'status'=>'pending_review','operations'=>$operations,'totalQty'=>$totalQty,'totalCostTwd'=>round($totalCost,2)]);
}

if($action==='quick_stockin_batch'){
    if(!$directInitialSetup&&($quickStockApprovalIndex===null||$quickStockApprovalId===''))scanner_response(['ok'=>false,'error'=>'必須從草稿審核入口核准，不能直接入庫'],403);
    $isInitialSetup=(string)($payload['entryType']??'')==='initial_inventory_setup';
    $lines=is_array($payload['lines']??null)?$payload['lines']:[];
    if(!$lines)scanner_response(['ok'=>false,'error'=>'快速入庫批次沒有產品'],400);
    if(count($lines)>300)scanner_response(['ok'=>false,'error'=>'單次快速入庫最多 300 個品項'],400);
    $prepared=[];$knownSkuIds=[];
    foreach($skus as $i=>$sku)if(is_array($sku)){$id=(string)($sku['id']??$sku['sku']??'');if($id!=='')$knownSkuIds[$id]=$i;}
    foreach($lines as $lineIndex=>$line){
        $number=$lineIndex+1;
        if(!is_array($line))scanner_response(['ok'=>false,'error'=>'第 '.$number.' 筆格式錯誤'],400);
        $lineSkuId=trim((string)($line['skuId']??''));
        $rawCode=trim((string)($line['rawCode']??$line['barcode']??$line['productCode']??''));
        $lineWarehouse=trim((string)($line['warehouse']??$warehouse));
        $lineQty=(int)($line['qty']??0);
        $unitCost=round((float)($line['unitCostTwd']??0),2);
        $category=trim((string)($line['category']??''))?:'快速入庫待補';
        $color=trim((string)($line['color']??''))?:'待補顏色';
        $size=scanner_quick_size($line['size']??'NO SIZE');
        $lineShelf=trim((string)($line['shelf']??''));
        $lineLayer=trim((string)($line['layer']??''));
        $inventoryAction=trim((string)($line['inventoryAction']??'stockin'));
        $sourceSkuId=trim((string)($line['sourceSkuId']??''));
        $sourceWarehouse=trim((string)($line['sourceWarehouse']??''));
        if($lineWarehouse==='')scanner_response(['ok'=>false,'error'=>'第 '.$number.' 筆缺少入庫倉庫'],400);
        if(!$isInitialSetup&&($lineShelf===''||$lineLayer===''))scanner_response(['ok'=>false,'error'=>'第 '.$number.' 筆缺少貨架或層位，請先完成倉位設定'],400);
        if($lineQty<1)scanner_response(['ok'=>false,'error'=>'第 '.$number.' 筆入庫數量至少 1 件'],400);
        if($unitCost<=0)scanner_response(['ok'=>false,'error'=>'第 '.$number.' 筆缺少有效單件成本'],400);
        if($inventoryAction==='review')scanner_response(['ok'=>false,'error'=>'第 '.$number.' 筆在其他倉已有庫存，請先選擇新貨入庫或跨倉調回'],409);
        if(!in_array($inventoryAction,['stockin','transfer'],true))scanner_response(['ok'=>false,'error'=>'第 '.$number.' 筆庫存處理方式無效'],400);
        $sourceIndex=null;
        if($inventoryAction==='transfer'){
            if($sourceSkuId===''||!isset($knownSkuIds[$sourceSkuId]))scanner_response(['ok'=>false,'error'=>'第 '.$number.' 筆找不到其他倉來源 SKU，請重新掃描'],409);
            $sourceIndex=(int)$knownSkuIds[$sourceSkuId];
            $actualSourceWarehouse=trim((string)($skus[$sourceIndex]['warehouse']??$skus[$sourceIndex]['warehouseName']??''));
            if($sourceWarehouse===''||$actualSourceWarehouse!==$sourceWarehouse||$sourceWarehouse===$lineWarehouse)scanner_response(['ok'=>false,'error'=>'第 '.$number.' 筆跨倉來源不正確，請重新選擇'],409);
            if((int)($skus[$sourceIndex]['stock']??0)<$lineQty)scanner_response(['ok'=>false,'error'=>'第 '.$number.' 筆「'.$sourceWarehouse.'」只剩 '.(int)($skus[$sourceIndex]['stock']??0).' 件，不足以調回 '.$lineQty.' 件'],409);
        }
        $baseIndex=$lineSkuId!==''&&isset($knownSkuIds[$lineSkuId])?(int)$knownSkuIds[$lineSkuId]:null;
        if($baseIndex===null&&$rawCode!==''){
            // Never create a second product merely because the browser omitted
            // skuId. Resolve the scanned value on the server and stop on any
            // cross-product/cross-variant ambiguity.
            $baseIndex=scanner_unique_exact_sku_index($products,$skus,$rawCode,$sessions,$barcodeAliases,$lineWarehouse);
            if($baseIndex!==null){
                $lineSkuId=(string)($skus[$baseIndex]['id']??$skus[$baseIndex]['sku']??'');
            }
        }
        if($baseIndex===null&&$rawCode==='')scanner_response(['ok'=>false,'error'=>'第 '.$number.' 筆缺少 SKU 或原始條碼'],400);
        if($baseIndex!==null){
            $existingColor=trim((string)($skus[$baseIndex]['colorName']??$skus[$baseIndex]['color']??''));
            if(!scanner_placeholder_color($existingColor)&&$color!==$existingColor){
                scanner_response(['ok'=>false,'error'=>'第 '.$number.' 筆既有正式顏色是「'.$existingColor.'」，不能在快速入庫改成「'.$color.'」；請改選正確產品或先建新顏色規格'],409);
            }
        }
        $prepared[]=[
            'line'=>$line,'lineNumber'=>$number,'baseIndex'=>$baseIndex,'skuId'=>$lineSkuId,'rawCode'=>$rawCode,
            'warehouse'=>$lineWarehouse,'qty'=>$lineQty,'unitCostTwd'=>$unitCost,'category'=>$category,
            'color'=>$color,'size'=>$size,'title'=>trim((string)($line['title']??'')),
            'productCode'=>trim((string)($line['productCode']??'')),'catalogProductCode'=>trim((string)($line['catalogProductCode']??scanner_quick_product_code($rawCode))),'shelf'=>$lineShelf,
            'layer'=>$lineLayer,'imageData'=>is_string($line['imageData']??null)?$line['imageData']:'','imageRole'=>in_array((string)($line['imageRole']??''),['main','color'],true)?(string)$line['imageRole']:'color',
            'inventoryAction'=>$inventoryAction,'sourceSkuId'=>$sourceSkuId,'sourceWarehouse'=>$sourceWarehouse,'sourceIndex'=>$sourceIndex
        ];
    }

    $ledger=scanner_read($ledgerFile);
    $clientRequestId=trim((string)($payload['clientRequestId']??''));
    if($clientRequestId===''&&$quickStockApprovalId!=='')$clientRequestId='APPROVE-'.$quickStockApprovalId;
    if($directInitialSetup&&$clientRequestId==='')scanner_response(['ok'=>false,'error'=>'本次入庫缺少防重複交易編號，已停止寫入庫存。請重新整理後再儲存'],400);
    if($clientRequestId!==''){
        $replayed=[];
        foreach($ledger as $oldOperation)if(is_array($oldOperation)&&hash_equals((string)($oldOperation['clientRequestId']??''),$clientRequestId))$replayed[]=$oldOperation;
        if($replayed){
            $replayBatch=(string)($replayed[0]['batchId']??'');
            scanner_response([
                'ok'=>true,'replayed'=>true,'batchId'=>$replayBatch,
                'entryType'=>$isInitialSetup?'initial_inventory_setup':'stockin',
                'totalItems'=>count($replayed),
                'totalQty'=>array_sum(array_map(static fn($row)=>max(0,(int)($row['qty']??0)),$replayed)),
                'totalCostTwd'=>round(array_sum(array_map(static fn($row)=>(float)($row['totalCostTwd']??0),$replayed)),2),
                'customerAllocations'=>[],'operations'=>$replayed,
                'message'=>'此入庫交易已成功寫入過，本次只回傳原結果，沒有再次增加庫存。'
            ]);
        }
    }
    $batchId=($isInitialSetup?'INIT-':'QSI-').date('Ymd-His').'-'.strtoupper(bin2hex(random_bytes(2)));
    $costSource=$isInitialSetup?'initial_inventory_setup':'scanner_quick_stockin';
    $operationAction=$isInitialSetup?'initial_inventory_setup':'quick_stockin';
    $operations=[];$totalQty=0;$totalCost=0.0;$now=date(DATE_ATOM);
    $ordersForAllocation=scanner_read($ordersFile);$receiptAllocations=[];$allocationChanged=false;
    foreach($prepared as $item){
        $baseIndex=$item['baseIndex'];$productIndex=null;$image='';
        if($baseIndex===null){
            $code=scanner_quick_product_code($item['catalogProductCode']!==''?$item['catalogProductCode']:($item['productCode']!==''?$item['productCode']:$item['rawCode']));
            $normalized=scanner_norm($code);
            if($normalized==='')$normalized='QSI'.date('YmdHis').strtoupper(bin2hex(random_bytes(2)));
            $productIndex=scanner_find_product_index($products,'',$code);
            if($productIndex===null){
                $image=scanner_save_image($item['imageData'])?:trim((string)($item['line']['photo']??''));
                $productId=scanner_unique_id($products,'PROD-'.$normalized);
                $products[]=[
                    'id'=>$productId,'code'=>$code,'title'=>$code,
                    'category'=>$item['category'],'mainImage'=>$image!==''?$image:'./assets/brand-logo.png',
                    'status'=>'active','active'=>true,'pendingMetadata'=>$item['category']==='快速入庫待補'||scanner_placeholder_color($item['color']),
                    'currentCostTwd'=>$item['unitCostTwd'],'cost'=>$item['unitCostTwd'],'costSource'=>$costSource,
                    'costEffectiveAt'=>$now,'currentCostUpdatedAt'=>$now,'costUpdatedAt'=>$now,'createdAt'=>$now,'updatedAt'=>$now
                ];
                $productIndex=count($products)-1;
            }
            $productId=(string)($products[$productIndex]['id']??$products[$productIndex]['code']??$code);
            $targetIndex=scanner_find_variant_warehouse($skus,$productId,$item['color'],$item['size'],$item['warehouse']);
            if($targetIndex===null){
                $skuId=scanner_unique_id($skus,'SKU-'.$normalized.'-'.substr(md5($item['color'].'|'.$item['size'].'|'.$item['warehouse']),0,6));
                $skus[]=[
                    'id'=>$skuId,'sku'=>$skuId,'productId'=>$productId,'barcode'=>$item['rawCode'],'companyBarcode'=>$item['rawCode'],
                    'color'=>$item['color'],'colorName'=>$item['color'],'size'=>$item['size'],'sizeName'=>$item['size'],
                    'warehouse'=>$item['warehouse'],'warehouseName'=>$item['warehouse'],'warehouseCode'=>scanner_warehouse_code($item['warehouse']) ?: $item['warehouse'],
                    'stock'=>0,'status'=>'active','active'=>true,'createdAt'=>$now,'updatedAt'=>$now
                ];
                $targetIndex=count($skus)-1;
            }
        }else{
            $productId=(string)($skus[$baseIndex]['productId']??'');
            $productIndex=scanner_find_product_index($products,$productId,$item['productCode']);
            if($productIndex===null)scanner_response(['ok'=>false,'error'=>'第 '.$item['lineNumber'].' 筆找不到產品主檔，整批尚未入庫'],404);
            if(scanner_placeholder_color(trim((string)($skus[$baseIndex]['colorName']??$skus[$baseIndex]['color']??'')))){
                $skus[$baseIndex]['color']=$item['color'];$skus[$baseIndex]['colorName']=$item['color'];
            }
            $targetIndex=scanner_find_variant_warehouse($skus,$productId,$item['color'],$item['size'],$item['warehouse']);
            if($targetIndex===null){
                $copy=$skus[$baseIndex];
                $newSkuId=scanner_unique_id($skus,(string)($copy['id']??$copy['sku']??'SKU').'-'.substr(md5($item['warehouse']),0,6));
                $copy['id']=$newSkuId;$copy['sku']=$newSkuId;$copy['sourceSkuId']=(string)($skus[$baseIndex]['id']??$skus[$baseIndex]['sku']??'');
                $copy['color']=$item['color'];$copy['colorName']=$item['color'];$copy['size']=$item['size'];$copy['sizeName']=$item['size'];
                $copy['warehouse']=$item['warehouse'];$copy['warehouseName']=$item['warehouse'];$copy['warehouseCode']=scanner_warehouse_code($item['warehouse']) ?: $item['warehouse'];
                $copy['stock']=0;$copy['status']='active';$copy['active']=true;$copy['createdAt']=$now;$copy['updatedAt']=$now;
                $skus[]=$copy;$targetIndex=count($skus)-1;
            }
        }
        $previous=(int)($skus[$targetIndex]['stock']??0);
        $uploadedImage=scanner_save_image($item['imageData'])?:trim((string)($item['line']['photo']??''));
        if($uploadedImage!==''){
            $image=$uploadedImage;
            if($item['imageRole']==='color'){$skus[$targetIndex]['colorImage']=$uploadedImage;$skus[$targetIndex]['image']=$uploadedImage;}
            if($item['imageRole']==='main'||scanner_placeholder_image($products[$productIndex]['mainImage']??''))$products[$productIndex]['mainImage']=$uploadedImage;
            $productImages=is_array($products[$productIndex]['images']??null)?$products[$productIndex]['images']:[];
            array_unshift($productImages,$uploadedImage);
            $products[$productIndex]['images']=array_values(array_slice(array_unique(array_filter($productImages)),0,30));
            $colorMatched=$item['imageRole']!=='color';
            $productColors=is_array($products[$productIndex]['colors']??null)?$products[$productIndex]['colors']:[];
            foreach($productColors as &$colorMeta){
                if(!is_array($colorMeta))continue;
                $colorName=trim((string)($colorMeta['name']??$colorMeta['color']??''));
                if($colorName!==''&&scanner_color_key($colorName)===scanner_color_key($item['color'])){
                    $colorMeta['image']=$uploadedImage;
                    $colorMatched=true;
                    break;
                }
            }
            unset($colorMeta);
            if(!$colorMatched)$productColors[]=['code'=>'','name'=>$item['color'],'image'=>$uploadedImage];
            $products[$productIndex]['colors']=$productColors;
        }
        if(scanner_placeholder_image($products[$productIndex]['mainImage']??'')){
            $fallbackMain=scanner_first_color_image($products[$productIndex]);
            if($fallbackMain!=='')$products[$productIndex]['mainImage']=$fallbackMain;
        }
        $sourcePrevious=null;$sourceRemaining=null;
        if($item['inventoryAction']==='transfer'){
            $transferSource=$skus[$item['sourceIndex']];
            if((string)($transferSource['productId']??'')!==(string)($skus[$targetIndex]['productId']??'')
                ||scanner_color_key($transferSource['colorName']??$transferSource['color']??'')!==scanner_color_key($item['color'])
                ||scanner_quick_size($transferSource['sizeName']??$transferSource['size']??'')!==scanner_quick_size($item['size']))
                scanner_response(['ok'=>false,'error'=>'第 '.$item['lineNumber'].' 筆來源倉商品、顏色或尺寸不符，請重新查詢並選擇來源'],409);
            $sourcePrevious=(int)($skus[$item['sourceIndex']]['stock']??0);
            if($sourcePrevious<$item['qty'])scanner_response(['ok'=>false,'error'=>'第 '.$item['lineNumber'].' 筆其他倉庫存剛被異動，請重新整理後再試'],409);
            $sourceRemaining=$sourcePrevious-$item['qty'];
            $skus[$item['sourceIndex']]['stock']=$sourceRemaining;
            $skus[$item['sourceIndex']]['updatedAt']=$now;
        }
        $remaining=$previous+$item['qty'];
        $skus[$targetIndex]['stock']=$remaining;$skus[$targetIndex]['status']='active';$skus[$targetIndex]['active']=true;
        $skus[$targetIndex]['category']=$item['category'];$skus[$targetIndex]['color']=$item['color'];$skus[$targetIndex]['colorName']=$item['color'];
        $skus[$targetIndex]['size']=$item['size'];$skus[$targetIndex]['sizeName']=$item['size'];
        // Restoring counted stock must not reprice an existing SKU or its cost history.
        $hasSkuCost=false;
        foreach(['currentCostTwd','cost','finalCostTwd'] as $costKey)if(isset($skus[$targetIndex][$costKey])&&is_numeric($skus[$targetIndex][$costKey]))$hasSkuCost=true;
        if(!$isInitialSetup||!$hasSkuCost){
            $restoreCost=$item['unitCostTwd'];
            if($isInitialSetup)foreach(['currentCostTwd','cost','finalCostTwd'] as $costKey)if(isset($products[$productIndex][$costKey])&&is_numeric($products[$productIndex][$costKey])){$restoreCost=(float)$products[$productIndex][$costKey];break;}
            $skus[$targetIndex]['currentCostTwd']=$restoreCost;$skus[$targetIndex]['cost']=$restoreCost;$skus[$targetIndex]['finalCostTwd']=$restoreCost;
            $skus[$targetIndex]['costSource']=$costSource;$skus[$targetIndex]['costEffectiveAt']=$now;$skus[$targetIndex]['currentCostUpdatedAt']=$now;$skus[$targetIndex]['costUpdatedAt']=$now;
        }
        $skus[$targetIndex]['updatedAt']=$now;
        if($item['shelf']!=='')$skus[$targetIndex]['shelf']=$item['shelf'];if($item['layer']!=='')$skus[$targetIndex]['layer']=$item['layer'];
        $pendingMetadata=$item['category']==='快速入庫待補'||scanner_placeholder_color($item['color']);
        $products[$productIndex]['category']=$item['category'];$products[$productIndex]['status']='active';$products[$productIndex]['active']=true;$products[$productIndex]['pendingMetadata']=$pendingMetadata;
        $hasProductCost=false;
        foreach(['currentCostTwd','cost','finalCostTwd'] as $costKey)if(isset($products[$productIndex][$costKey])&&is_numeric($products[$productIndex][$costKey]))$hasProductCost=true;
        if(!$isInitialSetup||!$hasProductCost){
            $products[$productIndex]['currentCostTwd']=$item['unitCostTwd'];$products[$productIndex]['cost']=$item['unitCostTwd'];$products[$productIndex]['costSource']=$costSource;$products[$productIndex]['costEffectiveAt']=$now;$products[$productIndex]['currentCostUpdatedAt']=$now;$products[$productIndex]['costUpdatedAt']=$now;
        }
        $products[$productIndex]['updatedAt']=$now;
        $lineTotal=($item['inventoryAction']==='transfer'||$isInitialSetup)?0:round($item['unitCostTwd']*$item['qty'],2);
        $itemOperationAction=$item['inventoryAction']==='transfer'?'scanner_cross_warehouse_transfer':$operationAction;
        $itemAllocations=[];
        if(!$isInitialSetup&&$item['inventoryAction']!=='transfer'&&scanner_warehouse_code($skus[$targetIndex]['warehouseCode']??$skus[$targetIndex]['warehouseName']??$skus[$targetIndex]['warehouse']??'')==='TW'){
            $itemAllocations=scanner_allocate_taiwan_receipt($ordersForAllocation,$skus[$targetIndex],$products[$productIndex],$item['qty'],$batchId,(string)($operator['name']??$operator['account']??'入庫人員'),$now);
            $allocatedFromReceipt=array_sum(array_map(static fn($row)=>max(0,(int)($row['qty']??0)),$itemAllocations));
            if($allocatedFromReceipt>0){
                $allocationChanged=true;$remaining=max(0,$remaining-$allocatedFromReceipt);$skus[$targetIndex]['stock']=$remaining;
                foreach($itemAllocations as $allocation){$allocation['skuId']=(string)($skus[$targetIndex]['id']??$skus[$targetIndex]['sku']??'');$receiptAllocations[]=$allocation;}
            }
        }
        $operation=[
            'id'=>'SCAN-'.date('Ymd-His').'-'.strtoupper(bin2hex(random_bytes(2))),'batchId'=>$batchId,'action'=>$itemOperationAction,'entryType'=>$item['inventoryAction']==='transfer'?'warehouse_transfer':($isInitialSetup?'initial_inventory_setup':'stockin'),
            'skuId'=>$item['skuId'],'targetSkuId'=>(string)($skus[$targetIndex]['id']??$skus[$targetIndex]['sku']??''),
            'barcode'=>(string)($item['line']['barcode']??$item['rawCode']),'productId'=>(string)($products[$productIndex]['id']??''),
            'productCode'=>(string)($products[$productIndex]['code']??$item['productCode']),'productTitle'=>(string)($products[$productIndex]['title']??$item['title']),
            'category'=>$item['category'],'color'=>$item['color'],'size'=>$item['size'],'warehouse'=>$item['warehouse'],
            'qty'=>$item['qty'],'previousStock'=>$previous,'remainingStock'=>$remaining,'unitCostTwd'=>$item['unitCostTwd'],
            'totalCostTwd'=>$lineTotal,'costSource'=>$costSource,'pendingMetadata'=>$pendingMetadata,
            'inventoryPurpose'=>$isInitialSetup?'restore_existing_stock':'stockin',
            'affectsProfitLoss'=>!$isInitialSetup,'excludedFromLossSurplus'=>$isInitialSetup,'skipInventoryGainLoss'=>$isInitialSetup,
            'newPurchaseExpenseTwd'=>$isInitialSetup?0:$lineTotal,'inventoryGainLossTwd'=>0,
            'preserveExistingCost'=>$isInitialSetup,'submittedReferenceUnitCostTwd'=>$item['unitCostTwd'],
            'shelf'=>$item['shelf'],'layer'=>$item['layer'],'image'=>$image!==''?$image:scanner_image($products[$productIndex],$skus[$targetIndex]),
            'sourceWarehouse'=>$item['sourceWarehouse'],'sourceSkuId'=>$item['sourceSkuId'],'sourcePreviousStock'=>$sourcePrevious,'sourceRemainingStock'=>$sourceRemaining,
            'customerAllocations'=>$itemAllocations,'customerAllocatedQty'=>array_sum(array_map(static fn($row)=>max(0,(int)($row['qty']??0)),$itemAllocations)),
            'clientRequestId'=>$clientRequestId,'operator'=>$operator,'createdAt'=>$now
        ];
        $ledger[]=$operation;$operations[]=$operation;$totalQty+=$item['qty'];$totalCost+=$lineTotal;
    }
    $newConflicts=lz_catalog_new_conflicts($skusBeforeMutation,$skus);
    if($newConflicts)scanner_response(['ok'=>false,'error'=>lz_catalog_integrity_message($newConflicts),'code'=>'catalog_integrity_failed','conflicts'=>$newConflicts],409);
    $state['skus']=$skus;
    if($allocationChanged)$state['orders']=$ordersForAllocation;
    $state['updatedAt']=$now;$state['revision']=max(0,(int)($state['revision']??0))+1;
    $commitWrites=[
        $productsFile=>$products,
        $skusFile=>$skus,
        $stateFile=>$state,
        $ledgerFile=>array_slice($ledger,-5000)
    ];
    if($allocationChanged)$commitWrites[$ordersFile]=$ordersForAllocation;
    if($quickStockApprovalIndex!==null){
        $entries=scanner_read($pendingStockinFile);
        $resolvedIndex=null;foreach($entries as $entryIndex=>$entry)if((string)($entry['id']??'')===$quickStockApprovalId){$resolvedIndex=$entryIndex;break;}
        if($resolvedIndex!==null){
            $entries[$resolvedIndex]['status']='approved';
            $entries[$resolvedIndex]['reviewer']=$operator;
            $entries[$resolvedIndex]['reviewedAt']=$now;
            $entries[$resolvedIndex]['formalBatchId']=$batchId;
            $commitWrites[$pendingStockinFile]=$entries;
        }
    }
    scanner_write_group($commitWrites);
    scanner_response(['ok'=>true,'committed'=>true,'clientRequestId'=>$clientRequestId,'batchId'=>$batchId,'entryType'=>$isInitialSetup?'initial_inventory_setup':'stockin','totalItems'=>count($operations),'totalQty'=>$totalQty,'totalCostTwd'=>round($totalCost,2),'customerAllocatedQty'=>array_sum(array_map(static fn($row)=>max(0,(int)($row['qty']??0)),$receiptAllocations)),'customerAllocations'=>$receiptAllocations,'operations'=>$operations]);
}

if($action==='transfer_batch'){
    $lines=is_array($payload['lines']??null)?$payload['lines']:[];
    if(!$lines)scanner_response(['ok'=>false,'error'=>'調撥單沒有產品'],400);
    if(count($lines)>300)scanner_response(['ok'=>false,'error'=>'單張調撥單最多 300 個品項'],400);

    $prepared=[];$sourceTotals=[];
    foreach($lines as $lineIndex=>$line){
        if(!is_array($line))scanner_response(['ok'=>false,'error'=>'第 '.($lineIndex+1).' 筆格式錯誤'],400);
        $lineSkuId=trim((string)($line['skuId']??''));
        $sourceWarehouse=trim((string)($line['sourceWarehouse']??''));
        $targetWarehouse=trim((string)($line['targetWarehouse']??''));
        $lineQty=(int)($line['qty']??0);
        if($lineSkuId===''||$sourceWarehouse===''||$targetWarehouse==='')scanner_response(['ok'=>false,'error'=>'第 '.($lineIndex+1).' 筆缺少 SKU 或倉庫'],400);
        if($sourceWarehouse===$targetWarehouse)scanner_response(['ok'=>false,'error'=>'第 '.($lineIndex+1).' 筆來源與目的倉庫不能相同'],400);
        if($lineQty<1)scanner_response(['ok'=>false,'error'=>'第 '.($lineIndex+1).' 筆調撥數量至少 1 件'],400);
        $baseIndex=null;
        foreach($skus as $i=>$sku)if((string)($sku['id']??$sku['sku']??'')===$lineSkuId){$baseIndex=$i;break;}
        if($baseIndex===null)scanner_response(['ok'=>false,'error'=>'第 '.($lineIndex+1).' 筆找不到 SKU'],404);
        $sourceIndex=find_target_sku($skus,$skus[$baseIndex],$sourceWarehouse);
        if($sourceIndex===null)scanner_response(['ok'=>false,'error'=>'第 '.($lineIndex+1).' 筆來源倉庫沒有這個顏色尺寸'],404);
        $sourceKey=(string)$sourceIndex;
        $sourceTotals[$sourceKey]=($sourceTotals[$sourceKey]??0)+$lineQty;
        $prepared[]=[
            'skuId'=>$lineSkuId,
            'barcode'=>(string)($line['barcode']??''),
            'qty'=>$lineQty,
            'sourceWarehouse'=>$sourceWarehouse,
            'targetWarehouse'=>$targetWarehouse,
            'sourceIndex'=>$sourceIndex
        ];
    }
    foreach($sourceTotals as $sourceKey=>$requestedQty){
        $available=(int)($skus[(int)$sourceKey]['stock']??0);
        if($requestedQty>$available)scanner_response(['ok'=>false,'error'=>'整張調撥單合計 '.$requestedQty.' 件，超過來源庫存 '.$available.' 件；庫存尚未變更'],409);
    }

    $ledger=scanner_read($ledgerFile);
    $orders=scanner_read($ordersFile);
    $transferId='TRF-'.date('Ymd-His').'-'.strtoupper(bin2hex(random_bytes(2)));
    $operations=[];$totalQty=0;
    $cnTwBoundOrderIds=[];
    $cnTwTransferLines=[];
    $cnTwInTransit=false;
    foreach($prepared as $line){
        $sourceIndex=(int)$line['sourceIndex'];
        $sourceWarehouse=(string)$line['sourceWarehouse'];
        $targetWarehouse=(string)$line['targetWarehouse'];
        $lineQty=(int)$line['qty'];
        $sourceStock=(int)($skus[$sourceIndex]['stock']??0);
        $sourceProduct=$productMap[(string)($skus[$sourceIndex]['productId']??'')]??[];
        $targetIndex=find_target_sku($skus,$skus[$sourceIndex],$targetWarehouse);
        $sourceCostBefore=scanner_effective_cost($sourceProduct,$skus[$sourceIndex]);
        $targetCostBefore=$targetIndex===null?scanner_effective_cost([],[]):scanner_effective_cost($sourceProduct,$skus[$targetIndex]);
        $effectiveCost=scanner_newer_effective_cost($sourceCostBefore,$targetCostBefore);
        $resolvedAt=date(DATE_ATOM);
        $targetStockBefore=$targetIndex===null?0:(int)($skus[$targetIndex]['stock']??0);
        $isCnTw = scanner_warehouse_code($sourceWarehouse)==='CN' && scanner_warehouse_code($targetWarehouse)==='TW';
        $postedTw = false;
        $postedCn = false;
        if($isCnTw){
            $remain=(int)($skus[$sourceIndex]['stock']??0)-lz_cn_tw_in_transit_qty($skus[$sourceIndex]);
            if($lineQty>$remain){
                scanner_response([
                    'ok'=>false,
                    'error'=>'這批中國→台灣在途已佔 '.lz_cn_tw_in_transit_qty($skus[$sourceIndex]).' 件，帳面 '.(int)($skus[$sourceIndex]['stock']??0).' 件，不能再調 '.$lineQty.' 件。台灣倉尚未入帳。'.lz_cn_tw_holder_message($skus[$sourceIndex],$lineQty)
                ],409);
            }
            $cnTwInTransit=true;
            // First slice: keep China books, do not add Taiwan stock.
            lz_cn_tw_add_in_transit($skus[$sourceIndex],$lineQty);
            $skus[$sourceIndex]['updatedAt']=$resolvedAt;
            $skuQty=[(string)$line['skuId']=>$lineQty];
            $bound=lz_cn_tw_bind_orders_to_transfer($orders,$transferId,$skuQty,$resolvedAt);
            foreach($bound as $oid){ if($oid!=='') $cnTwBoundOrderIds[$oid]=true; }
            lz_cn_tw_attach_transfer_id_to_sku_holds($skus,$transferId,$bound);
            $cnTwTransferLines[]=[
                'skuId'=>(string)$line['skuId'],
                'qty'=>$lineQty,
                'boundOrderIds'=>$bound
            ];
        } else {
            $skus[$sourceIndex]['stock']=$sourceStock-$lineQty;
            $skus[$sourceIndex]['updatedAt']=$resolvedAt;
            if($targetIndex===null)$targetIndex=clone_to_warehouse($skus,$skus[$sourceIndex],$targetWarehouse,0,$sourceProduct,$effectiveCost);
            $skus[$targetIndex]['stock']=(int)($skus[$targetIndex]['stock']??0)+$lineQty;
            $skus[$targetIndex]['updatedAt']=$resolvedAt;
            $postedCn=true;
            $postedTw=true;
        }
        $syncCost=(bool)($effectiveCost['syncAcrossWarehouses']??true);
        if($syncCost){scanner_apply_effective_cost($skus[$sourceIndex],$effectiveCost,$resolvedAt); if($targetIndex!==null) scanner_apply_effective_cost($skus[$targetIndex],$effectiveCost,$resolvedAt);}
        $costValue=round((float)($effectiveCost['value']??0),2);
        $operation=[
            'id'=>'SCAN-'.date('Ymd-His').'-'.strtoupper(bin2hex(random_bytes(2))),
            'transferId'=>$transferId,
            'action'=>$isCnTw?'transfer_in_transit':'transfer',
            'skuId'=>(string)$line['skuId'],
            'barcode'=>(string)$line['barcode'],
            'warehouse'=>$sourceWarehouse,
            'sourceWarehouse'=>$sourceWarehouse,
            'targetWarehouse'=>$targetWarehouse,
            'qty'=>$lineQty,
            'operator'=>$operator,
            'createdAt'=>$resolvedAt,
            'productId'=>(string)($sourceProduct['id']??''),
            'productCode'=>(string)($sourceProduct['code']??''),
            'productTitle'=>(string)($sourceProduct['title']??''),
            'color'=>(string)($skus[$sourceIndex]['colorName']??$skus[$sourceIndex]['color']??''),
            'size'=>(string)($skus[$sourceIndex]['sizeName']??$skus[$sourceIndex]['size']??'NO SIZE'),
            'previousStock'=>$sourceStock,
            'remainingStock'=>$postedCn?($sourceStock-$lineQty):$sourceStock,
            'targetPreviousStock'=>$targetStockBefore,
            'targetRemainingStock'=>$postedTw?($targetStockBefore+$lineQty):$targetStockBefore,
            'destinationPosted'=>$postedTw,
            'sourcePosted'=>$postedCn,
            'inTransit'=>$isCnTw,
            'transferStatus'=>$isCnTw?'in_transit':'posted',
            'boundOrderIds'=>$isCnTw?array_values($cnTwBoundOrderIds):[],
            'note'=>$isCnTw?'中國→台灣調撥改為在途：中國帳面仍保留、台灣倉不加，等整批驗收才入台灣庫':'',
            'transferredUnitCostTwd'=>$costValue,
            'sourceCostBeforeTwd'=>round((float)($sourceCostBefore['value']??0),2),
            'sourceCostAfterTwd'=>$syncCost?$costValue:round((float)($sourceCostBefore['value']??0),2),
            'targetCostBeforeTwd'=>round((float)($targetCostBefore['value']??0),2),
            'targetCostAfterTwd'=>$syncCost?$costValue:round((float)($targetCostBefore['value']??0),2),
            'costSource'=>(string)($effectiveCost['source']??'cost_unavailable'),
            'costSourceUpdatedAt'=>(string)($effectiveCost['updatedAt']??''),
            'costResolvedAt'=>$resolvedAt,
            'costSyncSkipped'=>!$syncCost,
            'costResolution'=>(string)($effectiveCost['resolution']??'latest_effective_cost')
        ];
        $ledger[]=$operation;
        $operations[]=$operation;
        $totalQty+=$lineQty;
    }
    scanner_write($skusFile,$skus);
    scanner_write($ledgerFile,array_slice($ledger,-5000));
    if($cnTwInTransit){
        scanner_write($ordersFile,$orders);
        $state=scanner_read($stateFile);
        $state['orders']=$orders;
        $state['skus']=$skus;
        $state['updatedAt']=date(DATE_ATOM);
        scanner_write($stateFile,$state);
        $transfers=lz_cn_tw_read_json(lz_cn_tw_transfers_path($dataDir));
        array_unshift($transfers,[
            'id'=>$transferId,
            'transferId'=>$transferId,
            'status'=>'in_transit',
            'sourceWarehouse'=>'中國倉',
            'sourceWarehouseCode'=>'CN',
            'targetWarehouse'=>'台灣倉',
            'destinationWarehouse'=>'台灣倉',
            'destinationWarehouseCode'=>'TW',
            'destinationPosted'=>false,
            'sourcePosted'=>false,
            'companyReceiveAs'=>'live_ready',
            'lines'=>$cnTwTransferLines,
            'boundOrderIds'=>array_values(array_keys($cnTwBoundOrderIds)),
            'operator'=>$operator,
            'createdAt'=>date(DATE_ATOM),
            'note'=>'第一刀：在途不過台灣帳。整批驗收才入台灣現貨（不是樣品）。'
        ]);
        scanner_write(lz_cn_tw_transfers_path($dataDir), array_slice($transfers,0,2000));
    }
    $boundIds=array_values(array_keys($cnTwBoundOrderIds));
    scanner_response([
        'ok'=>true,
        'transferId'=>$transferId,
        'totalItems'=>count($operations),
        'totalQty'=>$totalQty,
        'operations'=>$operations,
        'inTransit'=>$cnTwInTransit,
        'taiwanStockPosted'=>!$cnTwInTransit,
        'boundOrderIds'=>$boundIds,
        'message'=>$cnTwInTransit
            ? ('中國→台灣調撥已改為在途：台灣倉尚未入帳。'.($boundIds?'已串流綁住 '.implode('、',$boundIds).'。': '無客戶單時，驗收後當台灣現貨（下一階段整批驗收）。'))
            : ''
    ]);
}

$index=null;foreach($skus as $i=>$sku)if((string)($sku['id']??$sku['sku']??'')===$skuId){$index=$i;break;}if($index===null)scanner_response(['ok'=>false,'error'=>'找不到 SKU'],404);
$ledger=scanner_read($ledgerFile);
$operation=['id'=>'SCAN-'.date('Ymd-His').'-'.strtoupper(bin2hex(random_bytes(2))),'action'=>$action,'skuId'=>$skuId,'barcode'=>(string)($payload['barcode']??''),'warehouse'=>$warehouse,'qty'=>$qty,'operator'=>$operator,'createdAt'=>date(DATE_ATOM)];

if($action==='stocktake_entry'){
    if($warehouse==='')scanner_response(['ok'=>false,'error'=>'請選擇盤點倉庫'],400);
    $target=find_target_sku($skus,$skus[$index],$warehouse);$previous=$target===null?0:(int)($skus[$target]['stock']??0);$sessionId=(string)($payload['sessionId']??'');if($sessionId==='')scanner_response(['ok'=>false,'error'=>'盤點單號遺失，請重新開啟盤點機'],400);
    $found=null;foreach($sessions as $i=>$s)if((string)($s['id']??'')===$sessionId){$found=$i;break;}if($found===null){$sessions[]=['id'=>$sessionId,'status'=>'draft','warehouse'=>$warehouse,'operator'=>$operator,'startedAt'=>(string)($payload['startedAt']??date(DATE_ATOM)),'lines'=>[],'inventoryRebuildId'=>$inventoryRebuildActive?(string)$inventoryRebuild['id']:'','excludedFromLossSurplus'=>$inventoryRebuildActive,'baselineMode'=>$inventoryRebuildActive];$found=count($sessions)-1;}
    if(($sessions[$found]['status']??'draft')!=='draft')scanner_response(['ok'=>false,'error'=>'這張盤點單已送出，請建立新盤點單'],409);
    $photo=scanner_save_image(is_string($payload['imageData']??null)?$payload['imageData']:null);
    $imageRole=in_array((string)($payload['imageRole']??''),['main','color'],true)?(string)$payload['imageRole']:'color';
    $shelf=trim((string)($payload['shelf']??$skus[$index]['shelf']??''));$layer=trim((string)($payload['layer']??$skus[$index]['layer']??''));
    $lineProduct=$productMap[(string)($skus[$index]['productId']??'')]??[];
    $catalogImage=scanner_image($lineProduct,$skus[$index]);
    $savedImage=$photo!==''?$photo:$catalogImage;
    $line=['sourceSkuId'=>$skuId,'productId'=>(string)($skus[$index]['productId']??''),'productCode'=>(string)($lineProduct['code']??$lineProduct['id']??''),'displayProductCode'=>(string)($lineProduct['code']??$lineProduct['id']??''),'displayProductName'=>(string)($lineProduct['title']??$lineProduct['name']??''),'barcode'=>$operation['barcode'],'color'=>(string)($skus[$index]['colorName']??$skus[$index]['color']??''),'size'=>(string)($skus[$index]['sizeName']??$skus[$index]['size']??'NO SIZE'),'warehouse'=>$warehouse,'shelf'=>$shelf,'layer'=>$layer,'previousStock'=>$previous,'countedQty'=>$qty,'variance'=>$qty-$previous,'image'=>$savedImage,'displayImage'=>$savedImage,'imageRole'=>$imageRole,'photoAdded'=>$photo!=='','scannedAt'=>date(DATE_ATOM),'operator'=>$operator];
    $lineIndex=null;foreach(($sessions[$found]['lines']??[]) as $i=>$old)if((string)($old['productId']??'')===$line['productId']&&(string)($old['color']??'')===$line['color']&&(string)($old['size']??'')===$line['size']&&(string)($old['warehouse']??'')===$warehouse&&(string)($old['shelf']??'')===$shelf&&(string)($old['layer']??'')===$layer){$lineIndex=$i;break;}if($lineIndex===null)$sessions[$found]['lines'][]=$line;else$sessions[$found]['lines'][$lineIndex]=$line;
    $sessions[$found]['lastLocation']=['warehouse'=>$warehouse,'shelf'=>$shelf,'layer'=>$layer,'updatedAt'=>date(DATE_ATOM)];
    $operation['sessionId']=$sessionId;$operation['previousStock']=$previous;$operation['variance']=$qty-$previous;$operation['image']=$photo;$operation['shelf']=$shelf;$operation['layer']=$layer;$ledger[]=$operation;scanner_write($stocktakesFile,$sessions);scanner_write($ledgerFile,array_slice($ledger,-5000));scanner_response(['ok'=>true,'operation'=>$operation,'session'=>$sessions[$found]]);
}
if($action==='quick_stockin'){
    if($warehouse==='')scanner_response(['ok'=>false,'error'=>'請選擇入庫倉庫'],400);
    if($qty<1)scanner_response(['ok'=>false,'error'=>'入庫數量至少 1 件'],400);
    $sourceProduct=$productMap[(string)($skus[$index]['productId']??'')]??[];
    if(!$sourceProduct)scanner_response(['ok'=>false,'error'=>'找不到產品主檔，禁止直接入庫'],404);
    $category=trim((string)($sourceProduct['category']??''));
    if($category===''||$category==='待判別產品'||$category==='快速入庫待補')scanner_response(['ok'=>false,'error'=>'產品分類尚未完成，請先由管理者核對分類'],409);
    $targetIndex=find_target_sku($skus,$skus[$index],$warehouse);
    if($targetIndex===null)$targetIndex=clone_to_warehouse($skus,$skus[$index],$warehouse,0,$sourceProduct);
    $previous=(int)($skus[$targetIndex]['stock']??0);
    $now=date(DATE_ATOM);
    if(!empty($payload['dryRun'])){
        scanner_response(['ok'=>true,'dryRun'=>true,'operation'=>[
            'action'=>'quick_stockin',
            'skuId'=>$skuId,
            'productId'=>(string)($sourceProduct['id']??''),
            'productCode'=>(string)($sourceProduct['code']??''),
            'productTitle'=>(string)($sourceProduct['title']??''),
            'category'=>$category,
            'warehouse'=>$warehouse,
            'qty'=>$qty,
            'previousStock'=>$previous,
            'remainingStock'=>$previous+$qty
        ]]);
    }
    $skus[$targetIndex]['stock']=$previous+$qty;
    $skus[$targetIndex]['updatedAt']=$now;
    $shelf=trim((string)($payload['shelf']??''));
    $layer=trim((string)($payload['layer']??''));
    if($shelf!=='')$skus[$targetIndex]['shelf']=$shelf;
    if($layer!=='')$skus[$targetIndex]['layer']=$layer;
    $operation['productId']=(string)($sourceProduct['id']??'');
    $operation['productCode']=(string)($sourceProduct['code']??'');
    $operation['productTitle']=(string)($sourceProduct['title']??'');
    $operation['category']=$category;
    $operation['color']=(string)($skus[$targetIndex]['colorName']??$skus[$targetIndex]['color']??'');
    $operation['size']=(string)($skus[$targetIndex]['sizeName']??$skus[$targetIndex]['size']??'NO SIZE');
    $operation['previousStock']=$previous;
    $operation['remainingStock']=$previous+$qty;
    $operation['shelf']=$shelf;
    $operation['layer']=$layer;
    $ledger[]=$operation;
    scanner_write($skusFile,$skus);
    scanner_write($ledgerFile,array_slice($ledger,-5000));
    scanner_response(['ok'=>true,'operation'=>$operation]);
}
if($action==='transfer'){
    $targetWarehouse=trim((string)($payload['targetWarehouse']??''));if($warehouse===''||$targetWarehouse==='')scanner_response(['ok'=>false,'error'=>'請選擇來源倉庫與目的倉庫'],400);if($warehouse===$targetWarehouse)scanner_response(['ok'=>false,'error'=>'來源倉庫與目的倉庫不能相同'],400);
    $sourceIndex=find_target_sku($skus,$skus[$index],$warehouse);if($sourceIndex===null)scanner_response(['ok'=>false,'error'=>'來源倉庫沒有這個顏色尺寸的庫存'],404);$sourceStock=(int)($skus[$sourceIndex]['stock']??0);if($qty<1||$qty>$sourceStock)scanner_response(['ok'=>false,'error'=>'調撥數量超過來源庫存'],400);
    $sourceProduct=$productMap[(string)($skus[$sourceIndex]['productId']??'')]??[];$targetIndex=find_target_sku($skus,$skus[$sourceIndex],$targetWarehouse);$sourceCostBefore=scanner_effective_cost($sourceProduct,$skus[$sourceIndex]);$targetCostBefore=$targetIndex===null?scanner_effective_cost([],[]):scanner_effective_cost($sourceProduct,$skus[$targetIndex]);$effectiveCost=scanner_newer_effective_cost($sourceCostBefore,$targetCostBefore);$resolvedAt=date(DATE_ATOM);$targetStockBefore=$targetIndex===null?0:(int)($skus[$targetIndex]['stock']??0);
    $skus[$sourceIndex]['stock']=$sourceStock-$qty;$skus[$sourceIndex]['updatedAt']=$resolvedAt;if($targetIndex===null)$targetIndex=clone_to_warehouse($skus,$skus[$sourceIndex],$targetWarehouse,0,$sourceProduct,$effectiveCost);$skus[$targetIndex]['stock']=(int)($skus[$targetIndex]['stock']??0)+$qty;$skus[$targetIndex]['updatedAt']=$resolvedAt;
    $syncCost=(bool)($effectiveCost['syncAcrossWarehouses']??true);
    if($syncCost){scanner_apply_effective_cost($skus[$sourceIndex],$effectiveCost,$resolvedAt);scanner_apply_effective_cost($skus[$targetIndex],$effectiveCost,$resolvedAt);}
    $costValue=round((float)($effectiveCost['value']??0),2);$sourceAfter=$syncCost?$costValue:round((float)($sourceCostBefore['value']??0),2);$targetAfter=$syncCost?$costValue:round((float)($targetCostBefore['value']??0),2);$operation['sourceWarehouse']=$warehouse;$operation['targetWarehouse']=$targetWarehouse;$operation['previousStock']=$sourceStock;$operation['remainingStock']=$sourceStock-$qty;$operation['targetPreviousStock']=$targetStockBefore;$operation['targetRemainingStock']=$targetStockBefore+$qty;$operation['transferredUnitCostTwd']=$costValue;$operation['sourceCostBeforeTwd']=round((float)($sourceCostBefore['value']??0),2);$operation['sourceCostAfterTwd']=$sourceAfter;$operation['targetCostBeforeTwd']=round((float)($targetCostBefore['value']??0),2);$operation['targetCostAfterTwd']=$targetAfter;$operation['costSource']=(string)($effectiveCost['source']??'cost_unavailable');$operation['costSourceUpdatedAt']=(string)($effectiveCost['updatedAt']??'');$operation['costResolvedAt']=$resolvedAt;$operation['costSyncSkipped']=!$syncCost;$operation['costResolution']=(string)($effectiveCost['resolution']??'latest_effective_cost');$ledger[]=$operation;scanner_write($skusFile,$skus);scanner_write($ledgerFile,array_slice($ledger,-5000));scanner_response(['ok'=>true,'operation'=>$operation]);
}
scanner_response(['ok'=>false,'error'=>'不支援的操作'],400);

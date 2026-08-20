<?php
/**
 * Public cadastre helpers for the 火旺 admin form.
 * Deploy to: F:\Web\huowang-staging\api\cadastre.php
 *
 * Official sources we are allowed to use without MOI credentials:
 *   - NLSC town list:         https://api.nlsc.gov.tw/other/ListTown/{city}
 *   - NLSC land-section list: https://api.nlsc.gov.tw/other/ListLandSection/{city}/{town}
 *
 * We cannot auto-fill 地號 / 建號 / 所有權人 / 謄本. Those APIs require a
 * Ministry of the Interior application and (for 電子謄本) a land certificate.
 */
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");
header("Access-Control-Allow-Origin: *");

$action = isset($_GET["action"]) ? strtolower(trim((string)$_GET["action"])) : "";
$county = isset($_GET["county"]) ? trim((string)$_GET["county"]) : "";
$area   = isset($_GET["area"]) ? trim((string)$_GET["area"]) : "";
if ($area === "" && isset($_GET["town"])) $area = trim((string)$_GET["town"]);

$cityCodes = array(
  "基隆市"=>"C","台北市"=>"A","臺北市"=>"A","新北市"=>"F","桃園市"=>"H",
  "新竹市"=>"O","新竹縣"=>"J","苗栗縣"=>"K","台中市"=>"B","臺中市"=>"B",
  "彰化縣"=>"N","南投縣"=>"M","雲林縣"=>"P","嘉義市"=>"I","嘉義縣"=>"Q",
  "台南市"=>"D","臺南市"=>"D","高雄市"=>"E","屏東縣"=>"T","宜蘭縣"=>"G",
  "花蓮縣"=>"U","台東縣"=>"V","臺東縣"=>"V","澎湖縣"=>"X","金門縣"=>"W","連江縣"=>"Z"
);

function cadastreJson($payload, $code = 200) {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

function cadastreHttpGet($url) {
  $raw = "";
  if (function_exists("curl_init")) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_CONNECTTIMEOUT => 8,
      CURLOPT_TIMEOUT => 12,
      CURLOPT_HTTPHEADER => array("Accept: application/xml,application/json,*/*", "User-Agent: HuowangCadastre/1.0")
    ));
    $raw = curl_exec($ch);
    curl_close($ch);
    if ($raw === false) $raw = "";
  }
  if ($raw === "") {
    $ctx = stream_context_create(array(
      "http" => array(
        "method" => "GET",
        "timeout" => 12,
        "header" => "Accept: application/xml,application/json,*/*\r\nUser-Agent: HuowangCadastre/1.0\r\n",
        "ignore_errors" => true
      ),
      "ssl" => array("verify_peer" => true, "verify_peer_name" => true)
    ));
    $got = @file_get_contents($url, false, $ctx);
    $raw = $got === false ? "" : $got;
  }
  return $raw;
}

function cadastreXmlAttr($node, $names) {
  foreach ($names as $name) {
    if (isset($node->{$name})) {
      $v = trim((string)$node->{$name});
      if ($v !== "") return $v;
    }
  }
  return "";
}

function cadastreFindTownCode($cityCode, $townName) {
  $raw = cadastreHttpGet("https://api.nlsc.gov.tw/other/ListTown/" . rawurlencode($cityCode));
  if ($raw === "") return "";
  if (preg_match('/^[A-Z][0-9]{2}$/', $townName)) return $townName;
  $prev = libxml_use_internal_errors(true);
  $xml = simplexml_load_string($raw);
  libxml_clear_errors();
  libxml_use_internal_errors($prev);
  if ($xml) {
    foreach ($xml->xpath("//*[local-name()='townItem' or local-name()='item']") as $node) {
      $name = cadastreXmlAttr($node, array("townname", "townName", "name", "TOWNNAME"));
      $code = cadastreXmlAttr($node, array("towncode", "townCode", "code", "TOWNCODE"));
      if ($name === $townName && $code !== "") return $code;
    }
  }
  if (preg_match("/" . preg_quote($townName, "/") . "[^A-Z0-9]*([A-Z][0-9]{2})/u", $raw, $m)) {
    return $m[1];
  }
  return "";
}

function cadastreParseSections($raw, $county, $town) {
  $out = array();
  if ($raw === "") return $out;
  $json = json_decode($raw, true);
  if (is_array($json)) {
    $rows = $json;
    if (isset($json["landSections"]) && is_array($json["landSections"])) $rows = $json["landSections"];
    elseif (isset($json["sectItems"]) && is_array($json["sectItems"])) $rows = $json["sectItems"];
    elseif (isset($json["data"]) && is_array($json["data"])) $rows = $json["data"];
    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      $name = "";
      foreach (array("sectstr", "sectName", "section", "name", "地段名稱") as $k) {
        if (!empty($row[$k])) { $name = trim((string)$row[$k]); break; }
      }
      $code = "";
      foreach (array("sectcode", "sectCode", "sectionCode", "code", "地段代碼") as $k) {
        if (!empty($row[$k])) { $code = trim((string)$row[$k]); break; }
      }
      $sub = isset($row["subsection"]) ? trim((string)$row["subsection"]) : "";
      if ($sub !== "") $name .= $sub;
      if ($name === "" && $code === "") continue;
      $label = $name !== "" ? $name : $code;
      $out[] = array(
        "county" => $county,
        "town" => $town,
        "section" => $label,
        "name" => $label,
        "sectionCode" => $code,
        "code" => $code,
        "landNo" => "",
        "buildingNo" => "",
        "roadName" => ""
      );
    }
    return $out;
  }
  if (stripos($raw, "<") !== false) {
    $prev = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($raw);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if ($xml) {
      $nodes = $xml->xpath("//*[local-name()='sectItem' or local-name()='landSection' or local-name()='item']");
      if (!$nodes) $nodes = array();
      foreach ($nodes as $node) {
        $name = cadastreXmlAttr($node, array("sectstr", "sectName", "section", "name", "SECTSTR"));
        $code = cadastreXmlAttr($node, array("sectcode", "sectCode", "sectionCode", "code", "SECTCODE"));
        $sub  = cadastreXmlAttr($node, array("subsection", "subsectstr", "小段名稱"));
        if ($sub !== "") $name .= $sub;
        if ($name === "" && $code === "") continue;
        $label = $name !== "" ? $name : $code;
        $out[] = array(
          "county" => $county,
          "town" => $town,
          "section" => $label,
          "name" => $label,
          "sectionCode" => $code,
          "code" => $code,
          "landNo" => "",
          "buildingNo" => "",
          "roadName" => ""
        );
      }
    }
  }
  return $out;
}

if ($action === "" || $action === "help") {
  cadastreJson(array(
    "ok" => true,
    "actions" => array("sections", "roads"),
    "note" => "地段可從國土測繪公開清單帶入。地號、建號、謄本需地政憑證，本機無法代查。",
    "sites" => array(
      "easymap" => "https://easymap.land.moi.gov.tw/Z10Web/Normal",
      "nlsc" => "https://maps.nlsc.gov.tw/",
      "yilan" => "https://land.e-land.gov.tw/",
      "transcript" => "https://ep.land.nat.gov.tw/"
    )
  ));
}

if ($county === "" || $area === "") {
  cadastreJson(array("ok" => false, "error" => "missing county/area", "message" => "請先選縣市與區域"), 400);
}

$cityCode = isset($cityCodes[$county]) ? $cityCodes[$county] : "";
if ($cityCode === "") {
  cadastreJson(array("ok" => false, "error" => "unsupported county", "message" => "找不到此縣市的地政代碼", "county" => $county), 400);
}

$townCode = cadastreFindTownCode($cityCode, $area);
if ($townCode === "") {
  cadastreJson(array("ok" => false, "error" => "unknown town", "message" => "找不到此鄉鎮市區的地政代碼", "county" => $county, "area" => $area), 400);
}

if ($action === "sections") {
  $raw = cadastreHttpGet("https://api.nlsc.gov.tw/other/ListLandSection/" . rawurlencode($cityCode) . "/" . rawurlencode($townCode));
  $sections = cadastreParseSections($raw, $county, $area);
  cadastreJson(array(
    "ok" => true,
    "source" => "nlsc",
    "county" => $county,
    "area" => $area,
    "town" => $area,
    "cityCode" => $cityCode,
    "townCode" => $townCode,
    "sections" => $sections
  ));
}

if ($action === "roads") {
  cadastreJson(array(
    "ok" => true,
    "source" => "nlsc",
    "county" => $county,
    "area" => $area,
    "town" => $area,
    "cityCode" => $cityCode,
    "townCode" => $townCode,
    "roads" => array(),
    "note" => "國土測繪未開放免申請的路名清單 API。路名請從門牌或地籍圖資核對。"
  ));
}

cadastreJson(array("ok" => false, "error" => "unknown action", "message" => "未知動作", "action" => $action), 400);

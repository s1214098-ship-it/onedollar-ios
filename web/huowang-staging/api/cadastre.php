<?php
/**
 * Public cadastre helpers for the 火旺 admin form.
 * Deploy to: F:\Web\huowang-staging\api\cadastre.php
 *
 * Official NLSC sources used without MOI credentials:
 *   - Town / land-section lists
 *   - MapSearch doorplate (門牌定位)
 *   - landmaps qryTileMapIndex (門牌座標 → 地段地號)
 *   - S09_Ralid getLandInfoSect (面積、分區、建號清單)
 *
 * 所有權人、他項、謄本仍需地政憑證，本支不代查。
 */
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");
header("Access-Control-Allow-Origin: *");

$action = isset($_GET["action"]) ? strtolower(trim((string)$_GET["action"])) : "";
$county = isset($_GET["county"]) ? trim((string)$_GET["county"]) : "";
$area   = isset($_GET["area"]) ? trim((string)$_GET["area"]) : "";
if ($area === "" && isset($_GET["town"])) $area = trim((string)$_GET["town"]);
$road = isset($_GET["road"]) ? trim((string)$_GET["road"]) : "";
if ($road === "" && isset($_GET["roadName"])) $road = trim((string)$_GET["roadName"]);
$door = isset($_GET["door"]) ? trim((string)$_GET["door"]) : "";
if ($door === "" && isset($_GET["doorNo"])) $door = trim((string)$_GET["doorNo"]);
$q = isset($_GET["q"]) ? trim((string)$_GET["q"]) : "";
$lat = isset($_GET["lat"]) ? trim((string)$_GET["lat"]) : "";
$lng = isset($_GET["lng"]) ? trim((string)$_GET["lng"]) : "";
$section = isset($_GET["section"]) ? trim((string)$_GET["section"]) : "";
if ($section === "" && isset($_GET["sectionName"])) $section = trim((string)$_GET["sectionName"]);
$landNo = isset($_GET["landNo"]) ? trim((string)$_GET["landNo"]) : "";
if ($landNo === "" && isset($_GET["landno"])) $landNo = trim((string)$_GET["landno"]);

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

function cadastreHeaders($extra = array()) {
  $base = array(
    "Accept: application/xml,application/json,*/*",
    "User-Agent: HuowangCadastre/1.1",
    "Referer: https://maps.nlsc.gov.tw/T09/mapshow.action",
    "Origin: https://maps.nlsc.gov.tw"
  );
  return array_merge($base, $extra);
}

function cadastreHttpGet($url) {
  $raw = "";
  if (function_exists("curl_init")) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_CONNECTTIMEOUT => 8,
      CURLOPT_TIMEOUT => 15,
      CURLOPT_HTTPHEADER => cadastreHeaders()
    ));
    $raw = curl_exec($ch);
    curl_close($ch);
    if ($raw === false) $raw = "";
  }
  if ($raw === "") {
    $ctx = stream_context_create(array(
      "http" => array(
        "method" => "GET",
        "timeout" => 15,
        "header" => implode("\r\n", cadastreHeaders()) . "\r\n",
        "ignore_errors" => true
      ),
      "ssl" => array("verify_peer" => true, "verify_peer_name" => true)
    ));
    $got = @file_get_contents($url, false, $ctx);
    $raw = $got === false ? "" : $got;
  }
  return $raw;
}

function cadastreHttpPost($url, $fields) {
  $body = is_array($fields) ? http_build_query($fields) : (string)$fields;
  $raw = "";
  if (function_exists("curl_init")) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_CONNECTTIMEOUT => 8,
      CURLOPT_TIMEOUT => 20,
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => $body,
      CURLOPT_HTTPHEADER => cadastreHeaders(array("Content-Type: application/x-www-form-urlencoded"))
    ));
    $raw = curl_exec($ch);
    curl_close($ch);
    if ($raw === false) $raw = "";
  }
  if ($raw === "") {
    $ctx = stream_context_create(array(
      "http" => array(
        "method" => "POST",
        "timeout" => 20,
        "header" => implode("\r\n", cadastreHeaders(array("Content-Type: application/x-www-form-urlencoded"))) . "\r\n",
        "content" => $body,
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

function cadastreB64($raw) {
  $raw = trim((string)$raw);
  if ($raw === "") return "";
  $dec = base64_decode($raw, true);
  if ($dec === false || $dec === "") return $raw;
  if (preg_match("/\\p{Han}/u", $dec)) return $dec;
  return $raw;
}

function cadastreDigits($raw, $len = 8) {
  $d = preg_replace("/\\D+/", "", (string)$raw);
  if ($d === "") return "";
  if ($len <= 0) return $d;
  if (strlen($d) < $len) $d = str_pad($d, $len, "0", STR_PAD_LEFT);
  if (strlen($d) > $len) $d = substr($d, -$len);
  return $d;
}

function cadastreFormatLandNo($raw) {
  $d = cadastreDigits($raw, 8);
  if ($d === "") return trim((string)$raw);
  return substr($d, 0, 4) . "-" . substr($d, 4, 4);
}

function cadastreFormatBuildingNo($raw) {
  $d = cadastreDigits($raw, 8);
  if ($d === "") return trim((string)$raw);
  return substr($d, 0, 5) . "-" . substr($d, 5, 3);
}

function cadastrePing($sqm) {
  $n = floatval($sqm);
  if ($n <= 0) return "";
  return (string)round($n / 3.3058, 2);
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

function cadastreParseSearchItems($xmlRaw) {
  $out = array();
  if ($xmlRaw === "" || stripos($xmlRaw, "<") === false) return $out;
  if (function_exists("simplexml_load_string")) {
    $prev = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlRaw);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if ($xml) {
      $nodes = $xml->xpath("//*[local-name()='ITEM' or local-name()='item']");
      if ($nodes) {
        foreach ($nodes as $node) {
          $content = cadastreXmlAttr($node, array("CONTENT", "content"));
          $key = cadastreXmlAttr($node, array("KEY", "key"));
          $loc = cadastreXmlAttr($node, array("LOCATION", "location"));
          $remark = cadastreXmlAttr($node, array("REMARK", "remark"));
          $parts = array_map("trim", explode(",", $key));
          $kind = isset($parts[1]) ? strtoupper($parts[1]) : "";
          $lng = "";
          $lat = "";
          if (preg_match("/(-?\\d+\\.\\d+)\\s*,\\s*(-?\\d+\\.\\d+)/", $loc, $m)) {
            $lng = $m[1];
            $lat = $m[2];
          }
          $out[] = array(
            "content" => $content,
            "key" => $key,
            "kind" => $kind,
            "lng" => $lng,
            "lat" => $lat,
            "remark" => $remark
          );
        }
      }
      return $out;
    }
  }
  if (preg_match_all("/<ITEM>(.*?)<\\/ITEM>/is", $xmlRaw, $blocks)) {
    foreach ($blocks[1] as $block) {
      $pick = function ($name) use ($block) {
        return preg_match("/<" . $name . ">(.*?)<\\/" . $name . ">/is", $block, $m) ? trim(html_entity_decode($m[1], ENT_QUOTES, "UTF-8")) : "";
      };
      $content = $pick("CONTENT");
      $key = $pick("KEY");
      $loc = $pick("LOCATION");
      $parts = array_map("trim", explode(",", $key));
      $kind = isset($parts[1]) ? strtoupper($parts[1]) : "";
      $lng = "";
      $lat = "";
      if (preg_match("/(-?\\d+\\.\\d+)\\s*,\\s*(-?\\d+\\.\\d+)/", $loc, $m)) {
        $lng = $m[1];
        $lat = $m[2];
      }
      $out[] = array(
        "content" => $content,
        "key" => $key,
        "kind" => $kind,
        "lng" => $lng,
        "lat" => $lat,
        "remark" => $pick("REMARK")
      );
    }
  }
  return $out;
}

function cadastreQuerySearch($word, $extra = array()) {
  $fields = array_merge(array(
    "word" => rawurlencode($word),
    "feedback" => "XML"
  ), $extra);
  return cadastreParseSearchItems(cadastreHttpPost("https://api.nlsc.gov.tw/MapSearch/QuerySearch", $fields));
}

function cadastrePickDoor($items, $county, $area, $road, $door) {
  $wantDoor = cadastreDigits($door, 0);
  $best = null;
  foreach ($items as $item) {
    if ($item["kind"] !== "ADDRESS" && $item["kind"] !== "HOUSEHOLD") continue;
    $text = $item["content"];
    $score = 0;
    if ($county !== "" && strpos($text, $county) !== false) $score += 4;
    if ($area !== "" && strpos($text, $area) !== false) $score += 4;
    if ($road !== "" && strpos($text, $road) !== false) $score += 5;
    if ($wantDoor !== "" && preg_match("/" . preg_quote($wantDoor, "/") . "號/u", $text)) $score += 6;
    if ($item["lat"] !== "" && $item["lng"] !== "") $score += 1;
    if ($best === null || $score > $best["_score"]) {
      $item["_score"] = $score;
      $best = $item;
    }
  }
  return $best;
}

function cadastreLandpos($cityCode, $lng, $lat) {
  if ($cityCode === "" || $lng === "" || $lat === "") return array();
  $url = "https://landmaps.nlsc.gov.tw/S_Maps/qryTileMapIndex?" . http_build_query(array(
    "type" => "2",
    "flag" => "1",
    "city" => $cityCode,
    "x" => $lng,
    "y" => $lat,
    "alpah" => "0.5f"
  ));
  $raw = cadastreHttpGet($url);
  $json = json_decode($raw, true);
  if (!is_array($json)) return array();
  $row = $json;
  if (isset($json[0]) && is_array($json[0])) $row = $json[0];
  if (isset($row[0]) && is_array($row[0])) $row = $row[0];
  if (!is_array($row)) return array();
  $sect = isset($row["sect"]) ? trim((string)$row["sect"]) : "";
  $land = isset($row["landno"]) ? trim((string)$row["landno"]) : "";
  $office = isset($row["office"]) ? trim((string)$row["office"]) : "";
  $sectionName = cadastreB64(isset($row["sectStr"]) ? $row["sectStr"] : "");
  $officeName = cadastreB64(isset($row["officeStr"]) ? $row["officeStr"] : "");
  return array(
    "office" => $office,
    "officeName" => $officeName,
    "sectionCode" => $sect,
    "section" => $sectionName,
    "landNoRaw" => $land,
    "landNo" => cadastreFormatLandNo($land),
    "lng" => isset($row["cx"]) ? (string)$row["cx"] : $lng,
    "lat" => isset($row["cy"]) ? (string)$row["cy"] : $lat
  );
}

function cadastreLandInfo($cityCode, $sect, $landNo) {
  $sectCode = (string)$sect;
  if (preg_match("/(\\d{4})/", $sectCode, $m)) $sectCode = $m[1];
  $landDigits = cadastreDigits($landNo, 8);
  if ($cityCode === "" || $sectCode === "" || $landDigits === "") return array();
  $raw = cadastreHttpPost("https://api.nlsc.gov.tw/S09_Ralid/getLandInfoSect", array(
    "city" => $cityCode,
    "sect" => $sectCode,
    "landno" => $landDigits
  ));
  $json = json_decode($raw, true);
  if (!is_array($json)) return array();
  $ralid = isset($json["ralid"]) && is_array($json["ralid"]) ? $json["ralid"] : array();
  $zone = cadastreB64(isset($ralid["AA11"]) ? $ralid["AA11"] : "");
  $use = cadastreB64(isset($ralid["AA12"]) ? $ralid["AA12"] : "");
  $sqm = isset($ralid["AA10"]) ? $ralid["AA10"] : "";
  $buildings = array();
  if (isset($json["buildList"]) && is_array($json["buildList"])) {
    foreach ($json["buildList"] as $item) {
      $parts = explode(",", (string)$item);
      $bno = isset($parts[1]) ? $parts[1] : $item;
      $buildings[] = cadastreFormatBuildingNo($bno);
    }
  }
  return array(
    "sectionCode" => $sectCode,
    "landNo" => cadastreFormatLandNo($landDigits),
    "areaSqm" => $sqm === "" ? "" : (string)$sqm,
    "areaPing" => cadastrePing($sqm),
    "zone" => $zone,
    "landUse" => $use,
    "landUseZone" => trim($zone . ($use !== "" ? "／" . $use : "")),
    "buildingNos" => $buildings,
    "buildingNo" => isset($buildings[0]) ? $buildings[0] : ""
  );
}

function cadastreNearbyLandmarks($lng, $lat, $area) {
  $out = array();
  if ($lng === "" || $lat === "") return $out;
  $center = $lng . "," . $lat;
  $queries = array();
  if ($area !== "") {
    $queries[] = $area . "國小";
    $queries[] = $area . "宮";
  }
  $queries[] = "國小";
  $seen = array();
  foreach ($queries as $term) {
    $items = cadastreQuerySearch($term, array("center" => $center));
    foreach ($items as $item) {
      if ($item["kind"] !== "LANDGOAL" && $item["kind"] !== "LANDMARK") continue;
      if ($item["lat"] === "" || $item["lng"] === "") continue;
      $dlat = floatval($item["lat"]) - floatval($lat);
      $dlng = floatval($item["lng"]) - floatval($lng);
      $meters = sqrt(($dlat * 111000) * ($dlat * 111000) + ($dlng * 101000) * ($dlng * 101000));
      if ($meters > 2500) continue;
      $name = $item["content"];
      if (strpos($name, "，") !== false) {
        $bits = explode("，", $name);
        $name = trim(end($bits));
      }
      if (preg_match("/公墓|垃圾|殯葬|焚化|監獄|掩埋/", $name)) continue;
      if (isset($seen[$name])) continue;
      $seen[$name] = true;
      $out[] = array(
        "name" => $name,
        "lat" => $item["lat"],
        "lng" => $item["lng"],
        "meters" => (int)round($meters)
      );
    }
    if (count($out) >= 6) break;
  }
  usort($out, function ($a, $b) {
    $rank = function ($row) {
      $n = $row["name"];
      $bonus = 0;
      if (preg_match("/國小|國中|幼兒園/", $n)) $bonus -= 80;
      if (preg_match("/宮|廟|寺/", $n)) $bonus -= 40;
      if (preg_match("/橋|公園|市場/", $n)) $bonus -= 20;
      return $row["meters"] + $bonus;
    };
    return $rank($a) - $rank($b);
  });
  return array_slice($out, 0, 6);
}

function cadastreDmNearby($landmarks, $area) {
  foreach ($landmarks as $row) {
    $name = $row["name"];
    if ($name === "") continue;
    $label = $name;
    $place = $area;
    if ($place === "" && preg_match("/^(.+?)(國小|國中|宮|廟|橋)/u", $name, $m)) $place = $m[1];
    if ($place !== "" && strpos($label, $place) === false && !preg_match("/頭城|宜蘭/", $label)) {
      $label = $place . $label;
    }
    if (!preg_match("/附近$/", $label)) $label .= "附近";
    return $label;
  }
  return $area !== "" ? $area . "附近" : "";
}

function cadastreNormalizeDoor($door) {
  $door = trim((string)$door);
  if ($door === "") return "";
  if (!preg_match("/號/u", $door) && preg_match("/\\d/", $door)) $door .= "號";
  return $door;
}

function cadastreLookupDoor($county, $area, $road, $door, $q, $cityCodes) {
  $door = cadastreNormalizeDoor($door);
  $query = $q;
  if ($query === "") $query = $county . $area . $road . $door;
  $query = preg_replace("/\\s+/u", "", $query);
  if ($query === "") {
    cadastreJson(array("ok" => false, "error" => "missing address", "message" => "請先填縣市、區域、路名與門牌"), 400);
  }
  $cityCode = "";
  if ($county !== "" && isset($cityCodes[$county])) $cityCode = $cityCodes[$county];
  if ($cityCode === "" && $county !== "") $cityCode = isset($cityCodes[str_replace("台", "臺", $county)]) ? $cityCodes[str_replace("台", "臺", $county)] : "";
  $items = array();
  if ($cityCode !== "") {
    $items = cadastreQuerySearch($query, array(
      "City" => $cityCode,
      "IndexDB" => "ADDRESS",
      "mode" => "exact"
    ));
  }
  if (!$items) $items = cadastreQuerySearch($query, array("center" => "121.75,24.70"));
  $doorHit = cadastrePickDoor($items, $county, $area, $road, $door);
  if (!$doorHit) {
    cadastreJson(array(
      "ok" => false,
      "error" => "not found",
      "message" => "國土測繪找不到這個門牌，請核對路名與門牌號碼",
      "query" => $query
    ), 404);
  }
  $lng = $doorHit["lng"];
  $lat = $doorHit["lat"];
  if ($cityCode === "" && $doorHit["key"] !== "") {
    $parts = explode(",", $doorHit["key"]);
    $cityCode = isset($parts[0]) ? $parts[0] : "";
  }
  $parcel = cadastreLandpos($cityCode, $lng, $lat);
  $info = array();
  if (!empty($parcel["sectionCode"]) && !empty($parcel["landNoRaw"])) {
    $info = cadastreLandInfo($cityCode, $parcel["sectionCode"], $parcel["landNoRaw"]);
  }
  $village = "";
  if (preg_match_all("/([一-龥]{2}里)/u", $doorHit["content"], $ms) && !empty($ms[1])) {
    $village = end($ms[1]);
  } elseif (preg_match_all("/([一-龥]{3}里)/u", $doorHit["content"], $ms) && !empty($ms[1])) {
    $village = end($ms[1]);
  }
  $place = $village !== "" ? preg_replace("/里$/u", "", $village) : $area;
  $landmarks = cadastreNearbyLandmarks($lng, $lat, $place);
  if (!$landmarks && $area !== "" && $place !== $area) {
    $landmarks = cadastreNearbyLandmarks($lng, $lat, $area);
  }
  $sectionName = isset($parcel["section"]) ? $parcel["section"] : "";
  $landNoFmt = isset($parcel["landNo"]) ? $parcel["landNo"] : "";
  $buildingNo = isset($info["buildingNo"]) ? $info["buildingNo"] : "";
  $dm = cadastreDmNearby($landmarks, $place);
  cadastreJson(array(
    "ok" => true,
    "source" => "nlsc",
    "query" => $query,
    "county" => $county,
    "area" => $area,
    "town" => $area,
    "village" => $village,
    "roadName" => $road,
    "doorNo" => $door,
    "doorplate" => $doorHit["content"],
    "lat" => $lat,
    "lng" => $lng,
    "coordText" => ($lat !== "" && $lng !== "") ? ($lat . "," . $lng) : "",
    "office" => isset($parcel["officeName"]) ? $parcel["officeName"] : "",
    "section" => $sectionName,
    "sectionName" => $sectionName,
    "sectionCode" => isset($parcel["sectionCode"]) ? $parcel["sectionCode"] : "",
    "landNo" => $landNoFmt,
    "buildingNo" => $buildingNo,
    "buildingNos" => isset($info["buildingNos"]) ? $info["buildingNos"] : array(),
    "areaSqm" => isset($info["areaSqm"]) ? $info["areaSqm"] : "",
    "areaPing" => isset($info["areaPing"]) ? $info["areaPing"] : "",
    "landUseZone" => isset($info["landUseZone"]) ? $info["landUseZone"] : "",
    "zone" => isset($info["zone"]) ? $info["zone"] : "",
    "landUse" => isset($info["landUse"]) ? $info["landUse"] : "",
    "landmarks" => $landmarks,
    "dmNearby" => $dm,
    "note" => "門牌、地段、地號來自國土測繪公開圖資。所有權人與謄本仍需電子謄本。"
  ));
}

function cadastreLookupCoord($county, $lat, $lng, $cityCodes) {
  if ($lat === "" || $lng === "") {
    cadastreJson(array("ok" => false, "error" => "missing coord", "message" => "請先填緯度與經度"), 400);
  }
  $cityCode = ($county !== "" && isset($cityCodes[$county])) ? $cityCodes[$county] : "";
  if ($cityCode === "" && $county !== "") $cityCode = isset($cityCodes[str_replace("台", "臺", $county)]) ? $cityCodes[str_replace("台", "臺", $county)] : "";
  if ($cityCode === "") $cityCode = "G";
  $parcel = cadastreLandpos($cityCode, $lng, $lat);
  if (empty($parcel["landNo"])) {
    cadastreJson(array("ok" => false, "error" => "not found", "message" => "這個座標對不到地籍圖", "lat" => $lat, "lng" => $lng), 404);
  }
  $info = cadastreLandInfo($cityCode, $parcel["sectionCode"], $parcel["landNoRaw"]);
  $landmarks = cadastreNearbyLandmarks($lng, $lat, "");
  cadastreJson(array(
    "ok" => true,
    "source" => "nlsc",
    "county" => $county,
    "lat" => $lat,
    "lng" => $lng,
    "coordText" => $lat . "," . $lng,
    "office" => $parcel["officeName"],
    "section" => $parcel["section"],
    "sectionName" => $parcel["section"],
    "sectionCode" => $parcel["sectionCode"],
    "landNo" => $parcel["landNo"],
    "buildingNo" => isset($info["buildingNo"]) ? $info["buildingNo"] : "",
    "buildingNos" => isset($info["buildingNos"]) ? $info["buildingNos"] : array(),
    "areaSqm" => isset($info["areaSqm"]) ? $info["areaSqm"] : "",
    "areaPing" => isset($info["areaPing"]) ? $info["areaPing"] : "",
    "landUseZone" => isset($info["landUseZone"]) ? $info["landUseZone"] : "",
    "zone" => isset($info["zone"]) ? $info["zone"] : "",
    "landUse" => isset($info["landUse"]) ? $info["landUse"] : "",
    "landmarks" => $landmarks,
    "dmNearby" => cadastreDmNearby($landmarks, ""),
    "note" => "地段地號來自國土測繪地籍圖點位。所有權人與謄本仍需電子謄本。"
  ));
}

function cadastreLookupLand($county, $area, $section, $landNo, $cityCodes) {
  if ($section === "" || $landNo === "") {
    cadastreJson(array("ok" => false, "error" => "missing land", "message" => "請先填地段與地號"), 400);
  }
  $cityCode = ($county !== "" && isset($cityCodes[$county])) ? $cityCodes[$county] : "";
  if ($cityCode === "") {
    cadastreJson(array("ok" => false, "error" => "unsupported county", "message" => "請先選縣市"), 400);
  }
  $sectCode = $section;
  if (!preg_match("/^[0-9]{4}$/", $section)) {
    $townCode = $area !== "" ? cadastreFindTownCode($cityCode, $area) : "";
    if ($townCode !== "") {
      $raw = cadastreHttpGet("https://api.nlsc.gov.tw/other/ListLandSection/" . rawurlencode($cityCode) . "/" . rawurlencode($townCode));
      foreach (cadastreParseSections($raw, $county, $area) as $row) {
        if ($row["section"] === $section || $row["name"] === $section) {
          $sectCode = $row["sectionCode"];
          break;
        }
      }
    }
  }
  $info = cadastreLandInfo($cityCode, $sectCode, $landNo);
  if (empty($info["landNo"])) {
    cadastreJson(array("ok" => false, "error" => "not found", "message" => "國土測繪沒有這筆地號資料"), 404);
  }
  cadastreJson(array(
    "ok" => true,
    "source" => "nlsc",
    "county" => $county,
    "area" => $area,
    "section" => $section,
    "sectionName" => $section,
    "sectionCode" => $info["sectionCode"],
    "landNo" => $info["landNo"],
    "buildingNo" => $info["buildingNo"],
    "buildingNos" => $info["buildingNos"],
    "areaSqm" => $info["areaSqm"],
    "areaPing" => $info["areaPing"],
    "landUseZone" => $info["landUseZone"],
    "zone" => $info["zone"],
    "landUse" => $info["landUse"],
    "note" => "面積與分區來自國土測繪土地資訊。所有權人與謄本仍需電子謄本。"
  ));
}

if ($action === "" || $action === "help") {
  cadastreJson(array(
    "ok" => true,
    "actions" => array("sections", "roads", "door", "coord", "land"),
    "note" => "門牌可查地段地號與建號。所有權人、他項、謄本仍需地政憑證。",
    "sites" => array(
      "easymap" => "https://easymap.land.moi.gov.tw/Z10Web/Normal",
      "nlsc" => "https://maps.nlsc.gov.tw/",
      "yilan" => "https://land.e-land.gov.tw/",
      "transcript" => "https://ep.land.nat.gov.tw/"
    )
  ));
}

if (in_array($action, array("door", "search", "lookup"), true)) {
  cadastreLookupDoor($county, $area, $road, $door, $q, $cityCodes);
}

if (in_array($action, array("coord", "landpos"), true)) {
  cadastreLookupCoord($county, $lat, $lng, $cityCodes);
}

if (in_array($action, array("land", "landinfo"), true)) {
  cadastreLookupLand($county, $area, $section, $landNo, $cityCodes);
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

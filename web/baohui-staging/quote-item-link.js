(function (root) {
  "use strict";

  function uniq(list) {
    var seen = {};
    return (list || []).filter(function (x) {
      var k = String(x || "");
      if (!k || seen[k]) return false;
      seen[k] = 1;
      return true;
    });
  }

  function normalize(s) {
    return String(s || "")
      .toLowerCase()
      .replace(/[（）()\[\]【】]/g, "")
      .replace(/[\s\-_\/\\|,，.．]+/g, "");
  }

  function tokens(s) {
    var text = String(s || "");
    var out = [];
    var re = /[a-zA-Z]{1,12}\d{1,8}[a-zA-Z0-9]*/g;
    var m;
    while ((m = re.exec(text))) out.push(m[0].toLowerCase());
    (text.match(/[\u4e00-\u9fff]{2,8}/g) || []).forEach(function (w) { out.push(w); });
    (text.match(/\d{3,8}/g) || []).forEach(function (d) { out.push(d); });
    return uniq(out);
  }

  function hay(item) {
    return [item && item.name, item && item.brand, item && item.spec, item && item.category, item && item.subcategory].filter(Boolean).join(" ");
  }

  function tokenRelated(a, b) {
    if (!a || !b) return false;
    if (a === b) return true;
    if (a.length >= 3 && b.indexOf(a) >= 0) return true;
    if (b.length >= 3 && a.indexOf(b) >= 0) return true;
    return false;
  }

  function score(query, item) {
    var q = String(query || "").trim();
    if (!q || !item) return 0;
    var nq = normalize(q);
    var nh = normalize(hay(item));
    if (!nq || !nh) return 0;
    var s = 0;
    if (nh === nq) s += 200;
    else if (nh.indexOf(nq) >= 0) s += 120;
    else if (nq.indexOf(nh) >= 0 && nh.length >= 3) s += 80;
    var qt = tokens(q);
    var ht = tokens(hay(item));
    qt.forEach(function (a) {
      ht.forEach(function (b) {
        if (tokenRelated(a, b)) s += Math.min(48, 10 + Math.min(a.length, b.length) * 3);
      });
    });
    if (normalize(item.brand || "") === nq) s += 50;
    if (normalize(item.spec || "").indexOf(nq) >= 0) s += 30;
    if (normalize(item.name || "").indexOf(nq) >= 0) s += 20;
    return s;
  }

  function rank(query, items, limit) {
    limit = limit || 8;
    var seen = {};
    return (items || [])
      .map(function (item) { return { item: item, score: score(query, item) }; })
      .filter(function (row) { return row.score > 0; })
      .sort(function (a, b) { return b.score - a.score; })
      .filter(function (row) {
        var k = [row.item.name || "", row.item.brand || "", row.item.spec || ""].join("|");
        if (seen[k]) return false;
        seen[k] = 1;
        return true;
      })
      .slice(0, limit)
      .map(function (row) { return row.item; });
  }

  function mergePool(lists) {
    var seen = {};
    var out = [];
    (lists || []).forEach(function (list) {
      (list || []).forEach(function (item) {
        if (!item) return;
        if (!item.name && !item.brand && !item.spec) return;
        var k = [item.name || "", item.brand || "", item.spec || ""].join("|");
        if (seen[k]) return;
        seen[k] = 1;
        out.push(item);
      });
    });
    return out;
  }

  function guessBrand(name, brands) {
    var text = String(name || "");
    var hit = "";
    uniq(brands || []).forEach(function (b) {
      if (!b) return;
      if (text.indexOf(b) >= 0 && b.length > hit.length) hit = b;
    });
    return hit;
  }

  function cleanSpec(brand, spec) {
    spec = String(spec || "").trim();
    brand = String(brand || "").trim();
    if (spec && brand && spec === brand) return "";
    return spec;
  }

  function linkedFill(current, picked) {
    current = current || {};
    picked = picked || {};
    var brand = String(picked.brand || current.brand || "").trim();
    var spec = cleanSpec(brand, picked.spec || current.spec || "");
    var price = picked.price == null ? current.price : picked.price;
    return {
      name: String(picked.name || current.name || "").trim(),
      brand: brand,
      spec: spec,
      price: price,
      warranty: String(picked.warranty || current.warranty || "").trim(),
      taxMode: picked.taxMode || current.taxMode || "none"
    };
  }

  var api = {
    normalize: normalize,
    tokens: tokens,
    score: score,
    rank: rank,
    mergePool: mergePool,
    guessBrand: guessBrand,
    cleanSpec: cleanSpec,
    linkedFill: linkedFill
  };
  if (typeof module === "object" && module.exports) module.exports = api;
  root.baohuiQuoteLink = api;
})(typeof globalThis !== "undefined" ? globalThis : this);

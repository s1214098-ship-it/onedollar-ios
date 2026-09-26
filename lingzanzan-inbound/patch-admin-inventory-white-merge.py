#!/usr/bin/env python3
"""Patch live admin-inventory-color-auto-10.js for OLAN70 white merge."""
from pathlib import Path
import sys

p = Path(sys.argv[1] if len(sys.argv) > 1 else 'admin-inventory-color-auto-10.js')
s = p.read_text()
s = s.replace(
    "'928': '白黑',\n    '929': '白藍',\n    '930': '白綠',\n    '931': '白紅',\n    '932': '紅白'",
    "'928': '白粉(PUTI PINK)',\n    '929': '白藍(PUTI BIRU)',\n    '930': '白綠(PUTI HIGAU)',\n    '931': '白黑(PUTI HITEM)',\n    '932': '白紅(PUTI MERAL)'",
)
s = s.replace(
    "      '白色': '92', '白': '92', 'PUTI': '92', 'PUTIH': '92',",
    "      '白粉': '928', '白粉色': '928', '白色': '92', '白': '92', 'PUTI': '92', 'PUTIH': '92',",
    1,
)
s = s.replace(
    "'白黑': '928', '白藍': '929', '白綠': '930', '白紅': '931', '紅白': '932'",
    "'白黑': '931', '白藍': '929', '白綠': '930', '白紅': '932', '紅白': '932'",
)
old_find = """    if (aliases[normalized]) return aliases[normalized];
    var colorTemplateMap = inventoryColorTemplateMap();
    var direct = legacyCodeKeys(colorTemplateMap).find(function (code) {
      var name = colorTemplateMap[code].toUpperCase().replace(/[\\s()（）+＋\\-_]/g, '');
      return normalized === name || name.indexOf(normalized) > -1 || normalized.indexOf(name) > -1;
    });
    return direct || '';"""
new_find = """    if (aliases[normalized]) return aliases[normalized];
    /* LZ_WHITE_MERGE_20260926: longest stem first so 白粉≠白≠粉紅. */
    var aliasKeys = Object.keys(aliases).sort(function (a, b) { return b.length - a.length; });
    var ai;
    for (ai = 0; ai < aliasKeys.length; ai += 1) {
      if (normalized === aliasKeys[ai] || normalized.indexOf(aliasKeys[ai]) === 0) return aliases[aliasKeys[ai]];
    }
    var colorTemplateMap = inventoryColorTemplateMap();
    var direct = legacyCodeKeys(colorTemplateMap).find(function (code) {
      var name = colorTemplateMap[code].toUpperCase().replace(/[\\s()（）+＋\\-_]/g, '');
      return normalized === name;
    });
    return direct || '';"""
if old_find not in s and 'LZ_WHITE_MERGE_20260926' not in s:
    raise SystemExit('fuzzy matcher missing')
if old_find in s:
    s = s.replace(old_find, new_find, 1)
p.write_text(s)
print('patched', p)

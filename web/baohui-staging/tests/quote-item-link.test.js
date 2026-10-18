const link = require("../quote-item-link.js");
let failed = 0;
function expect(ok, msg) {
  if (ok) {
    console.log("ok  " + msg);
    return;
  }
  failed += 1;
  console.log("FAIL  " + msg);
}

const board = { name: "GA-H510M S2H(S)", brand: "技嘉", spec: "H510M" };
const asus = { name: "華碩FX503V筆電(S)", brand: "華碩", spec: "華碩" };
const psu = { name: "ATX電源測試器 - 黑", brand: "", spec: "電源檢測器" };
const other = { name: "MONTECH AIR 1000", brand: "MONTECH", spec: "機殼" };

expect(link.score("H510", board) > link.score("H510", other), "H510 ranks Gigabyte board over unrelated case");
expect(link.rank("H510", [board, other, psu], 3)[0].brand === "技嘉", "H510 first suggestion is H510M board");
expect(link.score("華碩", asus) > 0, "華碩 matches ASUS laptop name");
expect(link.guessBrand("華碩FX503V筆電(S)", ["華碩", "技嘉"]) === "華碩", "brand is guessed from item name");
expect(link.cleanSpec("華碩", "華碩") === "", "spec that only repeats brand is cleared");
expect(link.linkedFill({ name: "H510" }, board).spec === "H510M", "picking related item fills spec");
expect(link.linkedFill({ name: "華碩FX503V筆電(S)", brand: "" }, asus).brand === "華碩", "picking fills brand");
expect(link.tokens("GA-H510M S2H").indexOf("h510m") >= 0, "model tokens include H510M");

if (failed) {
  console.error(failed + " assertion(s) failed");
  process.exit(1);
}
console.log("all passed");

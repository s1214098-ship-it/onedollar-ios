import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
RULES = json.loads((ROOT / "accounting-rules.json").read_text(encoding="utf-8"))


def norm_date(text: str) -> str:
    text = str(text or "").replace("/", "-").replace(".", "-").strip()
    parts = text.split("-")
    if len(parts) >= 3 and parts[0].isdigit():
        return f"{int(parts[0]):04d}-{int(parts[1]):02d}-{int(parts[2]):02d}"
    return ""


def should_skip(inv: dict) -> bool:
    blob = " ".join(
        str(inv.get(k, "")) for k in ("seller_name", "from_email", "subject", "filename")
    ).lower()
    if any(word.lower() in blob for word in RULES["skipKeywords"]):
        return True
    seller = "".join(ch for ch in str(inv.get("seller_tax_id", "")) if ch.isdigit())
    return seller in ["".join(ch for ch in x if ch.isdigit()) for x in RULES["skipTaxIds"]]


def amounts_match(invoice_total: float, doc_amount: float) -> bool:
    tol = float(RULES["amountTolerance"])
    cands = [doc_amount, round(doc_amount * 1.05), round(doc_amount / 1.05, 2), round(doc_amount * 1.05, 2)]
    return any(abs(invoice_total - float(v)) <= tol for v in cands)


class AccountingRulesTest(unittest.TestCase):
    def test_cutoff_and_ids(self):
        self.assertEqual(RULES["matchFrom"], "2026-08-18")
        self.assertEqual(RULES["buyerTaxId"], "23365425")
        self.assertEqual(RULES["jieyuanTaxId"], "23134543")
        self.assertEqual(RULES["gmailFrom"], "捷元ebill@gcnc-group.com")
        self.assertEqual(RULES["oauthRedirect"], "https://baohui.paohui.org/accounting-gmail-oauth.php")

    def test_skip_agoda_and_unified(self):
        self.assertTrue(should_skip({"subject": "Agoda booking", "seller_tax_id": ""}))
        self.assertTrue(should_skip({"seller_tax_id": "70537075"}))
        self.assertFalse(should_skip({"seller_tax_id": "23134543", "subject": "捷元電子對帳單"}))

    def test_ignore_before_cutoff(self):
        self.assertLess(norm_date("2026/08/17"), RULES["matchFrom"])
        self.assertEqual(norm_date("2026-8-18"), "2026-08-18")

    def test_amount_match_with_vat(self):
        self.assertTrue(amounts_match(47549, 47549))
        self.assertTrue(amounts_match(105, 100))
        self.assertFalse(amounts_match(2000, 100))


if __name__ == "__main__":
    unittest.main()

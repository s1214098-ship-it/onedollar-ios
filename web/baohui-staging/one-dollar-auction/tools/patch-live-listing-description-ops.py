#!/usr/bin/env python3
"""Snapshot Traditional Chinese 解說 on create, and label both listing-copy fields."""

from __future__ import annotations

from pathlib import Path

PATCHES = [
    (
        """                    'product_description' => $product['description'] ?? '',
                    'product_sale_price' => (float)($product['sale_price'] ?? $product['reference_price'] ?? 0),
""",
        """                    'product_description' => $product['description'] ?? '',
                    'product_description_source' => $product['description_source'] ?? '',
                    'product_sale_price' => (float)($product['sale_price'] ?? $product['reference_price'] ?? 0),
""",
    ),
    (
        """              <label class="internal-description-field"><span>內部產品說明（繁體中文）</span><small>電腦部、服裝部及其他產品線共用；供庫存與員工辨識。</small><textarea name="description_source" id="productDescriptionSource" rows="9" lang="zh-Hant"><?=h($editProduct['description_source'] ?? '')?></textarea></label>
              <label class="ai-primary-field"><span>內容說明（越南文）</span><small>Facebook 與其他平台上架主要取用此欄；空白會標示待補，不會用中文頂替。</small><textarea name="description" id="productDescriptionAi" rows="9" lang="vi" placeholder="Nhập nội dung đăng bán bằng tiếng Việt"><?=h($editProduct['description'] ?? '')?></textarea></label>
""",
        """              <label class="internal-description-field"><span>內部產品說明（繁體中文）</span><small>上架文案的繁體解說取用此欄；電腦部、服裝部及其他產品線共用。</small><textarea name="description_source" id="productDescriptionSource" rows="9" lang="zh-Hant"><?=h($editProduct['description_source'] ?? '')?></textarea></label>
              <label class="ai-primary-field"><span>內容說明（越南文）</span><small>上架文案的越南文取用此欄；空白不會用中文頂替。</small><textarea name="description" id="productDescriptionAi" rows="9" lang="vi" placeholder="Nhập nội dung đăng bán bằng tiếng Việt"><?=h($editProduct['description'] ?? '')?></textarea></label>
""",
    ),
    (
        """        <label class="internal-description-field"><span>內部產品說明（繁體中文・必填）</span><small>只供後台、庫存及排程辨識，不會當作主要對外發文。</small>
          <textarea name="description_source" id="quickEditDescriptionSource" rows="11" required lang="zh-Hant" placeholder="請用繁體中文填寫：規格、外觀、功能、配件與注意事項"></textarea>
        </label>
        <label class="ai-primary-field"><span>內容說明（越南文・必填）</span><small>Facebook 與其他平台上架時，主要使用這個越南文窗格。</small>
          <textarea name="description" id="quickEditAiDescription" rows="11" required lang="vi" placeholder="Nhập nội dung đăng bán bằng tiếng Việt"></textarea>
        </label>
""",
        """        <label class="internal-description-field"><span>內部產品說明（繁體中文・必填）</span><small>上架文案的繁體解說取用此欄。</small>
          <textarea name="description_source" id="quickEditDescriptionSource" rows="11" required lang="zh-Hant" placeholder="請用繁體中文填寫：規格、外觀、功能、配件與注意事項"></textarea>
        </label>
        <label class="ai-primary-field"><span>內容說明（越南文・必填）</span><small>上架文案的越南文取用此欄；空白不會用中文頂替。</small>
          <textarea name="description" id="quickEditAiDescription" rows="11" required lang="vi" placeholder="Nhập nội dung đăng bán bằng tiếng Việt"></textarea>
        </label>
""",
    ),
    (
        """            <label class="wide">上架文案（依套組帶入，可複製貼到 Facebook）<textarea class="schedule-listing-draft" rows="8" readonly><?=h($listingDraft)?></textarea></label>
""",
        """            <label class="wide">上架文案（依套組帶入，可複製貼到 Facebook）<textarea class="schedule-listing-draft" rows="16" readonly><?=h($listingDraft)?></textarea></label>
""",
    ),
    (
        """          'description' => (string)(($firstPreviewProduct['description'] ?? '') ?: '門市現貨，可自取。'),
          'sale_price' => $firstPreviewProduct['sale_price'] ?? ($firstPreviewProduct['selling_price'] ?? 990),
""",
        """          'description' => (string)(($firstPreviewProduct['description'] ?? '') ?: '門市現貨，可自取。'),
          'description_source' => (string)(($firstPreviewProduct['description_source'] ?? '') ?: '門市現貨，可自取。'),
          'sale_price' => $firstPreviewProduct['sale_price'] ?? ($firstPreviewProduct['selling_price'] ?? 990),
""",
    ),
    (
        """          'product_description' => $postReplyPreviewProduct['description'],
          'close_at' => date('Y-m-d 23:59'),
""",
        """          'product_description' => $postReplyPreviewProduct['description'],
          'product_description_source' => $postReplyPreviewProduct['description_source'] ?? '',
          'close_at' => date('Y-m-d 23:59'),
""",
    ),
]


def apply(text: str) -> str:
    missing = []
    for old, new in PATCHES:
        if old not in text:
            missing.append(old[:180].replace("\n", " / "))
            continue
        if text.count(old) != 1:
            missing.append(f"count={text.count(old)} :: " + old[:180].replace("\n", " / "))
            continue
        text = text.replace(old, new, 1)
    if missing:
        raise SystemExit("patch targets missing or not unique:\n- " + "\n- ".join(missing))
    return text


def main() -> None:
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument("path")
    args = parser.parse_args()
    path = Path(args.path)
    original = path.read_text(encoding="utf-8", errors="surrogateescape")
    updated = apply(original)
    path.write_text(updated, encoding="utf-8", errors="surrogateescape")
    print(f"patched {path} bytes {len(original.encode('utf-8'))} -> {len(updated.encode('utf-8'))}")


if __name__ == "__main__":
    main()

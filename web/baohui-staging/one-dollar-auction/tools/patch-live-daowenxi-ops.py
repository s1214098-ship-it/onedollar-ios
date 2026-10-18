#!/usr/bin/env python3
"""Surgical patches for live operations.php: 達文西 company+branch linkage.
Does not replace the whole file (live ops is ~2.6MB and has 寄庫)."""

from __future__ import annotations

from pathlib import Path


PATCHES = [
    (
        "require_once __DIR__ . DIRECTORY_SEPARATOR . 'monthly-settlement-lib.php';\n",
        "require_once __DIR__ . DIRECTORY_SEPARATOR . 'ops-customer-company-lib.php';\nrequire_once __DIR__ . DIRECTORY_SEPARATOR . 'monthly-settlement-lib.php';\n",
    ),
    (
        """    $memberId = trim((string)($buyer['member_id'] ?? ''));
    if ($memberId !== '') {
        $matched = $uniqueMatch(array_values(array_filter($members, static fn($row) => trim((string)($row['id'] ?? '')) === $memberId)));
        if ($matched) return $matched;
    }
""",
        """    $memberId = trim((string)($buyer['member_id'] ?? ''));
    if ($memberId !== '') {
        $matched = $uniqueMatch(array_values(array_filter($members, static fn($row) => trim((string)($row['id'] ?? '')) === $memberId)));
        if ($matched) {
            if (function_exists('ops_customer_follow_merged_member')) $matched = ops_customer_follow_merged_member($members, $matched);
            return $matched;
        }
    }
""",
    ),
    (
        """            return false;
        })));
        if ($matched) return $matched;
    }
    return null;
}
function ops_apply_member_to_buyer($buyer, $member): array {
""",
        """            return false;
        })));
        if ($matched) return $matched;
    }
    if (function_exists('ops_customer_resolve_party')) {
        $resolved = ops_customer_resolve_party((string)($buyer['name'] ?? ''), (string)($buyer['branch'] ?? ''));
        if (!empty($resolved['known'])) {
            $matched = $bestContactMatch(array_values(array_filter($members, static function($row) use ($resolved) {
                if (!is_array($row) || (function_exists('ops_customer_member_is_merged') && ops_customer_member_is_merged($row))) return false;
                $company = function_exists('ops_customer_company_by_member') ? ops_customer_company_by_member($row) : null;
                return is_array($company) && ($company['key'] ?? '') === ($resolved['key'] ?? '');
            })));
            if ($matched) return $matched;
        }
    }
    return null;
}
function ops_apply_member_to_buyer($buyer, $member): array {
""",
    ),
    (
        """    $canonicalName = trim((string)($member['organization_name'] ?? $member['customer_name'] ?? $member['name'] ?? ''));
    if ($canonicalName !== '') $buyer['name'] = $canonicalName;
""",
        """    $canonicalName = trim((string)($member['name'] ?? $member['customer_name'] ?? ''));
    if (function_exists('ops_customer_resolve_party')) {
        $resolvedMember = ops_customer_resolve_party(
            trim((string)($member['organization_name'] ?? $canonicalName)),
            (string)($buyer['branch'] ?? '')
        );
        if ($resolvedMember['company'] !== '') $canonicalName = $resolvedMember['company'];
        if ($resolvedMember['branch'] !== '' && trim((string)($buyer['branch'] ?? '')) === '') $buyer['branch'] = $resolvedMember['branch'];
        if ($resolvedMember['canonical_member_id'] !== '' && trim((string)($buyer['member_id'] ?? '')) === '') $buyer['member_id'] = $resolvedMember['canonical_member_id'];
    } elseif ($canonicalName === '') {
        $canonicalName = trim((string)($member['organization_name'] ?? ''));
    }
    if ($canonicalName !== '') $buyer['name'] = $canonicalName;
""",
    ),
    (
        """        $customerBranch = trim((string)($_POST['sales_customer_branch'] ?? ''));
        $customerLookupPhone = $customerPhone;
""",
        """        $customerBranch = trim((string)($_POST['sales_customer_branch'] ?? ''));
        if (function_exists('ops_customer_apply_to_delivery_buyer')) {
            $normalizedSalesBuyer = ops_customer_apply_to_delivery_buyer([
                'name' => $customerName,
                'branch' => $customerBranch,
                'member_id' => $customerMemberId,
            ]);
            $customerName = trim((string)($normalizedSalesBuyer['name'] ?? $customerName));
            $customerBranch = trim((string)($normalizedSalesBuyer['branch'] ?? $customerBranch));
            if (trim((string)($normalizedSalesBuyer['member_id'] ?? '')) !== '') $customerMemberId = trim((string)$normalizedSalesBuyer['member_id']);
        }
        $customerLookupPhone = $customerPhone;
""",
    ),
    (
        """        $branchEditor = !empty($_POST['member_branches_editor']);
        if ($branchEditor) $row['branches'] = ops_parse_party_branches_from_post('member_branches');
""",
        """        $branchEditor = !empty($_POST['member_branches_editor']) || isset($_POST['member_branches_text']);
        if ($branchEditor) {
            if (isset($_POST['member_branches_text']) && function_exists('ops_customer_parse_branches_text')) {
                $row['branches'] = ops_customer_parse_branches_text($_POST['member_branches_text'] ?? '');
            } else {
                $row['branches'] = ops_parse_party_branches_from_post('member_branches');
            }
        }
""",
    ),
    (
        """        $customerName = trim((string)($_POST['billing_customer_name'] ?? ''));
        $customerBranch = trim((string)($_POST['billing_customer_branch'] ?? ''));
        $items = [];
""",
        """        $customerName = trim((string)($_POST['billing_customer_name'] ?? ''));
        $customerBranch = trim((string)($_POST['billing_customer_branch'] ?? ''));
        if (function_exists('ops_customer_resolve_party')) {
            $resolvedBillingParty = ops_customer_resolve_party($customerName, $customerBranch);
            if ($resolvedBillingParty['company'] !== '') $customerName = $resolvedBillingParty['company'];
            $customerBranch = (string)($resolvedBillingParty['branch'] ?? $customerBranch);
        }
        $items = [];
""",
    ),
    (
        """            if ($customerName === '' || !baohui_billing_customer_matches($buyer['name'] ?? '', $customerName)) continue;
""",
        """            $deliveryBranch = trim((string)($buyer['branch'] ?? ($delivery['customer_branch'] ?? '')));
            $billingScope = $customerBranch !== '' ? 'branch' : 'company';
            if ($customerName === '' || !(function_exists('ops_customer_document_matches')
                ? ops_customer_document_matches(['customer' => $buyer['name'] ?? '', 'branch' => $deliveryBranch], $customerName, $customerBranch, $billingScope)
                : baohui_billing_customer_matches($buyer['name'] ?? '', $customerName))) continue;
            if ($billingScope === 'branch' && function_exists('ops_customer_resolve_party')) {
                $resolvedDelivery = ops_customer_resolve_party((string)($buyer['name'] ?? ''), $deliveryBranch);
                if (ops_customer_norm($resolvedDelivery['branch']) !== ops_customer_norm($customerBranch)) continue;
            }
""",
    ),
    (
        """                'customer_name' => $customerName,
                'customer_branch' => ops_normalize_customer_branch($customerBranch ?? ''),
""",
        """                'customer_name' => $customerName,
                'customer_branch' => trim((string)($customerBranch ?? '')),
                'customer_scope' => trim((string)($customerBranch ?? '')) !== '' ? 'branch' : 'company',
""",
    ),
    (
        """                    $existingBranch = ops_normalize_customer_branch($existingRequest['customer_branch'] ?? '');
                    $newBranch = ops_normalize_customer_branch($customerBranch ?? '');
""",
        """                    $existingBranch = trim((string)($existingRequest['customer_branch'] ?? ''));
                    $newBranch = trim((string)($customerBranch ?? ''));
""",
    ),
    (
        """        $buyer=is_array($delivery['buyer']??null)?$delivery['buyer']:[];$customer=trim((string)($buyer['name']??''))?:'未指定單位';$branch=ops_normalize_customer_branch($buyer['branch']??($delivery['customer_branch']??''));$amount=max(0,(float)($delivery['total']??0));if($amount<=0)continue;
        $groupKey=ops_party_branch_group_key($customer,$branch);""",
        """        $buyer=is_array($delivery['buyer']??null)?$delivery['buyer']:[];$customer=trim((string)($buyer['name']??''))?:'未指定單位';$branch=trim((string)($buyer['branch']??($delivery['customer_branch']??'')));
        if(function_exists('ops_customer_resolve_party')){$resolvedBill=ops_customer_resolve_party($customer,$branch);if($resolvedBill['company']!==''){$customer=$resolvedBill['company'];}$branch=$resolvedBill['known']?'':($resolvedBill['branch']!==''?$resolvedBill['branch']:$branch);}
        $branch=ops_normalize_customer_branch($branch);$amount=max(0,(float)($delivery['total']??0));if($amount<=0)continue;
        $groupKey=ops_party_branch_group_key($customer,$branch);""",
    ),
    (
        """          $billingDeliveryCandidates[] = [
              'selection_type'=>'delivery', 'selection_id'=>$deliveryId, 'customer'=>$customer,
""",
        """          $candidateBranch = trim((string)($delivery['buyer']['branch'] ?? $delivery['customer_branch'] ?? ''));
          if (function_exists('ops_customer_apply_to_delivery_buyer')) {
              $normalizedCandidate = ops_customer_apply_to_delivery_buyer(['name'=>$customer,'branch'=>$candidateBranch]);
              $customer = (string)($normalizedCandidate['name'] ?? $customer);
              $candidateBranch = (string)($normalizedCandidate['branch'] ?? $candidateBranch);
          }
          $billingDeliveryCandidates[] = [
              'selection_type'=>'delivery', 'selection_id'=>$deliveryId, 'customer'=>$customer, 'branch'=>$candidateBranch,
""",
    ),
    (
        """        $openName=trim((string)($openBuyer['name']??''))?:'未填客戶';
        $openPhone=trim((string)($openBuyer['phone']??''));
""",
        """        $openName=trim((string)($openBuyer['name']??''))?:'未填客戶';
        if(function_exists('ops_customer_resolve_party')){$openResolved=ops_customer_resolve_party($openName,(string)($openBuyer['branch']??''));if($openResolved['company']!=='')$openName=$openResolved['company'];}
        $openPhone=trim((string)($openBuyer['phone']??''));
""",
    ),
    (
        """          if ($reconcileCustomer !== '' && $row['customer'] !== $reconcileCustomer) return false;
""",
        """          if ($reconcileCustomer !== '' && !(function_exists('ops_customer_same_company') ? ops_customer_same_company($row['customer'], $reconcileCustomer) : $row['customer'] === $reconcileCustomer)) return false;
""",
    ),
    (
        """          $key = $row['customer'];
          if (!isset($reconcileCustomerSummary[$key])) $reconcileCustomerSummary[$key] = ['customer'=>$key,'orders'=>0,'receivable'=>0,'paid'=>0,'outstanding'=>0,'overpaid'=>0];
""",
        """          $key = $row['customer'];
          if (function_exists('ops_customer_resolve_party')) {
              $resolvedReconcile = ops_customer_resolve_party((string)$key, (string)($row['branch'] ?? ''));
              if ($resolvedReconcile['company'] !== '') $key = $resolvedReconcile['company'];
          }
          if (!isset($reconcileCustomerSummary[$key])) $reconcileCustomerSummary[$key] = ['customer'=>$key,'orders'=>0,'receivable'=>0,'paid'=>0,'outstanding'=>0,'overpaid'=>0];
""",
    ),
    (
        """          $customerOptions[$customerName] = true;
""",
        """          if (function_exists('ops_customer_resolve_party')) {
              $resolvedOption = ops_customer_resolve_party($customerName);
              if ($resolvedOption['company'] !== '') $customerName = $resolvedOption['company'];
          }
          $customerOptions[$customerName] = true;
""",
    ),
    (
        """        <label>分店／據點<select id="billingCustomerBranch" name="billing_customer_branch"><option value="">（未分店）</option></select></label>
""",
        """        <label>分店／據點<select id="billingCustomerBranch" name="billing_customer_branch"><option value="">全公司（含各分店）</option></select></label>
""",
    ),
    (
        """                <textarea name="blacklist_reason" rows="2" placeholder="風險原因"><?=h($m['blacklist_reason'] ?? '')?></textarea>
                <?php $memberNote=trim((string)($m['note']??'')); $memberNoteIsImport=str_starts_with($memberNote,'會員由管家婆最新往來單位匯入'); ?>
""",
        """                <textarea name="blacklist_reason" rows="2" placeholder="風險原因"><?=h($m['blacklist_reason'] ?? '')?></textarea>
                <?php $mb=ops_normalize_party_branches($m['branches']??[]); ?>
                <input name="member_branches_text" value="<?=h(implode('、', array_map(static fn($b)=>(string)($b['name']??''), $mb)))?>" placeholder="分店，逗號分隔">
                <?php $memberNote=trim((string)($m['note']??'')); $memberNoteIsImport=str_starts_with($memberNote,'會員由管家婆最新往來單位匯入'); ?>
""",
    ),
    (
        """<script src="ops-billing-documents.js?v=billing-docs-20260922" charset="UTF-8"></script>
<script src="ops-inventory-hold.js?v=hold-pick-20260922" charset="UTF-8"></script>
""",
        """<script src="ops-customer-company.js?v=customer-company-20260922" charset="UTF-8"></script>
<script src="ops-billing-documents.js?v=billing-docs-20260922b" charset="UTF-8"></script>
<script src="ops-inventory-hold.js?v=hold-pick-20260922" charset="UTF-8"></script>
""",
    ),
]


def apply(text: str) -> str:
    missing = []
    for old, new in PATCHES:
        if old not in text:
            missing.append(old[:120].replace("\n", " / "))
            continue
        if text.count(old) != 1:
            missing.append(f"count={text.count(old)} :: " + old[:120].replace("\n", " / "))
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
    print(f"patched {path} bytes {len(original)} -> {len(updated)}")


if __name__ == "__main__":
    main()

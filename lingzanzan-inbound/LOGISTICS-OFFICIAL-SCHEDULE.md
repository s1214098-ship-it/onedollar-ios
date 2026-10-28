# 另讚讚出貨物流官方查件排程

Binding as of 2026-10-28 (peak 峰志: 「每天中午十二點更新跟下午三點」).

| Slot | Timezone | UTC |
| --- | --- | --- |
| **12:00** | Asia/Taipei | 04:00 |
| **15:00** | Asia/Taipei | 07:00 |

Retired afternoon slots: **18:00** (Windows / `thoroughWindow`) and **16:00** (Claude Desktop).

## What counts as done

Real official lookups + write-back, then leftover 未查 is zero (or documented `official_no_data` only):

1. 7-11 shopmore — https://tracking.shopmore.com.tw/ (max 6, captcha self-solve)
2. 全家 inner FME — `https://ecfme.fme.com.tw/FMEDCFPWebV2_II/index.aspx?orderno=` (not outer famiport; max 5)
3. 郵局 — https://postserv.post.gov.tw/pstmail/main_mail.html?targetTxn=EB500100
4. 新竹 HCT — https://www.hct.com.tw/Search/SearchGoods_n.aspx
5. 大榮 KTJ — https://www.kerrytj.com/zh/checking

Write-back: `data/carrier-tracking-queue.json` via `scripts/apply-carrier-results.js` / `scripts/apply-seven-official.js`.

**Not done:** only running `scripts/carrier-thorough-audit.js` to count 未查. LINE 429 or email failure is not a skip.

## Where the old schedule lived

| Place | Old | Change from this Linux VM |
| --- | --- | --- |
| Cursor alwaysApply rule (this repo) | 12:00／18:00 | Updated to 12:00／15:00 |
| MEMORY / BRAND / STRATEGY | 12:00／18:00 | Updated |
| Google Calendar `s1214098@gmail.com` | none | Daily 12:00 + 15:00 events created |
| Live `carrier-thorough-audit.js` on PHT | `thoroughWindow: "12:00 and 18:00"` | **Cannot edit** — share/rclone not mounted here. Patch string to `"12:00 and 15:00"` on the Windows/PHT box |
| Live `carrier-tracking-scheduled-run.php` | `$slot = date('Y-m-d-H')` (hour = launch time) | No hour constant; Windows trigger time must move 18→15 |
| Windows Task Scheduler | 12:00 and 18:00 | Import XML in `windows/` on the PC. This VM cannot reach Task Scheduler |
| Claude Desktop 「物流更新 張張+寶輝（每日12:00、16:00）」 | 12:00 and 16:00; failing since 2026-10-09 | Disable/retitle on the PC. This VM cannot edit Claude schedules |
| Cursor cloud automations | none found (`source=automations` = 0) | Create a Cursor Automation in the dashboard if you want cloud-agent fire at 04:00/07:00 UTC |
| GitHub Actions in this iOS repo | none for logistics | Not added — Actions cannot solve shopmore/FME captcha |

Live code paths (historical):

- `\\192.168.2.142\PHT-Web\lingzanzan-staging\`
- rclone `pht:PHT-Web/lingzanzan-staging/`
- `/mnt/pht-web/lingzanzan-staging/carrier-tracking-scheduled-run.php`

## Windows PC remaining action

On the logistics PC (Claude emails named it **1f-foringer**):

1. Task Scheduler → find tasks that run at 12:00 / 18:00 involving `carrier-tracking-scheduled-run.php` or `carrier-thorough-audit.js`.
2. Import `lingzanzan-inbound/windows/LZ-OfficialCheck-1200.xml` and `LZ-OfficialCheck-1500.xml`, or run `lingzanzan-inbound/windows/install-official-check-tasks.ps1` as Administrator.
3. Delete or disable the 18:00 trigger. Do not leave 18:00 and 15:00 both firing.
4. In Claude Desktop, disable 「物流更新 張張+寶輝（每日12:00、16:00）」 or change it to 12:00 and 15:00 **and** pin the Chrome that is logged into 張張/寶輝 (multi-browser picker is why it has failed since 2026-10-09).
5. On PHT staging, replace `thoroughWindow` `"12:00 and 18:00"` with `"12:00 and 15:00"` in `carrier-thorough-audit.js` (and any UI copy of that string).

The PHP scheduled-run may still push LINE after lookups. If LINE 429, **keep looking up**. Use `--no-line` / `--no-push` if the push is blocking the worker; never skip the official sites.

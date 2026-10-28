# MEMORY — 另讚讚 lingzanzan

Updated: 2026-10-28 (Asia/Taipei)

## Logistics official-check schedule (BINDING)

Peak 峰志 (Designer/Software engineer, Traditional Chinese) said 2026-10-28: 「每天中午十二點更新跟下午三點」.

- **Windows: 12:00 and 15:00 Asia/Taipei, every day.**
- Old windows **12:00 and 18:00** are retired.
- Broken Claude Desktop schedule 「物流更新 張張+寶輝（每日12:00、16:00）」 last useful run ~2026-10-08 12:00; failing since 2026-10-09 (multi-Chrome picker / machine not connected). Do not treat it as the live schedule.
- This must be **real** official lookups + write-back:
  - 7-11 shopmore `https://tracking.shopmore.com.tw/`
  - 全家 inner FME `https://ecfme.fme.com.tw/FMEDCFPWebV2_II/index.aspx?orderno=`
  - 郵局 `https://postserv.post.gov.tw/pstmail/main_mail.html?targetTxn=EB500100`
  - 新竹 HCT `https://www.hct.com.tw/Search/SearchGoods_n.aspx`
  - 大榮 KTJ `https://www.kerrytj.com/zh/checking`
- Running `carrier-thorough-audit.js` only to count 未查 is **not** completion.
- LINE 429 or email failure is **not** a reason to skip lookups.
- Captcha is authorized for the agent to self-solve.

Google Calendar (s1214098@gmail.com, Asia/Taipei) now has daily events:

- 另讚讚物流官方查件 12:00 — id `f3kunqs1r31pv06baebtedqulg` — https://www.google.com/calendar/event?eid=ZjNrdW5xczFyMzFwdjA2YmFlYnRlZHF1bGdfMjAyNjEwMjlUMDQwMDAwWiBzMTIxNDA5OEBt
- 另讚讚物流官方查件 15:00 — id `ivbvlg4v8jdvlm6pabppu4jf1s` — https://www.google.com/calendar/event?eid=aXZidmxnNHY4amR2bG02cGFicHB1NGpmMXNfMjAyNjEwMjlUMDcwMDAwWiBzMTIxNDA5OEBt

UTC equivalents: 04:00 and 07:00.

## Where the old schedule lived

- Cursor alwaysApply rule: 十二點六點 / 12:00／18:00 (this repo now 12:00／15:00).
- Live PHT: `carrier-tracking-scheduled-run.php` (CLI, Asia/Taipei, `$slot = date('Y-m-d-H')` — hour comes from Windows Task Scheduler launch time, not hardcoded 18).
- Live audit JSON: `thoroughWindow: "12:00 and 18:00"` from `carrier-thorough-audit.js` on `\\192.168.2.142\PHT-Web\lingzanzan-staging` / rclone `pht:PHT-Web/lingzanzan-staging/`. This Linux VM cannot mount that share.
- Windows Task Scheduler on the user's PC (1f-foringer / PHT) launched the 12:00 and 18:00 slots. Cannot install tasks from this VM.
- Claude Desktop scheduled task 「物流更新 張張+寶輝（每日12:00、16:00）」 emails from `no-reply-claude@mail.anthropic.com`.
- No Cursor cloud automation (`source=automations` list = 0; `get-automation` needs a UUID we do not have).
- Google Calendar previously had **no** 物流 official-check series.

## Do not

- Invent SKUs.
- Spend ads.
- Print passwords from `/tmp/lz-creds.env` or `myship-7-11.secret`.
- Fight Chrome CDP 9334 while another agent is doing remaining 全家/郵局/shopmore lookups.

# STRATEGY — 另讚讚 lingzanzan

## CONSTRAINT

- Daily outbound logistics official-check is **12:00 and 15:00 Asia/Taipei** only.
- Do not use 18:00 or 16:00 as the afternoon slot.
- Do not treat `carrier-thorough-audit.js` leftover counts as a completed window.
- Do not skip official lookups because LINE returned 429 or email failed.
- Captcha on shopmore / FME / 郵局 is authorized for the agent to solve.

## AVOID

- Matching the retired Claude title 「物流更新 張張+寶輝（每日12:00、16:00）」 as if it were live.
- Fighting Chrome CDP port 9334 while another agent already owns remaining 全家/郵局/shopmore lookups.

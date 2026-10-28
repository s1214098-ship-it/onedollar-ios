# Live PHT patch (cannot apply from this Linux cloud VM)

File: `lingzanzan-staging/scripts/carrier-thorough-audit.js` (and any copy that emits `thoroughWindow`)

Replace:

```js
"12:00 and 18:00"
```

with:

```js
"12:00 and 15:00"
```

If hours are compared as integers, use `[12, 15]` not `[12, 18]`.

`carrier-tracking-scheduled-run.php` keys `$slot = date('Y-m-d-H')` in `Asia/Taipei`. Moving the Windows trigger from 18:00 to 15:00 is enough for that file; do not treat LINE push failure as a skipped slot.

// Select only a true occasion and the urgency justified by the actual close time.
// This script never posts; content.js keeps the Facebook evidence and submit gates.
(() => {
  const catalog = globalThis.__BAOHUI_REMINDER_SPOKEN_CATALOG || {};
  const unsafeClaims = /郭大吃粽子了|今晚月亮很亮|郭大今天不趕|郭大中途來啦/;
  const twParts = nowMs => {
    const parts = new Intl.DateTimeFormat('en-US', {
      timeZone: 'Asia/Taipei', year: 'numeric', month: '2-digit', day: '2-digit',
      hour: '2-digit', hourCycle: 'h23', weekday: 'short'
    }).formatToParts(new Date(nowMs));
    const value = name => parts.find(part => part.type === name)?.value || '';
    return {
      date: `${value('year')}-${value('month')}-${value('day')}`,
      monthDay: `${value('month')}-${value('day')}`,
      weekday: value('weekday'), hour: Number(value('hour'))
    };
  };
  const closeMillis = value => {
    const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/);
    if (!match) return NaN;
    return Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3]),
      Number(match[4]) - 8, Number(match[5]), Number(match[6] || 0));
  };
  const hash = value => {
    let result = 2166136261;
    for (const char of String(value)) {
      result ^= char.codePointAt(0);
      result = Math.imul(result, 16777619);
    }
    return result >>> 0;
  };
  const eligibleScene = (payload, clock) => {
    // Movable holidays and campaigns require an explicitly confirmed day.
    const configured = String(payload.reminderOccasion || '').trim();
    if (configured && String(payload.reminderOccasionDate || '') === clock.date && catalog[configured]) {
      return configured;
    }
    const calendarScene = String(globalThis.__BAOHUI_REMINDER_CONFIRMED_DATES?.[clock.date] || '');
    if (catalog[calendarScene]) return calendarScene;
    if (clock.monthDay === '01-01') return '元旦';
    if (clock.monthDay === '02-14') return '情人節或七夕';
    if (clock.date.slice(5, 7) === '05' && clock.weekday === 'Sun'
        && Number(clock.date.slice(8, 10)) >= 8 && Number(clock.date.slice(8, 10)) <= 14) return '母親節';
    if (clock.monthDay === '08-08') return '父親節';
    if (clock.monthDay === '12-25') return '聖誕';
    if (clock.date.slice(5, 7) === '12' && Number(clock.date.slice(8, 10)) >= 26) return '年末感謝';
    if (clock.weekday === 'Sat' && clock.hour < 12) return '週六早晨';
    if (clock.weekday === 'Sat' && clock.hour < 18) return '週六午後';
    if (clock.weekday === 'Sun' && clock.hour < 18) return '週日悠閒';
    return '';
  };
  const pick = (payload, nowMs = Date.now()) => {
    const clock = twParts(nowMs);
    const scene = eligibleScene(payload || {}, clock);
    const remaining = closeMillis(payload?.closeAt) - nowMs;
    const lead = scene === '元旦' ? '元旦啦，' : scene === '父親節' ? '父親節啦，'
      : scene === '聖誕' ? '聖誕啦，' : scene.startsWith('週六') ? '週六啦，'
      : scene === '週日悠閒' ? '週日啦，' : '';
    // Do not assert an exact countdown unless the verified closing clock supports it.
    if (Number.isFinite(remaining) && remaining > 0 && remaining <= 30 * 60000) {
      if (remaining < 60000) return `${lead}郭大在等你，剩不到 1 分鐘啦，看準時間再來！`;
      const minutes = Math.ceil(remaining / 60000);
      if (minutes <= 5) return `${lead}郭大在等你，剩約 ${minutes} 分鐘啦，往前再來！`;
      if (minutes <= 15) return `${lead}快結標啦，剩約 ${minutes} 分鐘，郭大等你往前衝！`;
      return `${lead}剩約 ${minutes} 分鐘，郭大在等你，來來來！`;
    }
    const choices = (catalog[scene] || []).filter(line =>
      !unsafeClaims.test(line) && !(scene === '情人節或七夕' && clock.monthDay === '02-14' && /七夕/.test(line)));
    if (!choices.length) return '郭大在等你啊，來來來，往前再來！';
    const seed = `${payload?.scheduleId || payload?.targetId || ''}|${payload?.reminderPhase || ''}|${clock.date}|${scene}`;
    const spoken = choices[hash(seed) % choices.length];
    return spoken.includes('郭大') ? spoken : `郭大喊一下，${spoken}`;
  };
  globalThis.baohuiReminderScenePick = pick;
  if (typeof module !== 'undefined' && module.exports) module.exports = {pick, eligibleScene, closeMillis, twParts};
})();

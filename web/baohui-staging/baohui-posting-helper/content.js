(() => {
  const isBaohui = location.hostname === 'baohui.paohui.org';
  const isFacebook = /(^|\.)facebook\.com$/.test(location.hostname);
  // Facebook dialogs may contain iframes. Only Baohui's embedded admin pages
  // need an iframe bridge; never run a second posting worker in Facebook frames.
  if (window.top !== window && !isBaohui) return;

  if (isBaohui) {
    if (/\/one-dollar-auction\/facebook-auction-worker-queue\.php$/i.test(location.pathname) && new URLSearchParams(location.search).get('action') === 'api_due') {
      try {
        const data = JSON.parse((document.body && document.body.innerText || '').trim());
        if (!data.ok || !Array.isArray(data.tasks) || !data.tasks.length) {
          document.body.textContent = '立即上架未送出：' + (data.error || '目前沒有可交付的任務，請回後台查看此筆狀態。');
          return;
        }
        chrome.runtime.sendMessage({type: 'BAOHUI_AUCTION_DUE_RESPONSE', tasks: data.tasks});
      } catch (error) {}
      return;
    }
    document.documentElement.dataset.baohuiPostingHelper = chrome.runtime.getManifest().version;
    window.postMessage({source: 'baohui-posting-helper', type: 'HELPER_READY'}, location.origin);

    window.addEventListener('message', event => {
      if (event.source !== window || event.origin !== location.origin || !event.data) return;
      try {
        if (!chrome.runtime.id) return;
      } catch (error) {
        return;
      }
      if (event.data.source === 'baohui-posting-helper-test' && event.data.type === 'FORCE_QUEUE_TEST' && /\/one-dollar-auction\/facebook-auction-worker-queue\.php$/i.test(location.pathname)) {
        chrome.runtime.sendMessage({type: 'BAOHUI_FORCE_QUEUE_TEST'}, response => {
          const error = chrome.runtime.lastError && chrome.runtime.lastError.message;
          window.postMessage({
            source: 'baohui-posting-helper', type: 'FORCE_QUEUE_TEST_ACK',
            ok: !!(response && response.ok) && !error,
            error: error || (response && response.error) || '',
            auctionTaskId: response && response.auctionTaskId || '',
            advertisingTaskId: response && response.advertisingTaskId || ''
          }, location.origin);
        });
        return;
      }
      if (!['baohui-ad-posting','baohui-product-posting'].includes(event.data.source)) return;
      if (event.data.type === 'PREPARE_FACEBOOK_POST') {
        const payload = event.data.payload || {};
        chrome.runtime.sendMessage({type: 'BAOHUI_PREPARE', payload}, response => {
          const error = chrome.runtime.lastError && chrome.runtime.lastError.message;
          window.postMessage({
            source: 'baohui-posting-helper', type: 'PREPARE_FACEBOOK_ACK',
            requestId: payload.requestId || '', ok: !!(response && response.ok) && !error,
            received: Number(response && response.received || 0), retained: response && response.retained === true,
            error: error || (response && response.error) || '', tabId: response && response.tabId
          }, location.origin);
        });
      }
      if (event.data.type === 'START_AUCTION_QUEUE') {
        const payload = event.data.payload || {};
        chrome.runtime.sendMessage({
          type: 'BAOHUI_AUCTION_POLL_NOW',
          taskIds: Array.isArray(payload.taskIds) ? payload.taskIds : [],
          forceImmediate: payload.forceImmediate === true,
          operatorStart: payload.batchStart === true
        }, response => {
          const error = chrome.runtime.lastError && chrome.runtime.lastError.message;
          window.postMessage({
            source: 'baohui-posting-helper', type: 'START_AUCTION_QUEUE_ACK',
            requestId: payload.requestId || '', ok: !!(response && response.ok) && !error,
            error: error || (response && response.error) || ''
          }, location.origin);
        });
      }
      if (event.data.type === 'PREPARE_FACEBOOK_DELETE') {
        const payload = event.data.payload || {};
        chrome.runtime.sendMessage({type: 'BAOHUI_PREPARE_DELETE', payload}, response => {
          const error = chrome.runtime.lastError && chrome.runtime.lastError.message;
          window.postMessage({
            source: 'baohui-posting-helper', type: 'PREPARE_FACEBOOK_DELETE_ACK',
            requestId: payload.requestId || '', ok: !!(response && response.ok) && !error,
            error: error || (response && response.error) || ''
          }, location.origin);
        });
      }
      if (event.data.type === 'PREPARE_FACEBOOK_SOLD') {
        const payload = event.data.payload || {};
        chrome.runtime.sendMessage({type: 'BAOHUI_PREPARE_SOLD_MARK', payload}, response => {
          const error = chrome.runtime.lastError && chrome.runtime.lastError.message;
          window.postMessage({
            source: 'baohui-posting-helper', type: 'PREPARE_FACEBOOK_SOLD_ACK',
            requestId: payload.requestId || '', ok: !!(response && response.ok) && !error,
            error: error || (response && response.error) || ''
          }, location.origin);
        });
      }
      if (event.data.type === 'GROUP_REJECTION_RECORDED') {
        chrome.runtime.sendMessage({type: 'BAOHUI_ACK_GROUP_REJECTION', fingerprint: String(event.data.fingerprint || '')});
      }
    });

    // The scheduling workspace is embedded in admin.php. Its own window must
    // receive the button ACK, while the top page alone owns the queue heartbeat
    // and backend report listener (otherwise every iframe duplicates them).
    if (window.top !== window) return;

    // Authenticated Queue-page diagnostic.  The query flag is deliberate: it
    // gives operators a visible, auditable one-shot trigger without creating
    // new backend schedules or duplicating existing queue items.
    if (/\/one-dollar-auction\/facebook-auction-worker-queue\.php$/i.test(location.pathname) && new URLSearchParams(location.search).get('codex_force_test') === '1') {
      const banner = document.createElement('div');
      banner.id = 'baohui-force-test-status';
      banner.style.cssText = 'position:fixed;right:18px;top:18px;z-index:2147483647;max-width:520px;padding:14px 18px;border-radius:10px;background:#0b3d66;color:#fff;font:700 15px/1.5 system-ui,sans-serif;box-shadow:0 8px 24px #0004';
      banner.textContent = '正在啟動單次測試：先一元競標，再一筆合格推廣文…';
      (document.body || document.documentElement).appendChild(banner);
      const cleanUrl = new URL(location.href);
      cleanUrl.searchParams.delete('codex_force_test');
      history.replaceState(null, '', cleanUrl.href);
      chrome.runtime.sendMessage({type: 'BAOHUI_FORCE_QUEUE_TEST'}, response => {
        const error = chrome.runtime.lastError && chrome.runtime.lastError.message;
        const ok = !!(response && response.ok) && !error;
        banner.style.background = ok ? '#087f5b' : '#b42318';
        banner.textContent = ok
          ? `單次測試已啟動｜競標 ${response.auctionTaskId || '已選取'}｜推廣 ${response.advertisingTaskId || '目前無合格任務'}`
          : `單次測試未啟動｜${error || (response && response.error) || '未知錯誤'}`;
      });
    }

    chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
      if (message && message.type === 'BAOHUI_FETCH_AUCTION_DUE') {
        const query = new URLSearchParams({action: 'api_due'});
        query.set('helper_version', chrome.runtime.getManifest().version);
        if (message.helperBuild) query.set('helper_build', String(message.helperBuild));
        const requestedTaskIds = Array.isArray(message.taskIds) ? message.taskIds.map(value => String(value || '').trim()).filter(Boolean) : [];
        if (requestedTaskIds.length) query.set('task_ids', requestedTaskIds.join(','));
        if (message.operatorStart === true && requestedTaskIds.length) {
          query.set('operator_start', '1');
          query.set('prepare_scheduled', '1');
        } else if (message.forceImmediate === true && requestedTaskIds.length === 1) {
          query.set('force_task_id', requestedTaskIds[0]);
        }
        fetch('/one-dollar-auction/facebook-auction-worker-queue.php?' + query.toString(), {credentials:'same-origin', cache:'no-store'})
          .then(async response => ({ok:response.ok, status:response.status, data:await response.json().catch(() => ({}))}))
          .then(result => sendResponse({ok:result.ok, status:result.status, tasks:Array.isArray(result.data.tasks)?result.data.tasks:[]}))
          .catch(error => sendResponse({ok:false, error:error.message}));
        return true;
      }
      if (message && message.type === 'BAOHUI_AUCTION_REPORT_REQUEST') {
        fetch('/one-dollar-auction/facebook-auction-worker-queue.php?action=api_report', {
          method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(message.payload || {})
        }).then(async response => ({ok: response.ok, data: await response.json().catch(() => ({}))}))
          .then(result => sendResponse(result))
          .catch(error => sendResponse({ok: false, error: error.message}));
        return true;
      }
      if (message && message.type === 'BAOHUI_REPORT_COMPLETE') {
        const payload = message.payload || {};
        window.postMessage({
          source: 'baohui-posting-helper',
          type: 'FACEBOOK_POST_SUBMITTED',
          targetId: payload.targetId,
          targetName: payload.targetName || '',
          slot: payload.slot || '',
          materialId: payload.materialId || '',
          recordMode: payload.recordMode || 'automatic',
          eventId: payload.requestId || '',
          postUrl: payload.postUrl || '',
          postId: payload.postId || '',
          verifiedIdentity: payload.verifiedIdentity || payload.requiredVisibleName || '',
          verifiedVisible: payload.verifiedVisible === true
        }, location.origin);
      }
      if (message && message.type === 'BAOHUI_PRODUCT_POST_RESULT') {
        window.postMessage({source: 'baohui-posting-helper', type: 'PRODUCT_POST_RESULT', ...message.payload}, location.origin);
      }
      if (message && message.type === 'BAOHUI_PRODUCT_DELETE_RESULT') {
        window.postMessage({source: 'baohui-posting-helper', type: 'PRODUCT_DELETE_RESULT', ...message.payload}, location.origin);
      }
      if (message && message.type === 'BAOHUI_PRODUCT_SOLD_RESULT') {
        window.postMessage({source: 'baohui-posting-helper', type: 'PRODUCT_SOLD_RESULT', ...message.payload}, location.origin);
      }
      if (message && message.type === 'BAOHUI_GROUP_REJECTION') {
        window.postMessage({source: 'baohui-posting-helper', type: 'GROUP_REJECTION_DETECTED', ...message.payload}, location.origin);
      }
    });
    chrome.runtime.sendMessage({type: 'BAOHUI_GET_GROUP_REJECTIONS'}, response => {
      for (const payload of (response && response.items || [])) window.postMessage({source: 'baohui-posting-helper', type: 'GROUP_REJECTION_DETECTED', ...payload}, location.origin);
    });
    // Every open Baohui page keeps the MV3 service worker and its three-stage
    // queue alive. This must run before the Baohui branch returns; the old
    // heartbeat at the bottom of the Facebook-only branch was unreachable.
    let wakeQueueTimer;
    const wakeQueue = () => {
      try {
        if (!chrome.runtime.id) throw new Error('extension_context_invalidated');
        chrome.runtime.sendMessage({type:'BAOHUI_WAKE_QUEUE'}, () => void chrome.runtime.lastError);
      } catch (_) {
        // Reloading an unpacked extension invalidates content scripts already
        // present in admin tabs. Stop their stale timer instead of reporting a
        // spurious error every 30 seconds; a fresh page load gets a new script.
        if (wakeQueueTimer) clearInterval(wakeQueueTimer);
      }
    };
    wakeQueue();
    wakeQueueTimer = setInterval(wakeQueue, 30000);
    return;
  }

  if (!isFacebook) return;
  let activePayload = null;
  let reportSent = false;
  let noticeTimer = null;
  const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
  async function waitFor(getter, timeout = 20000) {
    const end = Date.now() + timeout;
    while (Date.now() < end) {
      const value = getter();
      if (value) return value;
      await sleep(400);
    }
    return null;
  }
  function textOf(element) { return String(element && (element.innerText || element.textContent || element.getAttribute?.('aria-label')) || '').trim(); }
  function normalizeIdentityName(value) {
    return String(value || '')
      .replace(/\s*(?:的個人檔案|的個人主頁|個人檔案|個人主頁|profile)\s*$/i, '')
      .replace(/\s*(?:的動態時報|動態時報|timeline)\s*$/i, '')
      .replace(/^以\s*/u, '')
      .replace(/\s*的身分(?:留言|發佈|發布)?\s*$/u, '')
      .replace(/\s+/g, ' ')
      .trim();
  }
  function visiblePostingIdentity(anchor) {
    if (!anchor) return '';
    const roots = [];
    if (anchor.matches && anchor.matches('[role="dialog"],[role="article"]')) roots.push(anchor);
    let current = anchor;
    for (let depth = 0; current && depth < 5; depth++, current = current.parentElement) roots.push(current);
    for (const root of roots) {
      const labelled = root.matches && root.matches('[aria-label]') ? [root] : [];
      labelled.push(...root.querySelectorAll('[aria-label]'));
      for (const element of labelled) {
        const label = String(element.getAttribute('aria-label') || '').trim();
        let match = label.match(/^以\s*(.+?)\s*的身分(?:留言|發佈|發布)/u);
        if (match) return normalizeIdentityName(match[1]);
        match = label.match(/^(.+?)\s*(?:的個人檔案|的個人主頁)$/u);
        if (match && !/^(?:你|你自己|your)$/i.test(String(match[1] || '').trim())) return normalizeIdentityName(match[1]);
      }
      const profileLink = root.querySelector('a[href*="/user/"][aria-label],a[href*="/profile.php"][aria-label]');
      if (profileLink) {
        const name = normalizeIdentityName(profileLink.getAttribute('aria-label'));
        if (name) return name;
      }
    }
    return '';
  }
  function assertRequiredIdentity(payload, anchor, fallbackIdentity = '') {
    const required = requiredIdentity(payload);
    if (!required) return '';
    const actual = visiblePostingIdentity(anchor) || normalizeIdentityName(fallbackIdentity);
    if (!actual) throw new Error(`無法核對 Facebook 發布身分；必須顯示「${required}」才允許發布`);
    if (actual.toLowerCase() !== required.toLowerCase()) {
      throw new Error(`目前 Facebook 身分是「${actual}」，必須切換為「${required}」；已停止發布`);
    }
    return actual;
  }
  function requiredIdentity(payload) {
    const kind = String(payload && payload.kind || '');
    const contentType = String(payload && payload.contentType || '');
    const action = String(payload && payload.action || '');
    if (kind === 'auction_reminder' || action === 'auction_reminder' || contentType === 'facebook_auction_reminder') return 'Kuo Feng Cheng';
    if (action === 'product_promotion' || contentType === 'facebook_product_promotion') return 'YUKI LIU';
    if (kind === 'auction_listing' || contentType === 'facebook_auction_listing') return 'Kuo Feng Cheng';
    const requested = normalizeIdentityName(payload && payload.requiredVisibleName || '');
    if (kind === 'advertising_post' || contentType === 'facebook_advertising_post') {
      if (['YUKI LIU', '海賊團行政專員', '寶輝科技官方網站 粉絲團', '寶輝科技有限公司 頭城竹安店'].includes(requested)) return requested;
      throw new Error(`一般廣告指定身分「${requested || '空白'}」不符合目前核准帳號，已停止，禁止改用其他身分代發`);
    }
    return '寶輝科技官方網站 粉絲團';
  }
  function visibleElement(element) {
    return !!(element && element.getClientRects && element.getClientRects().length && element.getAttribute('aria-disabled') !== 'true');
  }
  function findAccountMenuButton() {
    return [...document.querySelectorAll('[role="button"],button,[role="menuitem"],[role="option"],[role="link"],a[href],div[tabindex="0"]')].find(element => {
      const label = `${element.getAttribute('aria-label') || ''} ${textOf(element)}`;
      return visibleElement(element) && /你的個人檔案|Your profile/i.test(label);
    }) || null;
  }
  function selectedIdentityFromOpenMenu() {
    const selected = [...document.querySelectorAll('[role="button"],button')].find(element =>
      visibleElement(element) && /(?:目前已選擇|currently selected)/i.test(`${element.getAttribute('aria-label') || ''} ${textOf(element)}`)
    );
    if (selected) {
      const label = String(selected.getAttribute('aria-label') || textOf(selected));
      return normalizeIdentityName(label.replace(/[，,]?\s*(?:目前已選擇|currently selected).*$/i, ''));
    }
    const checked = [...document.querySelectorAll('[aria-checked="true"],[aria-selected="true"]')].find(visibleElement);
    if (checked) {
      const row = checked.closest('[role="button"],button,a') || checked.parentElement;
      const name = normalizeIdentityName(textOf(row));
      if (name) return name;
    }
    const active = [...document.querySelectorAll('a[href]')].find(element =>
      visibleElement(element) && /^(?:\/me\/?|https?:\/\/(?:www\.)?facebook\.com\/me\/?)$/i.test(String(element.getAttribute('href') || element.href || ''))
    );
    return active ? normalizeIdentityName(textOf(active)) : '';
  }
  function currentVisibleIdentity(anchor) {
    const trigger = findPostComposerTrigger();
    return selectedIdentityFromOpenMenu() || visiblePostingIdentity(trigger || anchor);
  }
  async function verifiedIdentityFromAccountMenu() {
    const accountButton = await waitFor(() => findAccountMenuButton(), 12000);
    if (!accountButton) return '';
    if (accountButton.getAttribute('aria-expanded') !== 'true') await trustedFacebookClick(accountButton);
    const selected = await waitFor(() => selectedIdentityFromOpenMenu(), 8000);
    await closeAccountMenu();
    return normalizeIdentityName(selected);
  }
  async function closeAccountMenu() {
    const menuButton = findAccountMenuButton();
    const menuIsOpen = () => {
      const currentButton = findAccountMenuButton();
      const selectedMenuRow = [...document.querySelectorAll('[role="button"],button')].some(element =>
        visibleElement(element) && /(?:目前已選擇|currently selected)/i.test(`${element.getAttribute('aria-label') || ''} ${textOf(element)}`)
      );
      return !!((currentButton && currentButton.getAttribute('aria-expanded') === 'true') || selectedMenuRow);
    };
    if (!menuIsOpen()) return true;
    for (let attempt = 0; attempt < 2 && menuIsOpen(); attempt++) {
      const options = {key: 'Escape', code: 'Escape', keyCode: 27, which: 27, bubbles: true, cancelable: true};
      document.dispatchEvent(new KeyboardEvent('keydown', options));
      document.dispatchEvent(new KeyboardEvent('keyup', options));
      await sleep(350);
    }
    if (menuIsOpen() && menuButton) {
      menuButton.click();
      await sleep(500);
    }
    return !menuIsOpen();
  }
  function findIdentitySwitchButton(required) {
    const wanted = normalizeIdentityName(required).toLowerCase();
    return [...document.querySelectorAll('[role="button"],button')].find(element => {
      if (!visibleElement(element)) return false;
      const label = String(element.getAttribute('aria-label') || '');
      const text = textOf(element);
      const candidates = [label, text].map(value => normalizeIdentityName(value.replace(/^(?:切換為|Switch to)\s*/i, '')).toLowerCase());
      return candidates.includes(wanted) || candidates.some(value => value.startsWith(`${wanted} `));
    }) || null;
  }
  async function openAccountMenuForIdentity(required) {
    const accountButton = await waitFor(() => findAccountMenuButton(), 12000);
    if (!accountButton) throw new Error(`找不到 Facebook 右上角身分選單，無法核對「${required}」`);
    const menuReady = () => accountButton.getAttribute('aria-expanded') === 'true';
    if (menuReady() || findIdentitySwitchButton(required)) return accountButton;
    await trustedFacebookClick(accountButton);
    if (await waitFor(() => menuReady() || findIdentitySwitchButton(required), 3000)) return accountButton;
    // A post dialog can cover the fixed header while the worker's tab is in
    // the background. Chrome may acknowledge the input without opening the
    // React menu. Retry this same header control once through its click event.
    accountButton.click();
    if (await waitFor(() => menuReady() || findIdentitySwitchButton(required), 3000)) return accountButton;
    throw new Error(`Facebook 身分選單未展開，無法核對「${required}」；已停止發布`);
  }
  async function ensureRequiredIdentity(payload, anchor) {
    const required = requiredIdentity(payload);
    if (!required) return '';
    const attemptKey = `baohuiIdentitySwitch:${required.toLowerCase()}`;
    // Scheduled-post management has no composer. An existing post's author is
    // not proof of the currently selected Facebook identity, so check the
    // account switcher when the composer is absent.
    const composerTrigger = findPostComposerTrigger();
    const initiallyVisible = composerTrigger
      ? currentVisibleIdentity(composerTrigger)
      : await verifiedIdentityFromAccountMenu();
    try {
      const verified = assertRequiredIdentity(payload, findPostComposerTrigger(), initiallyVisible);
      sessionStorage.removeItem(attemptKey);
      await closeAccountMenu();
      return verified;
    } catch (error) {}
    const current = initiallyVisible || '目前身分';
    let previous = 0;
    try {
      const saved = JSON.parse(sessionStorage.getItem(attemptKey) || '{}');
      const sameTask = String(saved.taskId || '') === String(payload.taskId || payload.requestId || payload.targetId || '');
      if (sameTask && Date.now() - Number(saved.at || 0) < 120000) previous = Number(saved.count || 0);
    } catch (error) {
      sessionStorage.removeItem(attemptKey);
    }
    if (previous >= 2) throw new Error(`Facebook 已嘗試切換為「${required}」但驗證仍未通過；已停止發布，避免用錯身分`);
    // Group pages hydrate the fixed Facebook header after the feed.  Looking
    // only once makes a valid account menu appear "missing" on slower pages.
    const accountButton = await waitFor(() => findAccountMenuButton(), 12000);
    if (!accountButton) throw new Error(`目前 Facebook 身分是「${current}」，但找不到右上角身分選單，無法自動切換為「${required}」`);
    showNotice(`正在把 Facebook 身分由「${current}」切換為「${required}」…`);
    await openAccountMenuForIdentity(required);
    let switchButton = await waitFor(() => findIdentitySwitchButton(required), 8000);
    if (!switchButton) {
      const allProfiles = await waitFor(() => findButton(/查看所有個人檔案|See all profiles/i), 3000);
      if (allProfiles) {
        await trustedFacebookClick(allProfiles);
        switchButton = await waitFor(() => findIdentitySwitchButton(required), 8000);
      }
    }
    if (!switchButton) throw new Error(`身分選單中找不到「${required}」，無法自動切換`);
    sessionStorage.setItem(attemptKey, JSON.stringify({
      count: previous + 1,
      at: Date.now(),
      taskId: String(payload.taskId || payload.requestId || payload.targetId || '')
    }));
    // Facebook can visually mark the requested row before the real profile
    // navigation has completed. A plain HTMLElement.click() therefore caused
    // a false "switched" result and the later composer guard still saw the
    // old Page identity. Use a trusted click, wait for the switcher to leave,
    // then verify only the group composer identity outside that menu.
    await trustedFacebookClick(switchButton);
    await sleep(700);
    await closeAccountMenu();
    const verified = await waitFor(() => {
      const nextTrigger = findPostComposerTrigger();
      const actual = nextTrigger ? visiblePostingIdentity(nextTrigger) : selectedIdentityFromOpenMenu();
      return actual && actual.toLowerCase() === required.toLowerCase() ? actual : null;
    }, 12000) || await verifiedIdentityFromAccountMenu();
    if (!verified || verified.toLowerCase() !== required.toLowerCase()) throw new Error(`已點選切換為「${required}」，但 Facebook 重新載入後仍無法驗證；已停止發布`);
    sessionStorage.removeItem(attemptKey);
    await closeAccountMenu();
    return verified;
  }
  function normalizedEditorText(editor) {
    return String(editor && (editor.innerText || editor.textContent) || '')
      .replace(/\u00a0/g, ' ')
      .replace(/\r\n?/g, '\n')
      .replace(/[ \t]+\n/g, '\n')
      .trim();
  }
  function canonicalAdvertisingCopy(value) {
    // Facebook may represent an intentional blank paragraph as one or two DOM
    // line breaks. Compare the actual visible lines without weakening the
    // content check: wording, order, URLs and every non-empty line must remain
    // identical to the server-frozen copy.
    return String(value || '').normalize('NFC')
      .replace(/[\u200B-\u200D\u2060\uFEFF]/g, '')
      .replace(/\u00a0/g, ' ')
      .replace(/\r\n?/g, '\n')
      .replace(/[ \t]+\n/g, '\n')
      .replace(/\n{2,}/g, '\n')
      .trim();
  }
  function visibleCopyCount(scope, value) {
    const haystack = String(textOf(scope || document.body) || '').replace(/\s+/g, ' ').trim();
    const needle = String(value || '').replace(/\s+/g, ' ').trim();
    if (!needle) return 0;
    return haystack.split(needle).length - 1;
  }
  function auctionReminderSignature(value) {
    const compact = String(value || '').normalize('NFKC')
      .replace(/[\s,\u200B-\u200D\u2060\uFEFF]/g, '')
      .replace(/[｜︱│]/g, '|');
    // The scheduled cycle is part of the reminder identity.  Price alone is
    // intentionally allowed to repeat at the next reminder time; only an
    // exact retry of the same cycle should be treated as a duplicate.
    const cycleMarker = compact.indexOf('|⏱️');
    const cycle = cycleMarker >= 0 ? compact.slice(cycleMarker) : '';
    const bid = compact.match(/目前最高NT\$?([0-9]+)\|下一口至少NT\$?([0-9]+)/u);
    if (bid) return `目前最高NT$${bid[1]}|下一口至少NT$${bid[2]}起${cycle}`;
    const opening = compact.match(/尚無出價\|第一口至少NT\$?([0-9]+)/u);
    return opening ? `尚無出價|第一口至少NT$${opening[1]}起${cycle}` : '';
  }
  function visibleAuctionReminderEvidence(scope, value, requiredVisibleName) {
    const signature = auctionReminderSignature(value);
    if (!signature) return null;
    const compact = input => String(input || '').normalize('NFKC')
      .replace(/[\s,\u200B-\u200D\u2060\uFEFF]/g, '')
      .replace(/[｜︱│]/g, '|');
    const [priceSignature, cycleSignature = ''] = signature.split('|⏱️');
    for (const link of (scope || document.body).querySelectorAll('a[href*="comment_id="],a[href*="reply_comment_id="]')) {
      if (!link.getClientRects().length) continue;
      let item = link;
      for (let depth = 0; depth < 12 && item; depth++, item = item.parentElement) {
        const content = compact(textOf(item));
        if (content.includes(priceSignature)
            && (!cycleSignature || content.includes(cycleSignature))
            && (!requiredVisibleName || content.includes(compact(requiredVisibleName)))) {
          try {
            const url = new URL(link.href);
            const commentId = url.searchParams.get('comment_id') || url.searchParams.get('reply_comment_id') || '';
            if (/^\d+$/.test(commentId)) return {commentUrl:url.href, commentId};
          } catch (_) {}
          break;
        }
        if (content.length > 2000) break;
      }
    }
    return null;
  }
  function copyLineCount(value) {
    return String(value || '').replace(/\r\n?/g, '\n').split('\n').filter(line => line.trim() !== '').length;
  }
  function verifyEditorCopy(editor, expected) {
    const wanted = String(expected || '').replace(/\r\n?/g, '\n').trim();
    const actual = normalizedEditorText(editor);
    if (!wanted || !actual) throw new Error('Facebook 文案沒有完整帶入');
    const firstLine = wanted.split('\n').find(line => line.trim())?.trim() || '';
    if (firstLine && !actual.includes(firstLine)) throw new Error('Facebook 文案第一行不完整，已停止發布');
    if (firstLine && actual.split(firstLine).length - 1 > 1) throw new Error('Facebook 文案被重複帶入，已停止發布');
    const expectedLines = copyLineCount(wanted);
    const actualLines = copyLineCount(actual);
    if (expectedLines >= 4 && actualLines < 3) throw new Error('Facebook 文案換行消失，已停止發布');
    const code = wanted.match(/(?:編號|商品編號)[：:]\s*([A-Z0-9-]+)/i)?.[1];
    if (code && !actual.includes(code)) throw new Error('Facebook 文案缺少產品編號，已停止發布');
    return true;
  }
  function clearEditor(editor) {
    editor.focus();
    const selection = window.getSelection();
    const range = document.createRange();
    range.selectNodeContents(editor);
    selection.removeAllRanges();
    selection.addRange(range);
    document.execCommand('delete', false);
    editor.dispatchEvent(new InputEvent('input', {bubbles: true, inputType: 'deleteContentBackward'}));
  }

  async function trustedClearFacebookEditor(editor) {
    if (!editor) throw new Error('Facebook 文案輸入框不存在');
    editor.focus();
    await sleep(120);
    const response = await chrome.runtime.sendMessage({type:'BAOHUI_TRUSTED_CLEAR_EDITOR'});
    if (!response || !response.ok) throw new Error(response && response.error || 'Chrome 無法清除 Facebook 舊草稿');
    await sleep(350);
    if (normalizedEditorText(editor) !== '') {
      clearEditor(editor);
      await sleep(250);
    }
    if (normalizedEditorText(editor) !== '') throw new Error('Facebook 舊草稿未清除乾淨，已停止以避免文案重複');
    return true;
  }

  async function prepareFacebookTaskTabClose() {
    const buttonName = element => `${textOf(element)} ${element.getAttribute('aria-label') || ''} ${element.getAttribute('title') || ''}`.trim();
    const visibleButtons = root => [...(root || document).querySelectorAll('[role="button"],button')]
      .filter(item => item.getClientRects().length && item.getAttribute('aria-disabled') !== 'true');
    // Leave Facebook's nested schedule-time screen before touching the main
    // composer. Closing the browser tab while this screen is open always
    // creates Chrome's native "Leave site?" prompt.
    const scheduleDialog = findFacebookScheduleDialog();
    if (scheduleDialog) {
      const scheduleBack = visibleButtons(scheduleDialog).find(item => /返回|上一頁|back/i.test(buttonName(item)))
        || visibleButtons(scheduleDialog).find(item => !textOf(item).trim() && item.querySelector('svg'));
      if (scheduleBack) scheduleBack.click();
      await sleep(180);
    }
    const composer = findOpenPostComposer();
    const editors = [...document.querySelectorAll('[contenteditable="true"][role="textbox"],div[contenteditable="true"]')]
      .filter(item => item.getClientRects().length && (item.closest('[role="dialog"]') || /回覆|reply/i.test(`${item.getAttribute('aria-label') || ''} ${item.getAttribute('aria-description') || ''}`)));
    for (const editor of editors) {
      clearEditor(editor);
      editor.dispatchEvent(new InputEvent('input', {bubbles:true, inputType:'deleteContentBackward', data:null}));
      editor.dispatchEvent(new Event('change', {bubbles:true}));
      editor.blur();
    }
    if (document.activeElement instanceof HTMLElement) document.activeElement.blur();
    if (composer && document.contains(composer)) {
      const composerClose = visibleButtons(composer).find(item => /^(?:關閉|取消|close|cancel)(?:\s|$)/i.test(buttonName(item)))
        || visibleButtons(composer).find(item => /關閉|close/i.test(buttonName(item)));
      if (composerClose) composerClose.click();
      await sleep(450);
    }
    // Facebook sometimes asks to discard the emptied composer. This is safe:
    // the worker invokes this routine only for its own completed/failed tab.
    const discardDialog = [...document.querySelectorAll('[role="dialog"],[aria-modal="true"]')]
      .filter(item => item.getClientRects().length)
      .find(item => /捨棄|放棄|discard|離開頁面|留言還沒完成|還沒發佈就要離開/i.test(textOf(item)));
    if (discardDialog) {
      const discard = visibleButtons(discardDialog).find(item => /^(?:捨棄|放棄|離開頁面|離開|discard)(?:\s|$)/i.test(buttonName(item)));
      if (discard) discard.click();
      await sleep(450);
    }
    const leaveDialogStillOpen = [...document.querySelectorAll('[role="dialog"],[aria-modal="true"]')]
      .filter(item => item.getClientRects().length)
      .some(item => /離開頁面|留言還沒完成|還沒發佈就要離開/i.test(textOf(item)));
    return {ok:!leaveDialogStillOpen, clearedEditors:editors.length, scheduleClosed:!!scheduleDialog, composerClosed:!!composer, leaveDialogStillOpen};
  }
  async function trustedFacebookMultiline(editor, value, leadingBreak = false, softBreaks = false) {
    if (!editor) throw new Error('Facebook 文案輸入框不存在');
    const copy = String(value || '').replace(/\r\n?/g, '\n');
    editor.focus();
    await sleep(120);
    const response = await chrome.runtime.sendMessage({
      type:'BAOHUI_TRUSTED_MULTILINE',
      text:copy,
      leadingBreak:!!leadingBreak,
      softBreaks:!!softBreaks
    });
    if (!response || !response.ok) throw new Error(response && response.error || 'Chrome 無法保留 Facebook 文案換行');
  }
  async function insertMultilineText(editor, value, softBreaks = false) {
    const copy = String(value || '').replace(/\r\n?/g, '\n');
    const existing = normalizedEditorText(editor);
    const expected = copy.replace(/\u00a0/g, ' ')
      .replace(/[ \t]+\n/g, '\n')
      .trim();
    // A Facebook composer can survive a delayed worker response.  If the
    // complete copy is already present, keep it instead of clearing and typing
    // the same text for a second time.
    if (existing && existing === expected) {
      verifyEditorCopy(editor, copy);
      return true;
    }
    const firstLine = expected.split('\n').find(line => line.trim())?.trim() || '';
    // Facebook may preserve the previous attempt's draft. Clear it through
    // Chrome's trusted keyboard path, verify the editor is truly empty, then
    // insert exactly one fresh copy instead of failing and wasting the slot.
    await trustedClearFacebookEditor(editor);
    // Facebook may merge DOM-created paragraphs. Type every line through the
    // browser input pipeline and press a trusted Enter for every newline.
    // This is one insertion path, so an asynchronous paste cannot duplicate it.
    await trustedFacebookMultiline(editor, copy, false, softBreaks);
    await sleep(700);
    verifyEditorCopy(editor, copy);
    return true;
  }
  async function insertAuctionReplyText(editor, value, leader) {
    const copy = String(value || '').replace(/\r\n?/g, '\n');
    const name = String(leader || '').replace(/^@+/, '').trim();
    const label = `${editor.getAttribute('aria-label') || ''} ${editor.getAttribute('aria-description') || ''}`;
    const existing = normalizedEditorText(editor);
    const firstLine = copy.split('\n').find(line => line.trim())?.trim() || '';
    const existingCopies = firstLine ? existing.split(firstLine).length - 1 : 0;
    if (existingCopies === 1) {
      verifyEditorCopy(editor, copy);
      return true;
    }
    if (existingCopies > 1) {
      throw new Error('Facebook 回覆框已有重複提醒文字，已停止送出；請關閉此分頁後重試');
    }
    // Facebook 的回覆框可能預先放入不可取代的客人姓名標籤；保留它並從後方輸入，
    // 避免清空姓名時一併取消「回覆該留言」的關聯。
    if (name && label.replace(/\s+/g, '').includes(`回覆${name.replace(/\s+/g, '')}`) && existing.includes(name)) {
      const oldText = existing.replace(name, '').replace(/^[@\s\u200b]+|[\s\u200b]+$/g, '');
      if (oldText) throw new Error('Facebook 回覆框仍保留上一輪文字，已停止送出；請關閉此分頁後重試');
      editor.focus();
      const selection = window.getSelection();
      const range = document.createRange();
      range.selectNodeContents(editor);
      range.collapse(false);
      selection.removeAllRanges();
      selection.addRange(range);
      await trustedFacebookMultiline(editor, copy, true, true);
      await sleep(500);
      const insertedText = normalizedEditorText(editor);
      const insertedCopies = firstLine ? insertedText.split(firstLine).length - 1 : 0;
      if (insertedCopies !== 1) throw new Error(insertedCopies > 1
        ? 'Facebook 將提醒文字重複帶入，已停止送出；本頁保留供核對'
        : '客人回覆框已開啟，但提醒文字沒有成功帶入');
      return true;
    }
    await insertMultilineText(editor, copy, true);
    return true;
  }
  function findButton(pattern) {
    return [...document.querySelectorAll('[role="button"],button')].find(element => element.getClientRects().length && pattern.test(textOf(element)) && element.getAttribute('aria-disabled') !== 'true');
  }
  function findPostComposerTrigger() {
    const pattern = /建立貼文|建立新貼文|在想些什麼|寫些什麼|留個言吧|撰寫貼文|Write something|Create post/i;
    const candidates = [...document.querySelectorAll('[role="button"],button')]
      .filter(element => element.getClientRects().length && element.getAttribute('aria-disabled') !== 'true')
      .filter(element => pattern.test(`${textOf(element)} ${element.getAttribute('aria-label') || ''}`));
    return candidates.sort((left, right) => textOf(left).length - textOf(right).length)[0] || null;
  }
  function findOpenPostComposer() {
    const editorSelector = '[contenteditable="true"][role="textbox"],div[contenteditable="true"]';
    const dialogs = [...document.querySelectorAll('[role="dialog"]')]
      .filter(item => item.getClientRects().length && item.querySelector(editorSelector));
    if (dialogs.length) return dialogs[dialogs.length - 1];
    const editor = [...document.querySelectorAll(editorSelector)].find(item => {
      if (!item.getClientRects().length) return false;
      const label = `${item.getAttribute('aria-label') || ''} ${item.getAttribute('data-placeholder') || ''}`;
      return /在想些什麼|寫些什麼|建立貼文|Write something|Create post/i.test(label);
    });
    return editor ? (editor.closest('[role="dialog"]') || editor.parentElement) : null;
  }
  function showNotice(message, danger = false) {
    if (noticeTimer) clearTimeout(noticeTimer);
    let notice = document.getElementById('baohui-posting-helper-notice');
    if (!notice) {
      notice = document.createElement('div');
      notice.id = 'baohui-posting-helper-notice';
      Object.assign(notice.style, {position:'fixed',right:'18px',bottom:'18px',zIndex:'2147483647',maxWidth:'360px',padding:'14px 17px',borderRadius:'12px',font:'700 15px/1.5 system-ui',boxShadow:'0 10px 35px #0005',color:'#fff'});
      document.documentElement.appendChild(notice);
    }
    notice.style.background = danger ? '#b42318' : '#087f5b';
    notice.style.display = 'block';
    notice.textContent = message;
    noticeTimer = setTimeout(() => {
      notice.style.display = 'none';
      noticeTimer = null;
    }, danger ? 7000 : 3500);
  }
  function rejectionFingerprint(text) {
    let hash = 2166136261;
    for (let index = 0; index < text.length; index++) { hash ^= text.charCodeAt(index); hash = Math.imul(hash, 16777619); }
    return 'reject-' + (hash >>> 0).toString(16);
  }
  function isUnpostableGroupError(error) {
    // A composer click or selector failure is a helper/UI fault, not proof that
    // this identity has lost posting rights in the group.
    return /待審內容已達到數量上限|社團目前沒有顯示發文入口|尚未加入|入社審核|加入申請正在審核中|被限制發文/u.test(String(error && error.message || error || ''));
  }
  function reportUnpostableGroup(payload, error) {
    if (!payload || !payload.targetName || !/facebook\.com\/groups\//i.test(String(payload.groupUrl || ''))) return;
    const notice = `無法在此社團發文：${String(error && error.message || error || 'Facebook 未提供發文入口')}`;
    chrome.runtime.sendMessage({
      type: 'BAOHUI_GROUP_REJECTION_DETECTED',
      payload: {
        groupName: String(payload.targetName),
        targetId: String(payload.targetId || ''),
        groupUrl: String(payload.groupUrl || ''),
        notice,
        fingerprint: rejectionFingerprint(`cannot-post|${payload.targetId || ''}|${payload.groupUrl || ''}|${notice}`),
        reasonType: 'cannot_post',
        detectedAt: new Date().toISOString()
      }
    });
  }
  const seenRejectionFingerprints = new Set();
  function detectRejectedGroupPosts() {
    const candidates = [...document.querySelectorAll('[role="alert"], [role="status"], [aria-live="assertive"], [aria-live="polite"]')]
      .filter(element => element.getClientRects().length && !element.closest('#baohui-posting-helper-notice'));
    const found = new Map();
    for (const element of candidates) {
      const notice = textOf(element).replace(/\s+/g, ' ').trim();
      if (!notice || notice.length > 700) continue;
      let match = notice.match(/管理員拒絕了你在(.+?)(?:發佈|發布)的貼文/u);
      if (!match) match = notice.match(/管理員拒絕了你在(.+?)的貼文/u);
      if (!match) match = notice.match(/(?:An admin|Admins?) (?:declined|rejected) your post in (.+?)(?:\.|$)/i);
      const groupName = match && String(match[1] || '').trim().replace(/[。．.]$/u, '');
      if (!groupName || groupName.length > 160) continue;
      const fingerprint = rejectionFingerprint(groupName + '|' + notice);
      if (seenRejectionFingerprints.has(fingerprint)) continue;
      seenRejectionFingerprints.add(fingerprint);
      found.set(fingerprint, {groupName, notice, fingerprint, detectedAt: new Date().toISOString()});
    }
    for (const payload of found.values()) chrome.runtime.sendMessage({type: 'BAOHUI_GROUP_REJECTION_DETECTED', payload});
  }
  let rejectionScanTimer = null;
  const scheduleRejectionScan = () => {
    clearTimeout(rejectionScanTimer);
    rejectionScanTimer = setTimeout(detectRejectedGroupPosts, 600);
  };
  scheduleRejectionScan();
  new MutationObserver(scheduleRejectionScan).observe(document.documentElement, {childList:true, subtree:true});
  function decodeFile(result, name) {
    const binary = atob(result.base64);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
    const mime = result.mime || 'image/png';
    const extension = mime.includes('jpeg') ? 'jpg' : mime.includes('webp') ? 'webp' : 'png';
    return new File([bytes], `${name || 'baohui-ad'}.${extension}`, {type: mime});
  }
  async function attachImage(dialog, payload) {
    const existingMedia = new Set([...dialog.querySelectorAll('img[src],video[src]')].map(element => element.currentSrc || element.src).filter(Boolean));
    let input = dialog.querySelector('input[type="file"][accept*="image"],input[type="file"][accept*="video"]');
    if (!input) {
      const photo = [...dialog.querySelectorAll('[role="button"],button')].find(element => /相片|照片|Photo|Video/i.test(textOf(element)));
      if (photo) photo.click();
      input = await waitFor(() => dialog.querySelector('input[type="file"][accept*="image"],input[type="file"][accept*="video"]'), 8000);
    }
    if (!input) throw new Error('找不到 Facebook 圖片上傳欄位');
    const transfer = new DataTransfer();
    const urls = [...new Set((payload.imageUrls?.length ? payload.imageUrls : [payload.imageUrl]).filter(Boolean))];
    if (!urls.length) throw new Error('任務沒有產品圖片');
    for (const [index, url] of urls.entries()) {
      const result = await chrome.runtime.sendMessage({type: 'BAOHUI_GET_IMAGE', url});
      if (!result || !result.ok) throw new Error(`第 ${index + 1} 張圖片讀取失敗`);
      if(payload.protocol===2&&payload.imageSha256){
        if(urls.length!==1||result.sha256!==payload.imageSha256)throw Error('廣告圖片內容已變更，已停止送出');
        payload.uploadedImageSha256=result.sha256;
      }
      transfer.items.add(decodeFile(result, `${payload.productId || payload.materialId || 'product'}-${index + 1}`));
    }
    input.files = transfer.files;
    input.dispatchEvent(new Event('input', {bubbles: true}));
    input.dispatchEvent(new Event('change', {bubbles: true}));
    const previewReady = await waitFor(() => {
      const removable = [...dialog.querySelectorAll('[aria-label],[role="button"]')].some(element => /移除.*(?:相片|照片|圖片)|remove.*(?:photo|image)/i.test(element.getAttribute('aria-label') || textOf(element)));
      const newMedia = [...dialog.querySelectorAll('img[src],video[src]')].some(element => {
        const source = element.currentSrc || element.src || '';
        if (!source || existingMedia.has(source) || !element.getClientRects().length) return false;
        return source.startsWith('blob:') || source.startsWith('data:') || Number(element.naturalWidth || element.videoWidth || 0) >= 120;
      });
      return removable || newMedia;
    }, 20000);
    if (!previewReady) throw new Error(`Facebook 尚未顯示照片預覽（應有 ${urls.length} 張），已停止發布`);
  }
  function setFacebookField(element, value) {
    element.focus();
    if (typeof element.select === 'function') element.select();
    let inserted = false;
    try {
      inserted = document.execCommand('insertText', false, value);
    } catch (error) {}
    const prototype = element instanceof HTMLTextAreaElement ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
    const setter = Object.getOwnPropertyDescriptor(prototype, 'value')?.set;
    if (!inserted || element.value !== value) {
      if (setter) setter.call(element, value); else element.value = value;
    }
    element.dispatchEvent(new InputEvent('input', {bubbles:true, inputType:'insertText', data:value}));
    element.dispatchEvent(new Event('change', {bubbles:true}));
    element.blur();
  }
  function activateFacebookControl(element) {
    if (!element) return;
    element.scrollIntoView({block:'center', inline:'center'});
    element.focus?.({preventScroll:true});
    const common = {bubbles:true, cancelable:true, composed:true, view:window, button:0, buttons:1};
    try { element.dispatchEvent(new PointerEvent('pointerdown', {...common, pointerId:1, pointerType:'mouse', isPrimary:true})); } catch (error) {}
    element.dispatchEvent(new MouseEvent('mousedown', common));
    try { element.dispatchEvent(new PointerEvent('pointerup', {...common, buttons:0, pointerId:1, pointerType:'mouse', isPrimary:true})); } catch (error) {}
    element.dispatchEvent(new MouseEvent('mouseup', {...common, buttons:0}));
    element.click();
  }
  async function trustedFacebookClick(element, mode = 'mouse', accessibleNamePrefix = '', axRole = '') {
    if (!element) throw new Error('Facebook 操作元件不存在');
    element.scrollIntoView({block:'center', inline:'center'});
    await sleep(120);
    const rect = element.getBoundingClientRect();
    if (!rect.width || !rect.height) throw new Error('Facebook 操作元件不可見');
    const response = await chrome.runtime.sendMessage({
      type:'BAOHUI_TRUSTED_CLICK',
      x:rect.left + rect.width / 2,
      y:rect.top + rect.height / 2,
      role:element.getAttribute('role') || '',
      ariaLabel:element.getAttribute('aria-label') || '',
      elementText:String(element.textContent || '').trim().slice(0, 240),
      clickMode:mode,
      accessibleNamePrefix,
      axRole
    });
    if (!response || !response.ok) throw new Error(response && response.error || 'Chrome 無法完成受信任點擊');
  }
  async function trustedFacebookEnter(editor) {
    if (!editor) throw new Error('Facebook 留言輸入框不存在');
    editor.focus();
    await sleep(120);
    const response = await chrome.runtime.sendMessage({type:'BAOHUI_TRUSTED_KEY', key:'Enter'});
    if (!response || !response.ok) throw new Error(response && response.error || 'Chrome 無法送出受信任 Enter');
  }
  async function submitFacebookCommentEditor(editor, timeoutMs = 12000) {
    await trustedFacebookEnter(editor);
    // Facebook frequently replaces the whole reply editor after accepting a
    // comment. The detached old node still retains its previous textContent,
    // so an empty-text-only check reports a successful post as failed.
    if (await waitFor(() => !editor.isConnected || normalizedEditorText(editor) === '', 1800)) return true;
    // Some Facebook reply editors keep Enter as a newline and expose only the
    // blue arrow as the real submit action. Locate the enabled button beside
    // this exact editor and click it through Chrome's trusted input path.
    const editorRect = editor.getBoundingClientRect();
    const scopes = [];
    for (let node = editor.parentElement, depth = 0; node && depth < 7; node = node.parentElement, depth++) scopes.push(node);
    const candidates = [...new Set(scopes.flatMap(scope => [...scope.querySelectorAll('[role="button"],button')]))]
      .filter(button => {
        const rect = button.getBoundingClientRect();
        if (!rect.width || !rect.height || button.getAttribute('aria-disabled') === 'true' || button.disabled) return false;
        const label = `${button.getAttribute('aria-label') || ''} ${button.getAttribute('title') || ''} ${textOf(button)}`.trim();
        const aligned = rect.left >= editorRect.left + editorRect.width * 0.55
          && rect.top <= editorRect.bottom + 30 && rect.bottom >= editorRect.top - 30;
        return aligned && (/傳送|送出|留言|回覆|send|post|comment|reply/i.test(label) || label === '');
      })
      .sort((a, b) => {
        const ar = a.getBoundingClientRect(), br = b.getBoundingClientRect();
        return Math.abs(ar.left - editorRect.right) - Math.abs(br.left - editorRect.right);
      });
    const submitButton = candidates[0] || null;
    if (!submitButton) return false;
    await trustedFacebookClick(submitButton);
    return Boolean(await waitFor(() => !editor.isConnected || normalizedEditorText(editor) === '', timeoutMs));
  }
  async function chooseFacebookDate(scheduleDialog, isoDate) {
    // Resolve and verify the live accessibility field after the debugger is
    // attached. Facebook often replaces the dialog DOM while the calendar is
    // opening, which made the previous cached element report a blank date even
    // though the visible control had already been filled.
    const result = await chrome.runtime.sendMessage({type:'BAOHUI_TRUSTED_DATE', value:isoDate});
    if (!result?.ok || result.verifiedValue !== isoDate) {
      throw new Error(result?.error || ('Facebook 日期驗證未完成：要求 ' + isoDate + '，讀回 ' + (result?.verifiedValue || '空白')));
    }
    return true;
  }
  async function chooseFacebookTime(scheduleDialog, hhmm) {
    // Use Chrome's live accessibility node, set the field directly, then read
    // it back. Facebook accepts this path reliably even when its virtual list
    // omits labels or renders a different scroll position.
    const result = await chrome.runtime.sendMessage({type:'BAOHUI_TRUSTED_TIME', value:hhmm});
    if (!result?.ok || result.verifiedValue !== hhmm) {
      throw new Error(result?.error || ('Facebook 時間驗證未完成：要求 ' + hhmm + '，讀回 ' + (result?.verifiedValue || '空白')));
    }
    return result.verifiedValue;
  }
  async function authorizeAuctionSubmit(payload) {
    if (!['auction_listing','auction_reminder'].includes(payload.kind)) return;
    const result = await chrome.runtime.sendMessage({type:'BAOHUI_VALIDATE_AUCTION',phase:'submit',payload});
    if (!result || result.ok !== true) throw new Error(result?.error || '送出前無法核准任務，已停止');
  }
  function findFacebookScheduleDialog() {
    const headings = [...document.querySelectorAll('h1,h2,h3,[role="heading"]')].filter(el => el.getClientRects().length && /^(設定貼文發佈時間|設定貼文發布時間|Schedule post)$/i.test(textOf(el).trim()));
    for (const heading of headings) {
      for (let scope = heading.parentElement; scope && scope !== document.body; scope = scope.parentElement) {
        if (scope.getClientRects().length && scope.querySelector('[role="combobox"],input') && [...scope.querySelectorAll('button,[role="button"]')].some(el => /^(排定時間|Schedule)$/i.test(textOf(el).trim()))) return scope;
      }
    }
    const modal = [...document.querySelectorAll('[role="dialog"],[aria-modal="true"]')].find(el => el.getClientRects().length && /設定貼文發佈時間|設定貼文發布時間|Schedule post/i.test(textOf(el)));
    if (modal) return modal;
    // New Facebook layout sometimes removes role=dialog and renders the panel
    // inside the composer form. Pick the smallest visible scope that owns both
    // schedule comboboxes and the enabled/disabled confirmation button.
    const candidates = [...document.querySelectorAll('form,main,section,div')].filter(el => {
      if (!el.getClientRects().length) return false;
      const controls = [...el.querySelectorAll('[role="combobox"],input')].filter(node => node.getClientRects().length);
      if (controls.length < 2) return false;
      return [...el.querySelectorAll('button,[role="button"]')].some(node => node.getClientRects().length && /^(排定時間|Schedule)$/i.test(textOf(node).trim()));
    });
    candidates.sort((a, b) => a.querySelectorAll('*').length - b.querySelectorAll('*').length);
    return candidates[0] || null;
  }
  async function scheduleFacebookPost(dialog, payload) {
    const match = String(payload.scheduledAt || '').trim().match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})/);
    if (!match) throw new Error('缺少可用的 Facebook 預約上架時間');
    const requestedAt = new Date(match[1]+'T'+match[2]+':00+08:00');
    if (!Number.isFinite(requestedAt.getTime()) || requestedAt.getTime() <= Date.now()) throw new Error('預約公開時間已過，請重新選擇；不會改成立即發布');
    const scheduleDate = match[1];
    let scheduleTime = match[2];
    const findScheduleButton = () => [...dialog.querySelectorAll('[role="button"],button')].find(element => element.getClientRects().length && /設定貼文發佈時間|設定貼文發布時間|Schedule post/i.test(textOf(element)) && element.getAttribute('aria-disabled') !== 'true' && !element.disabled);
    const scheduleButton = await waitFor(findScheduleButton, 15000);
    if (!scheduleButton) throw new Error('找不到 Facebook「設定貼文發佈時間」按鈕');
    await trustedFacebookClick(scheduleButton, 'mouse', '設定貼文發佈時間', 'button');
    let scheduleDialog = await waitFor(findFacebookScheduleDialog, 12000);
    if (!scheduleDialog) {
      const retryButton = findScheduleButton();
      if (retryButton) await trustedFacebookClick(retryButton, 'mouse');
      scheduleDialog = await waitFor(findFacebookScheduleDialog, 20000);
    }
    if (!scheduleDialog) throw new Error('Facebook 預約時間視窗沒有開啟');
    const dateChosen = await chooseFacebookDate(scheduleDialog, scheduleDate);
    if (!dateChosen) throw new Error(`Facebook 日期選擇失敗：未選中 ${scheduleDate}`);
    const timeChosen = await chooseFacebookTime(scheduleDialog, scheduleTime);
    if (!timeChosen) throw new Error(`Facebook 時間選擇失敗：未選中 ${scheduleTime}`);
    scheduleTime = timeChosen;
    payload.scheduledAt = `${scheduleDate} ${scheduleTime}`;
    if (payload.scheduledWindow && typeof payload.scheduledWindow === 'object') payload.scheduledWindow.start = payload.scheduledAt;
    const latestScheduleDialog = await waitFor(findFacebookScheduleDialog, 15000);
    if (!latestScheduleDialog) throw new Error('Facebook 時間選取後找不到最新排程視窗');
    latestScheduleDialog.querySelector('h2,[role="heading"]')?.focus?.();
    const confirmTime = await waitFor(() => [...latestScheduleDialog.querySelectorAll('[role="button"],button')].find(element => /^(排定時間|Schedule)$/i.test(textOf(element)) && element.getAttribute('aria-disabled') !== 'true'), 10000);
    if (!confirmTime) throw new Error('Facebook 未接受預約日期或時間');
    await authorizeAuctionSubmit(payload);
    // Persist lookup-only intent before the irreversible click; a missing toast is not proof of failure.
    sessionStorage.setItem('baohuiScheduledLinkLookup', String(payload.taskId || payload.scheduleId || '1'));
    await trustedFacebookClick(confirmTime, 'mouse', '排定時間', 'button');
    const scheduled = await waitFor(() => {
      const modalGone = !document.contains(latestScheduleDialog) || !latestScheduleDialog.getClientRects().length;
      const confirmation = /系統已經設定貼文發佈時間|系統已經設定貼文發布時間|post has been scheduled/i.test(document.body.innerText || '');
      return modalGone || confirmation;
    }, 15000);
    // Facebook may keep/reuse the dialog after accepting the reservation.
    // Reconcile the unique existing scheduled card regardless of transient acknowledgement.
    if (!scheduled) showNotice('預約送出結果待核對，正在查詢既有貼文；不會重送。');
    showNotice(`已送入 Facebook 預約排程：${scheduleDate} ${scheduleTime}；正在取得預約貼文連結…`);
    sessionStorage.setItem('baohuiScheduledLinkLookup', String(payload.taskId || payload.scheduleId || '1'));
    const groupMatch = String(payload.groupUrl || '').match(/^(https?:\/\/(?:www\.)?facebook\.com\/groups\/[^/?#]+)/i);
    if (!groupMatch) throw new Error('無法建立 Facebook 預約貼文查詢網址');
    location.assign(`${groupMatch[1]}/scheduled_posts/`);
    await new Promise(() => {});
  }

  function scrollFacebookScheduledList() {
    const heading = [...document.querySelectorAll('h1,h2,h3,[role="heading"]')].find(el => el.getClientRects().length && /排定發佈的貼文|Scheduled posts/i.test(textOf(el)));
    for (let scope = heading && heading.parentElement; scope && scope !== document.body; scope = scope.parentElement) {
      if (scope.clientHeight > 100 && scope.scrollHeight > scope.clientHeight + 10 && /auto|scroll/.test(getComputedStyle(scope).overflowY)) {
        scope.scrollBy(0, Math.max(1200, scope.clientHeight * 1.6));
        return;
      }
    }
    window.scrollBy(0, Math.max(1200, window.innerHeight * 1.6));
  }
  let facebookScheduledSnapshotSyncBusy = false;
  async function syncFacebookScheduledSnapshot() {
    // Do not race the unique-permalink search: both routines scroll Facebook's
    // virtualized list, and the background scan can move the target away.
    if (facebookScheduledSnapshotSyncBusy || pendingClaimStarted) return;
    facebookScheduledSnapshotSyncBusy = true;
    try {
    if (!/\/groups\/onecheep\/scheduled_posts\/?$/i.test(location.pathname)) return;
    const heading = await waitFor(() => [...document.querySelectorAll('h1,h2,h3,[role="heading"]')]
      .find(el => el.getClientRects().length && /排定發佈的貼文[\s·•]*\d+|Scheduled posts[\s·•]*\d+/i.test(textOf(el))), 12000);
    if (!heading) return;
    const countMatch = textOf(heading).replace(/,/g,'').match(/(\d+)/);
    const total = Math.max(0, Number(countMatch && countMatch[1] || 0));
    if (!total) return;
    // Each scan must cover the list from its beginning. Facebook keeps this
    // long virtualized page at the prior scan's scroll position otherwise.
    window.scrollTo(0, 0);
    await sleep(400);
    const seen = new Set();
    const observedPosts = new Map();
    for (let pass = 0; pass < 180 && location.pathname.includes('/scheduled_posts'); pass++) {
      for (const button of document.querySelectorAll('[role="button"],button')) {
        if (!/^(查看更多|See more)$/i.test(String(button.textContent || '').trim())) continue;
        if (button.getClientRects().length) button.click();
      }
      for (const anchor of document.querySelectorAll('a[href*="set=pcb."],a[href*="/posts/"],a[href*="story_fbid="]')) {
        const id = postIdFromUrl(anchor.href || '');
        if (!id) continue;
        seen.add(id);
        let card = anchor.closest('[role="article"],[role="listitem"]');
        if (!card) {
          for (let scope = anchor.parentElement; scope && scope !== document.body; scope = scope.parentElement) {
            const text = String(scope.textContent || '');
            if (/一元競標/.test(text) && /結標[：:]/.test(text)) { card = scope; break; }
          }
        }
        const cardText = String(card && card.textContent || '').replace(/\s+/g, ' ').trim();
        if (cardText) observedPosts.set(id, {postId:id, text:cardText.slice(0, 8000)});
      }
      if (seen.size >= total) break;
      if (pass % 2 === 1) scrollFacebookScheduledList();
      await sleep(700);
      if (!document.contains(heading)) return;
    }
    chrome.runtime.sendMessage({type:'BAOHUI_FACEBOOK_SCHEDULED_SNAPSHOT', payload:{
      total, postIds:[...seen], posts:[...observedPosts.values()], observedAt:new Date().toISOString(), visibleIdentity:'Kuo Feng Cheng'
    }}).catch(() => {});
    } finally {
      facebookScheduledSnapshotSyncBusy = false;
    }
  }
  async function captureScheduledPostLink(payload) {
    showNotice('正在切換為 Kuo Feng Cheng，再核對預約排程與貼文連結…');
    const verifiedIdentity = await ensureRequiredIdentity(payload, document.body);
    await closeAccountMenu();
    showNotice(`競標身分已核對：${verifiedIdentity}；正在預約排程中核對產品圖片與貼文連結…`);
    if (!/\/groups\/[^/]+\/scheduled_posts\/?$/i.test(location.pathname)) {
      const groupMatch = String(payload.groupUrl || location.href).match(/^(https?:\/\/(?:www\.)?facebook\.com\/groups\/[^/?#]+)/i);
      if (!groupMatch) throw new Error('無法進入 Facebook 預約排程清單');
      location.assign(`${groupMatch[1]}/scheduled_posts/`);
      await new Promise(() => {});
    }
    const needle = String(payload.listingSerial || '').trim();
    if (!needle) throw new Error('缺少本排程識別碼，不能以同商品其他貼文代替');
    const productTitleNeedle = String(payload.productTitle || '').trim();
    const closeNeedle = String(payload.closeAt || payload.scheduledWindow?.end || '').trim().slice(0, 16);
    let lookupPass = 0;
    const card = await waitFor(() => {
      // Facebook collapses the serial below See more on scheduled cards.
      // Expand text only; never publish or accept a same-product substitute.
      for (const button of document.querySelectorAll('[role="button"],button')) {
        if (!/^(查看更多|See more)$/i.test(String(button.textContent || '').trim())) continue;
        const rect = button.getBoundingClientRect();
        if (rect.height && rect.bottom > 0 && rect.top < window.innerHeight) button.click();
      }

      const cards = [...document.querySelectorAll('[role="article"], [role="listitem"]')].filter(item => item.getClientRects().length);
      const semanticCard = cards.find(item => needle && String(item.textContent || '').includes(needle) && new Set([...item.querySelectorAll('a[href]')].map(a => postIdFromUrl(normalizeFacebookPostUrl(a.href,payload))).filter(Boolean)).size === 1);
      if (semanticCard) return semanticCard;
      const visualCards = [...document.querySelectorAll('div')].filter(item => {
        if (!needle || !String(item.textContent || '').includes(needle) || !item.getClientRects().length) return false;
        const ids = new Set([...item.querySelectorAll('a[href]')].map(a => postIdFromUrl(normalizeFacebookPostUrl(a.href,payload))).filter(Boolean));
        return ids.size === 1;
      });
      visualCards.sort((a, b) => (a.textContent || '').length - (b.textContent || '').length);
      if (visualCards[0]) return visualCards[0];
      // The schedule serial is below Facebook's collapsed text and "See more"
      // sometimes does not hydrate it.  A product title plus its exact close
      // timestamp is also unique in the backend schedule validator.  Accept
      // this fallback only when the visible list contains one post id.
      if (lookupPass >= 8 && productTitleNeedle && closeNeedle) {
        const fallbackByPostId = new Map();
        for (const item of document.querySelectorAll('[role="article"], [role="listitem"], div')) {
          if (!item.getClientRects().length) continue;
          const text = String(item.textContent || '');
          if (!text.includes(productTitleNeedle) || !text.includes(closeNeedle)) continue;
          const links = [...item.querySelectorAll('a[href]')]
            .map(a => a.href)
            .filter(href => postIdFromUrl(normalizeFacebookPostUrl(href, payload)));
          const ids = new Set(links.map(href => postIdFromUrl(normalizeFacebookPostUrl(href, payload))).filter(Boolean));
          if (ids.size !== 1) continue;
          const id = [...ids][0];
          const previous = fallbackByPostId.get(id);
          if (!previous || text.length < String(previous.textContent || '').length) fallbackByPostId.set(id, item);
        }
        if (fallbackByPostId.size === 1) return [...fallbackByPostId.values()][0];
      }
      // Scheduled posts are lazy-loaded. Searching only the initially rendered
      // cards misses later products even after Facebook created them correctly.
      lookupPass++;
      if (lookupPass % 2 === 0) {
        const controls = [...document.querySelectorAll('[role="button"],button')];
        const more = controls.find(item => item.getClientRects().length && /^(顯示更多|載入更多|Load more)$/i.test(textOf(item)));
        if (more) more.click();
        // Keep moving forward. Returning to the last hydrated photo repeatedly
        // traps lookup on the first expanded post and never reaches later cards.
        scrollFacebookScheduledList();
      }
      return null;
    }, 90000);
    if (!card) throw new Error('預約排程中找不到對應的商品');
    const direct = [...card.querySelectorAll('a[href]')].map(a => a.href).find(href => postIdFromUrl(href));
    if (direct) {
      const postUrl = normalizeFacebookPostUrl(direct, payload);
      sessionStorage.removeItem('baohuiScheduledLinkLookup');
      chrome.runtime.sendMessage({type:'BAOHUI_FACEBOOK_SCHEDULED', payload:{...payload, ok:true, scheduled:true, postUrl, postId:postIdFromUrl(postUrl)}});
      return;
    }
    const image = card.querySelector('img');
    const opener = image && (image.closest('a[href],[role="link"],[role="button"]') || image);
    if (!opener) throw new Error('找到預約商品，但無法開啟產品圖片');
    opener.click();
    const postUrl = await waitFor(() => {
      const current = location.href.split('#')[0];
      if (postIdFromUrl(current)) return normalizeFacebookPostUrl(current, payload);
      return ''; 
    }, 20000);
    if (!postUrl) throw new Error('已開啟產品圖片，但尚未取得預約貼文連結');
    sessionStorage.removeItem('baohuiScheduledLinkLookup');
    chrome.runtime.sendMessage({type:'BAOHUI_FACEBOOK_SCHEDULED', payload:{...payload, ok:true, scheduled:true, postUrl, postId:postIdFromUrl(postUrl)}});
  }
  function enforceWeekendAuctionClose(payload) {
    if (!payload || !['auction_listing','auction_reminder'].includes(payload.kind)) return payload;
    const oldClose = String(payload.closeAt || payload.scheduledWindow?.end || '').trim();
    const match = oldClose.match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})/);
    if (!match) return payload;
    const noon = new Date(`${match[1]}T12:00:00`);
    if (![0, 6].includes(noon.getDay()) || match[2] === '23:59') return payload;
    const corrected = `${match[1]} 23:59`;
    payload.closeAt = corrected;
    if (payload.scheduledWindow && typeof payload.scheduledWindow === 'object') payload.scheduledWindow.end = corrected;
    if (typeof payload.copy === 'string') payload.copy = payload.copy.split(oldClose).join(corrected);
    if (payload.copy && typeof payload.copy === 'object' && typeof payload.copy.text === 'string') payload.copy.text = payload.copy.text.split(oldClose).join(corrected);
    return payload;
  }
  function enforceAuctionMinimumPricing(payload) {
    if (!payload || !['auction_listing','auction_reminder'].includes(payload.kind)) return payload;
    // Only new listings may be normalised to the current minimum.  A reminder
    // must honour the rules already published on its original Facebook post.
    if (payload.kind === 'auction_reminder') return payload;
    const normalize = text => String(text || '')
      .replace(/(起標價\s*[：:]\s*NT\$\s*)(?:1|10)(?!\d)/g, (_m, prefix) => `${prefix}30`)
      .replace(/(每筆加價[^\n]{0,40}?NT\$\s*)(?:1|10)(\s*[～~-]\s*NT\$\s*2,?000)/g, (_m, prefix, suffix) => `${prefix}30${suffix}`)
      .replace(/(每次(?:至少|最低)[^0-9\n]{0,20})(?:1|10)(?!\d)/g, (_m, prefix) => `${prefix}30`)
      .replace(/(Giá khởi điểm\s*[：:]\s*NT\$\s*)(?:1|10)(?!\d)/gi, (_m, prefix) => `${prefix}30`);
    payload.startPrice = Math.max(30, Number(payload.startPrice || 30));
    payload.bidStep = Math.max(30, Number(payload.bidStep || 30));
    if (typeof payload.copy === 'string') payload.copy = normalize(payload.copy);
    if (payload.copy && typeof payload.copy === 'object' && typeof payload.copy.text === 'string') payload.copy.text = normalize(payload.copy.text);
    return payload;
  }
  function formatAuctionListingCopy(payload) {
    if (!payload || payload.kind !== 'auction_listing' || typeof payload.copy !== 'string') return payload;
    const raw = payload.copy.replace(/\r\n?/g, '\n').trim();
    if (!raw) return payload;
    const sourceLines = raw.split('\n');
    const output = [];
    for (let index = 0; index < sourceLines.length; index += 1) {
      let line = sourceLines[index].trim();
      if (!line || /^TW\s*【中文】$/i.test(line)) continue;
      // Some queue payloads already prepend the auction heading.  The formatter
      // adds the canonical heading below, so drop any incoming copy here to
      // keep exactly one title in the Facebook editor.
      if (/^🏴‍☠️\s*一元競標(?:\s*｜.*)?$/u.test(line)) continue;
      line = line.replace(/^\d+\.\s*/, '');
      if (/產品編號跟名稱/.test(line)) continue;
      if (/^(?:⏰\s*)?結標時間\s*$/.test(line)) {
        let value = '';
        while (index + 1 < sourceLines.length && !value) value = sourceLines[++index].trim();
        if (value) output.push(`⏰ 結標：${value}`);
        continue;
      }
      if (/產品出價規則/.test(line)) {
        output.push('', '💰【出價規則】');
        continue;
      }
      output.push(line);
    }
    const title = String(payload.productTitle || '').trim();
    const heading = `🏴‍☠️ 一元競標${title ? `｜${title}` : ''}`;
    payload.copy = [heading, '', ...output]
      .join('\n')
      .replace(/\n{3,}/g, '\n\n')
      .trim();
    return payload;
  }
  async function prepare(payload) {
    // Preserve the exact dates selected in the backend.
    payload = enforceAuctionMinimumPricing(payload);
    payload = formatAuctionListingCopy(payload);
    const currentPath = location.pathname.replace(/\/+$/, '').toLowerCase();
    let targetPath = '';
    try { targetPath = new URL(payload.recoveryOnly === true && payload.recoveryPostUrl ? payload.recoveryPostUrl : payload.groupUrl).pathname.replace(/\/+$/, '').toLowerCase(); } catch (error) {}
    const pageText = `${document.title || ''} ${document.querySelector('h1')?.innerText || ''}`;
    const isAuctionTask = payload.kind === 'auction_listing' && payload.contentType === 'facebook_auction_listing';
    const isAuctionPage = /\/groups\/onecheep(?:\/|$)/i.test(currentPath) || /海賊|一元競標|競標場|大家來喊價/i.test(pageText);
    if (isAuctionTask && !/\/groups\/onecheep(?:\/|$)/i.test(targetPath)) {
      throw new Error('競標任務只能發布到指定的一元競標社團');
    }
    if (!isAuctionTask && (isAuctionPage || /\/groups\/onecheep(?:\/|$)/i.test(targetPath))) {
      throw new Error('海賊團／一元競標場只供競標，已阻止一般廣告帶入');
    }
    activePayload = payload;
    showNotice('寶輝發文助手：正在核對 Facebook 身分與目標社團…');
    const verifiedIdentity = await ensureRequiredIdentity(payload, document.body);
    await closeAccountMenu();
    showNotice(`發布身分已核對：${verifiedIdentity}`);
    const verifiedPath = location.pathname.replace(/\/+$/, '').toLowerCase();
    if (payload.destinationType !== 'fan_page' && (!targetPath || verifiedPath !== targetPath)) {
      if (!targetPath || !/^\/groups\//i.test(targetPath)) throw new Error('指定的 Facebook 社團網址無效，已停止帶入');
      showNotice(`身分切換完成；正在回到「${payload.targetName || '指定社團'}」繼續發文…`);
      location.assign(payload.recoveryOnly === true && payload.recoveryPostUrl ? payload.recoveryPostUrl : payload.groupUrl);
      await new Promise(() => {});
      return;
    }
    if (/\/groups\//i.test(location.pathname)) {
      const visiblePageText = String(document.body?.innerText || '');
      if (/(^|\n)加入社團(\n|$)|(^|\n)Join group(\n|$)/iu.test(visiblePageText)) {
        throw new Error('目前發文身分尚未加入這個社團');
      }
      if (/入社審核|加入申請正在審核中|你快要能夠發佈貼文和留言了|回答這些問題，協助管理員瞭解你|我同意遵守社團規則|membership questions|answer these questions/iu.test(visiblePageText)) {
        throw new Error('目前發文身分尚未完成社團入社審核，沒有發文權限');
      }
    }
    const isAdvertisingTask = payload.kind === 'advertising_post' || payload.contentType === 'facebook_advertising_post';
    if(isAdvertisingTask){
      if(payload.protocol!==2||!payload.autoPublish)throw Error('請更新後台及助手：舊版廣告禁止送出');
      const claimant=crypto.randomUUID();
      const claimed=await chrome.runtime.sendMessage({type:'AD_V2_IDENTITY_READY',observed:{identity:verifiedIdentity,groupUrl:location.origin+location.pathname.replace(/\/$/,''),claimant}});
      if(claimed?.duplicate){
        showNotice('同一工作分頁已由另一個助手流程接管；本流程不重複送出。');
        return;
      }
      if(!claimed?.ok)throw Error(claimed?.error||'無法取得此筆廣告執行權');
      payload={...payload,...claimed.binding,imageUrls:[claimed.binding.imageUrl],_adClaimant:claimant};activePayload=payload;
    }
    // A retry count by itself only means the work tab or profile switch did
    // not finish before the content script claimed the task.  No composer or
    // publish click exists at that point, so it is safe to continue.  Enter
    // lookup-only recovery only when the task explicitly carries evidence
    // that a Facebook submission may already have happened.
    const isAdvertisingRecovery = isAdvertisingTask && (
      payload.recoveryOnly === true ||
      payload.lookupOnly === true ||
      payload.outcomeUnknown === true ||
      payload.submissionAccepted === true
    );
    if (isAdvertisingRecovery) {
      showNotice('重試前先核對社團是否已有同一篇廣告，避免重複發佈…');
      const existing = await findAdvertisingPermalink(payload, 10000);
      if (existing.postUrl && existing.postId) {
        reportSent = true;
        showNotice('已找到同一則可見貼文；本輪只補後台回寫，不重複發佈。');
        chrome.runtime.sendMessage({type: 'BAOHUI_FACEBOOK_POST_CLICKED', payload: {
          ...payload,
          ...existing,
          ok: true,
          verifiedIdentity,
          verifiedVisible: true,
          recoveredExistingPost: true
        }});
        return;
      }
      // An uncertain submit is lookup-only; absence from the current feed does
      // not prove that Facebook rejected it.
      throw new Error('廣告送出結果待核對：未找到正式連結，禁止自動重發');
    }
    const trigger = await waitFor(findPostComposerTrigger, 20000);
    if (!trigger) {
      if (!/\/groups\//i.test(location.pathname)) throw new Error('目前登入身分在這個個人／粉專頁沒有發文入口。請改填可發文的 Facebook 社團網址，或先切換成可管理該粉專的身分');
      if (/待審內容已達到數量上限/u.test(document.body?.innerText || '')) throw new Error('這個社團的待審內容已達到數量上限');
      throw new Error('這個社團目前沒有顯示發文入口，可能尚未加入、被限制發文，或網址不是社團首頁');
    }
    assertRequiredIdentity(payload, trigger, verifiedIdentity);
    await trustedFacebookClick(trigger);
    let dialog = await waitFor(findOpenPostComposer, 30000);
    if (!dialog && trigger.isConnected && trigger.getClientRects().length) {
      // The first trusted coordinate click can miss Facebook's moving group
      // composer. Retry only this harmless opener through its AX button; no
      // text or publish action has happened at this stage.
      await trustedFacebookClick(trigger, 'ax', textOf(trigger).trim(), 'button');
      dialog = await waitFor(findOpenPostComposer, 12000);
    }
    if (!dialog) throw new Error('Facebook 發文框沒有開啟');
    assertRequiredIdentity(payload, dialog, verifiedIdentity);
    const editor = await waitFor(() => dialog.querySelector('[contenteditable="true"][role="textbox"],div[contenteditable="true"]'));
    if (!editor) throw new Error('找不到 Facebook 文案輸入區');
    await insertMultilineText(editor, payload.copy || '');
    // Escape can close the entire composer when no suggestions are open.
    // Move focus within the composer instead of dismissing a dialog.
    editor.blur();
    dialog.querySelector('h2,[role="heading"]')?.click();
    await attachImage(dialog, payload);
    if (payload.facebookNativeSchedule && payload.kind === 'auction_listing') {
      showNotice(`正在建立 Facebook 預約貼文：${payload.scheduledAt}`);
      await scheduleFacebookPost(dialog, payload);
    } else if (payload.autoPublish) {
      const isAdvertising = payload.kind === 'advertising_post' || payload.contentType === 'facebook_advertising_post';
      const minimumDelayMs = isAdvertising ? 5000 : 10000;
      const delayMs = Math.max(minimumDelayMs, Number(payload.publishDelayMs || minimumDelayMs));
      showNotice(`文案與圖片已帶入；本頁將在 ${Math.ceil(delayMs / 1000)} 秒後自動發佈。`);
      await sleep(delayMs);
      if (!document.contains(dialog) || !dialog.getClientRects().length) throw new Error('發文框已關閉，已取消本次自動發布');
      const publishButton = await waitFor(() => [...dialog.querySelectorAll('[role="button"],button')].find(element => /^(發佈|貼文|Post)$/i.test(textOf(element)) && element.getAttribute('aria-disabled') !== 'true'), 15000);
      if (!publishButton) throw new Error('圖片可能仍在處理，找不到可按的發佈按鈕；本頁已保留供人工檢查');
      assertRequiredIdentity(payload, dialog, verifiedIdentity);
      verifyEditorCopy(editor, payload.copy || '');
      showNotice('正在自動按下 Facebook「發佈」；完成後將在同一分頁繼續下一筆。');
      await authorizeAuctionSubmit(payload);
      if(isAdvertising){
        const expectedCopy=canonicalAdvertisingCopy(payload.copy||'');
        if(canonicalAdvertisingCopy(normalizedEditorText(editor))!==expectedCopy)throw Error('Facebook 文案與本輪核准全文不一致，已停止發布');
        payload.beforePostIds=[...document.querySelectorAll('a[href]')].map(a=>postIdFromUrl(a.href)).filter(Boolean);
        const gate=await chrome.runtime.sendMessage({type:'AD_V2_SUBMIT_READY',claimant:payload._adClaimant,actual:{groupUrl:location.origin+location.pathname.replace(/\/$/,''),requiredVisibleName:verifiedIdentity,materialId:payload.materialId,copy:payload.copy,imageUrl:payload.imageUrl,imageSha256:payload.uploadedImageSha256,beforePostIds:payload.beforePostIds}});
        if(gate?.duplicate)return;
        if(!gate?.allowed)throw Error(gate?.error||'後台尚未允許送出，禁止點擊發布');
      }
      await trustedFacebookClick(publishButton);
      if (isAdvertising && !reportSent) {
        reportSent = true;
        await sleep(10000);
        const rateLimited = /我們會限制你在特定期間內發佈貼文|限制.*發佈貼文|temporarily limited.*post/i.test(document.body.innerText || '');
        if (rateLimited) {
          showNotice('Facebook 已明確拒絕本次發文；所有廣告帳號將停止 2 小時。', true);
          await chrome.runtime.sendMessage({type:'BAOHUI_AUTO_FAILED', payload:{...payload, ok:false, error:'facebook_rate_limit_30m'}});
          return;
        }
        // Facebook may expose a permalink to the author even while the post
        // is awaiting group-admin approval. Capture the submit acknowledgement
        // before permalink lookup can navigate or dismiss its transient toast.
        const pendingReviewAtSubmit = advertisingPendingReviewVisible();
        const link = await findAdvertisingPermalink(payload, 45000);
        const pendingReview = pendingReviewAtSubmit || advertisingPendingReviewVisible();
        if(!link.postId&&!pendingReview){
          sessionStorage.setItem(advertisingPostSubmitLookupKey(payload),String(Date.now()));
          const searchUrl=advertisingGroupSearchUrl(payload);
          if(searchUrl){
            showNotice('發布結果尚未顯示；正在同一分頁進入社團搜尋正式連結。');
            location.assign(searchUrl);
            await new Promise(()=>{});
            return;
          }
        }
        const ack=await chrome.runtime.sendMessage({type:'AD_V2_RESULT',claimant:payload._adClaimant,evidence:{post_url:link.postUrl||'',post_id:link.postId||'',verified_identity:verifiedIdentity,verified_visible:Boolean(link.postUrl&&link.postId&&!pendingReview),pendingReview}});
        if(!ack?.ok)throw Error(ack?.error||'結果未保存，禁止重發');
      }
    } else {
      showNotice('文案與圖片已帶入。請人工檢查內容，確認後只要按 Facebook「發佈」。');
    }
  }
  function advertisingPendingReviewVisible() {
    const text = String(document.body?.innerText || '');
    return /等待管理員核准|等待管理員批准|貼文待審核|等待社團管理員審核|pending approval|submitted to group admins for approval|submitted for approval|awaiting admin approval/i.test(text);
  }
  function postIdFromUrl(url) {
    const value = String(url || '');
    // Facebook photo links put the *photo* fbid before set=pcb.POST_ID.
    // Match in semantic priority order, never by the leftmost URL position.
    for (const pattern of [/[?&]set=(?:gm|pcb)\.(\d+)/i, /\/posts\/(\d+)/i,
      /\/permalink\/(\d+)/i, /[?&]story_fbid=(\d+)/i, /[?&]fbid=(\d+)/i]) {
      const match = value.match(pattern);
      if (match) return match[1];
    }
    return '';
  }
  function normalizeFacebookPostUrl(url, payload) {
    const value = String(url || '').replace(/&__cft__\[[^\]]+\]=[^&]*/g, '').replace(/&__tn__=[^&]*/g, '');
    const pcb = (value.match(/[?&]set=(?:gm|pcb)\.(\d+)/i) || [])[1];
    if (!pcb) return value;
    const group = String(payload?.groupUrl || '').match(/facebook\.com\/groups\/([^/?#]+)/i);
    return group ? `https://www.facebook.com/groups/${group[1]}/posts/${pcb}/` : value;
  }
  function articleMatchesPublishedAuction(article, payload) {
    const text = textOf(article).replace(/\s+/g, ' ').trim();
    const serial = String(payload.listingSerial || '').replace(/^#/, '').toUpperCase();
    const product = String(payload.productId || '').trim();
    const qty = Number(payload.lotQuantity || 0);
    const expected = (payload.productSerialSuffixes || []).map(v => String(v).trim().toUpperCase()).sort();
    const close = String(payload.closeAt || '').replace('T', ' ').slice(0,16);
    if (!serial || !product || !Number.isInteger(qty) || qty < 1 || expected.length !== qty || !close) return false;
    if (!text.toUpperCase().includes('#' + serial) || !text.includes(product) || !text.includes(close)) return false;
    const quantity = text.match(/一標\s*(\d+)/u);
    if (!quantity || Number(quantity[1]) !== qty) return false;
    // Warranty binding repeats the same serial; it is not another physical
    // item. Read only the explicit product-serial field, keeping exact count.
    const section = text.match(/產品序號後六碼[:：]\s*([\s\S]*?)(?:序號[／/]保固綁定|排程識別碼)/u);
    const found = section ? (section[1].toUpperCase().match(/\b[A-Z0-9]{6}\b/g) || []).sort() : [];
    if (JSON.stringify(found) !== JSON.stringify(expected)) return false;
    return !!article.querySelector('img');
  }
  async function findPostedPermalink(payload, timeoutMs = 60000) {
    const end = Date.now() + Math.max(1000, Number(timeoutMs || 60000));
    while (Date.now() < end) {
      const articles = [...document.querySelectorAll('[role="article"]')];
      const article = articles.find(item => articleMatchesPublishedAuction(item, payload)) || null;
      if (article) {
        const anchors = [...article.querySelectorAll('a[href]')].map(a => a.href).filter(Boolean);
        const url = anchors.find(href => /\/posts\/\d+|\/permalink\/\d+|set=pcb\.\d+|story_fbid=\d+|fbid=\d+/i.test(href));
        if (url) {
          const postUrl = normalizeFacebookPostUrl(url, payload);
          return {postUrl, postId: postIdFromUrl(postUrl)};
        }
      }
      await sleep(800);
    }
    return {postUrl: '', postId: ''};
  }
  async function advertisingPermalinkRemainsVisible(payload, candidate, delayMs = 15000) {
    if (!candidate?.postId || !candidate?.postUrl) return false;
    await sleep(Math.max(10000, Number(delayMs || 15000)));
    const pageText = String(document.body?.innerText || '');
    if (/目前無法查看此內容|此內容目前無法顯示|已移除|已遭拒絕|等待.*批准|待審核|審核中|This content isn.t available|removed content|pending approval/i.test(pageText)) return false;
    if (/\/my_removed_content\/?$|\/pending_posts\/?$|\/my_pending_content\/?$/i.test(location.pathname)) return false;
    const expectedIdentity = String(payload?.requiredVisibleName || '').trim();
    const firstLine = String(payload?.copy || '').split(/\r?\n/).map(line => line.trim()).find(line => line.length >= 8) || '';
    const normalizeCopy = value => String(value || '').replace(/[^\p{L}\p{N}]/gu, '').toLowerCase();
    const copyNeedle = normalizeCopy(firstLine).slice(0, 36);
    const matchingLinks = [...document.querySelectorAll('a[href]')].filter(link => postIdFromUrl(link.href) === String(candidate.postId));
    for (const link of matchingLinks) {
      let card = link.parentElement;
      for (let depth = 0; card && depth < 18; depth += 1, card = card.parentElement) {
        const text = textOf(card);
        const author = [...card.querySelectorAll('h2,h3,[role="heading"],a[href]')].some(node => textOf(node).trim() === expectedIdentity);
        if (author && copyNeedle && normalizeCopy(text).includes(copyNeedle)) return true;
      }
    }
    return false;
  }
  async function findAdvertisingPermalink(payload, timeoutMs = 30000) {
    const end = Date.now() + Math.max(1000, Number(timeoutMs || 30000));
    const expectedIdentity = String(payload?.requiredVisibleName || '').trim();
    const copyLines = String(payload?.copy || '').split(/\r?\n/).map(line => line.trim()).filter(line => line.length >= 8);
    const normalizeCopy = value => String(value || '').replace(/[^\p{L}\p{N}]/gu, '').toLowerCase();
    const copyNeedle = normalizeCopy(copyLines[0]).slice(0, 36);
    while (Date.now() < end) {
      const articles = [...document.querySelectorAll('[role="article"]')];
      const direct = String(payload?.recoveryPostUrl || '');
      if (payload?.recoveryOnly === true && direct && location.href.split('?')[0].replace(/\/$/,'') === direct.split('?')[0].replace(/\/$/,'')) {
        const dialog=[...document.querySelectorAll('[role="dialog"]')].find(d=>d.getAttribute('aria-label') === expectedIdentity+'的貼文' || [...d.querySelectorAll('h2,[role="heading"]')].some(h=>textOf(h).trim()===expectedIdentity+'的貼文'));
        if(dialog && copyNeedle && normalizeCopy(textOf(dialog)).includes(copyNeedle) && [...dialog.querySelectorAll('a')].some(a=>textOf(a).trim()===expectedIdentity) && !/等待.*批准|待審核|審核中|已遭拒絕/.test(textOf(dialog))) {
          const postUrl=normalizeFacebookPostUrl(direct,payload),postId=postIdFromUrl(postUrl);
          if(postId){
            const candidate={postUrl,postId};
            if(await advertisingPermalinkRemainsVisible(payload,candidate))return candidate;
          }
        }
      }
      // Facebook's current group feed often renders the new post as an
      // unlabelled generic card.  The media URL is still authoritative: for a
      // group post it contains set=gm.<post id>.  Bind that URL to the nearest
      // ancestor that contains both the exact posting identity and the first
      // line of this task's copy.  This is stricter than a page-wide scan and
      // avoids falling through to group search after a post is visibly live.
      for (const link of document.querySelectorAll('a[href*="set=gm."],a[href*="set=pcb."]')) {
        const href=String(link.href||'');
        const candidateId=postIdFromUrl(href);
        if(!candidateId||(payload.beforePostIds||[]).includes(candidateId))continue;
        let card=link.parentElement;
        for(let depth=0;card&&depth<18;depth++,card=card.parentElement){
          const cardText=textOf(card);
          const author=[...card.querySelectorAll('h2,h3,[role="heading"],a[href]')].some(node=>textOf(node).trim()===expectedIdentity);
          if(!author||!copyNeedle||!normalizeCopy(cardText).includes(copyNeedle))continue;
          // The set=gm/set=pcb URL is itself media evidence. Facebook often
          // omits or localises the fresh timestamp, and images may still have
          // naturalWidth=0 while the post card is already public. The ID was
          // absent from the pre-submit snapshot and the card is bound to the
          // exact identity + first copy line, so a timestamp is not required.
          const media=/[?&]set=(?:gm|pcb)\.\d+/i.test(href)
            || [...card.querySelectorAll('img')].some(img=>img.getClientRects().length&&img.naturalWidth>=120);
          if(payload.protocol===2&&!media)continue;
          const postUrl=normalizeFacebookPostUrl(href,payload),postId=postIdFromUrl(postUrl);
          if(postId){
            const candidate={postUrl,postId};
            if(await advertisingPermalinkRemainsVisible(payload,candidate))return candidate;
          }
        }
      }
      // Current Facebook feeds often use unlabelled divs, not role=article.
      // Bind the media link to the nearest single-author post, never the feed.
      for (const link of document.querySelectorAll('a[href*="set=gm."],a[href*="set=pcb."],a[href*="/posts/"]')) {
        let parent = link.parentElement;
        for (let depth = 0; parent && depth < 12; depth++, parent = parent.parentElement) {
          const headings = [...parent.querySelectorAll('h2,h3,[role="heading"]')];
          if (headings.length > 1) break;
          if (headings.length === 1 && textOf(headings[0]).trim() === expectedIdentity && normalizeCopy(textOf(parent)).includes(copyNeedle)) {
            articles.push(parent); break;
          }
        }
      }
      const matched = articles.filter(item => {
        const text = textOf(item);
        const author=[...item.querySelectorAll('h2,h3,[role="heading"],a[href]')].some(a=>textOf(a).trim()===expectedIdentity);
        return Boolean(copyNeedle) && author && normalizeCopy(text).includes(copyNeedle) && !/等待.*批准|待審核|審核中|已遭拒絕/.test(text);
      });
      for (const article of matched) {
        const anchors = [...article.querySelectorAll('a[href]')].map(a => a.href).filter(Boolean);
        const url = anchors.find(href => /\/posts\/\d+|\/permalink\/\d+|set=(?:gm|pcb)\.\d+|story_fbid=\d+|fbid=\d+/i.test(href)&&!(payload.beforePostIds||[]).includes(postIdFromUrl(href)));
        if (url) {
          const postUrl = normalizeFacebookPostUrl(url, payload);
          const postId = postIdFromUrl(postUrl);
          if(payload.protocol===2){
            const fresh=[...article.querySelectorAll('a,span,time')].some(a=>/^(剛剛|幾秒前|數秒前|just now|a moment ago|\d{1,2}\s*秒|海賊團剛剛|秒前|[1-3]\s*分鐘|\d{1,2}\s*seconds?|[1-3]\s*(?:min|mins|minutes?)(?:\s+ago)?)$/i.test(textOf(a).trim()));
            const media=[...article.querySelectorAll('img')].some(img=>img.getClientRects().length&&img.naturalWidth>=120)
              || [...article.querySelectorAll('a[href]')].some(a=>/\/photo\/?\?[^#]*\bfbid=\d+|[?&]set=(?:gm|pcb)\.\d+/i.test(String(a.href||'')))
              || [...article.querySelectorAll('[style]')].some(node=>/background-image\s*:\s*url\(/i.test(String(node.getAttribute('style')||'')));
            // Excluding the complete pre-submit ID snapshot is stronger than
            // Facebook's unstable/localised "just now" label. Keep media and
            // exact identity/copy binding mandatory, but do not reject a
            // public post only because that label is missing.
            if(!media)continue;
          }
          if (postId) {
            const candidate={postUrl,postId};
            if(await advertisingPermalinkRemainsVisible(payload,candidate))return candidate;
          }
        }
      }
      await sleep(800);
    }
    return {postUrl: '', postId: ''};
  }
  function advertisingPostSubmitLookupKey(payload){
    return `baohuiAdvertisingPostSubmitLookup:${String(payload?.task_date||'')}|${String(payload?.slot||'')}|${String(payload?.targetId||payload?.id||'')}`;
  }
  function advertisingGroupSearchUrl(payload){
    const group=String(payload?.groupUrl||'').match(/facebook\.com\/groups\/([^/?#]+)/i);
    const firstLine=String(payload?.copy||'').split(/\r?\n/).map(line=>line.trim()).find(line=>line.length>=8)||'';
    return group&&firstLine?`https://www.facebook.com/groups/${group[1]}/search/?q=${encodeURIComponent(firstLine)}`:'';
  }
  function publishedLookupKey(payload) {
    return `baohuiPublishedPermalinkLookup:${String(payload?.taskId || payload?.scheduleId || '')}`;
  }
  function lookupClearedForPublishKey(payload) {
    return `baohuiLookupClearedForPublish:${String(payload?.taskId || payload?.scheduleId || '')}`;
  }
  function groupSearchUrl(payload) {
    const groupMatch = String(payload?.groupUrl || '').match(/facebook\.com\/groups\/([^/?#]+)/i);
    // Published auction copy may intentionally omit the backend product id.
    // Search by the visible product title before falling back to the id.
    const query = String(payload?.listingSerial || payload?.productTitle || payload?.productId || '').trim();
    return groupMatch && query
      ? `https://www.facebook.com/groups/${groupMatch[1]}/search/?q=${encodeURIComponent(query)}`
      : '';
  }
  function facebookGroupSearchIsEmpty() {
    const pageText = String(document.body?.innerText || '');
    return /在這個社團找不到任何結果|找不到任何結果|No results found|We (?:did not|didn't) find any results/i.test(pageText);
  }
  function continueLookupOnlyToPublish(payload) {
    sessionStorage.removeItem(publishedLookupKey(payload));
    sessionStorage.removeItem(lookupClearedForPublishKey(payload));
    showNotice('這筆只允許核對舊貼文；目前未找到專屬網址，已交回後台待核對，不會重新上架。', true);
    chrome.runtime.sendMessage({
      type:'BAOHUI_AUTO_FAILED',
      payload:{...payload, ok:false, outcomeUnknown:true, error:'permalink_recovery_only_not_found'}
    });
  }
  async function recoverPublishedPermalink(payload) {
    if (payload.lookupOnly === true && /\/groups\/[^/]+\/search\//i.test(location.pathname)) {
      const searchState = await waitFor(() => {
        if (facebookGroupSearchIsEmpty()) return 'empty';
        if (document.querySelector('[role="article"]')) return 'results';
        return null;
      }, 8000);
      if (searchState === 'empty') {
        continueLookupOnlyToPublish(payload);
        return;
      }
      if (searchState === 'results') {
        const existingLink = await findPostedPermalink(payload, 10000);
        if (existingLink.postUrl) {
          sessionStorage.removeItem(publishedLookupKey(payload));
          chrome.runtime.sendMessage({type:'BAOHUI_FACEBOOK_POST_CLICKED', payload:{...payload, ...existingLink, ok:true}});
          return;
        }
      }
    }
    showNotice('正在切換為 Kuo Feng Cheng，再搜尋競標貼文…');
    const verifiedIdentity = await ensureRequiredIdentity(payload, document.body);
    await closeAccountMenu();
    showNotice(`競標身分已核對：${verifiedIdentity}；正在用產品編號與結標時間補抓貼文連結…`);
    let link = await findPostedPermalink(payload, 25000);
    if (link.postUrl) {
      sessionStorage.removeItem(publishedLookupKey(payload));
      chrome.runtime.sendMessage({type:'BAOHUI_FACEBOOK_POST_CLICKED', payload:{...payload, ...link, ok:true}});
      return;
    }
    const searchUrl = groupSearchUrl(payload);
    if (searchUrl && !/\/search\//i.test(location.pathname)) {
      sessionStorage.setItem(publishedLookupKey(payload), '1');
      location.assign(searchUrl);
      return;
    }
    link = await findPostedPermalink(payload, 60000);
    sessionStorage.removeItem(publishedLookupKey(payload));
    if (!link.postUrl && payload.lookupOnly === true) {
      continueLookupOnlyToPublish(payload);
      return;
    }
    chrome.runtime.sendMessage({
      type:'BAOHUI_FACEBOOK_POST_CLICKED',
      payload:{...payload, ...link, ok:true, error:link.postUrl ? '' : 'publish_clicked_permalink_pending_after_search'}
    });
  }
  function auctionRemaining(closeAt) {
    const closeTs = Date.parse(String(closeAt || '').replace(' ', 'T'));
    if (!Number.isFinite(closeTs)) return '';
    const minutes = Math.max(0, Math.ceil((closeTs - Date.now()) / 60000));
    if (minutes >= 60) return `約 ${Math.ceil(minutes / 60)} 小時`;
    return `${minutes} 分鐘`;
  }
  function rebuildValidAuctionBids(bids, opening, minimum, maximum) {
    const ordered = [...bids].sort((a,b) => a.timestamp - b.timestamp);
    const valid = [];
    for (let i=0; i<ordered.length; i++) {
      const bid = ordered[i];
      if (!Number.isFinite(bid.timestamp)) throw new Error('留言精確時間缺失，不能重建有效出價');
      if (i && bid.timestamp === ordered[i-1].timestamp && bid.amount !== ordered[i-1].amount) {
        throw new Error('同時點不同出價順序不明，最高有效價待核對');
      }
      const last = valid[valid.length-1];
      const allowed = Number.isFinite(bid.amount) && (last
        ? bid.amount-last.amount >= minimum && bid.amount-last.amount <= maximum
        : bid.amount >= opening && bid.amount-opening <= maximum);
      if (allowed) valid.push(bid);
    }
    return valid;
  }
  function readVisibleAuctionBids(article, payload) {
    const postText = textOf(article);
    const startMatch = postText.match(/起標價?[^0-9]{0,20}(?:NT\$|\$)?\s*([0-9][0-9,]*)/i);
    const incrementRangeMatch = postText.match(/(?:每(?:次|筆)加價|最低加價)[^0-9]{0,60}(?:NT\$|\$)?\s*([0-9][0-9,]*)\s*[～~－—-]\s*(?:NT\$|\$)?\s*([0-9][0-9,]*)/i);
    const minimumMatch = incrementRangeMatch || postText.match(/(?:每(?:次|筆)加價|最低加價)[^0-9]{0,60}(?:NT\$|\$)?\s*([0-9][0-9,]*)/i);
    const maximumMatch = incrementRangeMatch ? [incrementRangeMatch[0], incrementRangeMatch[2]] : postText.match(/(?:單次最高|最高加價)[^0-9]{0,30}(?:NT\$|\$)?\s*([0-9][0-9,]*)/i);
    const postedStart = startMatch ? Number(startMatch[1].replace(/,/g, '')) : 0;
    const postedMinimum = minimumMatch ? Number(minimumMatch[1].replace(/,/g, '')) : 0;
    const postedMaximum = maximumMatch ? Number(maximumMatch[1].replace(/,/g, '')) : 0;
    // The published post is the customer-facing auction contract. Prefer its
    // visible increment rules over an older backend default carried by a task.
    const minimum = Math.max(1, postedMinimum || Number(payload.bidStep || 30));
    const maximum = Math.max(minimum, postedMaximum || Number(payload.maximumIncrement || 2000));
    const violations = [];
    const seen = new Set();
    const bids = [];
    const nonBidderNames = new Set([
      requiredIdentity(payload),
      payload.ownerName,
      payload.publisherName,
      payload.posterName,
      'Kuo Feng Cheng'
    ].map(normalizeIdentityName).filter(Boolean).map(name => name.toLowerCase()));
    // Facebook often renders comments as siblings of the post inside the same
    // dialog, not as descendants of the post article. Read the whole active
    // post dialog so visible bids are not incorrectly reported as zero.
    const scope = article.closest('[role="dialog"]') || document;
    const candidates = [];
    const amountPattern = /^(?:NT\$|\$)?\s*[0-9][0-9,]{0,9}(?:\s*元)?$/i;
    const numericLeaves = [...scope.querySelectorAll('span,div,[dir="auto"]')].filter(item => {
      if (!item.getClientRects().length || !amountPattern.test(textOf(item))) return false;
      return ![...item.children].some(child => textOf(child) === textOf(item));
    });
    for (const leaf of numericLeaves) {
      let container = leaf;
      for (let depth = 0; depth < 7 && container && container !== scope; depth++, container = container.parentElement) {
        const value = textOf(container);
        const replyButton = [...container.querySelectorAll('[role="button"],button,a')].find(item => /^(?:回覆|Reply)$/i.test(textOf(item).trim()));
        if (replyButton && value.length < 500) {
          if (!candidates.includes(container)) candidates.push(container);
          break;
        }
      }
    }
    // Current Facebook comment markup can place the numeric text more than
    // seven wrappers away from its Reply button. Walk upward from every
    // visible Reply control and select the smallest complete comment row.
    const replyControls = [...scope.querySelectorAll('[role="button"],button,a')].filter(item =>
      item.getClientRects().length && /^(?:回覆|Reply)$/i.test(textOf(item).trim())
    );
    for (const replyButton of replyControls) {
      let container = replyButton.parentElement;
      for (let depth = 0; depth < 14 && container && container !== scope; depth++, container = container.parentElement) {
        const value = textOf(container);
        if (value.length > 900) continue;
        const hasExactAmount = [...container.querySelectorAll('span,div,[dir="auto"]')].some(item =>
          item.getClientRects().length && amountPattern.test(textOf(item)) && ![...item.children].some(child => textOf(child) === textOf(item))
        );
        const hasAuthor = [...container.querySelectorAll('a[href]')].some(anchor => {
          const label = textOf(anchor).trim();
          return label && !/^\d|剛剛|小時|分鐘|天|週/i.test(label);
        });
        if (hasExactAmount && hasAuthor) {
          if (!candidates.includes(container)) candidates.push(container);
          break;
        }
      }
    }
    function bidTimestamp(item) {
      const nodes = [item, ...item.querySelectorAll('a,time,abbr')];
      for (const node of nodes) {
        const values = [node.getAttribute?.('datetime'), node.getAttribute?.('data-utime'), node.getAttribute?.('aria-label'), node.getAttribute?.('title')];
        for (const rawValue of values) {
          const value = String(rawValue || '').trim();
          if (!value) continue;
          if (/^\d{10,13}$/.test(value)) return Number(value) * (value.length === 10 ? 1000 : 1);
          const exact = value.match(/(\d{4})年(\d{1,2})月(\d{1,2})日.*?(上午|下午)(\d{1,2}):(\d{2})/);
          if (exact) {
            let hour = Number(exact[5]);
            if (exact[4] === '下午' && hour < 12) hour += 12;
            if (exact[4] === '上午' && hour === 12) hour = 0;
            return new Date(Number(exact[1]), Number(exact[2]) - 1, Number(exact[3]), hour, Number(exact[6])).getTime();
          }
          const parsed = Date.parse(value);
          if (Number.isFinite(parsed)) return parsed;
        }
      }
      return NaN;
    }
    for (const [domIndex, item] of candidates.entries()) {
      const lines = textOf(item).split(/\n+/).map(line => line.trim()).filter(Boolean);
      const amountLine = lines.find(line => amountPattern.test(line));
      if (!amountLine) continue;
      const amountMatch = amountLine.match(/([0-9][0-9,]{0,9})/);
      const amount = amountMatch ? Number(amountMatch[1].replace(/,/g, '')) : 0;
      const authorLine = lines.find(line => line !== amountLine && !/^(讚|回覆|分享|Like|Reply|Share|剛剛|[0-9]+\s*(分|小時|天|週|w|h|min))/i.test(line)) || '匿名船員';
      const author = authorLine
        .replace(/的留言(?:剛剛|[0-9]+\s*(?:分鐘|分|小時|天|週|w|h|min)).*$/i, '')
        .replace(/\s*[·・]\s*(?:剛剛|[0-9]+\s*(?:分鐘|分|小時|天|週|w|h|min)).*$/i, '')
        .trim() || '匿名船員';
      // The auction host publishes rules and reminder comments. Host text is
      // never a bid and must never become the current leader.
      if (nonBidderNames.has(normalizeIdentityName(author).toLowerCase())) continue;
      const key = `${author}|${amount}`;
      if (!amount || seen.has(key)) continue;
      seen.add(key);
      const authorAnchor = [...item.querySelectorAll('a[href]')].find(anchor => textOf(anchor).trim() === author);
      const replyButton = [...item.querySelectorAll('[role="button"],button,a')].find(control => /^(?:回覆|Reply)$/i.test(textOf(control).trim()));
      if (!replyButton) continue;
      bids.push({author, authorUrl: String(authorAnchor?.href || ''), amount, timestamp: bidTimestamp(item), domIndex, commentElement: item, replyButton});
    }
    if (bids.some(bid => !Number.isFinite(bid.timestamp))) {
      throw new Error('留言精確時間缺失，最高有效出價待人工核對；禁止自動提醒');
    }
    // "Most relevant" is not chronological. Rebuild the auction from the
    // exact comment timestamps; when Facebook omits them, ascending amounts
    // are a safer fallback than the ranked screen order.
    bids.sort((a, b) => {
      const aHasTime = Number.isFinite(a.timestamp);
      const bHasTime = Number.isFinite(b.timestamp);
      if (aHasTime && bHasTime && a.timestamp !== b.timestamp) return a.timestamp - b.timestamp;
      if (aHasTime !== bHasTime) return aHasTime ? -1 : 1;
      return a.amount - b.amount || a.domIndex - b.domIndex;
    });
    if (!bids.length) {
      const startPrice = Math.max(1, postedStart || Number(payload.startPrice || payload.openingBid || payload.startingBid || 1));
      const backendLeader = String(payload.currentLeader || '').trim();
      const backendBid = Math.max(0, Number(payload.currentBid || 0));
      // The backend historically stored the opening price in currentBid even
      // before anyone commented. Do not mistake that seed value for a bid.
      const verifiedBackendBid = backendLeader || backendBid > startPrice ? backendBid : 0;
      return {
        highest: verifiedBackendBid,
        leader: backendLeader,
        leaderComment: null,
        bidders: [],
        violations,
        visibleBidCount: 0,
        startPrice,
        minimumIncrement: minimum,
        maximumIncrement: maximum
      };
    }
    const opening = Math.max(1, postedStart || Number(payload.startPrice || payload.openingBid || payload.startingBid || 1));
    // The reminder must reply to the highest visible numeric bid. Facebook's
    // “most relevant” order and missing timestamps must not make a lower,
    // older comment win. Equal bids prefer the newest visible comment.
    const validBids = rebuildValidAuctionBids(bids, opening, minimum, maximum);
    if (!validBids.length) throw new Error('可見數字留言沒有可核實有效出價；停止提醒');
    const leaderBid = validBids[validBids.length-1];
    const bidderMap = new Map();
    for (const bid of validBids) {
      const key = normalizeIdentityName(bid.author).toLowerCase();
      const previous = bidderMap.get(key);
      const bidTime = Number.isFinite(bid.timestamp) ? bid.timestamp : bid.domIndex;
      const previousTime = previous && (Number.isFinite(previous.timestamp) ? previous.timestamp : previous.domIndex);
      if (!previous || bid.amount > previous.amount || (bid.amount === previous.amount && bidTime > previousTime)) bidderMap.set(key, bid);
    }
    return {
      highest: leaderBid.amount,
      leader: leaderBid.author,
      leaderUrl: leaderBid.authorUrl,
      leaderComment: leaderBid.commentElement,
      leaderReplyButton: leaderBid.replyButton,
      bidders: [...bidderMap.values()],
      violations,
      visibleBidCount: validBids.length,
      bidEvidence: validBids.map(bid => ({amount:bid.amount,timestamp:bid.timestamp,author:bid.author})),
      startPrice: opening,
      minimumIncrement: minimum,
      maximumIncrement: maximum
    };
  }
  async function revealAuctionComments(article) {
    const scope = article.closest('[role="dialog"]') || document;
    const clean = value => String(value || '').replace(/[\u200B-\u200D\uFEFF]/g, '').replace(/\s+/g, ' ').trim();
    // Facebook changes this control frequently. In the current UI the
    // visible text can include a down-arrow while the useful value only
    // exists in aria-label. Check each label independently instead of
    // relying on textOf(), which prefers textContent over aria-label.
    const labelsOf = item => [...new Set([
      item?.getAttribute?.('aria-label'),
      item?.getAttribute?.('title'),
      item?.innerText,
      item?.textContent
    ].map(clean).filter(Boolean))];
    const hasCommentSortLabel = (item, choices) => labelsOf(item).some(label => {
      if (label.length > 180) return false;
      const alternation = choices.join('|');
      return new RegExp(`(?:^|[：:\\s])(?:${alternation})(?:$|[：:\\s（(）)·•▼▾⌄])`, 'i').test(label);
    });
    // Post dialogs often render their comments only after their inner scroller
    // reaches the bottom. Load that region before looking for sort/expand UI.
    const scrollContainers = [...scope.querySelectorAll('div')]
      .filter(item => item.scrollHeight > item.clientHeight + 120 && item.clientHeight > 180)
      .sort((a, b) => (b.clientHeight * b.clientWidth) - (a.clientHeight * a.clientWidth));
    const commentScroller = scrollContainers[0] || null;
    if (commentScroller) {
      for (let pass = 0; pass < 3; pass++) {
        const heightBefore = commentScroller.scrollHeight;
        commentScroller.scrollTop = commentScroller.scrollHeight;
        commentScroller.dispatchEvent(new Event('scroll', {bubbles:true}));
        // Facebook loads additional comments asynchronously after reaching
        // the bottom. Wait ten seconds before deciding the highest visible
        // bid; if the list grew, repeat from the new bottom and wait again.
        await sleep(10000);
        const atBottom = commentScroller.scrollTop + commentScroller.clientHeight >= commentScroller.scrollHeight - 8;
        if (commentScroller.scrollHeight <= heightBefore + 20 && atBottom) break;
      }
    } else await sleep(10000);
    const candidates = [...scope.querySelectorAll(
      '[role="button"],button,[aria-haspopup="menu"],[aria-haspopup="listbox"],[aria-label],[title]'
    )].filter(item => item.getClientRects().length);
    const allCommentChoices = ['所有留言', 'All comments'];
    const sortChoices = ['最相關', '由新到舊', '所有留言', 'Most relevant', 'Newest', 'All comments'];
    const sortControl = candidates
      .filter(item => hasCommentSortLabel(item, sortChoices))
      .sort((a, b) => Math.min(...labelsOf(a).map(label => label.length)) - Math.min(...labelsOf(b).map(label => label.length)))[0];
    // If the dialog already says "所有留言", it is already in the safe
    // state. Do not reopen the menu and wait forever for an option that is
    // no longer present.
    if (sortControl && !hasCommentSortLabel(sortControl, allCommentChoices)) {
      await trustedFacebookClick(sortControl);
      const allComments = await waitFor(() => {
        const matches = [...document.querySelectorAll('[role="menuitem"],[role="option"],[role="button"],[tabindex="0"],[role="menu"] div,[role="menu"] span,[aria-label],[title]')]
          .filter(item => {
            if (!item.getClientRects().length) return false;
            return hasCommentSortLabel(item, allCommentChoices);
          })
          .sort((a, b) => Math.min(...labelsOf(a).map(label => label.length)) - Math.min(...labelsOf(b).map(label => label.length)));
        const item = matches[0];
        return item && (item.closest('[role="menuitem"],[role="option"],[role="button"],[tabindex="0"]') || item);
      }, 8000);
      if (allComments) {
        await trustedFacebookClick(allComments);
        await waitFor(() => [...scope.querySelectorAll('[role="button"],button,[aria-haspopup],[aria-label],[title]')].some(item =>
          item.getClientRects().length && hasCommentSortLabel(item, allCommentChoices)
        ), 10000);
        await sleep(1200);
      } else {
        document.dispatchEvent(new KeyboardEvent('keydown', {key:'Escape', code:'Escape', bubbles:true}));
        await sleep(500);
      }
    }
    for (let pass = 0; pass < 3; pass++) {
      const controls = [...scope.querySelectorAll('[role="button"],button,a')].filter(item => {
        if (!item.getClientRects().length) return false;
        return /(?:查看|顯示|更多|另有).*(?:留言|回覆)|(?:View|See).*(?:comment|repl)/i.test(textOf(item));
      });
      if (!controls.length) break;
      for (const control of controls.slice(0, 5)) control.click();
      await sleep(900);
    }
    // Expanding replies can append another lazy-loaded block. Finish at the
    // real bottom and give Facebook one final five-second render window.
    if (commentScroller) {
      commentScroller.scrollTop = commentScroller.scrollHeight;
      commentScroller.dispatchEvent(new Event('scroll', {bubbles:true}));
      await sleep(5000);
    }
    // Some Facebook post dialogs no longer expose a sort menu at all. The
    // expanded, bottom-loaded comments above are still available for exact
    // numeric validation, so absence of that optional control must not stop
    // every price reminder.
  }
  function auctionReminderCopy(payload, bidState) {
    const bid = Math.max(0, Math.round(Number(bidState.highest || 0)));
    const start = Math.max(1, Math.round(Number(bidState.startPrice || payload.startPrice || 1)));
    const step = Math.max(1, Math.round(Number(bidState.minimumIncrement || payload.bidStep || 30)));
    const nextBid = bid > 0 ? bid + step : start;
    const phase = String(payload.reminderPhase || payload.reminder_phase || '').trim();
    const timedPhase = phase.match(/(?:half-hourly|extended|final-three)-(\d{8})(\d{2})(\d{2})/i);
    const cycleLabel = timedPhase
      ? `${timedPhase[2]}:${timedPhase[3]} 更新`
      : phase === 'final-15' ? '結標前 15 分鐘'
      : phase === 'final-5' ? '結標前 5 分鐘'
      : phase === 'final-1' ? '結標前 1 分鐘'
      : phase.startsWith('recovery-') ? `重新同步 ${phase.slice(-6, -4)}:${phase.slice(-4, -2)}`
      : phase ? `本輪 ${phase}` : '本輪更新';
    const title = String(payload.productTitle || '這件寶物').trim().replace(/【.*$/u, '').slice(0, 12);
    const closeMatch = String(payload.closeAt || '').match(/(?:\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})/);
    const closeLabel = closeMatch ? closeMatch[1] : '以貼文公告時間為準';
    const priceCopy = bid > 0
      ? `🏴‍☠️ 目前最高 NT$${bid.toLocaleString('zh-TW')}｜下一口至少 NT$${nextBid.toLocaleString('zh-TW')} 起。`
      : `🏴‍☠️ 尚無出價｜第一口至少 NT$${start.toLocaleString('zh-TW')} 起。`;
    const sceneCopy = typeof globalThis.baohuiReminderScenePick === 'function'
      ? globalThis.baohuiReminderScenePick(payload)
      : '郭大在等你啊，來來來，往前再來！';
    // Keep the verified price signature and cycle marker intact so retries
    // can recognize an existing Facebook comment before submitting again.
    return `${sceneCopy} ${priceCopy}\n「${title}」本標 ${closeLabel} 結標｜⏱️ ${cycleLabel}`;
  }

  function auctionOutbidCopy(payload, bidState, bidder) {
    const highest = Math.max(0, Math.round(Number(bidState.highest || 0)));
    const previous = Math.max(0, Math.round(Number(bidder.amount || 0)));
    const phase = String(payload.reminderPhase || payload.reminder_phase || '').trim();
    const timedPhase = phase.match(/(?:half-hourly|extended|final-three)-(\d{8})(\d{2})(\d{2})/i);
    const cycleLabel = timedPhase
      ? `${timedPhase[2]}:${timedPhase[3]} 更新`
      : phase === 'final-15' ? '結標前 15 分鐘'
      : phase === 'final-5' ? '結標前 5 分鐘'
      : phase === 'final-1' ? '結標前 1 分鐘'
      : '本輪更新';
    const outbidCalls = [
      '已有更高出價，繼續努力奪寶！', '領先旗已換人，再加一口搶回寶藏！',
      '寶箱被別人追過了，快回來繼續競標！', '船長榜出現新高價，下一口等你！',
      '尋寶對手已超前，快來再次喊價！', '寶藏還沒定主，繼續出價就有機會！',
      '海上出現新高價，回來挑戰領先者！', '你的航線被超越了，繼續加碼奪寶！',
      '金幣數字已刷新，快來追上最高價！', '奪寶戰還在繼續，再喊一口吧！'
    ];
    const tiedCalls = [
      '已有同額出價，請提高金額繼續奪寶！', '同樣價格已有船員喊過，再加一口搶寶藏！',
      '這口價格重複了，往上加價繼續競標！', '船長榜已有同額紀錄，請喊更高價格！',
      '同額無法超船，下一口請再提高！', '寶箱收到相同價格，繼續加碼就有機會！',
      '海上已有同價船員，請用更高價挑戰！', '這次喊價撞船了，再加一口繼續尋寶！',
      '金幣數字相同，請提高價格再出發！', '同價競標已出現，下一口再努力！'
    ];
    const seed = `${payload.scheduleId || payload.schedule_id || ''}|${phase}|${bidder.author || ''}`;
    let index = 0;
    for (const char of seed) index = ((index * 31) + char.codePointAt(0)) >>> 0;
    const calls = previous >= highest ? tiedCalls : outbidCalls;
    const closeMatch = String(payload.closeAt || '').match(/(?:\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})/);
    const closeLabel = closeMatch ? closeMatch[1] : '以貼文公告時間為準';
    return `🏴‍☠️ ${bidder.author}，目前最高 NT$${highest.toLocaleString('zh-TW')}｜你曾出價 NT$${previous.toLocaleString('zh-TW')}\n${calls[index % calls.length]} 本標 ${closeLabel} 結標｜⏱️ ${cycleLabel}`;
  }

  async function insertAuctionReminderWithLeaderTag(editor, copy, leader) {
    const name = String(leader || '').replace(/^@+/, '').trim();
    if (!name) {
      await insertMultilineText(editor, copy);
      return false;
    }
    clearEditor(editor);
    await sleep(150);
    document.execCommand('insertText', false, `@${name}`);
    editor.dispatchEvent(new InputEvent('input', {bubbles:true, inputType:'insertText', data:`@${name}`}));
    const suggestion = await waitFor(() => {
      const choices = [...document.querySelectorAll('[role="option"],[role="menuitem"],[role="listbox"] [tabindex],div[aria-label]')]
        .filter(item => item.getClientRects().length && !editor.contains(item));
      return choices.find(item => {
        const label = textOf(item).split(/\n+/)[0].replace(/^@+/, '').trim();
        return label === name;
      }) || null;
    }, 4500);
    if (!suggestion) {
      await insertMultilineText(editor, `@${name}\n${copy}`);
      return false;
    }
    suggestion.click();
    await sleep(350);
    await trustedFacebookMultiline(editor, copy, true, true);
    await sleep(400);
    const inserted = normalizedEditorText(editor);
    if (!inserted.includes(name) || !inserted.includes(copy.split('\n').find(line => line.trim())?.trim() || '')) {
      throw new Error('最高出價者標註或提醒內容未完整帶入，已停止送出');
    }
    return true;
  }

  async function notifyOutbidAuctionBidder(payload, article, scope, bidder, copy, verifiedIdentity) {
    if (!bidder?.commentElement || !bidder?.author) return false;
    if (visibleCopyCount(scope, copy) > 0) return true;
    const replyButton = bidder.replyButton || [...bidder.commentElement.querySelectorAll('[role="button"],button,a')]
      .find(item => /^(?:回覆|Reply)$/i.test(textOf(item).trim()));
    if (!replyButton) return false;
    const selector = '[contenteditable="true"][role="textbox"],div[contenteditable="true"]';
    const editorsBefore = new Set([...document.querySelectorAll(selector)].filter(item => item.getClientRects().length));
    bidder.commentElement.scrollIntoView({block:'center', behavior:'instant'});
    await sleep(350);
    replyButton.click();
    await sleep(450);
    const normalizedBidder = String(bidder.author).replace(/\s+/g, '');
    const editor = await waitFor(() => [...document.querySelectorAll(selector)].filter(item => item.getClientRects().length).find(item => {
      if (!article.contains(item)) return false;
      const context = `${item.getAttribute('aria-label') || ''} ${item.getAttribute('aria-description') || ''} ${textOf(item.parentElement || '')}`.replace(/\s+/g, '');
      const editorText = normalizedEditorText(item).replace(/\s+/g, '');
      return context.includes(`回覆${normalizedBidder}`)
        || context.toLowerCase().includes(`replyto${normalizedBidder.toLowerCase()}`)
        || (!editorsBefore.has(item) && editorText.includes(normalizedBidder));
    }) || null, 8000);
    if (!editor) return false;
    assertRequiredIdentity(payload, editor, verifiedIdentity);
    await insertAuctionReplyText(editor, copy, bidder.author);
    await sleep(2500);
    const sent = await submitFacebookCommentEditor(editor, 10000);
    if (!sent) return false;
    await sleep(500);
    return true;
  }
  async function postAuctionReminder(payload) {
    const closeTs = Date.parse(String(payload.closeAt || '').replace(' ', 'T'));
    if (Number.isFinite(closeTs) && closeTs <= Date.now()) throw new Error('競標已結標，已取消本次推文');
    const expected = String(payload.postUrl || '').split('#')[0];
    const expectedId = postIdFromUrl(expected);
    if (!expected || !expectedId) throw new Error('後台尚未設定正確的 Facebook 競標貼文連結');
    activePayload = payload;
    showNotice('正在切換為 Kuo Feng Cheng 並核對競標貼文…');
    const verifiedIdentity = await ensureRequiredIdentity(payload, document.body);
    await closeAccountMenu();
    const current = location.href.split('#')[0];
    if (!current.startsWith(expected) && !current.includes(expectedId)) {
      showNotice('已切換正確身分，正在重新開啟指定的競標貼文…');
      location.assign(expected);
      return;
    }
    const verifiedUrl = location.href.split('#')[0];
    if (!verifiedUrl.startsWith(expected) && (!expectedId || !verifiedUrl.includes(expectedId))) {
      showNotice('競標身分已切換，正在回到指定貼文…');
      location.assign(expected);
      await new Promise(() => {});
      return;
    }
    showNotice(`競標身分已核對：${verifiedIdentity}；正在核對留言與最高價…`);
    const expectedProductId = String(payload.productId || '').trim();
    const expectedClose = String(payload.closeAt || '').trim();
    const expectedCloseDate = expectedClose.slice(0, 10);
    const expectedCloseTime = expectedClose.slice(11, 16);
    // The exact Facebook post URL/post ID was verified above. Long Facebook
    // posts are virtualized while scrolling, so the header that contains the
    // product ID and closing time can be removed from the live DOM even though
    // the correct post dialog is still open. Use the verified URL as the
    // immutable identity guard, then select the loaded post dialog as the
    // comment scope. Product/time text remains useful when it is present, but
    // it must not fail a reminder only because the user has scrolled down.
    const article = await waitFor(() => {
      const visibleDialogs = [...document.querySelectorAll('[role="dialog"]')]
        .filter(item => item.getClientRects().length);
      const candidates = [...visibleDialogs, document.querySelector('[role="main"]'), document.body]
        .filter(Boolean);
      const contentMatch = candidates.find(item => {
        const text = textOf(item).replace(/\s+/g, ' ').trim();
        return expectedProductId && expectedCloseDate && expectedCloseTime
          && text.includes(expectedProductId)
          && text.includes(expectedCloseDate)
          && text.includes(expectedCloseTime);
      });
      if (contentMatch) return contentMatch;
      return visibleDialogs.filter(item => {
        const text = textOf(item).replace(/\s+/g, ' ').trim();
        return text.length > 80 && item.querySelector('[contenteditable="true"][role="textbox"],div[contenteditable="true"]');
      }).sort((a, b) => textOf(b).length - textOf(a).length)[0]
        || visibleDialogs.filter(item => textOf(item).replace(/\s+/g, ' ').trim().length > 80)
          .sort((a, b) => textOf(b).length - textOf(a).length)[0]
        || document.querySelector('[role="main"]')
        || null;
    }, 25000);
    if (!article) throw new Error(`指定貼文 ${expectedId} 尚未載入完成；已保留提醒供下次重試`);
    // Once a post is public, its visible closing time is authoritative. The
    // backend may contain a later date after an old schedule rollover; never
    // use that stale date to continue commenting on an auction that the public
    // post already says is closed.
    const visiblePostText = textOf(article).replace(/\u00a0/g, ' ');
    const visibleCloseMatch = visiblePostText.match(/結標\s*[：:]\s*(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2})/u);
    const visibleCloseAt = visibleCloseMatch ? `${visibleCloseMatch[1]} ${visibleCloseMatch[2]}` : '';
    const visibleCloseTs = visibleCloseAt ? Date.parse(visibleCloseAt.replace(' ', 'T')) : NaN;
    if (Number.isFinite(visibleCloseTs) && visibleCloseTs <= Date.now()) {
      showNotice(`貼文顯示已於 ${visibleCloseAt} 結標；已停止提醒並回寫後台截標時間。`);
      chrome.runtime.sendMessage({type:'BAOHUI_FACEBOOK_POST_CLICKED', payload:{
        ...payload, ok:true, skippedExpired:true, actualCloseAt:visibleCloseAt,
        verifiedVisible:true, postUrl:expected, postId:expectedId,
        currentBid:0, currentLeader:'', violations:[]
      }});
      return;
    }
    if (!articleMatchesPublishedAuction(article, payload)) throw new Error('原貼產品、完整逐件序號、数量、圖片或結標時間未核對；禁止提醒');
    await revealAuctionComments(article);
    const bidState = readVisibleAuctionBids(article, payload);
    // A backend bid without a visible bidder comment cannot be verified.
    // Do not tell customers there are no bids when the backend says otherwise.
    if (bidState.visibleBidCount === 0 && bidState.highest > 0) {
      throw new Error('後台有出價，但 Facebook 留言尚無可核對的出價；已停止本筆價格提醒');
    }
    // The backend may seed currentBid with the opening price before bidding;
    // only a visible Facebook bidder comment establishes the current leader.
    const reminderBidState = bidState.visibleBidCount > 0 && bidState.leaderComment
      ? bidState
      : {...bidState, highest:0, leader:'', leaderComment:null, leaderReplyButton:null};
    payload = {...payload, bidEvidence:reminderBidState.bidEvidence || [], currentBid:reminderBidState.highest, currentLeader:reminderBidState.leader};
    const copy = auctionReminderCopy(payload, reminderBidState);
    const verificationScope = article.closest('[role="dialog"]') || document;
    const existingReminder = visibleAuctionReminderEvidence(verificationScope, copy, payload.requiredVisibleName);
    // A previous attempt can be accepted by Facebook before its DOM updates.
    // On retry, seeing the same price reminder is proof of success and must
    // never create a duplicate reply.
    if (existingReminder) {
      [...verificationScope.querySelectorAll('[contenteditable="true"][role="textbox"],div[contenteditable="true"]')]
        .filter(item => item.getClientRects().length && /回覆|reply/i.test(`${item.getAttribute('aria-label') || ''} ${item.getAttribute('aria-description') || ''}`))
        .forEach(item => item.blur());
      showNotice('已找到同一提醒時段的留言，確認本輪先前已成功；不再重送本輪。');
      chrome.runtime.sendMessage({type: 'BAOHUI_FACEBOOK_POST_CLICKED', payload: {...payload, ok:true, verifiedVisible:true, recoveredExistingReminder:true, reminderSignatureVersion:2, postUrl: expected, postId: expectedId, commentUrl: existingReminder.commentUrl, commentId: existingReminder.commentId, currentBid: reminderBidState.highest, currentLeader: reminderBidState.leader, violations: reminderBidState.violations}});
      return;
    }
    if (payload.requireManualConfirm && !window.confirm(`已完成最高有效出價檢查，請確認是否發布：\n\n${copy}`)) throw new Error('使用者取消手動出價提醒');
    const editorSelector = '[contenteditable="true"][role="textbox"],div[contenteditable="true"]';
    // Every cycle leaves one top-level comment under the product post.
    // Never reply to the leader or notify every prior bidder individually.
    const publicEditor = () => [...verificationScope.querySelectorAll(editorSelector)].find(item => {
      if (!item.getClientRects().length || !article.contains(item)) return false;
      const editorLabel = `${item.getAttribute('aria-label') || ''} ${item.getAttribute('aria-description') || ''}`;
      const context = `${editorLabel} ${textOf(item.parentElement || '')}`;
      return /留言|comment/i.test(context) && !/回覆|reply/i.test(editorLabel);
    }) || null;
    let editor = await waitFor(publicEditor, 10000);
    if (!editor) {
      const commentButton = [...verificationScope.querySelectorAll('[role="button"],button')]
        .find(item => item.getClientRects().length && /^(?:留言|Comment)$/i.test(textOf(item).trim()));
      if (commentButton) {
        commentButton.click();
        await sleep(600);
        editor = await waitFor(publicEditor, 8000);
      }
    }
    if (!editor) throw new Error('Facebook 沒有開啟本產品貼文底下的公開留言框；已停止本筆提醒');
    assertRequiredIdentity(payload, editor, verifiedIdentity);
    const noBidReminder = reminderBidState.highest <= 0;
    showNotice(noBidReminder ? '尚無有效出價；將在原貼文留一則起標提醒。' : `已核對最高有效出價 NT$${Math.round(reminderBidState.highest).toLocaleString('zh-TW')}；將在原貼文留一則提醒。`);
    const visibleBefore = visibleAuctionReminderEvidence(verificationScope, copy, payload.requiredVisibleName);
    await insertMultilineText(editor, copy, true);
    const delayMs = Math.max(10000, Number(payload.publishDelayMs || 10000));
    showNotice(`單則價格提醒已帶入公開留言框；${Math.ceil(delayMs / 1000)} 秒後送出。`);
    await sleep(delayMs);
    const lateExisting = visibleAuctionReminderEvidence(verificationScope, copy, payload.requiredVisibleName);
    if (lateExisting) {
      clearEditor(editor);
      editor.blur();
      showNotice('等待送出期間已找到同時段正式留言；已取消草稿並回寫原留言，不重複發布。');
      chrome.runtime.sendMessage({type:'BAOHUI_FACEBOOK_POST_CLICKED', payload:{...payload, ok:true, verifiedVisible:true, recoveredExistingReminder:true, reminderSignatureVersion:2, postUrl:expected, postId:expectedId, commentUrl:lateExisting.commentUrl, commentId:lateExisting.commentId, currentBid:reminderBidState.highest, currentLeader:reminderBidState.leader, violations:reminderBidState.violations}});
      return;
    }
    await authorizeAuctionSubmit(payload);
    const sent = await submitFacebookCommentEditor(editor, 12000);
    if (!sent) throw new Error('Facebook 未確認提醒留言已送出；本頁保留供人工核對');
    const commentScroller = [...verificationScope.querySelectorAll('div')].find(item => item.scrollHeight > item.clientHeight + 100 && item.clientHeight > 250);
    if (commentScroller) {
      commentScroller.scrollTop = commentScroller.scrollHeight;
      commentScroller.dispatchEvent(new Event('scroll', {bubbles:true}));
    }
    const visibleEvidence = await waitFor(() => !visibleBefore && visibleAuctionReminderEvidence(verificationScope, copy, payload.requiredVisibleName), 45000);
    const verifiedVisible = Boolean(visibleEvidence);
    if (verifiedVisible) showNotice('單則價格提醒已在原貼文公開可見，正在回寫結果。');
    else showNotice('提醒已送出但 Facebook 尚未顯示；列為待核對，不會重複發送。');
    chrome.runtime.sendMessage({type:'BAOHUI_FACEBOOK_POST_CLICKED', payload:{...payload, ok:true, verifiedVisible, submissionAccepted:true, noBidReminder, outbidNotified:0, outbidSkipped:0, postUrl:expected, postId:expectedId, commentUrl:visibleEvidence?.commentUrl || '', commentId:visibleEvidence?.commentId || '', currentBid:reminderBidState.highest, currentLeader:reminderBidState.leader, violations:reminderBidState.violations, error:verifiedVisible ? '' : 'facebook_reply_display_pending'}});
    return;
  }
  async function postProductPromotion(payload) {
    const expected = String(payload.postUrl || '').split('#')[0];
    const expectedId = postIdFromUrl(expected);
    if (!expected || !expectedId) throw new Error('結標商品沒有正確的 Facebook 貼文連結');
    activePayload = payload;
    const verifiedIdentity = await ensureRequiredIdentity(payload, document.body);
    await closeAccountMenu();
    if (!location.href.includes(expectedId)) { location.assign(expected); return; }
    const article = await waitFor(() => [...document.querySelectorAll('[role="article"]')].find(item => [...item.querySelectorAll('a[href]')].some(a => String(a.href || '').includes(expectedId))) || [...document.querySelectorAll('[role="article"]')][0], 25000);
    if (!article) throw new Error('找不到剛結標的指定貼文');
    let editor = [...article.querySelectorAll('[contenteditable="true"][role="textbox"],div[contenteditable="true"]')].find(item => item.getClientRects().length && /留言|comment/i.test(item.getAttribute('aria-label') || ''));
    if (!editor) {
      const commentButton = [...article.querySelectorAll('[role="button"],button')].find(item => /留言|Comment/i.test(textOf(item)));
      if (commentButton) { commentButton.click(); await sleep(500); }
      editor = await waitFor(() => [...article.querySelectorAll('[contenteditable="true"][role="textbox"],div[contenteditable="true"]')].find(item => item.getClientRects().length && /留言|comment/i.test(item.getAttribute('aria-label') || '')), 10000);
    }
    if (!editor) throw new Error('Facebook 沒有開啟公開留言輸入框');
    assertRequiredIdentity(payload, editor, verifiedIdentity);
    editor.focus();
    await insertMultilineText(editor, String(payload.copy || ''), true);
    const delayMs = Math.max(10000, Number(payload.publishDelayMs || 10000));
    showNotice(`結標後產品推薦已帶入公開留言；${Math.ceil(delayMs / 1000)} 秒後送出。`);
    await sleep(delayMs);
    await trustedFacebookEnter(editor);
    const sent = await waitFor(() => textOf(editor) === '', 8000);
    if (!sent) throw new Error('Facebook 未確認產品推薦留言已送出');
    chrome.runtime.sendMessage({type:'BAOHUI_FACEBOOK_POST_CLICKED',payload:{...payload,ok:true,postUrl:expected,postId:expectedId}});
  }
  async function deleteProductPost(payload) {
    const current = location.href.split('#')[0];
    const expected = String(payload.postUrl || '').split('#')[0];
    if (!expected || (!current.startsWith(expected) && payload.postId && !current.includes(payload.postId))) throw new Error('目前網址與下架紀錄不符，已停止刪除');
    activePayload = payload;
    showNotice(`正在核對 ${payload.listingSerial} 的 Facebook 貼文…`);
    const article = await waitFor(() => [...document.querySelectorAll('[role="article"]')].find(item => {
      const text = textOf(item);
      return (payload.listingSerial && text.includes(payload.listingSerial)) || (payload.postId && [...item.querySelectorAll('a[href]')].some(a => a.href.includes(payload.postId)));
    }), 25000);
    if (!article) throw new Error('找不到含有相同上架序號／貼文 ID 的文章，為避免刪錯已停止');
    const menu = [...article.querySelectorAll('[role="button"],button')].find(element => /對此貼文採取的動作|可對此貼文採取的動作|更多|Actions for this post|More/i.test(textOf(element)));
    if (!menu) throw new Error('找不到這篇文章的操作選單');
    menu.click();
    const remove = await waitFor(() => [...document.querySelectorAll('[role="menuitem"],[role="button"],button')].find(element => /^(刪除貼文|移除貼文|移至垃圾桶|Delete post|Remove post|Move to trash)$/i.test(textOf(element))), 10000);
    if (!remove) throw new Error('目前帳號沒有顯示刪除這篇貼文的權限');
    remove.click();
    const confirmButton = await waitFor(() => [...document.querySelectorAll('[role="dialog"] [role="button"],[role="dialog"] button')].find(element => /^(刪除|移除|移至垃圾桶|Delete|Remove|Move)$/i.test(textOf(element)) && element.getAttribute('aria-disabled') !== 'true'), 10000);
    if (!confirmButton) throw new Error('Facebook 沒有顯示刪除確認按鈕');
    confirmButton.click();
    await sleep(3500);
    showNotice('貼文已刪除，正在清除寶輝後台活動連結。');
    chrome.runtime.sendMessage({type: 'BAOHUI_FACEBOOK_DELETE_RESULT', payload: {...payload, ok: true}});
  }
  async function markProductSold(payload) {
    const current = location.href.split('#')[0];
    const expected = String(payload.postUrl || '').split('#')[0];
    if (!expected || (!current.startsWith(expected) && payload.postId && !current.includes(payload.postId))) throw new Error('目前網址與粉絲團販售紀錄不符，已停止修改');
    activePayload = payload;
    showNotice(`正在核對 ${payload.listingSerial} 的粉絲團貼文…`);
    const article = await waitFor(() => [...document.querySelectorAll('[role="article"]')].find(item => {
      const text = textOf(item);
      return (payload.listingSerial && text.includes(payload.listingSerial)) || (payload.postId && [...item.querySelectorAll('a[href]')].some(a => a.href.includes(payload.postId)));
    }), 25000);
    if (!article) throw new Error('找不到含有相同上架序號／貼文 ID 的文章，為避免改錯已停止');
    if (/【已售出】/.test(textOf(article))) {
      chrome.runtime.sendMessage({type: 'BAOHUI_FACEBOOK_SOLD_RESULT', payload: {...payload, ok: true}});
      return;
    }
    const menu = [...article.querySelectorAll('[role="button"],button')].find(element => /對此貼文採取的動作|可對此貼文採取的動作|更多|Actions for this post|More/i.test(textOf(element)));
    if (!menu) throw new Error('找不到這篇粉絲團貼文的操作選單');
    menu.click();
    const edit = await waitFor(() => [...document.querySelectorAll('[role="menuitem"],[role="button"],button')].find(element => /^(編輯貼文|編輯|Edit post|Edit)$/i.test(textOf(element))), 10000);
    if (!edit) throw new Error('目前粉絲團身分沒有顯示「編輯貼文」權限');
    edit.click();
    const dialog = await waitFor(() => [...document.querySelectorAll('[role="dialog"]')].find(item => item.querySelector('[contenteditable="true"][role="textbox"],div[contenteditable="true"]')), 12000);
    if (!dialog) throw new Error('Facebook 編輯貼文視窗沒有開啟');
    const editor = dialog.querySelector('[contenteditable="true"][role="textbox"],div[contenteditable="true"]');
    const original = textOf(editor);
    if (!original.includes(payload.listingSerial || '')) throw new Error('編輯內容沒有相同上架序號，已停止修改');
    editor.focus();
    const range = document.createRange();
    range.selectNodeContents(editor);
    const selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
    const soldText = `【已售出】\n\n${original}`;
    insertMultilineText(editor, soldText);
    const save = await waitFor(() => [...dialog.querySelectorAll('[role="button"],button')].find(element => /^(儲存|儲存變更|Save|Save changes)$/i.test(textOf(element)) && element.getAttribute('aria-disabled') !== 'true'), 10000);
    if (!save) throw new Error('找不到 Facebook 儲存按鈕');
    save.click();
    await sleep(3000);
    showNotice('粉絲團貼文已保留並標示「已售出」。');
    chrome.runtime.sendMessage({type: 'BAOHUI_FACEBOOK_SOLD_RESULT', payload: {...payload, ok: true}});
  }
  document.addEventListener('click', event => {
    if (!activePayload || reportSent) return;
    // Automatic ads report from their awaited publish operation, not a
    // framework-dependent delegated click handler.
    if (activePayload.autoPublish && (activePayload.kind === 'advertising_post' || activePayload.contentType === 'facebook_advertising_post')) return;
    const button = event.target.closest('[role="button"],button');
    if (!button || !/^(發佈|貼文|Post)$/i.test(textOf(button)) || !button.closest('[role="dialog"]')) return;
    reportSent = true;
    showNotice('已按 Facebook 發佈，正在回寫寶輝工作評比…');
    // Facebook may continue uploading/processing media after the publish click.
    // Keep the tab alive for at least ten seconds before reporting completion.
    setTimeout(async () => {
      const limited = await waitFor(() => /我們會限制你在特定期間內發佈貼文|限制.*發佈貼文|temporarily limited.*post/i.test(document.body.innerText || ''), 10000);
      if (limited) {
        showNotice(`Facebook 已限制發文頻率，僅 ${activePayload.requiredVisibleName || '本次帳號'} 停止 2 小時；其他帳號照常輪發。`, true);
        chrome.runtime.sendMessage({type:'BAOHUI_AUTO_FAILED', payload:{...activePayload, ok:false, error:'facebook_rate_limit_30m'}});
        return;
      }
      if (activePayload.kind === 'auction_listing') {
        await recoverPublishedPermalink(activePayload);
        return;
      }
      const isAdvertising = activePayload.kind === 'advertising_post' || activePayload.contentType === 'facebook_advertising_post';
      const link = activePayload.kind === 'product_sale'
        ? await findPostedPermalink(activePayload)
        : isAdvertising
          ? await findAdvertisingPermalink(activePayload, 30000)
          : {postUrl:'',postId:''};
      chrome.runtime.sendMessage({type: 'BAOHUI_FACEBOOK_POST_CLICKED', payload: {
        ...activePayload,
        ...link,
        ok:true,
        verifiedIdentity: isAdvertising ? String(activePayload.requiredVisibleName || '') : String(activePayload.verifiedIdentity || ''),
        verifiedVisible: isAdvertising ? Boolean(link.postUrl && link.postId) : activePayload.verifiedVisible === true
      }});
    }, 10000);
  }, true);
  let pendingClaimStarted = false;
  function handlePendingPayload(payload) {
    if (!payload || pendingClaimStarted) return false;
    pendingClaimStarted = true;
    const isAdvertising=payload.kind==='advertising_post'||payload.contentType==='facebook_advertising_post';
    const submitLookupKey=isAdvertising?advertisingPostSubmitLookupKey(payload):'';
    if(isAdvertising&&submitLookupKey&&sessionStorage.getItem(submitLookupKey)){
      activePayload=payload;
      showNotice('正在社團搜尋結果中核對剛才的廣告與正式連結…');
      findAdvertisingPermalink(payload,45000).then(async link=>{
        sessionStorage.removeItem(submitLookupKey);
        const pendingReview=advertisingPendingReviewVisible();
        const ack=await chrome.runtime.sendMessage({type:'AD_V2_RESULT',evidence:{post_url:link.postUrl||'',post_id:link.postId||'',verified_identity:String(payload.requiredVisibleName||''),verified_visible:Boolean(link.postUrl&&link.postId&&!pendingReview),pendingReview}});
        if(!ack?.ok)throw Error(ack?.error||'結果未保存，禁止重發');
      }).catch(error=>chrome.runtime.sendMessage({type:'BAOHUI_AUTO_FAILED',payload:{...payload,ok:false,outcomeUnknown:true,error:`post_submit_lookup_failed: ${error.message}`}}));
      return true;
    }
    // A permalink recovery task only searches for the already-public post.
    // It must never reopen the composer or submit the auction a second time.
    if (payload.kind === 'auction_listing' && payload.lookupOnly === true) {
      const knownPostUrl = String(payload.knownPostUrl || '').trim();
      if (/^https:\/\/(?:www\.)?facebook\.com\/groups\/[^/?#]+\/(?:posts|permalink)\/\d+\/?(?:[?#].*)?$/i.test(knownPostUrl)
          && postIdFromUrl(location.href) !== postIdFromUrl(knownPostUrl)) {
        sessionStorage.setItem(publishedLookupKey(payload), '1');
        location.assign(knownPostUrl);
        return true;
      }
      const searchUrl = groupSearchUrl(payload);
      if (!knownPostUrl && searchUrl && !/\/groups\/[^/]+\/search\//i.test(location.pathname)) {
        sessionStorage.setItem(publishedLookupKey(payload), '1');
        location.assign(searchUrl);
        return true;
      }
      recoverPublishedPermalink(payload).catch(error => {
        sessionStorage.removeItem(publishedLookupKey(payload));
        chrome.runtime.sendMessage({type:'BAOHUI_AUTO_FAILED', payload:{...payload, ok:false, outcomeUnknown:true, error:`permalink_recovery_only: ${error.message}`}});
      });
      return true;
    }
    // A scheduled-list page is a reconciliation surface, never a composer.
    // Recover existing pending tasks here after reload without another submit.
    if (payload.kind === 'auction_listing' && /\/groups\/[^/]+\/scheduled_posts\/?$/i.test(location.pathname)) {
      captureScheduledPostLink(payload).catch(error => {
        showNotice('既有預約網址核對失敗：' + error.message + '；保留待核對，不會重新發文。', true);
        chrome.runtime.sendMessage({type:'BAOHUI_AUTO_FAILED',payload:{...payload,ok:false,outcomeUnknown:true,error:'scheduled_lookup_only: '+error.message}});
      });
      return true;
    }
    if (payload.kind === 'auction_listing' && sessionStorage.getItem(publishedLookupKey(payload))) {
      recoverPublishedPermalink(payload).catch(error => {
        sessionStorage.removeItem(publishedLookupKey(payload));
        chrome.runtime.sendMessage({type:'BAOHUI_FACEBOOK_POST_CLICKED', payload:{...payload, ok:true, postUrl:'', postId:'', error:`publish_clicked_permalink_pending_after_search: ${error.message}`}});
      });
      return true;
    }
    if (sessionStorage.getItem('baohuiScheduledLinkLookup') === String(payload.taskId || payload.scheduleId || '1') && payload.kind === 'auction_listing') {
      captureScheduledPostLink(payload).catch(error => {
        showNotice(`Facebook 預約已送出，連結待核對：${error.message}。此筆不重發，繼續下一筆。`, true);
        chrome.runtime.sendMessage({type: 'BAOHUI_FACEBOOK_SCHEDULED', payload: {...payload, scheduled:true, postUrl:'', postId:'', error:`scheduled_link_lookup_pending: ${error.message}`}});
      });
      return true;
    }
    if (payload.action === 'delete_product_post') {
      deleteProductPost(payload).catch(error => {
        showNotice(`無法安全刪除：${error.message}。本頁與後台連結已保留。`, true);
        chrome.runtime.sendMessage({type: 'BAOHUI_FACEBOOK_DELETE_RESULT', payload: {...payload, ok:false, error:error.message}});
      });
      return true;
    }
    if (payload.action === 'mark_product_sold') {
      markProductSold(payload).catch(error => {
        showNotice(`無法安全標記已售出：${error.message}。貼文與後台連結已保留。`, true);
        chrome.runtime.sendMessage({type: 'BAOHUI_FACEBOOK_SOLD_RESULT', payload: {...payload, ok:false, error:error.message}});
      });
      return true;
    }
    if (payload.action === 'auction_reminder') {
      postAuctionReminder(payload).catch(error => {
        showNotice(`競標推文未送出：${error.message}。系統會保留紀錄供下次重試。`, true);
        chrome.runtime.sendMessage({type: 'BAOHUI_AUTO_FAILED', payload: {...payload, ok:false, error:error.message}});
      });
      return true;
    }
    if (payload.action === 'product_promotion') {
      postProductPromotion(payload).catch(error => {
        showNotice(`結標後產品推薦未送出：${error.message}。系統會保留供下次重試。`, true);
        chrome.runtime.sendMessage({type:'BAOHUI_AUTO_FAILED', payload:{...payload,ok:false,error:error.message}});
      });
      return true;
    }
    if (!payload.groupUrl) {
      pendingClaimStarted = false;
      return false;
    }
    prepare(payload).catch(error => {
      const isAdvertising = payload.kind === 'advertising_post' || payload.contentType === 'facebook_advertising_post';
      const attempt = Math.max(1, Number(payload._retryCount || 0) + 1);
      const willRetry = !isAdvertising && attempt < 2;
      showNotice(isAdvertising
        ? (willRetry
          ? `此廣告社團無法發文：${error.message}。第 1 次未完成，10 秒後關閉本頁並進行最後 1 次重試。`
          : `此廣告未完成：${error.message}。保留紀錄待核對，10 秒後關閉此工作分頁；禁止自動重發。`)
        : (willRetry
          ? `無法自動帶入：${error.message}。第 1 次未完成，10 秒後關閉異常分頁並進行最後 1 次重試。`
          : `無法自動帶入：${error.message}。第 2 次仍未完成，已停止這一筆並繼續下一筆。`), true);
      if (isUnpostableGroupError(error)) reportUnpostableGroup(payload, error);
      if (payload.kind === 'product_sale') chrome.runtime.sendMessage({type: 'BAOHUI_FACEBOOK_POST_CLICKED', payload: {...payload, ok:false, error:error.message}});
      if (payload.autoPublish) chrome.runtime.sendMessage({type: 'BAOHUI_AUTO_FAILED', payload: {...payload, error: error.message}});
    });
    return true;
  }
  function claimPending(attempt = 0) {
    if (pendingClaimStarted) return;
    chrome.runtime.sendMessage({type: 'BAOHUI_GET_PENDING'}, payload => {
      // A transient service-worker wake failure is not an empty queue. Retry
      // within this document instead of silently abandoning its assigned task.
      if (chrome.runtime.lastError) {
        if (attempt < 39) setTimeout(() => claimPending(attempt + 1), 500);
        return;
      }
      if (handlePendingPayload(payload)) return;
      if (attempt < 39) setTimeout(() => claimPending(attempt + 1), 500);
    });
  }
  function stableNotificationHash(value) {
    let hash = 2166136261;
    for (const char of String(value || '')) {
      hash ^= char.codePointAt(0);
      hash = Math.imul(hash, 16777619);
    }
    return (hash >>> 0).toString(16).padStart(8, '0');
  }

  function notificationDestination(rawHref) {
    let value = String(rawHref || '').replace(/&amp;/g, '&');
    for (let depth = 0; depth < 3; depth++) {
      let parsed;
      try { parsed = new URL(value, 'https://www.facebook.com/'); } catch (_) { return value; }
      let nested = '';
      for (const key of ['target_url', 'href', 'url', 'u']) {
        const candidate = parsed.searchParams.get(key);
        if (candidate && /facebook\.com|\/groups\//i.test(candidate)) { nested = candidate; break; }
      }
      if (!nested) return parsed.href;
      try { value = decodeURIComponent(nested); } catch (_) { value = nested; }
    }
    return value;
  }

  function canonicalOnecheepPost(rawHref) {
    const href = notificationDestination(rawHref);
    const postMatch = href.match(/\/groups\/onecheep\/(?:posts|permalink)\/([^/?#]+)/i);
    let parsed;
    try { parsed = new URL(href); } catch (_) { parsed = null; }
    const postId = postMatch?.[1] || parsed?.searchParams.get('post_id') || parsed?.searchParams.get('story_fbid') || '';
    if (!postId || !/\/groups\/onecheep\/?/i.test(parsed?.pathname || href)) return null;
    let commentId = '';
    if (parsed) commentId = parsed.searchParams.get('comment_id') || parsed.searchParams.get('reply_comment_id') || '';
    return {postId, commentId, postUrl: `https://www.facebook.com/groups/onecheep/posts/${postId}/`};
  }

  function recentNotificationMinutes(text) {
    const value = String(text || '').replace(/\s+/g, ' ').trim();
    if (/剛剛|幾秒前|數秒前|just now|a moment ago|seconds? ago/i.test(value)) return 0;
    let match = value.match(/(?:^|\s)(\d{1,2})\s*分鐘/);
    if (!match) match = value.match(/(?:^|\s)(\d{1,2})\s*(?:min|mins|minute|minutes)\b/i);
    return match ? Number(match[1]) : Number.POSITIVE_INFINITY;
  }

  function scanAuctionNotificationEvents() {
    const events = new Map();
    const actionPattern = /回應了|回覆了|留言|回應|回覆|commented|replied/i;
    for (const anchor of document.querySelectorAll('a[href]')) {
      const post = canonicalOnecheepPost(anchor.href || anchor.getAttribute('href'));
      if (!post) continue;
      let row = anchor;
      for (let depth = 0; depth < 8 && row?.parentElement; depth++) {
        const candidate = row.closest('[role="listitem"], [role="article"]') || row.parentElement;
        const candidateText = String(candidate?.innerText || candidate?.textContent || '').replace(/\s+/g, ' ').trim();
        row = candidate;
        if (candidateText.length >= 15 && candidateText.length <= 800 && actionPattern.test(candidateText)) break;
      }
      const text = String(row?.innerText || row?.textContent || anchor.innerText || '').replace(/\s+/g, ' ').trim();
      if (!actionPattern.test(text) || recentNotificationMinutes(text) > 15) continue;
      // Facebook rewrites "1 分鐘" to "2 分鐘" in place. Exclude that
      // changing age label so the same notification keeps one stable key.
      const identityText = text
        .replace(/(?:^|\s)\d{1,2}\s*分鐘/g, ' ')
        .replace(/(?:^|\s)\d{1,2}\s*(?:min|mins|minute|minutes)\b/gi, ' ')
        .replace(/剛剛|幾秒前|數秒前|just now|a moment ago|seconds? ago/gi, ' ')
        .replace(/\s+/g, ' ').trim();
      const keyMaterial = `${post.postId}|${post.commentId}|${identityText}`;
      const notificationKey = `${post.postId}:${post.commentId || 'notification'}:${stableNotificationHash(keyMaterial)}`;
      events.set(notificationKey, {notificationKey, postUrl: post.postUrl, postId: post.postId, commentId: post.commentId, text: text.slice(0, 500)});
    }
    return [...events.values()].slice(0, 50);
  }

  chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
    if (message && message.type === 'BAOHUI_SCAN_AUCTION_NOTIFICATIONS') {
      sendResponse({ok: true, events: scanAuctionNotificationEvents()});
      return false;
    }
    if (message && message.type === 'BAOHUI_PREPARE_TAB_CLOSE') {
      prepareFacebookTaskTabClose().then(sendResponse).catch(error => sendResponse({ok:false,error:error.message}));
      return true;
    }
  });
  // The hidden 15-minute notification scanner is observation-only. It must
  // never claim a queued Facebook publishing job for its temporary tab.
  if (!/^\/notifications\/?/i.test(location.pathname)) claimPending();
  setTimeout(() => syncFacebookScheduledSnapshot().catch(() => {}), 2500);
  // Facebook switches profiles through an in-page navigation. The original
  // content script can survive that switch, so retry periodically after the
  // Kuo Feng Cheng scheduled list appears instead of taking one startup shot.
  setInterval(() => syncFacebookScheduledSnapshot().catch(() => {}), 60000);

})();

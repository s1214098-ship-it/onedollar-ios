import {createAdLiveBridge} from './ad-live-bridge.mjs';
import {handoffDecision, taskReceiptKey} from './task-handoff.mjs';
const HANDOFF_RECEIPTS_KEY = 'baohuiTaskHandoffReceipts';
const adLive=createAdLiveBridge({chromeApi:chrome,onFinish:async(tabId,ok,receipt)=>{
  const pending=(await chrome.storage.local.get(PENDING_KEY))[PENDING_KEY]||{};
  if(pending[String(tabId)])await saveHandoffReceipt(pending[String(tabId)],receipt);
  await new Promise(resolve=>setTimeout(resolve,10000));
  await finishAutoTask(tabId,ok === true);
}});

async function saveHandoffReceipt(payload, receipt) {
  const records=(await chrome.storage.local.get(HANDOFF_RECEIPTS_KEY))[HANDOFF_RECEIPTS_KEY]||{};
  await chrome.storage.local.set({[HANDOFF_RECEIPTS_KEY]:{...records,[taskReceiptKey(payload)]:{...receipt,executionStartedAt:Number(payload._executionStartedAt||0),at:Date.now()}}});
}

async function holdTaskForReconciliation(tabId, reason) {
  const active=(await chrome.storage.local.get(AUTO_ACTIVE_KEY))[AUTO_ACTIVE_KEY]||{};
  if(Number(active.tabId)!==Number(tabId))return;
  await chrome.storage.local.set({[AUTO_ACTIVE_KEY]:{...active,paused:true,handoffHold:true,error:reason,pausedAt:Date.now()}});
  await chrome.alarms.clear(AUTO_WATCHDOG_ALARM).catch(()=>{});
}

function resolveFacebookDateCell(nodes, prefix) {
  const byId = new Map(nodes.map(node => [node.nodeId, node]));
  const hasDate = (node, seen = new Set()) => {
    if (!node || seen.has(node.nodeId)) return false;
    seen.add(node.nodeId);
    const name = String(node.name?.value || '').trim().replace(/^已選擇\s*/, '');
    if (name.startsWith(prefix)) return true;
    return (node.childIds || []).some(id => hasDate(byId.get(id), seen));
  };
  const matches = nodes.filter(node => ['cell','gridcell'].includes(node.role?.value)
    && !node.ignored && node.backendDOMNodeId
    && !(node.properties || []).some(p => p.name === 'disabled' && p.value?.value === true)
    && hasDate(node));
  if (matches.length !== 1) throw new Error('Facebook 日期格無法唯一確認：' + prefix + '（' + matches.length + ' 格）');
  return matches[0];
}

const PENDING_KEY = 'baohuiPendingFacebookPosts';
const AUTO_QUEUE_KEY = 'baohuiAutoPostingQueue';
const AUTO_ACTIVE_KEY = 'baohuiAutoPostingActive';
const AUCTION_HELPER_BUILD = 'batch-three-pass-v163';
const RUNTIME_VERSION_KEY = 'baohuiPostingHelperRuntimeVersion';
const AUTO_IDENTITY_KEY = 'baohuiAutoPostingIdentity';
const AD_NEXT_ALLOWED_KEY = 'baohuiAdvertisingNextAllowedAt';
const AD_IDENTITY_COOLDOWNS_KEY = 'baohuiAdvertisingIdentityCooldowns';
const AD_LAST_IDENTITY_KEY = 'baohuiAdvertisingLastIdentity';
const AD_WRITEBACK_ALARM = 'baohui-ad-writeback-retry';
const AD_DEFERRED_ALARM = 'baohui-ad-deferred-shift';
const AD_DEFERRED_KEY = 'baohuiAdvertisingDeferredShift';
let advertisingShiftBusy = false;
function currentTaipeiAdvertisingSlot(now=new Date()) {
  const parts=new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Taipei',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',hourCycle:'h23'}).formatToParts(now);
  const fields=Object.fromEntries(parts.map(p=>[p.type,p.value]));
  return {date:`${fields.year}-${fields.month}-${fields.day}`,hour:fields.hour};
}
async function deferAdvertisingShift(hour,reason) {
  const current=currentTaipeiAdvertisingSlot();
  if(String(hour).padStart(2,'0')!==current.hour)return;
  await chrome.storage.local.set({[AD_DEFERRED_KEY]:{...current,reason,at:Date.now()}});
  await chrome.alarms.create(AD_DEFERRED_ALARM,{when:Date.now()+60000});
}
async function resumeDeferredAdvertisingShift() {
  const row=(await chrome.storage.local.get(AD_DEFERRED_KEY))[AD_DEFERRED_KEY];
  if(!row)return;
  const current=currentTaipeiAdvertisingSlot();
  if(row.date!==current.date||row.hour!==current.hour){await chrome.storage.local.remove(AD_DEFERRED_KEY);return;}
  await runAdvertisingShift(row.hour);
}
async function runAdvertisingShift(hour) {
  if(advertisingShiftBusy)return;
  advertisingShiftBusy=true;
  try{await startAdvertisingShift(hour);}
  catch(error){await deferAdvertisingShift(hour,String(error.message||'後台尚未交付廣告工作'));}
  finally{advertisingShiftBusy=false;}
}
let adWritebackBusy = false;
async function flushAdvertisingWritebacks(){
  // V1 outboxes have no claim owner. Preserve them for audit, never replay them
  // through the new completion gate or infer completion from a legacy ACK.
  try{return await adLive.recover();}catch(error){await chrome.storage.local.set({baohuiAdvertisingWritebackError:{error:error.message,at:Date.now()}});}
/*
  if(adWritebackBusy)return;
  adWritebackBusy=true;
  try{
    const all=await chrome.storage.local.get(null);
    for(const [key,item] of Object.entries(all)){
      if(!key.startsWith('baohuiAdvertisingWriteback:')||!item?.body)continue;
      if(new Date(item.at).toLocaleDateString('en-CA',{timeZone:'Asia/Taipei'})!==new Date().toLocaleDateString('en-CA',{timeZone:'Asia/Taipei'}))continue;
      const response=await fetch('https://baohui.paohui.org/ad-posting-api.php?action=update',{method:'POST',credentials:'include',headers:{'Content-Type':'application/json'},body:JSON.stringify(item.body)});
      const result=await response.json();
      if(!response.ok||!result.ok)throw new Error(result.error||'後台證據回寫失敗');
      await chrome.storage.local.set({baohuiAdvertisingLastResult:{...item.body,at:Date.now(),writebackConfirmed:true}});
      await chrome.storage.local.remove(key);
    }
  }catch(error){await chrome.storage.local.set({baohuiAdvertisingWritebackError:{error:error.message,at:Date.now()}})}finally{adWritebackBusy=false}
*/
}
const AUCTION_NEXT_ALLOWED_KEY = 'baohuiAuctionNextAllowedAt';
const WORK_MODE_KEY = 'baohuiPostingWorkMode';
const REUSABLE_FACEBOOK_TAB_KEY = 'baohuiReusableFacebookWorkTab';
const AD_ADMIN_TAB_KEY = 'baohuiAdvertisingAdminWorkTab';
const AUCTION_BACKEND_HOLD_KEY = 'baohuiAuctionBackendHold';
const REJECTION_QUEUE_KEY = 'baohuiGroupRejectionQueue';
const ADMIN_URL = 'https://baohui.paohui.org/admin-ad-posting.php';
const AUCTION_API_URL = 'https://baohui.paohui.org/one-dollar-auction/facebook-auction-worker-queue.php';
const AUCTION_POSTING_IDENTITY = 'Kuo Feng Cheng';
const AUCTION_REMINDER_IDENTITY = 'Kuo Feng Cheng';
const WINNER_NOTICE_IDENTITY = '海賊團行政專員';
const BID_CLOSE_IDENTITY = 'YUKI LIU';
const FAN_PAGE_IDENTITY = '寶輝科技官方網站 粉絲團';
const AD_POSTING_IDENTITIES = ['YUKI LIU', '海賊團行政專員', '寶輝科技官方網站 粉絲團'];
const AD_POSTING_IDENTITY_ALIASES = {
  '寶輝科技有限公司 頭城竹安店': '寶輝科技官方網站 粉絲團',
  'KUO GFENG CHENG': 'Kuo Feng Cheng',
  'KUO FENG CHENG': 'Kuo Feng Cheng'
};
function canonicalAdIdentity(name) {
  const normalized = String(name || '').replace(/\s+/g, ' ').trim();
  return AD_POSTING_IDENTITY_ALIASES[normalized] || normalized;
}
function isApprovedAdIdentity(name) {
  const normalized = String(name || '').replace(/\s+/g, ' ').trim();
  const canonical = canonicalAdIdentity(normalized);
  return AD_POSTING_IDENTITIES.includes(canonical)
    || normalized === '寶輝科技有限公司 頭城竹安店';
}
// All three business identities live inside the owner's single green Chrome
// profile. The queue may switch Facebook identities, but never Chrome profiles.
const ADVERTISING_ONLY_MODE = false;
const AUCTION_LISTING_CYCLE_MINUTES = 60;
const AUCTION_REMINDER_CYCLE_MINUTES = 30;
// Wake the advertising scheduler at every hour. The backend remains
// authoritative for the rotating identity and eight regional posts in each
// hourly slot (two each in North, Central, South and East).
const ADVERTISING_SHIFT_HOURS = Array.from({length: 24}, (_, hour) => hour);
// 一元競標與價格提醒各以一小時為業務輪次。這個一分鐘 heartbeat
// 只負責準時接住 30/15/5/1 分鐘提醒及延長賽，不會改變三層優先順序。
const AUCTION_POLL_MINUTES = 1;
const AUCTION_ITEM_GAP_MS = 10000;
const FAILED_TAB_INSPECTION_MS = 10000;
// A Facebook document can take well over ten seconds to reach document_idle
// under Chrome memory pressure. Do not mark an unclaimed task failed while its
// content script has not had a fair chance to start.
const FACEBOOK_PRECLAIM_TIMEOUT_MS = 60000;
const MAX_TASK_ATTEMPTS = 2;
const AD_PREPARED_PUBLISH_DELAY_MS = 5000;
const AD_BETWEEN_POSTS_MS = 10000;
const AUCTION_LISTING_GRACE_MS = 10 * 60 * 1000;
const AUTO_WATCHDOG_ALARM = 'baohui-auto-task-watchdog';
const NEXT_SCHEDULED_ALARM = 'baohui-next-scheduled-task';
const FAILED_RECOVERY_ALARM = 'baohui-failed-task-recovery';
const FAILED_RECOVERY_KEY = 'baohuiFailedTaskRecovery';

// One-time recovery for the 2026-09-16 noon batch. Every listed task was
// verified server-side as failed-before-submit with no post id, permalink,
// evidence or outbox. Remove only those local lookup-only contracts when this
// build is reloaded; all other anti-duplicate records remain untouched.
const VERIFIED_NO_SUBMIT_RETRY_KEYS = [
  'ad-v2-task:2026-09-16|12:00|FB0045',
  'ad-v2-task:2026-09-16|12:00|FB0235',
  'ad-v2-task:2026-09-16|12:00|FB0137',
  'ad-v2-task:2026-09-16|12:00|FB0161',
  'ad-v2-task:2026-09-16|12:00|FB0156',
  // 2026-09-19 13:03: Facebook identity guard stopped FB0215 before
  // submission; backend phase is failed_before_submit with no evidence.
  'ad-v2-task:2026-09-19|13:00|FB0215'
];
const AUCTION_NOTIFICATION_SCAN_ALARM = 'baohui-auction-notification-scan';
const AUCTION_NOTIFICATION_SEEN_KEY = 'baohuiAuctionNotificationSeen';
const AUCTION_NOTIFICATION_SCAN_MINUTES = 15;
let autoQueueBusy = false;
let auctionPollBusy = false;
let auctionPollTabId = 0;
let auctionNotificationScanBusy = false;
let queueMutationChain = Promise.resolve();
const failureRecoveryTabs = new Set();
const failureRecoveryRunningTabs = new Set();

function enforceWeekendAuctionClose(task) {
  if (!task || typeof task !== 'object') return task;
  const isAuction = task.kind === 'auction_listing' || task.kind === 'auction_reminder' || task.contentType === 'facebook_auction_listing' || task.contentType === 'facebook_auction_reminder';
  if (!isAuction) return task;
  const oldClose = String(task.closeAt || task.scheduledWindow?.end || '').trim();
  const match = oldClose.match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})/);
  if (!match) return task;
  const noon = new Date(`${match[1]}T12:00:00`);
  if (![0, 6].includes(noon.getDay()) || match[2] === '23:59') return task;
  const corrected = `${match[1]} 23:59`;
  task.closeAt = corrected;
  if (task.scheduledWindow && typeof task.scheduledWindow === 'object') {
    task.scheduledWindow.end = corrected;
    task.scheduledWindow.latestPublishAt = `${match[1]} 17:59`;
  }
  if (typeof task.copy === 'string') task.copy = task.copy.split(oldClose).join(corrected);
  if (task.copy && typeof task.copy === 'object' && typeof task.copy.text === 'string') task.copy.text = task.copy.text.split(oldClose).join(corrected);
  return task;
}

function enforceAuctionMinimumPricing(task) {
  if (!task || typeof task !== 'object') return task;
  const isAuction = task.kind === 'auction_listing' || task.kind === 'auction_reminder' || task.contentType === 'facebook_auction_listing' || task.contentType === 'facebook_auction_reminder';
  if (!isAuction) return task;
  const normalize = text => String(text || '')
    .replace(/(起標價\s*[：:]\s*NT\$\s*)(?:1|10)(?!\d)/g, (_m, prefix) => `${prefix}30`)
    .replace(/(每筆加價[^\n]{0,40}?NT\$\s*)(?:1|10)(\s*[～~-]\s*NT\$\s*2,?000)/g, (_m, prefix, suffix) => `${prefix}30${suffix}`)
    .replace(/(每次(?:至少|最低)[^0-9\n]{0,20})(?:1|10)(?!\d)/g, (_m, prefix) => `${prefix}30`)
    .replace(/(Giá khởi điểm\s*[：:]\s*NT\$\s*)(?:1|10)(?!\d)/gi, (_m, prefix) => `${prefix}30`);
  task.startPrice = Math.max(30, Number(task.startPrice || 30));
  task.bidStep = Math.max(30, Number(task.bidStep || task.bidRules?.minimumIncrement || 30));
  if (task.bidRules && typeof task.bidRules === 'object') task.bidRules.minimumIncrement = 30;
  if (typeof task.copy === 'string') task.copy = normalize(task.copy);
  if (task.copy && typeof task.copy === 'object' && typeof task.copy.text === 'string') task.copy.text = normalize(task.copy.text);
  return task;
}

function taskQueuePriority(task) {
  if (!task) return 0;
  // A one-shot operator test may run ahead of historical advertising backlog,
  // but every advertising task must remain behind auction listings and price
  // reminders. This keeps the owner-approved lane order strict even in tests.
  if ((task.kind === 'advertising_post' || task.contentType === 'facebook_advertising_post') && task.forceImmediate === true) {
    return 350000 + Math.min(999, Math.max(0, Number(task.priority || 0)));
  }
  const isRealtimeReminder = (task.action === 'auction_reminder' || task.kind === 'auction_reminder' || task.contentType === 'facebook_auction_reminder')
    && /^realtime-/i.test(String(task.reminderPhase || task.reminder_phase || task.audit?.phase || ''));
  // Even a last-minute reminder stays behind a due listing. This keeps the
  // owner-approved three-lane order deterministic: listing, reminder, ads.
  if (isRealtimeReminder) return 550000 + Math.min(999, Math.max(0, Number(task.priority || 0)));
  // First resolve listings whose Facebook result is still unknown.  These
  // payloads only search for an existing post and cannot submit a duplicate.
  // Then handle near-term reservations and live-auction price reminders.
  if ((task.kind === 'auction_listing' || task.contentType === 'facebook_auction_listing') && task.lookupOnly === true) {
    return 700000 + Math.min(999, Math.max(0, Number(task.priority || 0)));
  }
  if (task.kind === 'auction_listing' || task.contentType === 'facebook_auction_listing') return 600000 + Math.min(999, Math.max(0, Number(task.priority || 0)));
  if (task.action === 'auction_reminder' || task.kind === 'auction_reminder' || task.contentType === 'facebook_auction_reminder') return 500000 + Math.min(999, Math.max(0, Number(task.priority || 0)));
  if (task.kind === 'advertising_post' || task.contentType === 'facebook_advertising_post') return 300000 + Math.min(999, Math.max(0, Number(task.priority || 0)));
  if (task.kind === 'product_sale' || task.contentType === 'facebook_product_sale') return 200000 + Math.min(999, Math.max(0, Number(task.priority || 0)));
  if (['delete_product_post', 'mark_product_sold'].includes(task.action)) return 100000;
  return 100;
}

function taskWorkMode(task) {
  if (!task) return 'advertising';
  const isAuction = task._identityGroup === 'auction-personal'
    || task.kind === 'auction_listing'
    || task.kind === 'auction_reminder'
    || task.contentType === 'facebook_auction_listing'
    || task.contentType === 'facebook_auction_reminder'
    || task.action === 'auction_reminder';
  return isAuction ? 'auction' : 'advertising';
}

function isBulkAdvertisingTask(task) {
  if (!task || typeof task !== 'object') return false;
  if (taskWorkMode(task) === 'auction') return false;
  return Boolean(String(task.slot || '').trim())
    || task.kind === 'advertising_post'
    || task.contentType === 'facebook_advertising_post';
}

function isAutomatedCommentTask(task) {
  if (!task || typeof task !== 'object') return false;
  return task.action === 'auction_reminder'
    || task.kind === 'auction_reminder'
    || task.contentType === 'facebook_auction_reminder';
}

function prepareQueueTask(task, order = 0) {
  const item = enforceAuctionMinimumPricing(task && typeof task === 'object' ? task : {});
  // Auction reminders are deliberately one concise line. Do not prepend a
  // system heading: Facebook reply editors can duplicate multiline content.
  if (isAutomatedCommentTask(item) && typeof item.copy === 'string') item.copy = item.copy.replace(/\s*\n+\s*/g, '｜').trim();
  const kind = String(item.kind || ''), contentType = String(item.contentType || ''), action = String(item.action || '');
  const isAuctionListing = kind === 'auction_listing' || contentType === 'facebook_auction_listing';
  // Preserve the operator's public date; create a native reservation in advance.
  if (isAuctionListing) {
    item.facebookNativeSchedule = item.publishMode !== 'instant';
    item.scheduleMode = item.facebookNativeSchedule ? 'facebook_native' : 'manual_instant';
  }
  // Preserve the requested identity verbatim. An unknown advertising identity
  // must fail closed in the Facebook verifier, never fall back to the Page.
  const requestedAdIdentity = String(item.requiredVisibleName || '').trim();
  item.requiredVisibleName = kind === 'auction_reminder' || action === 'auction_reminder' || contentType === 'facebook_auction_reminder'
      ? AUCTION_REMINDER_IDENTITY
      : kind === 'auction_listing' || contentType === 'facebook_auction_listing'
      ? AUCTION_POSTING_IDENTITY
      : requestedAdIdentity;
  if (kind === 'product_promotion' || contentType === 'facebook_product_promotion') {
    item.requiredVisibleName = BID_CLOSE_IDENTITY;
  }
  // 工作類型和 Facebook 身分分開：一般廣告只准 YUKI LIU／海賊團行政專員／官方粉絲團。
  item._identityGroup = isAuctionListing || isAutomatedCommentTask(item) ? 'auction-personal' : 'company-page';
  item._postingIdentity = item.requiredVisibleName;
  // Recalculate on every queue pass so tasks saved by an older extension
  // immediately adopt the current listing > reminder > other order.
  item._queuePriority = taskQueuePriority(item);
  if (!Number.isFinite(Number(item._queuedAt))) item._queuedAt = Date.now() + order;
  return item;
}

function isUrgentCrossIdentityTask(task, now = Date.now()) {
  if (!task || task._identityGroup !== 'auction-personal') return false;
  const isReminder = task.action === 'auction_reminder' || task.kind === 'auction_reminder' || task.contentType === 'facebook_auction_reminder';
  if (!isReminder) return false;
  const closeTs = Date.parse(String(task.closeAt || '').replace(' ', 'T'));
  return Number.isFinite(closeTs) && closeTs > now && closeTs - now <= 30 * 60 * 1000;
}

function sortAutoQueue(queue) {
  queue.forEach((task, index) => prepareQueueTask(task, index));
  queue.sort((left, right) => {
    const priority = Number(right._queuePriority || 0) - Number(left._queuePriority || 0);
    return priority || Number(left._queuedAt || 0) - Number(right._queuedAt || 0);
  });
  return queue;
}

function taskNotBefore(task) {
  const explicit = Number(task && task._notBefore || 0);
  if (explicit > Date.now()) return explicit;
  const isAuctionListing = task && (task.kind === 'auction_listing' || task.contentType === 'facebook_auction_listing');
  if (isAuctionListing && task.facebookNativeSchedule) return 0;
  if (!isAuctionListing || !task.scheduledAt) return 0;
  const parsed = Date.parse(String(task.scheduledAt).replace(' ', 'T'));
  return Number.isFinite(parsed) ? parsed : 0;
}

function isExpiredAuctionListingTask(task, now = Date.now()) {
  const isListing = task && (task.kind === 'auction_listing' || task.contentType === 'facebook_auction_listing');
  if (!isListing) return false;
  if (task.lookupOnly === true) return false;
  const rawStart = task.scheduledAt || task.scheduledWindow?.start || '';
  const startTs = Date.parse(String(rawStart).replace(' ', 'T'));
  return Number.isFinite(startTs) && startTs + AUCTION_LISTING_GRACE_MS <= now;
}

function appendAutoTask(queue, payload, order = 0) {
  queue.push(prepareQueueTask({...payload}, order));
  return sortAutoQueue(queue);
}

function mutateAutoQueue(operation) {
  const run = queueMutationChain.catch(() => {}).then(operation);
  queueMutationChain = run.then(() => undefined, () => undefined);
  return run;
}

function autoTaskDedupeKeys(task, dedupeField = '') {
  if (!task || typeof task !== 'object') return [];
  const keys = [];
  if(isBulkAdvertisingTask(task)&&task.protocol===2)keys.push(`ad-v2:${task.task_date}|${task.slot}|${task.targetId}`);
  const direct = dedupeField ? String(task[dedupeField] || '').trim() : '';
  if (direct) keys.push(`id:${direct}`);
  const isListing = task.kind === 'auction_listing' || task.contentType === 'facebook_auction_listing';
  if (isListing) {
    const scheduleId = String(task.scheduleId || task.schedule_id || task.targetId || '').trim();
    const productId = String(task.productId || task.product_id || '').trim();
    const closeDay = String(task.closeAt || task.scheduledWindow?.end || '').trim().slice(0, 10);
    if (scheduleId) keys.push(`schedule:${scheduleId}`);
    // Separate approved schedules may sell separate units. Deduplicate by task and schedule.
  }
  return [...new Set(keys)];
}

function dedupeStoredAutoQueue(queue) {
  const known = new Set();
  return (Array.isArray(queue) ? queue : []).filter(task => {
    if (!task || typeof task !== 'object') return false;
    const keys = autoTaskDedupeKeys(task, 'taskId');
    if (keys.length && keys.some(key => known.has(key))) return false;
    for (const key of keys) known.add(key);
    return true;
  });
}

function enqueueAutoTasks(tasks, dedupeField = '') {
  const incoming = (Array.isArray(tasks) ? tasks : [tasks]).filter(task => task && typeof task === 'object');
  return mutateAutoQueue(async () => {
    const stored = await chrome.storage.local.get([AUTO_QUEUE_KEY, AUTO_ACTIVE_KEY, PENDING_KEY]);
    const queue = Array.isArray(stored[AUTO_QUEUE_KEY]) ? stored[AUTO_QUEUE_KEY] : [];
    const known = new Set(queue.flatMap(item => autoTaskDedupeKeys(item, dedupeField)));
    {
      const active = stored[AUTO_ACTIVE_KEY] || {};
      const pending = stored[PENDING_KEY] || {};
      const activePayload = active.tabId ? pending[String(active.tabId)] : null;
      if (activePayload) for (const key of autoTaskDedupeKeys(activePayload, dedupeField)) known.add(key);
    }
    let added = 0;
    for (const task of incoming) {
      // Once an hourly advertising batch has actually been handed to the
      // helper, keep that batch durable until every item reaches a terminal
      // result. A newer clock hour may enqueue behind it, but must not erase
      // unfinished work from the already-started batch.
      const queuedTask = isBulkAdvertisingTask(task)
        ? {...task, _batchActivatedAt: Number(task._batchActivatedAt || 0) || Date.now()}
        : task;
      const keys = autoTaskDedupeKeys(queuedTask, dedupeField);
      const existingIndex = queue.findIndex(item => task.taskId && item.taskId === task.taskId);
      if (existingIndex >= 0 && queuedTask.claimToken && queue[existingIndex].claimToken !== queuedTask.claimToken) {
        queue[existingIndex] = prepareQueueTask(queuedTask, existingIndex);
        added++;
        continue;
      }
      if (existingIndex >= 0 && queuedTask.publishMode === 'instant') {
        queue[existingIndex] = {...queue[existingIndex], ...queuedTask, scheduledAt: '', facebookNativeSchedule: false};
        added++;
        continue;
      }
      if (keys.some(key => known.has(key))) continue;
      appendAutoTask(queue, queuedTask, queue.length);
      for (const key of keys) known.add(key);
      added++;
    }
    await chrome.storage.local.set({[AUTO_QUEUE_KEY]: queue});
    return {added, queueLength: queue.length};
  });
}

function nextLocalTime(hour) {
  const when = new Date();
  when.setHours(hour, 0, 0, 0);
  if (when.getTime() <= Date.now()) when.setDate(when.getDate() + 1);
  return when.getTime();
}

function nextAuctionPollTime() {
  const when = new Date();
  when.setSeconds(0, 0);
  when.setMinutes(when.getMinutes() + 1);
  return when.getTime();
}

async function installShiftAlarms() {
  const alarms = await chrome.alarms.getAll();
  await Promise.all(alarms.filter(alarm => alarm.name.startsWith('baohui-shift-')).map(alarm => chrome.alarms.clear(alarm.name)));
  for (const hour of ADVERTISING_SHIFT_HOURS) chrome.alarms.create(`baohui-shift-${hour}`, {when: nextLocalTime(hour), periodInMinutes: 1440});
  if (ADVERTISING_ONLY_MODE) {
    await chrome.alarms.clear('baohui-auction-poll');
    await chrome.alarms.clear(AUCTION_NOTIFICATION_SCAN_ALARM);
  } else {
    chrome.alarms.create('baohui-auction-poll', {when: nextAuctionPollTime(), periodInMinutes: AUCTION_POLL_MINUTES});
    chrome.alarms.create(AUCTION_NOTIFICATION_SCAN_ALARM, {when: Date.now() + 60000, periodInMinutes: AUCTION_NOTIFICATION_SCAN_MINUTES});
  }
}

function isExpiredAdvertisingTask(task, now = Date.now()) {
  if (!isBulkAdvertisingTask(task) || taskWorkMode(task) === 'auction') return false;
  // A batch marked here has already started. It must drain to a terminal
  // result even when the next hourly alarm fires. This marker is written only
  // when the backend has actually dispatched the batch, so merely having a
  // historical schedule does not create a backfill.
  if (Number(task._batchActivatedAt || 0) > 0) return false;
  // A controlled single test is an explicit operator action and may be used to
  // validate a just-finished slot. It still obeys identity cooldowns and all
  // publish/evidence gates; only the automatic past-slot drop is bypassed.
  if (task.controlledTest === true) return false;
  const taskDate = String(task.task_date || '').trim();
  const slot = String(task.slot || '').trim();
  if (!/^\d{4}-\d{2}-\d{2}$/.test(taskDate) || !/^(?:[01]\d|2[0-3]):00$/.test(slot)) return false;
  const slotStart = Date.parse(`${taskDate}T${slot}:00+08:00`);
  return Number.isFinite(slotStart) && now >= slotStart + 60 * 60 * 1000;
}

function waitForTabComplete(tabId, timeoutMs = 20000) {
  return new Promise(resolve => {
    let settled = false;
    const finish = value => {
      if (settled) return;
      settled = true;
      chrome.tabs.onUpdated.removeListener(listener);
      clearTimeout(timer);
      resolve(value);
    };
    const listener = (changedId, changeInfo) => {
      if (changedId === tabId && changeInfo.status === 'complete') finish(true);
    };
    const timer = setTimeout(() => finish(false), timeoutMs);
    chrome.tabs.onUpdated.addListener(listener);
    chrome.tabs.get(tabId).then(tab => {
      if (tab?.status === 'complete') finish(true);
    }).catch(() => finish(false));
  });
}

async function requestNotificationScan(tabId) {
  let lastError = null;
  for (let attempt = 0; attempt < 6; attempt++) {
    if (attempt) await new Promise(resolve => setTimeout(resolve, 1000));
    try {
      const response = await chrome.tabs.sendMessage(tabId, {type: 'BAOHUI_SCAN_AUCTION_NOTIFICATIONS'});
      if (response?.ok) return Array.isArray(response.events) ? response.events : [];
    } catch (error) { lastError = error; }
  }
  throw lastError || new Error('Facebook 通知頁尚未完成載入');
}

async function scanAuctionNotifications() {
  if (auctionNotificationScanBusy) return {created: 0, skipped: 0};
  auctionNotificationScanBusy = true;
  let tabId = 0;
  try {
    // The notification reader uses a temporary Facebook tab. Do not let it
    // overlap a publishing/reminder tab or interrupt a queued higher-priority
    // item merely to perform an observation-only scan.
    const state = await chrome.storage.local.get([AUTO_ACTIVE_KEY, AUTO_QUEUE_KEY]);
    const queue = Array.isArray(state[AUTO_QUEUE_KEY]) ? state[AUTO_QUEUE_KEY] : [];
    if (autoQueueBusy || state[AUTO_ACTIVE_KEY] || queue.some(task => taskNotBefore(task) <= Date.now())) {
      return {created:0, skipped:0, reason:'posting_worker_busy'};
    }
    const tab = await chrome.tabs.create({url: 'https://www.facebook.com/notifications/', active: false});
    tabId = Number(tab?.id || 0);
    if (!tabId) throw new Error('無法開啟 Facebook 通知頁');
    await waitForTabComplete(tabId);
    await new Promise(resolve => setTimeout(resolve, 2500));
    const scanned = await requestNotificationScan(tabId);
    if (!scanned.length) return {created: 0, skipped: 0};

    const stored = await chrome.storage.local.get(AUCTION_NOTIFICATION_SEEN_KEY);
    const seen = stored[AUCTION_NOTIFICATION_SEEN_KEY] && typeof stored[AUCTION_NOTIFICATION_SEEN_KEY] === 'object'
      ? stored[AUCTION_NOTIFICATION_SEEN_KEY] : {};
    const events = scanned.filter(event => event?.notificationKey && !seen[event.notificationKey]);
    if (!events.length) return {created: 0, skipped: scanned.length};

    const response = await fetch(`${AUCTION_API_URL}?action=api_notification_events`, {
      method: 'POST', credentials: 'include', cache: 'no-store',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({helperVersion: chrome.runtime.getManifest().version, events})
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.ok) throw new Error(data.error || `即時通知回報失敗 (${response.status})`);
    const handled = Array.isArray(data.handledKeys) ? data.handledKeys : [];
    const now = Date.now();
    for (const key of handled) seen[String(key)] = now;
    const compactSeen = Object.fromEntries(Object.entries(seen).sort((a, b) => Number(b[1]) - Number(a[1])).slice(0, 1000));
    await chrome.storage.local.set({[AUCTION_NOTIFICATION_SEEN_KEY]: compactSeen});
    if (Number(data.created || 0) > 0) await pollAuctionQueue();
    return data;
  } finally {
    if (tabId) await chrome.tabs.remove(tabId).catch(() => {});
    auctionNotificationScanBusy = false;
    processAutoQueue();
  }
}

async function reportAuctionResult(payload) {
  if (!payload || !['auction_listing', 'auction_reminder'].includes(payload.kind) || !payload.taskId) return;
  const report = {helperVersion:chrome.runtime.getManifest().version, claimToken: payload.claimToken || '', verifiedVisible: payload.verifiedVisible === true, submissionAccepted: payload.submissionAccepted === true, noBidReminder: payload.noBidReminder === true, skippedExpired: payload.skippedExpired === true, actualCloseAt: payload.actualCloseAt || '', outcomeUnknown: payload.outcomeUnknown === true, helperRetryExhausted: payload.helperRetryExhausted === true, helperAttempt: Math.max(1, Number(payload.helperAttempt || 1)), taskId: payload.taskId, ok: payload.ok !== false, scheduled: payload.scheduled === true, scheduledAt: payload.scheduledAt || '', postUrl: payload.postUrl || '', postId: payload.postId || '', commentUrl: payload.commentUrl || '', commentId: payload.commentId || '', bidEvidence: Array.isArray(payload.bidEvidence) ? payload.bidEvidence : null, currentBid: Number(payload.currentBid || 0), currentLeader: payload.currentLeader || '', violations: Array.isArray(payload.violations) ? payload.violations : [], reminderPhase: payload.reminderPhase || '', recoveredExistingReminder: payload.recoveredExistingReminder === true, reminderSignatureVersion: Number(payload.reminderSignatureVersion || 0), outbidNotified: Math.max(0, Number(payload.outbidNotified || 0)), outbidSkipped: Math.max(0, Number(payload.outbidSkipped || 0)), error: payload.error || '', _executionStartedAt:Number(payload._executionStartedAt||0)};
  const outboxKey = 'baohui-auction-result-' + report.taskId;
  await chrome.storage.local.set({[outboxKey]:report});
  try {
    const tabs = await chrome.tabs.query({url: 'https://baohui.paohui.org/*'});
    for (const tab of tabs) {
      if (!tab.id || /facebook-auction-worker-queue\.php\?action=api_due/.test(tab.url || '')) continue;
      try {
        const response = await chrome.tabs.sendMessage(tab.id, {type: 'BAOHUI_AUCTION_REPORT_REQUEST', payload: report});
        if (response?.ok && response.data?.ok === true) { await acknowledgeAuctionReport(payload, report, outboxKey); return response.data; }
      } catch (error) {}
    }
    const sent = await fetch(`${AUCTION_API_URL}?action=api_report`, {method: 'POST', credentials: 'include', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(report)});
    if (sent.ok) { const response=await sent.json(); if(response.ok) { await acknowledgeAuctionReport(payload, report, outboxKey); return response; } }
  } catch (error) {}
}

async function acknowledgeAuctionReport(payload, report, outboxKey) {
  await saveHandoffReceipt(payload,{writebackConfirmed:true,resultKnown:!report.outcomeUnknown,safeStopped:true,
    phase:report.outcomeUnknown?'submitted_unknown':report.ok?(report.scheduled?'facebook_scheduled':'published'):'stopped'});
  await chrome.storage.local.remove(outboxKey);
  const active=(await chrome.storage.local.get(AUTO_ACTIVE_KEY))[AUTO_ACTIVE_KEY];
  const pending=(await chrome.storage.local.get(PENDING_KEY))[PENDING_KEY]||{};
  if(active?.handoffHold&&pending[String(active.tabId)]?.taskId===report.taskId&&!report.outcomeUnknown)await finishAutoTask(active.tabId,report.ok);
}

async function flushAuctionReports() {
  const stored = await chrome.storage.local.get(null);
  for (const [key, report] of Object.entries(stored)) {
    if (!key.startsWith('baohui-auction-result-')) continue;
    const response = await fetch(AUCTION_API_URL+'?action=api_report',{method:'POST',credentials:'include',headers:{'Content-Type':'application/json'},body:JSON.stringify(report)}).catch(()=>null);
    if (response?.ok && (await response.json().catch(()=>({}))).ok) {
      await acknowledgeAuctionReport(report,report,key);
      const active=(await chrome.storage.local.get(AUTO_ACTIVE_KEY))[AUTO_ACTIVE_KEY];
      const pending=(await chrome.storage.local.get(PENDING_KEY))[PENDING_KEY]||{};
      if(active?.handoffHold&&pending[String(active.tabId)]?.taskId===report.taskId&&!report.outcomeUnknown)await finishAutoTask(active.tabId,report.ok);
    }
  }
}

async function enqueueAuctionTasks(tasks) {
  tasks = Array.isArray(tasks) ? tasks : [];
  if (!tasks.length) return {added: 0, queueLength: 0, received: 0};
  const result = await enqueueAutoTasks(tasks, 'taskId');
  if (!result.added) return {...result, received: tasks.length};
  chrome.notifications.create(`baohui-auction-${Date.now()}`, {
    type: 'basic', iconUrl: 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128"><rect width="128" height="128" rx="24" fill="%2312395b"/><text x="64" y="82" text-anchor="middle" font-size="64" fill="white">$1</text></svg>',
    title: `一元競標自動上架 ${result.added} 件`, message: '已依排程加入共用發文隊伍，每篇完成後才會處理下一篇。', priority: 2
  }).catch(() => {});
  processAutoQueue();
  return {...result, received: tasks.length};
}

async function pollAuctionQueue(taskIds = [], forceImmediate = false, busyAttempt = 0, operatorStart = false) {
  if (ADVERTISING_ONLY_MODE) return {added: 0, received: 0, disabled: true};
  if (auctionPollBusy || auctionPollTabId) {
    if (forceImmediate && Array.isArray(taskIds) && taskIds.length && busyAttempt < 12) {
      await new Promise(resolve => setTimeout(resolve, 900));
      return pollAuctionQueue(taskIds, true, busyAttempt + 1, operatorStart);
    }
    if (forceImmediate) throw new Error('發文助手正在處理上一筆，這筆未能立即加入；請稍後再按一次。');
    return {added: 0, received: 0};
  }
  auctionPollBusy = true;
  try {
    const requestedTaskIds = Array.isArray(taskIds) ? taskIds.map(value => String(value || '').trim()).filter(Boolean) : [];
    const query = new URLSearchParams({action: 'api_due'});
    query.set('helper_version', chrome.runtime.getManifest().version);
    query.set('helper_build', AUCTION_HELPER_BUILD);
    if (requestedTaskIds.length) query.set('task_ids', requestedTaskIds.join(','));
    if (operatorStart && requestedTaskIds.length) {
      query.set('operator_start', '1');
      query.set('prepare_scheduled', '1');
    } else if (forceImmediate && requestedTaskIds.length === 1) {
      query.set('force_task_id', requestedTaskIds[0]);
    }
    const response = await fetch(`${AUCTION_API_URL}?${query.toString()}`, {credentials: 'include', cache: 'no-store'});
    if (response.ok) {
      const data = await response.json();
      await chrome.storage.local.remove(AUCTION_BACKEND_HOLD_KEY);
      const result = await enqueueAuctionTasks(data.tasks);
      if (forceImmediate && !result.received) {
        const local = await chrome.storage.local.get([AUTO_ACTIVE_KEY, AUTO_QUEUE_KEY]);
        const active = local[AUTO_ACTIVE_KEY] || {};
        const queue = Array.isArray(local[AUTO_QUEUE_KEY]) ? local[AUTO_QUEUE_KEY] : [];
        if (active.tabId || queue.some(task => taskWorkMode(task) === 'auction')) {
          processAutoQueue();
          return {...result, retained:true};
        }
        throw new Error('後台沒有交付這筆即時上架任務，且助手沒有保留中的競標工作。');
      }
      return {...result, higherPriorityPending:data.higherPriorityPending === true, paused:data.paused === true};
    }
    if (response.status === 409) {
      let reason = '競標後台暫停中';
      try { reason = String((await response.clone().json()).error || reason); } catch (ignored) {}
      await chrome.storage.local.set({[AUCTION_BACKEND_HOLD_KEY]: {active:true, reason, at:Date.now()}});
      return {added:0, received:0, backendHold:true};
    }
    const tabs = await chrome.tabs.query({url:'https://baohui.paohui.org/*'});
    for (const tab of tabs) {
      if (!tab.id || /facebook-auction-worker-queue\.php\?action=api_due/.test(tab.url || '')) continue;
      try {
        const result = await chrome.tabs.sendMessage(tab.id, {type:'BAOHUI_FETCH_AUCTION_DUE', taskIds: requestedTaskIds, forceImmediate, operatorStart, helperBuild: AUCTION_HELPER_BUILD});
        if (result?.ok) {
          const queued = await enqueueAuctionTasks(result.tasks);
          if (forceImmediate && !queued.received) throw new Error('後台沒有交付這筆即時上架任務，請重新整理後再按一次。');
          return queued;
        }
      } catch (ignored) {}
    }
  } catch (error) {
    if (forceImmediate) throw error;
    // Background polling stays quiet; manual failures must reach the operator.
  } finally {
    auctionPollBusy = false;
  }
}

async function auctionWorkBlocksAdvertising() {
  // 廣告時段開始前一定重查後台，避免已到時的一元競標或價格提醒
  // 還沒進本機佇列時，廣告就先開頁執行。
  const backend = await pollAuctionQueue();
  if (!backend || backend.backendHold || backend.paused || backend.higherPriorityPending) return true;
  const result = await chrome.storage.local.get([AUTO_QUEUE_KEY, AUTO_ACTIVE_KEY]);
  // Priority availability was verified above; local due work and active work
  // still take precedence even if another identity owns the next ad.
  const active = result[AUTO_ACTIVE_KEY] || {};
  if (active.identityGroup === 'auction-personal'
      || ['auction_listing', 'auction_reminder', 'facebook_auction_listing', 'facebook_auction_reminder'].includes(String(active.kind || ''))) {
    return true;
  }
  const now = Date.now();
  const queue = Array.isArray(result[AUTO_QUEUE_KEY]) ? result[AUTO_QUEUE_KEY] : [];
  return queue.some(task => taskWorkMode(task) === 'auction' && taskNotBefore(task) <= now);
}

async function startAdvertisingShift(hour) {
  // The hourly alarm may wake while ads are disabled. Do not keep opening
  // empty administration tabs or announce a dispatch that never starts.
  const response=await fetchWithDeadline('https://baohui.paohui.org/ad-posting-api.php?action=list',{credentials:'include',cache:'no-store'},30000);
  const runtime=await response.json();
  if(!response.ok||!runtime.ok)throw Error('廣告後台資料無法讀取，未自動派送');
  if(runtime.state?.automation?.helper_v2_enabled!==true){await chrome.storage.local.remove(AD_DEFERRED_KEY);return;}
  const armedFrom=Date.parse(runtime.state?.automation?.helper_v2_armed_from||'');
  if(Number.isFinite(armedFrom)&&Date.now()<armedFrom)return;
  if (!ADVERTISING_ONLY_MODE && await auctionWorkBlocksAdvertising()) {
    // Auction work is already processed by processAutoQueue. Do not open or
    // focus an advertising tab while priority 1 or 2 still has due work.
    processAutoQueue();
    await deferAdvertisingShift(hour,'競標或價格提醒優先，完成後接回本時段廣告');
    chrome.notifications.create(`baohui-ad-wait-${Date.now()}`, {
      type: 'basic',
      iconUrl: 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128"><rect width="128" height="128" rx="24" fill="%23b45309"/><text x="64" y="82" text-anchor="middle" font-size="64" fill="white">1</text></svg>',
      title: `${hour}:00 廣告輪次等待中`,
      message: '先完成一元競標與價格提醒；廣告保留本整點輪次，不會插隊。',
      priority: 1
    }).catch(() => {});
    return;
  }
  await chrome.storage.local.remove(AD_DEFERRED_KEY);
  await chrome.alarms.clear(AD_DEFERRED_ALARM);
  chrome.notifications.create(`baohui-${Date.now()}`, {
    type: 'basic',
    iconUrl: 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128"><rect width="128" height="128" rx="24" fill="%23087f5b"/><text x="64" y="82" text-anchor="middle" font-size="64" fill="white">P</text></svg>',
    title: `${hour}:00 Facebook 每小時區域輪發`,
    message: '兩帳號各用自己的社團，共用北、中、南、東各最多 2 篇，每小時最多 8 篇。',
    priority: 2
  }).catch(() => {});
  const stored = await chrome.storage.local.get(AD_ADMIN_TAB_KEY);
  const storedId = Number(stored[AD_ADMIN_TAB_KEY] || 0);
  let oldTab = storedId ? await chrome.tabs.get(storedId).catch(() => null) : null;
  if (!oldTab || !oldTab.id || !/\/admin-ad-posting\.php/.test(String(oldTab.url || ''))) {
    const candidates = await chrome.tabs.query({url: `${ADMIN_URL}*`});
    oldTab = candidates
      .filter(tab => tab.id && /\/admin-ad-posting\.php/.test(String(tab.url || '')))
      .sort((a, b) => Number(b.lastAccessed || 0) - Number(a.lastAccessed || 0))[0] || null;
    if (oldTab?.id) await chrome.storage.local.set({[AD_ADMIN_TAB_KEY]: oldTab.id});
  }
  const slot = `${String(hour).padStart(2, '0')}:00`;
  const adminUrl = `${ADMIN_URL}?auto_start=1&slot=${encodeURIComponent(slot)}`;
  if (oldTab && oldTab.id && /\/admin-ad-posting\.php/.test(String(oldTab.url || ''))) {
    await chrome.tabs.update(oldTab.id, {url:adminUrl, active:false});
    return;
  }
  const tab = await chrome.tabs.create({url:adminUrl, active:false});
  if (tab && tab.id) {
    await chrome.storage.local.set({[AD_ADMIN_TAB_KEY]: tab.id});
  }
}

async function getReusableFacebookWorkTab() {
  const stored = await chrome.storage.local.get(REUSABLE_FACEBOOK_TAB_KEY);
  const storedId = Number(stored[REUSABLE_FACEBOOK_TAB_KEY] || 0);
  const existing = storedId ? await chrome.tabs.get(storedId).catch(() => null) : null;
  if (existing && existing.id) return existing;
  const tab = await chrome.tabs.create({url:'about:blank', active:false});
  if (!tab || !tab.id) throw new Error('無法建立 Facebook 固定工作分頁');
  await chrome.storage.local.set({[REUSABLE_FACEBOOK_TAB_KEY]:tab.id});
  return tab;
}

async function closeLegacyAuctionPollTabs() {
  const tabs = await chrome.tabs.query({url:'https://baohui.paohui.org/one-dollar-auction/facebook-auction-worker-queue.php?action=api_due*'});
  const ids = tabs.map(tab => tab.id).filter(Boolean);
  if (ids.length) await chrome.tabs.remove(ids).catch(() => {});
  auctionPollTabId = 0;
}

async function acceptAuctionDueResponse(tasks, tabId) {
  try {
    await enqueueAuctionTasks(tasks);
  } finally {
    if (tabId) chrome.tabs.remove(tabId).catch(() => {});
    if (auctionPollTabId === tabId) auctionPollTabId = 0;
  }
}

function resolveFacebookTargetUrl(payload, preferPostUrl = false) {
  const task = payload && typeof payload === 'object' ? payload : {};
  if(task.recoveryOnly === true && task.recoveryPostUrl) {
    try { const p=new URL(task.recoveryPostUrl), g=new URL(task.groupUrl); if(p.hostname==='www.facebook.com'&&p.pathname.startsWith(g.pathname.replace(/\/+$/,'')+'/posts/')&&/\/posts\/\d+\/?$/.test(p.pathname)) return p.href; } catch (_) {}
  }
  const candidates = preferPostUrl
    ? [task.postUrl, task.post_url, task.facebookPostUrl, task.facebook_post_url]
    : [task.groupUrl, task.group_url, task.targetUrl, task.target_url, task.url];
  for (const candidate of candidates) {
    const value = String(candidate || '').trim();
    if (!value) continue;
    try {
      const parsed = new URL(value);
      if (/^(?:www\.)?facebook\.com$/i.test(parsed.hostname)) return parsed.href;
    } catch (_) {}
  }
  return '';
}

function notifyInvalidFacebookTarget(payload) {
  const name = String(payload?.targetName || payload?.productName || payload?.targetId || '這筆發文任務').trim();
  chrome.notifications.create('', {
    type: 'basic',
    iconUrl: 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128"><rect width="128" height="128" rx="24" fill="%23b42318"/><text x="64" y="82" text-anchor="middle" font-size="64" fill="white">!</text></svg>',
    title: '寶輝自動發文未執行',
    message: `${name} 沒有正確的 Facebook 網址，已略過且不會再開空白分頁。`
  }).catch(() => {});
}

async function validateAuctionDispatch(payload, phase = 'check') {
  if (!payload || !['auction_listing', 'auction_reminder'].includes(payload.kind)) return {ok:true};
  if (!payload.taskId || !payload.claimToken) throw new Error('舊版任務沒有本次授權，已停止；請從後台重新核對');
  const response = await fetchWithDeadline(AUCTION_API_URL + '?action=api_preflight', {
    method:'POST', credentials:'include', cache:'no-store', headers:{'Content-Type':'application/json'},
    body:JSON.stringify({taskId:payload.taskId,claimToken:payload.claimToken,phase,bidEvidence:payload.bidEvidence,currentBid:payload.currentBid,currentLeader:payload.currentLeader})
  }, 30000);
  const data = await response.json();
  if (!response.ok || data.ok !== true) throw new Error(data.error || '無法確認任務有效，已停止送出');
  return data;
}

async function fetchWithDeadline(url, options, timeoutMs) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try { return await fetch(url, {...options, signal:controller.signal}); }
  finally { clearTimeout(timer); }
}

async function processAutoQueue() {
  if (autoQueueBusy || auctionNotificationScanBusy) return;
  autoQueueBusy = true;
  let openedTabId = 0;
  let dispatchedTask = null;
  try {
    const queued = await mutateAutoQueue(async () => {
      const result = await chrome.storage.local.get([AUTO_QUEUE_KEY, AUTO_ACTIVE_KEY, AUTO_IDENTITY_KEY, AD_NEXT_ALLOWED_KEY, AD_IDENTITY_COOLDOWNS_KEY, AUCTION_NEXT_ALLOWED_KEY, WORK_MODE_KEY, AD_LAST_IDENTITY_KEY]);
      const active = result[AUTO_ACTIVE_KEY];
      const rawQueue = Array.isArray(result[AUTO_QUEUE_KEY]) ? result[AUTO_QUEUE_KEY] : [];
      let queue = sortAutoQueue(dedupeStoredAutoQueue(rawQueue));
      // 一般廣告大量自動輪發已停用：清除尚未執行的廣告任務，保留自有競標流程。
      const now = Date.now();
      const permittedQueue = queue.filter(task => {
        if (ADVERTISING_ONLY_MODE && taskWorkMode(task) === 'auction') return false;
        if (isExpiredAuctionListingTask(task, now)) return false;
        // 廣告只執行目前有效整點。帳號冷卻或瀏覽器離線後，舊整點
        // 不得回補，以免解除限制時把歷史佇列一次灌回 Facebook。
        if (isExpiredAdvertisingTask(task, now)) return false;
        return !isBulkAdvertisingTask(task) || (task.groupApproved === true && task.protocol === 2 && !!task.task_date);
      });
      if (permittedQueue.length !== rawQueue.length) {
        const quarantined=rawQueue.filter(task=>isBulkAdvertisingTask(task)&&(task.protocol!==2||!task.task_date));
        if(quarantined.length)await chrome.storage.local.set({['ad-v2-legacy-queue:'+Date.now()]:quarantined});
        queue = permittedQueue;
        await chrome.storage.local.set({[AUTO_QUEUE_KEY]: queue});
      }
      if (active || !queue.length) return null;
      const adNextAllowed = Math.max(0, Number(result[AD_NEXT_ALLOWED_KEY] || 0));
      const identityCooldowns = result[AD_IDENTITY_COOLDOWNS_KEY] && typeof result[AD_IDENTITY_COOLDOWNS_KEY] === 'object'
        ? result[AD_IDENTITY_COOLDOWNS_KEY]
        : {};
      const auctionNextAllowed = Math.max(0, Number(result[AUCTION_NEXT_ALLOWED_KEY] || 0));
      const lastIdentity=result[AD_LAST_IDENTITY_KEY];
      const dueAt = task => Math.max(taskNotBefore(task), taskWorkMode(task) === 'advertising'
        ? Math.max(
          adNextAllowed,
          Math.max(0, Number(identityCooldowns[String(task.requiredVisibleName || '')] || 0)),
          lastIdentity && lastIdentity.name !== task.requiredVisibleName ? Number(lastIdentity.at||0)+300000 : 0
        )
        : auctionNextAllowed);
      // Business order is authoritative on every pass. A remembered advertising
      // mode must never block a due listing or price reminder merely to avoid an
      // account switch. Within auction work, sortAutoQueue keeps listing first
      // and reminder second; advertising is considered only when neither is due.
      const dueAuctionIndex = queue.findIndex(task => taskWorkMode(task) === 'auction' && dueAt(task) <= now);
      const dueAdvertisingIndex = queue.findIndex(task => taskWorkMode(task) === 'advertising' && dueAt(task) <= now);
      const dueIndex = dueAuctionIndex >= 0 ? dueAuctionIndex : dueAdvertisingIndex;
      const workMode = dueAuctionIndex >= 0 ? 'auction' : dueAdvertisingIndex >= 0 ? 'advertising' : 'waiting';
      if (dueIndex < 0) {
        const nextTime = Math.min(...queue.map(dueAt).filter(value => value > now));
        if (Number.isFinite(nextTime)) chrome.alarms.create(NEXT_SCHEDULED_ALARM, {when: nextTime});
        await chrome.storage.local.set({[AUTO_QUEUE_KEY]: queue, [WORK_MODE_KEY]: workMode});
        return null;
      }
      const [next] = queue.splice(dueIndex, 1);
      await chrome.storage.local.set({[AUTO_QUEUE_KEY]: queue, [AUTO_IDENTITY_KEY]: next._identityGroup, [WORK_MODE_KEY]: workMode, [AUTO_ACTIVE_KEY]: {opening: true, payload: next, targetId: next.targetId, identityGroup: next._identityGroup, startedAt: Date.now()}});
      return next;
    });
    if (!queued) { autoQueueBusy = false; return; }
    dispatchedTask = queued;
    // Poll the backend immediately before starting lower-priority work. This
    // closes the gap between minute alarms: a newly due listing or reminder
    // is inserted and sorted ahead of ads and cross-group product posts.
    if (!ADVERTISING_ONLY_MODE && taskWorkMode(queued) !== 'auction') {
      const helperVersion = encodeURIComponent(chrome.runtime.getManifest().version);
      const priorityResponse = await fetchWithDeadline(`${AUCTION_API_URL}?action=api_due&helper_version=${helperVersion}&helper_build=${encodeURIComponent(AUCTION_HELPER_BUILD)}`, {credentials:'include', cache:'no-store'}, 30000).catch(() => null);
      if (!priorityResponse) {
        await chrome.storage.local.set({[AUCTION_BACKEND_HOLD_KEY]: {active:true, reason:'競標後台暫時無法連線，其他合法身分繼續', at:Date.now()}});
        throw Error('auction_priority_unavailable');
      } else if (priorityResponse.status === 409) {
        let reason = '競標後台暫停中';
        try { reason = String((await priorityResponse.clone().json()).error || reason); } catch (ignored) {}
        await chrome.storage.local.set({[AUCTION_BACKEND_HOLD_KEY]: {active:true, reason, at:Date.now()}});
        throw Error('auction_priority_unavailable');
      } else if (!priorityResponse.ok) {
        await chrome.storage.local.set({[AUCTION_BACKEND_HOLD_KEY]: {active:true, reason:`競標優先順序核對 HTTP ${priorityResponse.status}`, at:Date.now()}});
        throw Error('auction_priority_unavailable');
      } else {
        await chrome.storage.local.remove(AUCTION_BACKEND_HOLD_KEY);
        const priorityData = await priorityResponse.json();
        if(priorityData.paused===true)throw Error('auction_priority_unavailable');
        await enqueueAuctionTasks(priorityData.tasks || []);
        const refreshed = await chrome.storage.local.get(AUTO_QUEUE_KEY);
        const waiting = sortAutoQueue(dedupeStoredAutoQueue(Array.isArray(refreshed[AUTO_QUEUE_KEY]) ? refreshed[AUTO_QUEUE_KEY] : []));
        if (priorityData.higherPriorityPending === true || waiting.some(task => taskWorkMode(task) === 'auction' && taskNotBefore(task) <= Date.now())) {
          await enqueueAutoTasks(queued);
          await chrome.storage.local.remove(AUTO_ACTIVE_KEY);
          autoQueueBusy = false;
          if (waiting.some(task => taskWorkMode(task) === 'auction' && taskNotBefore(task) <= Date.now())) processAutoQueue();
          else chrome.alarms.create(NEXT_SCHEDULED_ALARM, {when:Date.now() + 30000});
          return;
        }
      }
    }
    const isPostMaintenance = queued && ['delete_product_post', 'mark_product_sold'].includes(queued.action);
    const isDirectPost = queued && queued.action === 'auction_reminder';
    const payload = isPostMaintenance ? {...queued, _executionStartedAt:Date.now(), autoClose: true} : {...queued, _executionStartedAt:Date.now(), publishDelayMs: isBulkAdvertisingTask(queued) ? AD_PREPARED_PUBLISH_DELAY_MS : 10000, autoPublish: true, autoClose: queued.controlledTest === true ? false : true};
    await validateAuctionDispatch(payload);
    const targetUrl = resolveFacebookTargetUrl(payload, isPostMaintenance || isDirectPost);
    if (!targetUrl) {
      notifyInvalidFacebookTarget(payload);
      throw new Error('發文任務缺少正確的 Facebook 網址');
    }
    const tab = await getReusableFacebookWorkTab();
    if (!tab || !tab.id) throw new Error('無法開啟發文頁');
    openedTabId = tab.id;
    const pendingResult = await chrome.storage.local.get(PENDING_KEY);
    const pending = pendingResult[PENDING_KEY] || {};
    pending[String(tab.id)] = {...payload, targetTabId: tab.id};
    await chrome.storage.local.set({
      [PENDING_KEY]: pending,
      [AUTO_ACTIVE_KEY]: {tabId: tab.id, targetId: payload.targetId, materialId: payload.materialId, kind: payload.kind || payload.contentType || '', identityGroup: payload._identityGroup || '', startedAt: Date.now()}
    });
    // tabs.update() to the exact URL already shown in the reusable tab may
    // leave the document untouched. After an extension reload that document's
    // old content script has an invalid runtime and cannot claim this payload.
    // This tab is dedicated to the serialized worker; refresh it before the
    // watchdog starts whenever navigation would otherwise be a no-op.
    const currentUrl = String(tab.url || '').replace(/\/+$/, '');
    const nextUrl = String(targetUrl).replace(/\/+$/, '');
    // Facebook's profile switcher may render in an inactive work tab, yet
    // ignore debugger mouse input without committing the profile navigation.
    // Keep the one serialized work tab foreground while its identity and
    // posting result are being verified. Never fall back to another account.
    if (currentUrl === nextUrl) {
      await chrome.tabs.update(tab.id, {active:true});
      await chrome.tabs.reload(tab.id);
    } else await chrome.tabs.update(tab.id, {url:targetUrl, active:true});
    // Detect only a navigation/content-script stall here. Once the content
    // script claims the payload, the normal composer watchdog remains active.
    setTimeout(async () => {
      const state = await chrome.storage.local.get(AUTO_ACTIVE_KEY).catch(() => ({}));
      const active = state[AUTO_ACTIVE_KEY] || {};
      if (active.tabId !== tab.id || active.claimedAt || active.paused) return;
      scheduleFailedTaskRecovery(payload, tab.id, 'Facebook 分頁載入後 10 秒仍未接手任務', 0);
    }, FACEBOOK_PRECLAIM_TIMEOUT_MS);
    // Native scheduled listings upload several images and then operate two
    // Facebook pickers. One minute can expire while the dialog is still valid.
    // Facebook may reload once after switching to the auction identity. Both
    // listings and reminders need enough time to return to the exact post,
    // inspect comments, and report the result before the watchdog fires.
    const isAuctionBrowserTask = payload.kind === 'auction_listing'
      || payload.kind === 'auction_reminder'
      || payload.contentType === 'facebook_auction_listing'
      || payload.contentType === 'facebook_auction_reminder';
    const watchdogMinutes = isAuctionBrowserTask || payload.protocol===2 ? 3 : 1;
    chrome.alarms.create(AUTO_WATCHDOG_ALARM, {delayInMinutes: watchdogMinutes});
    autoQueueBusy = false;
  } catch (error) {
    // A claimed backend task can fail before Facebook opens (for example,
    // preflight rejects changed schedule data). Keep the exact reason visible
    // instead of silently discarding the Chrome queue entry and waiting for a
    // misleading backend timeout.
    const failure = String(error && error.message || error || 'unknown_dispatch_error');
    await chrome.storage.local.set({baohuiLastDispatchError: {
      taskId: String(dispatchedTask && dispatchedTask.taskId || ''),
      kind: String(dispatchedTask && dispatchedTask.kind || ''),
      beforeFacebookTab: !openedTabId,
      error: failure,
      at: Date.now()
    }}).catch(() => {});
    console.error('BaoHui task dispatch failed', failure);
    if(openedTabId){
      await holdTaskForReconciliation(openedTabId,'分頁開啟後發生錯誤，先核對結果：'+failure);
      if(dispatchedTask?.kind?.startsWith('auction_'))await reportAuctionResult({...dispatchedTask,ok:false,outcomeUnknown:true,error:failure});
      autoQueueBusy=false;
      return;
    }
    await chrome.storage.local.remove(AUTO_ACTIVE_KEY).catch(() => {});
    autoQueueBusy = false;
    if(failure==='auction_priority_unavailable'&&dispatchedTask){
      await enqueueAutoTasks({...dispatchedTask,_notBefore:Date.now()+30000},'taskId');
      chrome.alarms.create(NEXT_SCHEDULED_ALARM,{when:Date.now()+30000});
      return;
    }
    // A transient preflight network failure happens before any Facebook tab
    // or publish click. Keep the exact backend claim in the local queue for a
    // short retry instead of dropping it and leaving a five-minute running
    // lease in the backend. A later 409 invalid-token response is not retried.
    if (!openedTabId && dispatchedTask && /Failed to fetch|NetworkError|network request failed|AbortError|競標優先順序核對失敗 HTTP 5\d\d/i.test(failure)) {
      const retries = Math.max(0, Number(dispatchedTask._dispatchFailureCount || 0));
      if (retries < 1) {
        await enqueueAutoTasks({...dispatchedTask, _dispatchFailureCount:retries + 1, _notBefore:Date.now() + 60000}, 'taskId').catch(() => {});
      } else if (taskWorkMode(dispatchedTask) === 'auction') {
        reportAuctionResult({...dispatchedTask, ok:false, helperRetryExhausted:true, error:failure}).catch(() => {});
      }
    }
    processAutoQueue();
  }
}

async function closeFacebookTaskTab(tabId) {
  if (!tabId) return;
  const target = {tabId};
  let attached = false;
  let closeSettled = false;
  let leaveConfirmed = false;
  // Clear a selected reply target before closing. Facebook treats the target
  // chip itself as an unsaved draft and otherwise opens a beforeunload prompt.
  await Promise.race([
    chrome.tabs.sendMessage(tabId, {type:'BAOHUI_PREPARE_TAB_CLOSE'}).catch(() => null),
    new Promise(resolve => setTimeout(resolve, 3000))
  ]);
  const boundedRemove = (timeoutMs = 1800) => Promise.race([
    chrome.tabs.remove(tabId).catch(() => {}),
    new Promise(resolve => setTimeout(resolve, timeoutMs))
  ]);
  const handleDialog = (source, method, params) => {
    if (source.tabId !== tabId || method !== 'Page.javascriptDialogOpening' || params?.type !== 'beforeunload') return;
    // The task has already finished or entered retry recovery. Confirm Facebook's
    // native leave prompt so one stale reply/composer draft cannot block the queue.
    leaveConfirmed = true;
    chrome.debugger.sendCommand(target, 'Page.handleJavaScriptDialog', {accept: true}).catch(() => {});
  };
  chrome.debugger.onEvent.addListener(handleDialog);
  try {
    await chrome.debugger.attach(target, '1.3');
    attached = true;
    await chrome.debugger.sendCommand(target, 'Page.enable').catch(() => {});
    const closeRequest = chrome.debugger.sendCommand(target, 'Page.close')
      .catch(boundedRemove)
      .finally(() => { closeSettled = true; });
    // A beforeunload dialog can keep Page.close pending. The event handler
    // confirms leaving so the queue can continue without operator input.
    await Promise.race([closeRequest, new Promise(resolve => setTimeout(resolve, 5000))]);
    if (!closeSettled || leaveConfirmed) await boundedRemove();
    // Chrome can display the native beforeunload prompt after Page.close was
    // requested. Retry the affirmative action for a few seconds instead of
    // leaving the serialized queue blocked behind that prompt.
    for (let attempt = 0; attempt < 10; attempt++) {
      const exists = await chrome.tabs.get(tabId).then(() => true, () => false);
      if (!exists) break;
      if (leaveConfirmed) await chrome.debugger.sendCommand(target, 'Page.handleJavaScriptDialog', {accept: true}).catch(() => {});
      await boundedRemove(400);
      await new Promise(resolve => setTimeout(resolve, 250));
    }
  } catch (error) {
    await boundedRemove();
  } finally {
    chrome.debugger.onEvent.removeListener(handleDialog);
    if (attached) await chrome.debugger.detach(target).catch(() => {});
  }
  return {closed: !(await chrome.tabs.get(tabId).then(() => true, () => false))};
}

async function runFailedTaskRecovery(tabId) {
  if (!tabId || failureRecoveryRunningTabs.has(tabId)) return;
  failureRecoveryRunningTabs.add(tabId);
  try {
    const state = await chrome.storage.local.get([AUTO_ACTIVE_KEY, FAILED_RECOVERY_KEY]).catch(() => ({}));
    const active = state[AUTO_ACTIVE_KEY] || {};
    const recovery = state[FAILED_RECOVERY_KEY] || {};
    if (Number(recovery.tabId || 0) !== Number(tabId)) return;
    const payload = recovery.payload || {};
    if(isBulkAdvertisingTask(payload)){await chrome.storage.local.remove(FAILED_RECOVERY_KEY);await adLive.fail(tabId,Error('legacy_recovery_not_retried'));await finishAutoTask(tabId,true);return;}
    const errorMessage = String(recovery.errorMessage || 'facebook_prepare_failed');
    if (active.tabId !== tabId || (/載入後 10 秒仍未接手/.test(errorMessage) && active.claimedAt)) return;
    await chrome.storage.local.remove(FAILED_RECOVERY_KEY).catch(() => {});
    await chrome.alarms.clear(FAILED_RECOVERY_ALARM).catch(() => {});
    if (isAutomatedCommentTask(payload)) {
      const uncertain = /未確認.{0,20}送出|可能已送出|facebook_reply_display_pending/i.test(errorMessage);
      await Promise.race([
        reportAuctionResult({...payload, ok:false, outcomeUnknown:uncertain, helperRetryExhausted:true, error:errorMessage}),
        new Promise(resolve => setTimeout(resolve, 5000))
      ]).catch(() => {});
      await finishAutoTask(tabId, false);
      return;
    }
    const retryCount = Math.max(0, Number(payload._retryCount || 0));
    const currentAttempt = retryCount + 1;
    if (currentAttempt < MAX_TASK_ATTEMPTS) {
      await enqueueAutoTasks({
        ...payload,
        _retryCount: retryCount + 1,
        _queuedAt: Date.now() - 60000,
        _notBefore: Date.now()
      }).catch(() => {});
      chrome.notifications.create(`baohui-task-retry-${Date.now()}`, {
        type: 'basic',
        iconUrl: 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128"><rect width="128" height="128" rx="24" fill="%23b45309"/><text x="64" y="82" text-anchor="middle" font-size="64" fill="white">2</text></svg>',
        title: 'Facebook 分頁異常：重試同一筆',
        message: `${payload.productTitle || payload.targetName || payload.productId || '目前工作'}第 1 次未完成，已關閉異常分頁並進行第 2 次。`,
        priority: 1
      }).catch(() => {});
    } else {
      await reportAuctionResult({...payload, ok:false, helperRetryExhausted:true, helperAttempt:currentAttempt, error:errorMessage});
      chrome.notifications.create(`baohui-task-skip-${Date.now()}`, {
        type: 'basic',
        iconUrl: 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128"><rect width="128" height="128" rx="24" fill="%23b42318"/><text x="64" y="82" text-anchor="middle" font-size="64" fill="white">!</text></svg>',
        title: '同一筆重試 2 次仍失敗',
        message: `${payload.productTitle || payload.targetName || payload.productId || '目前工作'}已記錄異常，現在繼續下一筆。`,
        priority: 1
      }).catch(() => {});
    }
    await finishAutoTask(tabId, taskWorkMode(payload) === 'advertising');
  } finally {
    failureRecoveryTabs.delete(tabId);
    failureRecoveryRunningTabs.delete(tabId);
  }
}

function scheduleFailedTaskRecovery(payload, tabId, errorMessage, delayMs = FAILED_TAB_INSPECTION_MS) {
  if(isBulkAdvertisingTask(payload)){
    // An hourly advertising target gets one helper attempt, including failures
    // before claim. A second automatic attempt in the same slot violates the
    // one-shot contract and may open a duplicate Facebook work tab.
    if(!adLive.has(tabId)){
      const retryCount=Math.max(0,Number(payload?._retryCount||0));
      chrome.storage.local.set({baohuiAdvertisingPreclaimFailure:{targetId:payload.targetId,slot:payload.slot,error:String(errorMessage||''),attempts:retryCount+1,at:Date.now()}}).catch(()=>{});
      finishAutoTask(tabId,true);
      return {retrying:false,outcomeUnknown:false,preclaim:true};
    }
    adLive.fail(tabId,new Error(errorMessage));
    return {retrying:false,outcomeUnknown:true,preclaim:false};
  }
  const retryCount = Math.max(0, Number(payload?._retryCount || 0));
  const currentAttempt = retryCount + 1;
  if (failureRecoveryTabs.has(tabId)) return {retrying: currentAttempt < MAX_TASK_ATTEMPTS, attempt: currentAttempt};
  failureRecoveryTabs.add(tabId);
  const waitMs = Math.max(0, Number(delayMs || 0));
  chrome.storage.local.set({
    [FAILED_RECOVERY_KEY]: {tabId, payload, errorMessage:String(errorMessage || ''), runAt:Date.now() + waitMs}
  }).then(() => chrome.alarms.create(FAILED_RECOVERY_ALARM, {when:Date.now() + waitMs})).catch(() => {});
  // Fast path while the MV3 worker remains awake. The alarm above is the
  // persistent fallback when Chrome suspends the worker before ten seconds.
  setTimeout(() => runFailedTaskRecovery(tabId), waitMs);
  return {retrying: currentAttempt < MAX_TASK_ATTEMPTS, attempt: currentAttempt};
}

const finishingAutoTabs = new Set();
async function finishAutoTask(tabId, resultConfirmed) {
  if (!tabId || finishingAutoTabs.has(tabId)) return;
  finishingAutoTabs.add(tabId);
  try {
    const result = await chrome.storage.local.get([PENDING_KEY, AUTO_ACTIVE_KEY, AD_NEXT_ALLOWED_KEY, AUCTION_NEXT_ALLOWED_KEY, REUSABLE_FACEBOOK_TAB_KEY]);
    const pending = result[PENDING_KEY] || {};
    const finishedPayload = pending[String(tabId)];
    const active = result[AUTO_ACTIVE_KEY] || {};
    if (active.tabId !== tabId) {
      // A late report from an older tab must never close the current task or
      // release its lock. Only retire this tab's own pending message; leave
      // unrelated/manual Facebook tabs untouched.
      if (finishedPayload) {
        delete pending[String(tabId)];
        await chrome.storage.local.set({[PENDING_KEY]:pending});
      }
      return;
    }
    const managed=finishedPayload&&(isBulkAdvertisingTask(finishedPayload)||['auction_listing','auction_reminder'].includes(finishedPayload.kind));
    const records=(await chrome.storage.local.get(HANDOFF_RECEIPTS_KEY))[HANDOFF_RECEIPTS_KEY]||{};
    let receipt=finishedPayload?records[taskReceiptKey(finishedPayload)]:null;
    if(receipt&&receipt.executionStartedAt!==Number(finishedPayload._executionStartedAt||0))receipt=null;
    // The content script never claimed this page, so it could not submit.
    // Keep the backend failure report durable before allowing the next item.
    if(managed&&!active.claimedAt&&!receipt&&finishedPayload.kind?.startsWith('auction_')) {
      await reportAuctionResult({...finishedPayload,ok:false,helperRetryExhausted:true,error:'內容腳本未接手，未送出；安全停止'});
      receipt=((await chrome.storage.local.get(HANDOFF_RECEIPTS_KEY))[HANDOFF_RECEIPTS_KEY]||{})[taskReceiptKey(finishedPayload)];
    }
    const outbox=finishedPayload?.taskId?(await chrome.storage.local.get('baohui-auction-result-'+finishedPayload.taskId))['baohui-auction-result-'+finishedPayload.taskId]:null;
    const decision=handoffDecision({managed:!!managed,receipt,outboxPending:!!outbox});
    if(!decision.release){await holdTaskForReconciliation(tabId,decision.reason);return;}
    delete pending[String(tabId)];
    const updates = {[PENDING_KEY]: pending};
    if (finishedPayload && isBulkAdvertisingTask(finishedPayload)) updates[AD_LAST_IDENTITY_KEY] = {name:finishedPayload.requiredVisibleName, at:Date.now()};
    if (active.kind === 'advertising_post' || active.kind === 'facebook_advertising_post') updates[AD_NEXT_ALLOWED_KEY] = Math.max(Number(result[AD_NEXT_ALLOWED_KEY] || 0), Date.now() + AD_BETWEEN_POSTS_MS);
    if (active.identityGroup === 'auction-personal' || ['auction_listing','auction_reminder','facebook_auction_listing','facebook_auction_reminder'].includes(active.kind)) updates[AUCTION_NEXT_ALLOWED_KEY] = Date.now() + AUCTION_ITEM_GAP_MS;
    if (Number(result[REUSABLE_FACEBOOK_TAB_KEY] || 0) === Number(tabId)) updates[REUSABLE_FACEBOOK_TAB_KEY] = 0;
    if (!resultConfirmed && finishedPayload) {
      // Preserve the lookup target for an indeterminate result. Closing the
      // helper-owned tab is not permission to retry or mark the post complete.
      updates.baohuiLastClosedUnconfirmedWorkTab = {
        taskId:String(finishedPayload.taskId || ''),
        targetUrl:String(finishedPayload.postUrl || finishedPayload.groupUrl || ''),
        closedAt:Date.now(),
        requiresFacebookReconciliation:true
      };
    }
    await chrome.storage.local.set(updates);
    await chrome.alarms.clear(AUTO_WATCHDOG_ALARM).catch(() => {});
    // A terminal task owns exactly this one work tab. Wait for its actual
    // closure before releasing the active lock and starting the next item.
    const closed = await closeFacebookTaskTab(tabId);
    if (!closed.closed) {
      await chrome.storage.local.set({[AUTO_ACTIVE_KEY]:{...active, paused:true, error:'Facebook 工作分頁未能關閉；已暫停下一筆以防開出多個分頁'}});
      chrome.notifications.create(`baohui-work-tab-blocked-${tabId}`, {
        type:'basic',
        iconUrl:'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128"><rect width="128" height="128" rx="24" fill="%23b42318"/><text x="64" y="82" text-anchor="middle" font-size="64" fill="white">!</text></svg>',
        title:'Facebook 工作分頁未關閉，已暫停下一筆',
        message:'請檢查目前開啟的 Facebook 視窗；助手不會再開第二個工作分頁。',
        priority:2
      }).catch(() => {});
      return;
    }
    await chrome.storage.local.remove(AUTO_ACTIVE_KEY);
    processAutoQueue();
    if (active.identityGroup === 'auction-personal'
        || ['auction_listing','auction_reminder','facebook_auction_listing','facebook_auction_reminder'].includes(active.kind)) {
      setTimeout(() => pollAuctionQueue().catch(() => {}), 1000);
    }
  } catch (error) {
    // Keep the active lock when cleanup cannot be verified. Opening another
    // Facebook work tab here would defeat the one-tab-at-a-time guarantee.
    console.error('BaoHui work-tab cleanup failed', error);
    await chrome.storage.local.get(AUTO_ACTIVE_KEY).then(stored => {
      const active = stored[AUTO_ACTIVE_KEY] || {};
      if (active.tabId === tabId) return chrome.storage.local.set({
        [AUTO_ACTIVE_KEY]: {...active, paused:true, error:'Facebook 工作分頁清理失敗；已暫停下一筆以防重複開頁'}
      });
    }).catch(() => {});
  } finally {
    finishingAutoTabs.delete(tabId);
  }
}

function pauseAutoTask(tabId, errorMessage) {
  chrome.storage.local.get(AUTO_ACTIVE_KEY, result => {
    const active = result[AUTO_ACTIVE_KEY] || {};
    if (active.tabId !== tabId) return;
    chrome.alarms.clear(AUTO_WATCHDOG_ALARM).catch(() => {});
    chrome.storage.local.set({
      [AUTO_ACTIVE_KEY]: {...active, paused: true, error: String(errorMessage || 'facebook_prepare_failed'), pausedAt: Date.now()}
    });
  });
}

function broadcastRejections(items) {
  if (!Array.isArray(items) || !items.length) return;
  chrome.tabs.query({url: 'https://baohui.paohui.org/*'}, tabs => {
    for (const tab of tabs) for (const payload of items) chrome.tabs.sendMessage(tab.id, {type: 'BAOHUI_GROUP_REJECTION', payload}).catch(() => {});
  });
}

async function clearVerifiedNoSubmitRetryContracts() {
  const stored = await chrome.storage.local.get(VERIFIED_NO_SUBMIT_RETRY_KEYS);
  const removable = VERIFIED_NO_SUBMIT_RETRY_KEYS.filter(key => {
    const record = stored[key];
    return record && record.phase === 'lookup_only' && !record.outbox;
  });
  if (removable.length) await chrome.storage.local.remove(removable);
  await chrome.storage.local.set({
    baohuiVerifiedNoSubmitRetryCleanup: {at: Date.now(), removed: removable}
  });
  return removable;
}

// Version 1.10.202 changes the authoritative advertising quota from the old
// multi-identity batch to one rotating identity and eight posts per hour. Drop
// only advertising jobs that have not started yet. A live active tab is left
// untouched so an atomic publish can finish and record its real outcome.
async function migrateToRotatingHourlyEight() {
  const marker = 'baohuiAdvertisingQuotaMigration:1.10.202';
  const stored = await chrome.storage.local.get([marker, AUTO_QUEUE_KEY, AUTO_ACTIVE_KEY, PENDING_KEY]);
  if (stored[marker]) return stored[marker];
  const active = stored[AUTO_ACTIVE_KEY] || {};
  const activeTabId = Number(active.tabId || 0);
  const queue = Array.isArray(stored[AUTO_QUEUE_KEY]) ? stored[AUTO_QUEUE_KEY] : [];
  const keptQueue = queue.filter(item => !isBulkAdvertisingTask(item));
  const pending = stored[PENDING_KEY] && typeof stored[PENDING_KEY] === 'object' ? {...stored[PENDING_KEY]} : {};
  let removedPending = 0;
  for (const [tabId, payload] of Object.entries(pending)) {
    if (isBulkAdvertisingTask(payload) && Number(tabId) !== activeTabId) {
      delete pending[tabId];
      removedPending++;
    }
  }
  const evidence = {
    at: Date.now(),
    removedQueued: queue.length - keptQueue.length,
    removedPending,
    preservedActiveTabId: activeTabId || 0
  };
  await chrome.storage.local.set({[AUTO_QUEUE_KEY]: keptQueue, [PENDING_KEY]: pending, [marker]: evidence});
  return evidence;
}

// Version 1.10.203 removes Kuo Feng Cheng from general advertising while
// preserving that identity for auction listing/reminder work. Clear only
// unstarted advertising work so the backend can refill the current/future
// slots with the two remaining approved advertising identities. An active
// publish tab is still allowed to finish its atomic result/writeback.
async function migrateToTwoAdvertisingIdentities() {
  const marker = 'baohuiAdvertisingIdentityMigration:1.10.203';
  const stored = await chrome.storage.local.get([
    marker,
    AUTO_QUEUE_KEY,
    AUTO_ACTIVE_KEY,
    PENDING_KEY,
    AD_IDENTITY_COOLDOWNS_KEY,
    AD_LAST_IDENTITY_KEY
  ]);
  if (stored[marker]) return stored[marker];
  const active = stored[AUTO_ACTIVE_KEY] || {};
  const activeTabId = Number(active.tabId || 0);
  const queue = Array.isArray(stored[AUTO_QUEUE_KEY]) ? stored[AUTO_QUEUE_KEY] : [];
  const keptQueue = queue.filter(item => !isBulkAdvertisingTask(item));
  const pending = stored[PENDING_KEY] && typeof stored[PENDING_KEY] === 'object' ? {...stored[PENDING_KEY]} : {};
  let removedPending = 0;
  for (const [tabId, payload] of Object.entries(pending)) {
    if (isBulkAdvertisingTask(payload) && Number(tabId) !== activeTabId) {
      delete pending[tabId];
      removedPending++;
    }
  }
  const cooldowns = stored[AD_IDENTITY_COOLDOWNS_KEY] && typeof stored[AD_IDENTITY_COOLDOWNS_KEY] === 'object'
    ? {...stored[AD_IDENTITY_COOLDOWNS_KEY]}
    : {};
  delete cooldowns[AUCTION_POSTING_IDENTITY];
  const lastIdentity = stored[AD_LAST_IDENTITY_KEY] && stored[AD_LAST_IDENTITY_KEY].name === AUCTION_POSTING_IDENTITY
    ? null
    : stored[AD_LAST_IDENTITY_KEY];
  const evidence = {
    at: Date.now(),
    removedIdentity: AUCTION_POSTING_IDENTITY,
    removedQueued: queue.length - keptQueue.length,
    removedPending,
    preservedActiveTabId: activeTabId || 0
  };
  const updates = {
    [AUTO_QUEUE_KEY]: keptQueue,
    [PENDING_KEY]: pending,
    [AD_IDENTITY_COOLDOWNS_KEY]: cooldowns,
    [marker]: evidence
  };
  if (lastIdentity) updates[AD_LAST_IDENTITY_KEY] = lastIdentity;
  else await chrome.storage.local.remove(AD_LAST_IDENTITY_KEY);
  await chrome.storage.local.set(updates);
  return evidence;
}

chrome.runtime.onInstalled.addListener(details => {
  let cooldownReady = Promise.resolve();
  // Version 1.10.200 narrows the already observed rate-limit window to the
  // identity that actually triggered it. Other approved identities may keep
  // working after the normal five-minute identity-switch spacing.
  if (details?.reason === 'update' && chrome.runtime.getManifest().version === '1.10.200') {
    const marker = 'baohuiIdentityRateLimitMigration:1.10.200';
    cooldownReady = chrome.storage.local.get([marker, AD_NEXT_ALLOWED_KEY, AD_IDENTITY_COOLDOWNS_KEY, AD_LAST_IDENTITY_KEY]).then(stored => {
      if (stored[marker]) return;
      const lastIdentity = isApprovedAdIdentity(String(stored[AD_LAST_IDENTITY_KEY]?.name || '').trim())
        ? canonicalAdIdentity(String(stored[AD_LAST_IDENTITY_KEY].name).trim())
        : FAN_PAGE_IDENTITY;
      const previousGlobal = Math.max(0, Number(stored[AD_NEXT_ALLOWED_KEY] || 0));
      const resumeAt = previousGlobal > Date.now() ? previousGlobal : Date.now() + 2 * 60 * 60 * 1000;
      const cooldowns = stored[AD_IDENTITY_COOLDOWNS_KEY] && typeof stored[AD_IDENTITY_COOLDOWNS_KEY] === 'object'
        ? {...stored[AD_IDENTITY_COOLDOWNS_KEY]}
        : {};
      cooldowns[lastIdentity] = Math.max(Number(cooldowns[lastIdentity] || 0), resumeAt);
      return chrome.storage.local.set({
        [marker]: {at: Date.now(), identity:lastIdentity, resumeAt},
        [AD_IDENTITY_COOLDOWNS_KEY]: cooldowns,
        [AD_NEXT_ALLOWED_KEY]: 0
      }).then(() => chrome.alarms.create(NEXT_SCHEDULED_ALARM, {when: resumeAt}));
    }).catch(() => {});
  }
  // Reload only the dedicated advertising backend so its old content-script
  // connection is replaced after an unpacked-extension update.
  chrome.tabs.query({url: 'https://baohui.paohui.org/admin-ad-posting.php*'}, tabs => {
    for (const tab of tabs) if (tab.id) chrome.tabs.reload(tab.id).catch(() => {});
  });
  closeLegacyAuctionPollTabs();
  installShiftAlarms();
  if (details && details.reason === 'install') {
    chrome.storage.local.remove([AUTO_QUEUE_KEY, AUTO_ACTIVE_KEY]);
    return;
  }
  cooldownReady.finally(() => migrateToRotatingHourlyEight())
    .then(() => migrateToTwoAdvertisingIdentities())
    .then(() => clearVerifiedNoSubmitRetryContracts())
    .catch(() => [])
    .finally(() => recoverAutoQueueAfterReload(true).catch(() => {}));
});

// Pressing Reload on chrome://extensions does not fire onStartup. Recover a
// stale active-tab lock immediately so queued auction jobs can open again.
async function preserveRecentStartedAdvertisingBatchAfterUpgrade() {
  const stored = await chrome.storage.local.get([AUTO_QUEUE_KEY, PENDING_KEY]);
  const queue = Array.isArray(stored[AUTO_QUEUE_KEY]) ? stored[AUTO_QUEUE_KEY] : [];
  const pending = stored[PENDING_KEY] && typeof stored[PENDING_KEY] === 'object' ? {...stored[PENDING_KEY]} : {};
  const candidates = [...queue, ...Object.values(pending)].filter(isBulkAdvertisingTask);
  const batches = candidates.map(task => {
    const taskDate = String(task.task_date || '').trim();
    const slot = String(task.slot || '').trim();
    if (!/^\d{4}-\d{2}-\d{2}$/.test(taskDate) || !/^(?:[01]\d|2[0-3]):00$/.test(slot)) return null;
    const startedAt = Date.parse(`${taskDate}T${slot}:00+08:00`);
    return Number.isFinite(startedAt) ? {key:`${taskDate}|${slot}`, startedAt} : null;
  }).filter(Boolean).sort((a, b) => b.startedAt - a.startedAt);
  const latest = batches[0];
  // This is an upgrade bridge, not a historical backfill. Only adopt the most
  // recent batch if it was scheduled no more than two hours ago.
  if (!latest || latest.startedAt > Date.now() || Date.now() - latest.startedAt > 2 * 60 * 60 * 1000) return false;
  const activatedAt = Date.now();
  let changed = false;
  const mark = task => {
    if (!isBulkAdvertisingTask(task)) return task;
    const key = `${String(task.task_date || '').trim()}|${String(task.slot || '').trim()}`;
    if (key !== latest.key || Number(task._batchActivatedAt || 0) > 0) return task;
    changed = true;
    return {...task, _batchActivatedAt:Number(task._queuedAt || 0) || activatedAt};
  };
  const nextQueue = queue.map(mark);
  for (const [tabId, task] of Object.entries(pending)) pending[tabId] = mark(task);
  if (changed) await chrome.storage.local.set({[AUTO_QUEUE_KEY]:nextQueue, [PENDING_KEY]:pending});
  return changed;
}

async function recoverAutoQueueAfterReload(forceReloadRecovery = false) {
  await installShiftAlarms();
  await preserveRecentStartedAdvertisingBatchAfterUpgrade();
  const result = await chrome.storage.local.get([AUTO_ACTIVE_KEY, PENDING_KEY]);
  const active = result[AUTO_ACTIVE_KEY] || {};
  const pending = result[PENDING_KEY] || {};
  if (active.opening && !active.tabId && active.payload) {
    // A stopped worker had removed this item from its queue, but never opened
    // Facebook. Requeue the same claim; preflight will reject an expired one.
    await enqueueAutoTasks({...active.payload, _notBefore:Date.now() + 1000}, 'taskId');
  }
  if (active.tabId) {
    if(active.handoffHold)return;
    const payload = pending[String(active.tabId)];
    const tab = await chrome.tabs.get(active.tabId).catch(() => null);
    const liveFacebookTask = !!(payload && tab && /^https:\/\/(?:www\.)?facebook\.com\//i.test(String(tab.url || '')));
    if (liveFacebookTask) {
      if(isBulkAdvertisingTask(payload)){
        await adLive.recover();
        if(!adLive.has(active.tabId))setTimeout(()=>finishAutoTask(active.tabId,true),10000);
        return;
      }
      const activeAgeMs = Math.max(0, Date.now() - Number(active.startedAt || Date.now()));
      if (!forceReloadRecovery && activeAgeMs < 4 * 60 * 1000) {
        // MV3 service workers wake repeatedly during a normal task. Keep the
        // live Facebook tab and restore its watchdog instead of mistaking each
        // wake for an extension reload and closing the task.
        chrome.alarms.create(AUTO_WATCHDOG_ALARM, {delayInMinutes: Math.max(0.25, (3 * 60 * 1000 - activeAgeMs) / 60000)});
        return;
      }
      const isSafeRetry = !active.claimedAt
        || payload.lookupOnly === true
        || payload.permalinkRecoveryOnly === true
        || isBulkAdvertisingTask(payload);
      if (isSafeRetry) {
        scheduleFailedTaskRecovery(payload, active.tabId, '助手重載中斷，關閉異常分頁後重試同一筆');
      } else {
        await holdTaskForReconciliation(active.tabId,'result_unknown_read_only');
        await reportAuctionResult({...payload,ok:false,outcomeUnknown:true,error:'助手重載前的上架結果未知，禁止自動重送'});
        setTimeout(() => finishAutoTask(active.tabId, false), FAILED_TAB_INSPECTION_MS);
      }
      return;
    }
    // A missing tab is not proof of an acknowledged result. The same finish
    // gate used by live callbacks also owns restart recovery.
    if(payload){await finishAutoTask(active.tabId,false);return;}
    await holdTaskForReconciliation(active.tabId,'重啟後找不到原任務資料，保留鎖待核對');
    return;
  }
  await chrome.storage.local.remove(AUTO_ACTIVE_KEY);
  await pollAuctionQueue();
  processAutoQueue();
}

migrateToRotatingHourlyEight()
  .then(() => migrateToTwoAdvertisingIdentities())
  .catch(() => {})
  .finally(() => recoverAutoQueueAfterReload(false).catch(() => {}));
chrome.alarms.create(AD_WRITEBACK_ALARM,{periodInMinutes:1});

chrome.runtime.onStartup.addListener(() => {
  closeLegacyAuctionPollTabs();
});
chrome.runtime.onStartup.addListener(() => {
  recoverAutoQueueAfterReload(false).catch(()=>{});
});

chrome.alarms.onAlarm.addListener(alarm => {
  if(alarm.name===AD_DEFERRED_ALARM){resumeDeferredAdvertisingShift().catch(()=>{});return;}
  if(alarm.name===AD_WRITEBACK_ALARM){flushAdvertisingWritebacks();return;}
  if (alarm.name === FAILED_RECOVERY_ALARM) {
    chrome.storage.local.get(FAILED_RECOVERY_KEY, result => {
      const recovery = result[FAILED_RECOVERY_KEY] || {};
      if (recovery.tabId) runFailedTaskRecovery(Number(recovery.tabId));
    });
    return;
  }
  if (alarm.name === NEXT_SCHEDULED_ALARM) {
    processAutoQueue();
    return;
  }
  if (alarm.name === AUTO_WATCHDOG_ALARM) {
    chrome.storage.local.get([AUTO_ACTIVE_KEY, PENDING_KEY], result => {
      const active = result[AUTO_ACTIVE_KEY] || {};
      const pending = result[PENDING_KEY] || {};
      if (active.paused) return;
      const payload = active.tabId ? pending[String(active.tabId)] : null;
      if (!active.tabId || !payload) {
        chrome.storage.local.remove(AUTO_ACTIVE_KEY, processAutoQueue);
        return;
      }
      const isAdvertising = payload.kind === 'advertising_post' || payload.contentType === 'facebook_advertising_post';
      if (!isAdvertising) {
        const error = '競標處理逾時，結果待核對；禁止自動重送';
        holdTaskForReconciliation(active.tabId,'result_unknown_read_only');
        reportAuctionResult({...payload, ok: false, outcomeUnknown: true, error});
        chrome.notifications.create(`baohui-auction-timeout-${Date.now()}`, {
          type: 'basic',
          iconUrl: 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128"><rect width="128" height="128" rx="24" fill="%23b45309"/><text x="64" y="82" text-anchor="middle" font-size="64" fill="white">!</text></svg>',
          title: '競標結果待核對，保留工作分頁',
          message: `${payload.productTitle || payload.productId || '此商品'}逾時；先查證結果，不切帳號、不重送。`,
          priority: 1
        }).catch(() => {});
        return;
      }
      scheduleFailedTaskRecovery(payload,active.tabId,'廣告處理逾時，保留證據且禁止重發');
      return;
      const retryCount = Math.max(0, Number(payload._retryCount || 0));
      if (retryCount < 1) {
        enqueueAutoTasks({...payload, _retryCount: retryCount + 1, _queuedAt: Date.now() - 60000})
          .finally(() => finishAutoTask(active.tabId, false));
        return;
      }
      chrome.notifications.create(`baohui-ad-timeout-${Date.now()}`, {
        type: 'basic',
        iconUrl: 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128"><rect width="128" height="128" rx="24" fill="%23b42318"/><text x="64" y="82" text-anchor="middle" font-size="64" fill="white">!</text></svg>',
        title: '廣告社團逾時已跳過',
          message: `${payload.targetName || '此社團'}重試後仍無法完成，已記錄並在同一分頁繼續下一筆。`,
        priority: 1
      }).catch(() => {});
      finishAutoTask(active.tabId, false);
    });
    return;
  }
  if (alarm.name === 'baohui-auction-poll') {
    if (!ADVERTISING_ONLY_MODE) flushAuctionReports().finally(()=>pollAuctionQueue());
    return;
  }
  if (alarm.name === AUCTION_NOTIFICATION_SCAN_ALARM) {
    if (!ADVERTISING_ONLY_MODE) scanAuctionNotifications().catch(() => {});
    return;
  }
  if (!alarm.name.startsWith('baohui-shift-')) return;
  const hour = alarm.name.replace('baohui-shift-', '').padStart(2, '0');
  runAdvertisingShift(hour).catch(() => {});
});

chrome.tabs.onRemoved.addListener(tabId => {
  if (auctionPollTabId === tabId) auctionPollTabId = 0;
  // A claimed advertising tab can disappear before any submit. Tell the
  // contract owner so the backend reaches failed-before-submit (or an unknown
  // submitted state) instead of staying "running" forever. Never enqueue a
  // second copy from this callback.
  if (adLive.has(tabId)) adLive.fail(tabId, new Error('facebook_work_tab_closed_lookup_only')).catch(() => {});
  chrome.storage.local.get([AUTO_ACTIVE_KEY, PENDING_KEY, REUSABLE_FACEBOOK_TAB_KEY], result => {
    const active = result[AUTO_ACTIVE_KEY] || {};
    const pending = result[PENDING_KEY] || {};
    if(active.tabId===tabId&&!finishingAutoTabs.has(tabId)){
      holdTaskForReconciliation(tabId,'工作分頁非正常關閉，結果待核對；不釋放下一筆');
      return;
    }
    delete pending[String(tabId)];
    const updates = {[PENDING_KEY]: pending};
    if (Number(result[REUSABLE_FACEBOOK_TAB_KEY] || 0) === Number(tabId)) updates[REUSABLE_FACEBOOK_TAB_KEY] = 0;
    chrome.storage.local.set(updates, () => {
      if (active.tabId === tabId && !finishingAutoTabs.has(tabId)) {
        chrome.alarms.clear(AUTO_WATCHDOG_ALARM).catch(() => {});
        chrome.storage.local.remove(AUTO_ACTIVE_KEY, processAutoQueue);
      }
    });
  });
});

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  if (!message || !message.type) return;

  if (message.type === 'BAOHUI_AUCTION_POLL_NOW') {
    pollAuctionQueue(Array.isArray(message.taskIds) ? message.taskIds : [], message.forceImmediate === true, 0, message.operatorStart === true)
      .then(result => sendResponse({ok:true, added:Number(result && result.added || 0), received:Number(result && result.received || 0), retained:result && result.retained === true}))
      .catch(error => sendResponse({ok:false, error:error && error.message || '競標佇列啟動失敗'}));
    return true;
  }

  // Keep the queue alive even if Chrome suspends the Manifest V3 worker or
  // loses an alarm after an unpacked-extension reload. Any open Baohui admin
  // page sends this lightweight heartbeat and immediately wakes polling.
  if (message.type === 'BAOHUI_WAKE_QUEUE') {
    (async () => {
      await installShiftAlarms();
      await pollAuctionQueue();
      processAutoQueue();
      const alarm = await chrome.alarms.get('baohui-auction-poll');
      sendResponse({ok:true, pollAlarm:!!alarm});
    })().catch(error => sendResponse({ok:false, error:error && error.message || '背景排程喚醒失敗'}));
    return true;
  }

  // Manual one-shot diagnostic used from the authenticated Worker Queue page.
  // It reuses existing tasks: one auction listing first, then one approved ad.
  // No schedule/task is duplicated and every other auction keeps its original time.
  if (message.type === 'BAOHUI_FORCE_QUEUE_TEST') {
    (async () => {
      const result = await mutateAutoQueue(async () => {
        const stored = await chrome.storage.local.get([AUTO_QUEUE_KEY, AUTO_ACTIVE_KEY, PENDING_KEY]);
        const active = stored[AUTO_ACTIVE_KEY] || {};
        const pending = stored[PENDING_KEY] || {};
        let liveActive = false;
        if (active.tabId && pending[String(active.tabId)]) {
          try {
            const activeTab = await chrome.tabs.get(active.tabId);
            liveActive = !!(activeTab && /^https:\/\/(?:www\.)?facebook\.com\//i.test(String(activeTab.url || '')));
          } catch (error) {}
        }
        if (active.opening && Number(active.startedAt || 0) > Date.now() - 30000) liveActive = true;
        if (liveActive) return {ok:false, error:'目前已有 Facebook 工作執行中，請等該分頁完成或關閉後再測試'};
        if (active.tabId || active.opening) await chrome.storage.local.remove(AUTO_ACTIVE_KEY);
        const queue = sortAutoQueue(dedupeStoredAutoQueue(Array.isArray(stored[AUTO_QUEUE_KEY]) ? stored[AUTO_QUEUE_KEY] : []));
        const auction = queue.find(task => taskWorkMode(task) === 'auction' && (task.kind === 'auction_listing' || task.contentType === 'facebook_auction_listing'));
        const advertising = queue.find(task => taskWorkMode(task) === 'advertising' && task.groupApproved === true);
        if (!auction) return {ok:false, error:'Queue 內沒有可測試的一元競標上架任務'};
        auction.scheduledAt = '';
        auction._notBefore = Date.now() - 1000;
        if (advertising) advertising._notBefore = Date.now() - 1000;
        await chrome.storage.local.set({
          [AUTO_QUEUE_KEY]: sortAutoQueue(queue),
          [AUCTION_NEXT_ALLOWED_KEY]: 0,
          [AD_NEXT_ALLOWED_KEY]: 0
        });
        return {ok:true, auctionTaskId:String(auction.taskId || ''), advertisingTaskId:String(advertising && advertising.taskId || '')};
      });
      if (result.ok) processAutoQueue();
      sendResponse(result);
    })().catch(error => sendResponse({ok:false, error:error && error.message || '無法啟動即時測試'}));
    return true;
  }

  if (message.type === 'BAOHUI_TRUSTED_CLICK') {
    const tabId = sender.tab && sender.tab.id;
    if (!tabId) { sendResponse({ok:false, error:'無法辨識 Facebook 分頁'}); return true; }
    const target = {tabId};
    (async () => {
      try {
        const pending=(await chrome.storage.local.get(PENDING_KEY))[PENDING_KEY]||{};
        if(isBulkAdvertisingTask(pending[String(tabId)]) && /^(發佈|貼文|Post)$/i.test(String(message.elementText||message.ariaLabel||'').trim()))adLive.consumeClick(tabId);
        await chrome.debugger.attach(target, '1.3');
        await new Promise(resolve => setTimeout(resolve, 500));
        if (['ax', 'ax_date'].includes(String(message.clickMode || ''))) {
          await chrome.debugger.sendCommand(target, 'Accessibility.enable');
          const tree = await chrome.debugger.sendCommand(target, 'Accessibility.getFullAXTree');
          const prefix = String(message.accessibleNamePrefix || '');
          const wantedRole = String(message.axRole || message.role || 'gridcell');
          const node = message.clickMode === 'ax_date' ? resolveFacebookDateCell(tree.nodes || [], prefix) : (tree.nodes || []).find(item => item.role?.value === wantedRole && String(item.name?.value || '').startsWith(prefix) && !item.ignored);
          if (!node?.backendDOMNodeId) throw new Error(`Chrome 無障礙樹找不到 ${wantedRole}：${prefix}`);
          await chrome.debugger.sendCommand(target, 'DOM.scrollIntoViewIfNeeded', {backendNodeId:node.backendDOMNodeId}).catch(() => {});
          const box = await chrome.debugger.sendCommand(target, 'DOM.getBoxModel', {backendNodeId:node.backendDOMNodeId});
          const quad = box?.model?.content || box?.model?.border;
          if (!Array.isArray(quad) || quad.length < 8) throw new Error(`Chrome 無法取得 ${wantedRole} 位置：${prefix}`);
          const x = (quad[0] + quad[2] + quad[4] + quad[6]) / 4;
          const y = (quad[1] + quad[3] + quad[5] + quad[7]) / 4;
          await chrome.debugger.sendCommand(target, 'Input.dispatchMouseEvent', {type:'mouseMoved', x, y});
          await chrome.debugger.sendCommand(target, 'Input.dispatchMouseEvent', {type:'mousePressed', x, y, button:'left', buttons:1, clickCount:1});
          await chrome.debugger.sendCommand(target, 'Input.dispatchMouseEvent', {type:'mouseReleased', x, y, button:'left', buttons:0, clickCount:1});
          sendResponse({ok:true});
          return;
        }
        const descriptor = JSON.stringify({role:String(message.role || ''), ariaLabel:String(message.ariaLabel || ''), elementText:String(message.elementText || ''), x:Number(message.x || 0), y:Number(message.y || 0), clickMode:String(message.clickMode || 'mouse')});
        const located = await chrome.debugger.sendCommand(target, 'Runtime.evaluate', {returnByValue:true, expression:`(() => { const d=${descriptor}; const nodes=[...document.querySelectorAll(d.role ? '[role="'+CSS.escape(d.role)+'"]' : '*')].filter(n => { const r=n.getBoundingClientRect(); if(!r.width||!r.height||r.bottom<0||r.right<0||r.top>innerHeight||r.left>innerWidth) return false; const a=n.getAttribute('aria-label')||''; const t=(n.textContent||'').trim().slice(0,240); return d.ariaLabel ? a===d.ariaLabel : d.elementText && t===d.elementText; }); nodes.sort((a,b) => { const ar=a.getBoundingClientRect(), br=b.getBoundingClientRect(); const ad=Math.hypot(ar.left+ar.width/2-d.x,ar.top+ar.height/2-d.y), bd=Math.hypot(br.left+br.width/2-d.x,br.top+br.height/2-d.y); return ad-bd; }); const el=nodes[0]; if(!el) return null; if(el.tabIndex < 0) el.tabIndex=0; el.focus({preventScroll:true}); const r=el.getBoundingClientRect(); if(d.clickMode==='dom') el.click(); return {x:r.left+r.width/2,y:r.top+r.height/2,focused:document.activeElement===el,text:(el.textContent||'').trim().slice(0,240),domClicked:d.clickMode==='dom'}; })()`});
        // Some Facebook comboboxes have neither aria-label nor textContent.
        // In that case the content script's already measured centre point is
        // still valid, so use it rather than aborting the whole listing.
        const point = located && located.result && located.result.value
          || (Number.isFinite(Number(message.x)) && Number.isFinite(Number(message.y))
            ? {x:Number(message.x), y:Number(message.y), domClicked:false}
            : null);
        if (!point) throw new Error('Chrome 附加控制後找不到目標元件');
        if (String(message.clickMode || '') === 'dom') {
          if (!point.domClicked) throw new Error('Chrome 找到日期格但無法執行點選');
          sendResponse({ok:true});
          return;
        }
        await chrome.debugger.sendCommand(target, 'Input.dispatchMouseEvent', {type:'mouseMoved', x:point.x, y:point.y});
        await chrome.debugger.sendCommand(target, 'Input.dispatchMouseEvent', {type:'mousePressed', x:point.x, y:point.y, button:'left', buttons:1, clickCount:1});
        await chrome.debugger.sendCommand(target, 'Input.dispatchMouseEvent', {type:'mouseReleased', x:point.x, y:point.y, button:'left', buttons:0, clickCount:1});
        sendResponse({ok:true});
      } catch (error) {
        sendResponse({ok:false, error:error && error.message || 'Chrome 受信任點擊失敗'});
      } finally {
        chrome.debugger.detach(target).catch(() => {});
      }
    })();
    return true;
  }

  if (message.type === 'BAOHUI_TRUSTED_DATE') {
    const tabId = sender.tab && sender.tab.id;
    const value = String(message.value || '');
    if (!tabId || !/^\d{4}-\d{2}-\d{2}$/.test(value)) { sendResponse({ok:false,error:'日期格式不正確'}); return true; }
    const target = {tabId};
    (async () => {
      try {
        await chrome.debugger.attach(target,'1.3');
        await chrome.debugger.sendCommand(target,'Accessibility.enable');
        const [year, month, day] = value.split('-').map(Number);
        const prefix = `${year}年${month}月${day}日`;
        const readDateField = nodes => (nodes || []).filter(n => !n.ignored && n.role?.value === 'combobox' && n.backendDOMNodeId)
          .find(n => /開放日期挑選工具|日期|date/i.test(`${n.name?.value || ''} ${n.description?.value || ''}`) && !/時間|time/i.test(`${n.name?.value || ''} ${n.description?.value || ''}`));
        const before = await chrome.debugger.sendCommand(target,'Accessibility.getFullAXTree');
        const field = readDateField(before.nodes);
        if (!field) throw new Error('無法確認 Facebook 日期輸入欄');
        const resolved = await chrome.debugger.sendCommand(target,'DOM.resolveNode',{backendNodeId:field.backendDOMNodeId});
        if (!resolved.object?.objectId) throw new Error('無法解析 Facebook 日期欄');
        const filled = await chrome.debugger.sendCommand(target,'Runtime.callFunctionOn',{
          objectId:resolved.object.objectId, returnByValue:true, arguments:[{value}],
          functionDeclaration: function(value) {
            const input = this.tagName === 'INPUT' ? this : this.querySelector('input');
            if (!input) return {ok:false};
            input.focus();
            const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype,'value')?.set;
            if (!setter) return {ok:false};
            setter.call(input,value);
            input.dispatchEvent(new Event('input',{bubbles:true}));
            input.dispatchEvent(new Event('change',{bubbles:true}));
            input.blur();
            return {ok:true};
          }.toString()
        });
        if (!filled.result?.value?.ok) {
          const box = await chrome.debugger.sendCommand(target,'DOM.getBoxModel',{backendNodeId:field.backendDOMNodeId});
          const quad = box?.model?.content || box?.model?.border;
          if (!Array.isArray(quad) || quad.length < 8) throw new Error('Chrome 無法取得日期欄位置');
          const x=(quad[0]+quad[2]+quad[4]+quad[6])/4, y=(quad[1]+quad[3]+quad[5]+quad[7])/4;
          await chrome.debugger.sendCommand(target,'Input.dispatchMouseEvent',{type:'mousePressed',x,y,button:'left',buttons:1,clickCount:1});
          await chrome.debugger.sendCommand(target,'Input.dispatchMouseEvent',{type:'mouseReleased',x,y,button:'left',buttons:0,clickCount:1});
          await new Promise(resolve=>setTimeout(resolve,350));
          const calendar = await chrome.debugger.sendCommand(target,'Accessibility.getFullAXTree');
          const cell = resolveFacebookDateCell(calendar.nodes || [],prefix);
          const cellBox = await chrome.debugger.sendCommand(target,'DOM.getBoxModel',{backendNodeId:cell.backendDOMNodeId});
          const cellQuad = cellBox?.model?.content || cellBox?.model?.border;
          const cx=(cellQuad[0]+cellQuad[2]+cellQuad[4]+cellQuad[6])/4, cy=(cellQuad[1]+cellQuad[3]+cellQuad[5]+cellQuad[7])/4;
          await chrome.debugger.sendCommand(target,'Input.dispatchMouseEvent',{type:'mousePressed',x:cx,y:cy,button:'left',buttons:1,clickCount:1});
          await chrome.debugger.sendCommand(target,'Input.dispatchMouseEvent',{type:'mouseReleased',x:cx,y:cy,button:'left',buttons:0,clickCount:1});
        }
        await new Promise(resolve=>setTimeout(resolve,500));
        const after = await chrome.debugger.sendCommand(target,'Accessibility.getFullAXTree');
        const dateField = readDateField(after.nodes);
        const actual = `${dateField?.value?.value || ''} ${dateField?.name?.value || ''}`;
        const accepted = actual.includes(value) || actual.includes(prefix) || (actual.includes(String(year)) && actual.includes(`${month}月${day}日`));
        if (!accepted) throw new Error('Facebook 日期讀回不符：要求 ' + value + '，實際 ' + (actual.trim() || '空白'));
        sendResponse({ok:true,verifiedValue:value});
      } catch(error) { sendResponse({ok:false,error:error.message}); }
      finally { await chrome.debugger.detach(target).catch(()=>{}); }
    })();
    return true;
  }

  if (message.type === 'BAOHUI_TRUSTED_TIME') {
    const tabId = sender.tab && sender.tab.id;
    const value = String(message.value || '');
    if (!tabId || !/^(?:[01]\d|2[0-3]):[0-5]\d$/.test(value)) { sendResponse({ok:false,error:'時間格式不正確'}); return true; }
    const target = {tabId};
    (async () => {
      try {
        await chrome.debugger.attach(target,'1.3');
        await chrome.debugger.sendCommand(target,'Accessibility.enable');
        const readTimeField = nodes => (nodes || []).filter(node => !node.ignored && node.role?.value === 'combobox' && node.backendDOMNodeId)
          .find(node => /開放時間挑選工具|時間|time/i.test(`${node.name?.value || ''} ${node.description?.value || ''}`) && !/搜尋 Facebook|Search Facebook|日期|date/i.test(`${node.name?.value || ''} ${node.description?.value || ''}`));
        const before = await chrome.debugger.sendCommand(target,'Accessibility.getFullAXTree');
        const field = readTimeField(before.nodes || []);
        if (!field) throw new Error('無法確認 Facebook 時間輸入欄');
        const resolved = await chrome.debugger.sendCommand(target,'DOM.resolveNode',{backendNodeId:field.backendDOMNodeId});
        if (!resolved.object?.objectId) throw new Error('無法解析 Facebook 時間欄');
        const filled = await chrome.debugger.sendCommand(target,'Runtime.callFunctionOn',{
          objectId:resolved.object.objectId,
          returnByValue:true,
          arguments:[{value}],
          functionDeclaration:function(value){
            const input=this.tagName==='INPUT'?this:this.querySelector?.('input');
            if(!input)return {ok:false};
            input.focus();
            if(typeof input.select==='function')input.select();
            const setter=Object.getOwnPropertyDescriptor(HTMLInputElement.prototype,'value')?.set;
            if(!setter)return {ok:false};
            setter.call(input,value);
            input.dispatchEvent(new InputEvent('input',{bubbles:true,inputType:'insertReplacementText',data:value}));
            input.dispatchEvent(new Event('change',{bubbles:true}));
            return {ok:true,value:input.value};
          }.toString()
        });
        if (!filled.result?.value?.ok) throw new Error('Facebook 時間欄無法直接輸入');
        await new Promise(resolve=>setTimeout(resolve,250));
        await chrome.debugger.sendCommand(target,'Input.dispatchKeyEvent',{type:'rawKeyDown',key:'Enter',code:'Enter',windowsVirtualKeyCode:13,nativeVirtualKeyCode:13});
        await chrome.debugger.sendCommand(target,'Input.dispatchKeyEvent',{type:'keyUp',key:'Enter',code:'Enter',windowsVirtualKeyCode:13,nativeVirtualKeyCode:13});
        await new Promise(resolve=>setTimeout(resolve,450));
        let after = await chrome.debugger.sendCommand(target,'Accessibility.getFullAXTree');
        let actualField = readTimeField(after.nodes || []);
        let actual = String(actualField?.value?.value || '').match(/(?:[01]\d|2[0-3]):[0-5]\d/)?.[0] || '';
        if (actual !== value && actualField?.backendDOMNodeId) {
          // React may reject the value setter. Reproduce a real keyboard edit:
          // focus the live field, select all, insert the requested text, then
          // leave the field so Facebook commits it.
          await chrome.debugger.sendCommand(target,'DOM.focus',{backendNodeId:actualField.backendDOMNodeId});
          await chrome.debugger.sendCommand(target,'Input.dispatchKeyEvent',{type:'rawKeyDown',key:'a',code:'KeyA',modifiers:2,windowsVirtualKeyCode:65,nativeVirtualKeyCode:65});
          await chrome.debugger.sendCommand(target,'Input.dispatchKeyEvent',{type:'keyUp',key:'a',code:'KeyA',modifiers:2,windowsVirtualKeyCode:65,nativeVirtualKeyCode:65});
          await chrome.debugger.sendCommand(target,'Input.insertText',{text:value});
          await new Promise(resolve=>setTimeout(resolve,300));
          await chrome.debugger.sendCommand(target,'Input.dispatchKeyEvent',{type:'rawKeyDown',key:'Tab',code:'Tab',windowsVirtualKeyCode:9,nativeVirtualKeyCode:9});
          await chrome.debugger.sendCommand(target,'Input.dispatchKeyEvent',{type:'keyUp',key:'Tab',code:'Tab',windowsVirtualKeyCode:9,nativeVirtualKeyCode:9});
          await new Promise(resolve=>setTimeout(resolve,450));
          after = await chrome.debugger.sendCommand(target,'Accessibility.getFullAXTree');
          actualField = readTimeField(after.nodes || []);
          actual = String(actualField?.value?.value || '').match(/(?:[01]\d|2[0-3]):[0-5]\d/)?.[0] || '';
        }
        if (actual !== value) throw new Error('Facebook 時間讀回不符：要求 ' + value + '，實際 ' + (actual || '空白'));
        sendResponse({ok:true,verifiedValue:actual});
      } catch(error) { sendResponse({ok:false,error:error && error.message || 'Facebook 時間輸入失敗'}); }
      finally { await chrome.debugger.detach(target).catch(()=>{}); }
    })();
    return true;
  }

  if (message.type === 'BAOHUI_TRUSTED_KEY') {
    const tabId = sender.tab && sender.tab.id;
    if (!tabId) { sendResponse({ok:false, error:'無法辨識 Facebook 分頁'}); return true; }
    const target = {tabId};
    (async () => {
      try {
        await chrome.debugger.attach(target, '1.3');
        await new Promise(resolve => setTimeout(resolve, 120));
        const key = String(message.key || 'Enter');
        if (!['Enter','Escape'].includes(key)) throw new Error(`不支援的按鍵：${key}`);
        const params = {key, code:key, windowsVirtualKeyCode:key === 'Escape' ? 27 : 13, nativeVirtualKeyCode:key === 'Escape' ? 27 : 13};
        await chrome.debugger.sendCommand(target, 'Input.dispatchKeyEvent', {type:'rawKeyDown', ...params});
        await chrome.debugger.sendCommand(target, 'Input.dispatchKeyEvent', {type:'keyUp', ...params});
        sendResponse({ok:true});
      } catch (error) {
        sendResponse({ok:false, error:error && error.message || 'Chrome 受信任按鍵失敗'});
      } finally {
        chrome.debugger.detach(target).catch(() => {});
      }
    })();
    return true;
  }

  if (message.type === 'BAOHUI_TRUSTED_CLEAR_EDITOR') {
    const tabId = sender.tab && sender.tab.id;
    if (!tabId) { sendResponse({ok:false, error:'無法辨識 Facebook 分頁'}); return true; }
    const target = {tabId};
    (async () => {
      try {
        await chrome.debugger.attach(target, '1.3');
        await new Promise(resolve => setTimeout(resolve, 120));
        const ctrlA = {key:'a', code:'KeyA', windowsVirtualKeyCode:65, commands:['selectAll']};
        await chrome.debugger.sendCommand(target, 'Input.dispatchKeyEvent', {type:'rawKeyDown', ...ctrlA});
        await chrome.debugger.sendCommand(target, 'Input.dispatchKeyEvent', {type:'keyUp', ...ctrlA});
        const backspace = {key:'Backspace', code:'Backspace', windowsVirtualKeyCode:8};
        await chrome.debugger.sendCommand(target, 'Input.dispatchKeyEvent', {type:'rawKeyDown', ...backspace});
        await chrome.debugger.sendCommand(target, 'Input.dispatchKeyEvent', {type:'keyUp', ...backspace});
        sendResponse({ok:true});
      } catch (error) {
        sendResponse({ok:false, error:error && error.message || 'Chrome 無法清除 Facebook 舊草稿'});
      } finally {
        await chrome.debugger.detach(target).catch(() => {});
      }
    })();
    return true;
  }

  if (message.type === 'BAOHUI_TRUSTED_MULTILINE') {
    const tabId = sender.tab && sender.tab.id;
    const text = String(message.text || '').replace(/\r\n?/g, '\n');
    if (!tabId) { sendResponse({ok:false, error:'無法辨識 Facebook 分頁'}); return true; }
    if (!text) { sendResponse({ok:false, error:'Facebook 文案是空白'}); return true; }
    const target = {tabId};
    (async () => {
      try {
        await chrome.debugger.attach(target, '1.3');
        await new Promise(resolve => setTimeout(resolve, 120));
        const enter = async () => {
          const shift = message.softBreaks ? 8 : 0;
          const params = {key:'Enter', code:'Enter', windowsVirtualKeyCode:13, modifiers:shift};
          await chrome.debugger.sendCommand(target, 'Input.dispatchKeyEvent', {type:'keyDown', text:'\r', unmodifiedText:'\r', ...params});
          await chrome.debugger.sendCommand(target, 'Input.dispatchKeyEvent', {type:'keyUp', ...params});
        };
        if (message.leadingBreak) await enter();
        const lines = text.split('\n');
        for (let index = 0; index < lines.length; index++) {
          if (lines[index]) await chrome.debugger.sendCommand(target, 'Input.insertText', {text:lines[index]});
          if (index < lines.length - 1) await enter();
        }
        sendResponse({ok:true, lineCount:lines.length});
      } catch (error) {
        sendResponse({ok:false, error:error && error.message || 'Chrome 多行文案輸入失敗'});
      } finally {
        await chrome.debugger.detach(target).catch(() => {});
      }
    })();
    return true;
  }

  if (message.type === 'BAOHUI_VALIDATE_AUCTION') {
    (async()=>{
      const active=(await chrome.storage.local.get(AUTO_ACTIVE_KEY))[AUTO_ACTIVE_KEY]||{};
      if(active.tabId!==sender.tab?.id||active.paused)throw Error('目前任務已暫停，禁止繼續送出');
      return validateAuctionDispatch(message.payload,message.phase);
    })().then(sendResponse).catch(error => sendResponse({ok:false,error:error.message}));
    return true;
  }

  if(['AD_V2_IDENTITY_READY','AD_V2_SUBMIT_READY','AD_V2_RESULT'].includes(message.type)){
    (async()=>{
      const tabId=sender.tab?.id;const pending=(await chrome.storage.local.get(PENDING_KEY))[PENDING_KEY]||{};
      const payload=pending[String(tabId)];
      if(!tabId||!payload||payload.protocol!==2||!isBulkAdvertisingTask(payload)||!/^https:\/\/www\.facebook\.com\/groups\//.test(sender.url||''))throw Error('unowned_ad_tab');
      if(message.type==='AD_V2_IDENTITY_READY')await adLive.ready(tabId,payload,message.observed,sendResponse);
      else if(message.type==='AD_V2_SUBMIT_READY'){
        const beforePostIds=[...new Set((Array.isArray(message.actual?.beforePostIds)?message.actual.beforePostIds:[]).map(String).filter(Boolean))];
        if(beforePostIds.length){pending[String(tabId)]={...payload,beforePostIds};await chrome.storage.local.set({[PENDING_KEY]:pending});}
        adLive.prepared(tabId,String(message.claimant||''),{...message.actual,beforePostIds},sendResponse);
      }
      else{const accepted=adLive.result(tabId,String(message.claimant||''),message.evidence);sendResponse({ok:true,evidenceReceived:accepted,duplicate:!accepted});}
    })().catch(error=>sendResponse({ok:false,error:error.message}));
    return true;
  }

  if (message.type === 'BAOHUI_PREPARE') {
    const payload = message.payload || {};
    if(isBulkAdvertisingTask(payload)){
      let allowed=false;try{const url=new URL(sender.url||'');allowed=url.origin==='https://baohui.paohui.org'&&url.pathname==='/admin-ad-posting.php';}catch{}
      if(!allowed||payload.protocol!==2||!payload.task_date||!payload.autoPublish){sendResponse({ok:false,error:'廣告需使用新版後台與助手 v2 自動流程'});return true;}
    }
    const targetText = `${payload.groupUrl || ''} ${payload.targetName || ''}`;
    if (/\/groups\/onecheep(?:\/|$)|海賊|一元競標|競標場|大家來喊價/i.test(targetText)) {
      sendResponse({ok: false, error: '海賊團／一元競標場只供競標，不可刊登一般廣告'});
      return true;
    }
    if (payload.autoPublish) {
      if (isBulkAdvertisingTask(payload) && payload.groupApproved !== true) {
        sendResponse({ok: false, error: '此社團尚未由人員確認允許商業貼文，已停止自動發布'});
        return true;
      }
      enqueueAutoTasks(payload).then(result => {
        sendResponse({ok: true, planned: true, protocol:2, queuePosition: result.queueLength});
        processAutoQueue();
      }).catch(error => sendResponse({ok: false, error: error.message || '無法加入共用發文佇列'}));
      return true;
    }
    const targetUrl = resolveFacebookTargetUrl(payload, false);
    if (!targetUrl) {
      sendResponse({ok: false, error: '此發文目標沒有正確的 Facebook 網址，未開啟空白分頁'});
      return true;
    }
    (async () => {
      // A manual preparation must not navigate an existing Facebook draft or
      // the automatic worker's dedicated tab. Start from a clean tab instead.
      const tab = await chrome.tabs.create({url:'about:blank', active:false});
      if (!tab?.id) throw new Error('無法建立 Facebook 工作分頁');
      const result = await chrome.storage.local.get(PENDING_KEY);
      const pending = result[PENDING_KEY] || {};
      pending[String(tab.id)] = {...payload, targetTabId:tab.id};
      await chrome.storage.local.set({[PENDING_KEY]:pending});
      await chrome.tabs.update(tab.id, {url:targetUrl, active:false});
      sendResponse({ok:true, tabId:tab.id, reused:false});
    })().catch(error => sendResponse({ok:false, error:error.message || '無法開啟指定的 Facebook 社團'}));
    return true;
  }

  if (message.type === 'BAOHUI_PREPARE_AUCTION') {
    const payload = {...(message.payload || {}), requiredVisibleName: AUCTION_POSTING_IDENTITY};
    const targetText = `${payload.groupUrl || ''} ${payload.targetName || ''}`;
    if (payload.kind !== 'auction_listing' || !/\/groups\/onecheep(?:\/|$)|海賊|一元競標|競標場|大家來喊價/i.test(targetText)) {
      sendResponse({ok: false, error: '競標任務或目標社團不正確'});
      return true;
    }
    enqueueAutoTasks({...payload, autoPublish: true, autoClose: true, publishDelayMs: Math.max(10000, Number(payload.publishDelayMs || 10000))}, 'taskId').then(result => {
      sendResponse({ok: true, queued: true, queuePosition: result.queueLength, duplicate: result.added === 0});
      processAutoQueue();
    }).catch(error => sendResponse({ok: false, error: error.message || '無法加入競標發文佇列'}));
    return true;
  }

  if (message.type === 'BAOHUI_AUCTION_DUE_RESPONSE') {
    acceptAuctionDueResponse(message.tasks, sender.tab && sender.tab.id).then(() => sendResponse({ok: true}));
    return true;
  }

  if (message.type === 'BAOHUI_GROUP_REJECTION_DETECTED') {
    const payload = message.payload || {};
    if (!payload.groupName || !payload.notice || !payload.fingerprint) {
      sendResponse({ok: false, error: '拒絕通知資料不完整'});
      return true;
    }
    chrome.storage.local.get(REJECTION_QUEUE_KEY, result => {
      const queue = Array.isArray(result[REJECTION_QUEUE_KEY]) ? result[REJECTION_QUEUE_KEY] : [];
      if (!queue.some(item => item.fingerprint === payload.fingerprint)) queue.push(payload);
      const next = queue.slice(-100);
      const targetId = String(payload.targetId || '');
      const groupUrl = String(payload.groupUrl || '').replace(/\/+$/, '');
      chrome.storage.local.set({[REJECTION_QUEUE_KEY]: next}, () => {
        mutateAutoQueue(async () => {
          const stored = await chrome.storage.local.get(AUTO_QUEUE_KEY);
          const autoQueue = (Array.isArray(stored[AUTO_QUEUE_KEY]) ? stored[AUTO_QUEUE_KEY] : []).filter(item => {
            if (targetId && String(item.targetId || '') === targetId) return false;
            return !(groupUrl && String(item.groupUrl || '').replace(/\/+$/, '') === groupUrl);
          });
          await chrome.storage.local.set({[AUTO_QUEUE_KEY]: autoQueue});
        }).then(() => {
        broadcastRejections([payload]);
        sendResponse({ok: true});
        }).catch(error => sendResponse({ok: false, error: error.message || '無法更新拒絕社團佇列'}));
      });
    });
    return true;
  }

  if (message.type === 'BAOHUI_GET_GROUP_REJECTIONS') {
    chrome.storage.local.get(REJECTION_QUEUE_KEY, result => {
      const queue = Array.isArray(result[REJECTION_QUEUE_KEY]) ? result[REJECTION_QUEUE_KEY] : [];
      sendResponse({ok: true, items: queue});
    });
    return true;
  }

  if (message.type === 'BAOHUI_ACK_GROUP_REJECTION') {
    const fingerprint = String(message.fingerprint || '');
    chrome.storage.local.get(REJECTION_QUEUE_KEY, result => {
      const queue = (Array.isArray(result[REJECTION_QUEUE_KEY]) ? result[REJECTION_QUEUE_KEY] : []).filter(item => item.fingerprint !== fingerprint);
      chrome.storage.local.set({[REJECTION_QUEUE_KEY]: queue}, () => sendResponse({ok: true}));
    });
    return true;
  }

  if (message.type === 'BAOHUI_PREPARE_DELETE') {
    const payload = message.payload || {};
    if (!/^https:\/\/(?:www\.)?facebook\.com\//i.test(payload.postUrl || '') || !payload.listingSerial || !payload.destinationId) {
      sendResponse({ok: false, error: '缺少可核對的 Facebook 貼文網址或上架序號'});
      return true;
    }
    enqueueAutoTasks({...payload, action: 'delete_product_post'}).then(result => {
      sendResponse({ok: true, queued: true, queuePosition: result.queueLength});
      processAutoQueue();
    }).catch(error => sendResponse({ok: false, error: error.message || '無法加入貼文維護佇列'}));
    return true;
  }

  if (message.type === 'BAOHUI_PREPARE_SOLD_MARK') {
    const payload = message.payload || {};
    if (!/^https:\/\/(?:www\.)?facebook\.com\//i.test(payload.postUrl || '') || !payload.listingSerial || !payload.destinationId) {
      sendResponse({ok: false, error: '缺少可核對的粉絲團貼文網址或上架序號'});
      return true;
    }
    enqueueAutoTasks({...payload, action: 'mark_product_sold'}).then(result => {
      sendResponse({ok: true, queued: true, queuePosition: result.queueLength});
      processAutoQueue();
    }).catch(error => sendResponse({ok: false, error: error.message || '無法加入貼文維護佇列'}));
    return true;
  }

  if (message.type === 'BAOHUI_GET_PENDING') {
    chrome.storage.local.get([PENDING_KEY, AUTO_ACTIVE_KEY], result => {
      const pending = result[PENDING_KEY] || {};
      const tabId = sender.tab?.id;
      const payload = tabId ? (pending[String(tabId)] || null) : null;
      const active = result[AUTO_ACTIVE_KEY] || {};
      if (payload && active.tabId === tabId && !active.claimedAt) {
        chrome.storage.local.set({[AUTO_ACTIVE_KEY]: {...active, claimedAt: Date.now()}}, () => sendResponse(payload));
      } else sendResponse(payload);
    });
    return true;
  }

  if (message.type === 'BAOHUI_GET_IMAGE') {
    (async () => {
      let lastError = new Error('image fetch failed');
      for (let attempt = 1; attempt <= 3; attempt++) {
        try {
          const response = await fetch(message.url, {credentials: 'include', cache: 'no-store'});
          if (!response.ok) throw new Error(`image fetch failed (${response.status})`);
          const buffer = await response.arrayBuffer();
          if (!buffer.byteLength) throw new Error('image fetch returned empty file');
          const mime = response.headers.get('content-type') || 'image/png';
          const bytes = new Uint8Array(buffer);
          let binary = '';
          for (let i = 0; i < bytes.length; i += 0x8000) binary += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
          const sha256=[...new Uint8Array(await crypto.subtle.digest('SHA-256',buffer))].map(n=>n.toString(16).padStart(2,'0')).join('');
          sendResponse({ok: true, base64: btoa(binary), mime, sha256});
          return;
        } catch (error) {
          lastError = error;
          if (attempt < 3) await new Promise(resolve => setTimeout(resolve, 700));
        }
      }
      sendResponse({ok: false, error: lastError.message});
    })();
    return true;
  }

  if (message.type === 'BAOHUI_AUTO_FAILED') {
    const payload = message.payload || {};
    const errorMessage = payload.error || 'facebook_prepare_failed';
    const isAdvertising = payload.kind === 'advertising_post' || payload.contentType === 'facebook_advertising_post';
    if (isAdvertising && /facebook_rate_limit/i.test(errorMessage)) {
      const resumeAt = Date.now() + 2 * 60 * 60 * 1000;
      const affectedIdentity = isApprovedAdIdentity(String(payload.requiredVisibleName || '').trim())
        ? String(payload.requiredVisibleName).trim()
        : '';
      chrome.storage.local.get(AD_IDENTITY_COOLDOWNS_KEY).then(stored => {
        const cooldowns = stored[AD_IDENTITY_COOLDOWNS_KEY] && typeof stored[AD_IDENTITY_COOLDOWNS_KEY] === 'object'
          ? {...stored[AD_IDENTITY_COOLDOWNS_KEY]}
          : {};
        if (affectedIdentity) cooldowns[affectedIdentity] = Math.max(Number(cooldowns[affectedIdentity] || 0), resumeAt);
        return chrome.storage.local.set({[AD_IDENTITY_COOLDOWNS_KEY]: cooldowns});
      }).then(() => {
        chrome.alarms.create(NEXT_SCHEDULED_ALARM, {when: resumeAt});
        chrome.notifications.create(`baohui-ad-cooldown-${Date.now()}`, {type:'basic', iconUrl:'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128"><rect width="128" height="128" rx="24" fill="%23b42318"/><text x="64" y="82" text-anchor="middle" font-size="52" fill="white">2H</text></svg>', title:`Facebook 發文限制：${affectedIdentity || '本次帳號'}停 2 小時`, message:`Facebook 已拒絕 ${affectedIdentity || '本次發文帳號'} 的貼文；只暫停該帳號 2 小時，其他帳號仍會依排程切換並繼續。`, priority:2}).catch(()=>{});
      }).catch(()=>{});
      if (sender.tab?.id && adLive.has(sender.tab.id)) adLive.fail(sender.tab.id, new Error(errorMessage));
      else if (sender.tab?.id) finishAutoTask(sender.tab.id, true);
      sendResponse({ok:true, cooldownMinutes:120, retryAt:resumeAt, rejected:true, affectedIdentity});
      return true;
    }
    if(isAdvertising){scheduleFailedTaskRecovery(payload,sender.tab?.id,errorMessage);sendResponse({ok:true,retrying:false,lookupOnly:true});return true;}
    if (payload.kind === 'auction_listing') {
      if (/預約公開時間已過|指定時間.*(?:不在|已過)|schedule.*(?:past|expired)/iu.test(errorMessage)) {
        // Facebook did not accept the submission. Let the backend assign a new
        // native schedule from its rollover rules, then continue the queue.
        reportAuctionResult({...payload, ok:false, outcomeUnknown:false, error:errorMessage});
        if (sender.tab?.id) setTimeout(() => finishAutoTask(sender.tab.id, false), 1000);
        sendResponse({ok:true, continued:true, retrying:false, backendReschedule:true});
        return true;
      }
      // Never retry after Facebook may already have accepted the submission.
      if (payload.outcomeUnknown || /scheduled_lookup_only|publish_clicked|scheduled_link_lookup_pending/i.test(errorMessage)) {
        reportAuctionResult({...payload, ok:false, outcomeUnknown:true, error:errorMessage});
        if (sender.tab?.id) setTimeout(() => finishAutoTask(sender.tab.id, false), FAILED_TAB_INSPECTION_MS);
        sendResponse({ok:true, continued:true, outcomeUnknown:true, retrying:false});
        return true;
      }
      const recovery = sender.tab?.id
        ? scheduleFailedTaskRecovery(payload, sender.tab.id, errorMessage)
        : {retrying:false, attempt:Math.max(1, Number(payload._retryCount || 0) + 1)};
      sendResponse({ok:true, continued:true, retrying:recovery.retrying, attempt:recovery.attempt, inspectionSeconds:10});
      return true;
    }
    if (isAdvertising) {
      const recovery = sender.tab?.id
        ? scheduleFailedTaskRecovery(payload, sender.tab.id, errorMessage)
        : {retrying:false, attempt:Math.max(1, Number(payload._retryCount || 0) + 1)};
      sendResponse({ok:true, continued:true, retrying:recovery.retrying, attempt:recovery.attempt, inspectionSeconds:10});
      return true;
    }
    const isReminder = payload.action === 'auction_reminder' || payload.kind === 'auction_reminder' || payload.contentType === 'facebook_auction_reminder';
    if (isReminder) {
      const uncertain = /未確認.{0,20}送出|可能已送出|提醒已送出.*尚未顯示|facebook_reply_display_pending/i.test(errorMessage);
      Promise.race([
        reportAuctionResult({...payload, ok:false, outcomeUnknown:uncertain, helperRetryExhausted:true, error:errorMessage}),
        new Promise(resolve => setTimeout(resolve, 5000))
      ]).finally(() => { if (sender.tab?.id) finishAutoTask(sender.tab.id, false); });
      sendResponse({ok:true, continued:true, retrying:false, outcomeUnknown:uncertain});
      return true;
    }
    let recovery = {retrying:false, attempt:Math.max(1, Number(payload._retryCount || 0) + 1)};
    if (sender.tab && sender.tab.id) {
      recovery = scheduleFailedTaskRecovery(payload, sender.tab.id, errorMessage);
    }
    sendResponse({ok:true, continued:true, retrying:recovery.retrying, attempt:recovery.attempt, inspectionSeconds:10, isReminder});
    return true;
  }

  if (message.type === 'BAOHUI_FACEBOOK_POST_CLICKED') {
    const payload = message.payload || {};
    if(isBulkAdvertisingTask(payload)){sendResponse({ok:false,error:'legacy_completion_rejected_use_v2_result'});return true;}
    if (isBulkAdvertisingTask(payload) && payload.recordMode !== 'manual') {
      (async () => {
        const evidenceReady = Boolean(payload.verifiedVisible === true && payload.postUrl && payload.postId);
        const eventId = `ad-${payload.targetId}-${payload.slot}-${payload.postId || payload.requestId}`;
        const body = {id:String(payload.targetId),slot:payload.slot,status:evidenceReady?'已完成':'失敗',material_id:payload.materialId,
          post_url:payload.postUrl||'',post_id:payload.postId||'',verified_visible:evidenceReady,
          verified_identity:payload.verifiedIdentity||'',event_id:eventId,
          note:evidenceReady?'工具已核對可見貼文與發文身分，正式回寫':'已按發布但正式連結待核對，禁止重發'};
        const outboxKey = 'baohuiAdvertisingWriteback:' + eventId;
        await chrome.storage.local.set({[outboxKey]:{body,at:Date.now()}});
        const response = await fetch('https://baohui.paohui.org/ad-posting-api.php?action=update', {
          method:'POST',credentials:'include',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)
        });
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.error || `回寫 HTTP ${response.status}`);
        await chrome.storage.local.remove(outboxKey);
        await chrome.storage.local.set({baohuiAdvertisingLastResult:{...body,at:Date.now(),writebackConfirmed:true}});
        if (sender.tab?.id) setTimeout(()=>finishAutoTask(sender.tab.id,true),10000);
        sendResponse({ok:true,writebackConfirmed:true});
      })().catch(async error => {
        await chrome.storage.local.set({baohuiAdvertisingWritebackError:{error:error.message,at:Date.now()}});
        if (sender.tab?.id) await finishAutoTask(sender.tab.id,true);
        sendResponse({ok:false,error:error.message});
      });
      return true;
    }
    reportAuctionResult(payload).then(()=>chrome.tabs.query({url: 'https://baohui.paohui.org/*'})).then(tabs => {
      const reportType = payload.kind === 'product_sale' ? 'BAOHUI_PRODUCT_POST_RESULT' : 'BAOHUI_REPORT_COMPLETE';
      for (const tab of tabs) chrome.tabs.sendMessage(tab.id, {type: reportType, payload}).catch(() => {});
      if (sender.tab && sender.tab.id) {
        const tabId = sender.tab.id;
        if (payload.autoClose && payload.ok !== false) setTimeout(() => finishAutoTask(tabId, true), 10000);
        else finishAutoTask(tabId, false);
      }
      sendResponse({ok: true});
    }).catch(error=>sendResponse({ok:false,error:error.message}));
    return true;
  }

  if (message.type === 'BAOHUI_FACEBOOK_SCHEDULED') {
    const payload = message.payload || {};
    reportAuctionResult({...payload, ok:true, scheduled:true}).then(async ack=>{
      if(sender.tab?.id)await finishAutoTask(sender.tab.id,false);
      sendResponse({ok:!!ack?.ok,scheduled:true,writebackConfirmed:!!ack?.ok});
    }).catch(error=>sendResponse({ok:false,error:error.message}));
    return true;
  }

  if (message.type === 'BAOHUI_FACEBOOK_SCHEDULED_SNAPSHOT') {
    const payload = message.payload || {};
    fetch(`${AUCTION_API_URL}?action=api_scheduled_snapshot`, {
      method:'POST', credentials:'include', cache:'no-store',
      headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload)
    }).then(async response => {
      const data = await response.json().catch(() => ({}));
      sendResponse(response.ok ? {ok:true, snapshot:data.snapshot || {}} : {ok:false,error:data.error || `HTTP ${response.status}`});
    }).catch(error => sendResponse({ok:false,error:error.message}));
    return true;
  }

  if (message.type === 'BAOHUI_FACEBOOK_DELETE_RESULT') {
    const payload = message.payload || {};
    chrome.tabs.query({url: 'https://baohui.paohui.org/*'}, tabs => {
      for (const tab of tabs) chrome.tabs.sendMessage(tab.id, {type: 'BAOHUI_PRODUCT_DELETE_RESULT', payload}).catch(() => {});
      if (sender.tab && sender.tab.id) finishAutoTask(sender.tab.id, payload.ok !== false);
      sendResponse({ok: true});
    });
    return true;
  }


  if (message.type === 'BAOHUI_FACEBOOK_SOLD_RESULT') {
    const payload = message.payload || {};
    chrome.tabs.query({url: 'https://baohui.paohui.org/*'}, tabs => {
      for (const tab of tabs) chrome.tabs.sendMessage(tab.id, {type: 'BAOHUI_PRODUCT_SOLD_RESULT', payload}).catch(() => {});
      if (sender.tab && sender.tab.id) finishAutoTask(sender.tab.id, payload.ok !== false);
      sendResponse({ok: true});
    });
    return true;
  }
});

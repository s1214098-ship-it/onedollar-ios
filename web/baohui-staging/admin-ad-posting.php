<!doctype html>
<html lang="zh-Hant"><head>
<meta charset="utf-8">
<script>(function () {
  'use strict';
  if (window.StrictSaveGuard) return;
  var LEGACY_KEY = 'admin-strict-save-lock-v1';
  // A save lock belongs to this tab, not every page on the same origin.
  var KEY = 'admin-strict-save-lock-v2';
  var PAGE_KEY = LEGACY_KEY + ':page';
  var previousTab = null;
  try { previousTab=sessionStorage.getItem(PAGE_KEY); } catch (_) {}
  var tab = 'save-' + Date.now() + '-' + Math.random().toString(36).slice(2);
  try { sessionStorage.setItem(PAGE_KEY, tab); } catch (_) {}
  var reviewMode=new URL(location.href).searchParams.has('saveReview');
  var batch = null, box = null, idleTimer = 0, slowTimer = 0;
  var successTimer = 0, countdownTimer = 0, successDeadline = 0, legacyOwner = null;
  var inerts = new Map(), oldFocus = null, recent = new Map();
  var originalFetch = window.fetch && window.fetch.bind(window);
  var originalSubmit = HTMLFormElement.prototype.submit;
  var ownNativeNavigation = false;
  var receiptChannel = null;
  try { receiptChannel = new BroadcastChannel('admin-strict-save-receipts-v2'); } catch (_) {}
  // 20261018-quietrestore: no blocking "尚未確認" modal any more (峰志 10/18 14:13). An unconfirmed save is
  // moved out of the page lock into this tab-local list (request id + content fingerprints)
  // and is never deleted until a receipt confirms it (or the user explicitly lets it be resent).
  var UNCONFIRMED_KEY = 'admin-strict-save-unconfirmed-v1';
  var receiptQuery = null; // optional: StrictSaveGuard.setReceiptQuery(function(requestId){ return Promise<{phase}> })

  function stored() { try { return JSON.parse(sessionStorage.getItem(KEY) || 'null'); } catch (_) { return null; } }
  function copiedFromOpener(owner) {
    // window.open may copy its opener's sessionStorage once. That copy is not
    // a save started here; native result markers are handled separately.
    try { return !!owner && !!window.opener && window.opener.sessionStorage.getItem(PAGE_KEY)===owner; } catch (_) { return false; }
  }
  function publish() {
    if (!batch) return;
    try { sessionStorage.setItem(KEY, JSON.stringify({owner:tab, at:Date.now(), phase:batch.phase, native:!!batch.native, requestId:batch.id, keys:batch.keys||[], writes:batch.writes||0})); } catch (_) {} /* 20261018-quietrestore */
  }
  function releaseStored() {
    try { var row=stored(); if (row && row.owner===tab) sessionStorage.removeItem(KEY); } catch (_) {}
    // Only retire a legacy lock migrated from this same tab. Never clear an
    // unrelated old page's in-flight operation while deploying the new guard.
    if (legacyOwner) try { var old=JSON.parse(localStorage.getItem(LEGACY_KEY)||'null'); if(old&&old.owner===legacyOwner)localStorage.removeItem(LEGACY_KEY); } catch (_) {}
    legacyOwner=null;
  }
  function finishAcknowledgment() {
    if (!batch || batch.pending || batch.native || !['success','error','unknown'].includes(batch.phase)) return;
    if (batch.phase==='unknown' && (!box || !box.querySelector('input').checked)) return;
    releaseStored();closeBox();batch=null;ownNativeNavigation=false;recent.clear();
    window.dispatchEvent(new CustomEvent('admin:strict-save-acknowledged'));
  }
  function clearSuccessCountdown() {
    clearTimeout(successTimer);clearInterval(countdownTimer);
    successTimer=0;countdownTimer=0;successDeadline=0;
  }
  function updateSuccessCountdown() {
    if (!batch || batch.phase!=='success' || !box) return;
    var seconds=Math.max(0,Math.ceil((successDeadline-Date.now())/1000));
    box.querySelector('[data-strict-save-countdown]').textContent=seconds+' 秒後自動關閉並恢復本頁操作';
    if (!seconds) finishAcknowledgment();
  }
  function startSuccessCountdown() {
    clearSuccessCountdown();successDeadline=Date.now()+3000;
    updateSuccessCountdown();countdownTimer=setInterval(updateSuccessCountdown,250);
    successTimer=setTimeout(function(){if(batch&&batch.phase==='success')finishAcknowledgment();},3000);
  }
  function blocked() { return !reviewMode && (!!batch || !!stored()); }
  function busyError() { var e=new Error('本次儲存尚未完成或尚未核對，已阻止重複送出。'); e.name='SaveInProgressError'; return e; }
  function inertBackground() {
    if (!document.body || !box) return;
    Array.prototype.forEach.call(document.body.children, function (el) {
      if (el===box || el.tagName==='SCRIPT' || el.tagName==='STYLE') return;
      if (!inerts.has(el)) inerts.set(el, el.inert);
      el.inert=true;
    });
  }
  function ensureBox() {
    if (box || !document.body) return;
    oldFocus=document.activeElement;
    var style=document.createElement('style');
    style.textContent='[data-strict-save-dialog]{position:fixed;inset:0;z-index:2147483647;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(8,12,16,.78);font:16px/1.6 system-ui,sans-serif;color:#effffb}[data-strict-save-dialog] *{box-sizing:border-box}[data-strict-save-card]{width:min(560px,100%);max-height:90vh;overflow:auto;padding:28px;border:2px solid #eec15c;border-radius:18px;background:#172a2b;box-shadow:0 24px 90px #0009}[data-strict-save-title]{font-size:24px;font-weight:800;margin:0 0 12px}[data-strict-save-detail]{white-space:pre-wrap;overflow-wrap:anywhere;margin:0 0 18px}[data-strict-save-dialog] button{display:block;width:100%;min-height:48px;margin-top:14px;padding:10px;border:1px solid #eec15c;border-radius:10px;background:#f4c85f;color:#172122;font:700 16px system-ui;cursor:pointer}[data-strict-save-dialog] button:disabled{opacity:.4;cursor:not-allowed}[data-strict-save-dialog] [hidden]{display:none!important}[data-strict-save-dialog] label{display:block;margin-top:18px}[data-strict-save-reference]{color:#c6d9d6;font-size:12px;overflow-wrap:anywhere}@media(max-width:480px){[data-strict-save-card]{padding:20px}[data-strict-save-title]{font-size:21px}}';
    document.head.appendChild(style);
    box=document.createElement('div');box.setAttribute('data-strict-save-dialog','');box.setAttribute('role','dialog');box.setAttribute('aria-modal','true');box.setAttribute('aria-labelledby','strict-save-title');
    box.innerHTML='<section data-strict-save-card><h2 id="strict-save-title" data-strict-save-title></h2><p data-strict-save-detail role="status" aria-live="polite"></p><p data-strict-save-countdown role="status" aria-live="polite" hidden></p><small data-strict-save-reference></small><label data-strict-save-review hidden><input type="checkbox" data-save-guard-control> 我已另外核對正式紀錄、數量與庫存，確認本次結果</label><button type="button" data-save-guard-control data-strict-save-ack hidden></button><button type="button" data-save-guard-control data-strict-save-open-review hidden>另開原頁唯讀核對（不可儲存）</button></section>';
    document.body.appendChild(box);
    box.querySelector('input').addEventListener('change',function(){box.querySelector('button').disabled=!this.checked;});
    box.querySelector('button').addEventListener('click', function(){
      finishAcknowledgment();
    });
    box.querySelector('[data-strict-save-open-review]').addEventListener('click',function(){var url=new URL(location.href);url.searchParams.set('saveReview','1');window.open(url.href,'_blank','noopener');});
    inertBackground();
  }
  function lzQuietSaveToast(phase){
    try{
      var t=document.getElementById('lz-quiet-save');
      if(!t){t=document.createElement('div');t.id='lz-quiet-save';t.setAttribute('role','status');t.style.cssText='position:fixed;right:16px;bottom:16px;z-index:2147483646;padding:8px 14px;border-radius:10px;font:700 14px/1.4 system-ui,sans-serif;color:#fff;box-shadow:0 6px 18px rgba(0,0,0,.3);pointer-events:none;transition:opacity .3s';(document.body||document.documentElement).appendChild(t);}
      t.style.background=phase==='success'?'#13795b':'#245d86';t.textContent=phase==='success'?'✓ 已儲存':'儲存中…';t.style.opacity='1';
      clearTimeout(t.__h);if(phase==='success')t.__h=setTimeout(function(){t.style.opacity='0';},1800);
    }catch(_){}
  }
  // 20261018-quietrestore: quiet-restore helpers.
  function unconfirmedList(){try{var v=JSON.parse(sessionStorage.getItem(UNCONFIRMED_KEY)||'[]');return Array.isArray(v)?v:[];}catch(_){return [];}}
  function saveUnconfirmed(list){try{if(list.length)sessionStorage.setItem(UNCONFIRMED_KEY,JSON.stringify(list));else sessionStorage.removeItem(UNCONFIRMED_KEY);}catch(_){}}
  function openUnconfirmed(){return unconfirmedList().filter(function(r){return !r.released&&!r.okEvidence;});} /* 20261018-fix4: success evidence is not "unconfirmed" */
  // 20261018-fix4 (Codex-A 17:04): a record whose receipt confirmed SUCCESS is kept as evidence
  // (okEvidence:true) while another record for the same content is still unknown, so a manual
  // release of the unknown one can never silently resend content that is already saved.
  function recordsOverlap(a,b){
    var ak=Array.isArray(a.keys)?a.keys:[],bk=Array.isArray(b.keys)?b.keys:[];
    if(a.anyKey||!ak.length||b.anyKey||!bk.length)return true;
    return ak.some(function(k){return bk.indexOf(k)>=0;});
  }
  function markOkEvidence(id){
    var list=unconfirmedList(),hit=false;
    list.forEach(function(r){if(r.requestId===id&&!r.okEvidence){r.okEvidence=true;r.okAt=Date.now();hit=true;}});
    if(hit)saveUnconfirmed(list);
  }
  // 20261018-fix2 P1-2: a record whose content fingerprints are unknown (lock left by an older
  // guard version, or keys missing) is stored as anyKey:true and holds EVERY write until a receipt
  // confirms it or the user explicitly releases it. It is never treated as "matches nothing".
  function rememberUnconfirmed(id,keys,detail,anyKey){
    if(!id)return false;
    var list=unconfirmedList(),rec=list.find(function(r){return r.requestId===id;});
    if(!rec){rec={requestId:id,at:Date.now(),keys:[]};list.push(rec);}
    if(!Array.isArray(rec.keys))rec.keys=[];
    (keys||[]).forEach(function(k){if(k&&rec.keys.indexOf(k)<0)rec.keys.push(k);});
    if(anyKey||!rec.keys.length)rec.anyKey=true;
    if(detail)rec.detail=String(detail).slice(0,300);
    saveUnconfirmed(list);
    return unconfirmedList().some(function(r){return r.requestId===id;}); // really persisted?
  }
  // Only a confirmed receipt (success / explicit failure) removes a record.
  function confirmUnconfirmed(id,phase){
    var list=unconfirmedList(),rec=list.find(function(r){return r.requestId===id;});
    if(!rec)return;
    // 20261018-fix4: a success receipt for a record that still has an open, unknown sibling for the
    // same content is kept as success evidence instead of being deleted.
    if(phase==='success'&&list.some(function(r){return r!==rec&&!r.released&&!r.okEvidence&&recordsOverlap(rec,r);})){markOkEvidence(id);lzRemindUnconfirmed();return;}
    dropUnconfirmed(id);
  }
  function dropUnconfirmed(id){
    var list=unconfirmedList(),next=list.filter(function(r){return r.requestId!==id;});
    if(next.length!==list.length){saveUnconfirmed(next);lzRemindUnconfirmed();}
  }
  // User explicitly lets identical content be sent again: keep the record, only mark it released.
  function releaseUnconfirmed(id){
    var list=unconfirmedList();list.forEach(function(r){if(r.requestId===id){r.released=true;r.releasedAt=Date.now();}});
    saveUnconfirmed(list);
  }
  // 20261018-fix3 P1: return EVERY open record that applies to this content (anyKey / no keys / exact key).
  function unconfirmedAllFor(key){if(reviewMode)return [];return unconfirmedList().filter(function(r){return !r.released;}).filter( /* 20261018-fix4: includes success evidence */function(r){return r.anyKey||!Array.isArray(r.keys)||!r.keys.length||(!!key&&r.keys.indexOf(key)>=0);});}
  function unconfirmedFor(key){return unconfirmedAllFor(key)[0]||null;} /* 20261018-fix2 P1-2, fix3: built on unconfirmedAllFor */
  var NOTICE_COLORS={hold:'#8a5a12',success:'#13795b',error:'#a33a2b',info:'#245d86'};
  function lzQuietNotice(text,kind,onClick){
    try{
      var t=document.getElementById('lz-quiet-notice');
      if(!t){
        t=document.createElement('div');t.id='lz-quiet-notice';t.setAttribute('role','status');t.setAttribute('aria-live','polite');
        t.style.cssText='position:fixed;right:16px;bottom:64px;z-index:2147483645;max-width:min(360px,calc(100vw - 32px));padding:8px 14px;border-radius:10px;color:#fff;font:700 14px/1.4 system-ui,sans-serif;box-shadow:0 6px 18px rgba(0,0,0,.3);cursor:pointer;transition:opacity .3s';
        (document.body||document.documentElement).appendChild(t);
      }
      t.textContent=text;t.style.background=NOTICE_COLORS[kind]||NOTICE_COLORS.hold;t.style.opacity='1';
      t.setAttribute('data-lz-notice-kind',kind||'hold');
      t.onclick=onClick||function(){var url=new URL(location.href);url.searchParams.set('saveReview','1');window.open(url.href,'_blank','noopener');};
      t.title=onClick?'':'點一下另開唯讀頁核對（不可儲存）';
      // Fades after 6 s; stays (dimmed, clickable) while an unconfirmed record exists.
      clearTimeout(t.__h);t.__h=setTimeout(function(){if(openUnconfirmed().length&&kind==='hold')t.style.opacity='.55';else t.remove();},6000);
    }catch(_){}
  }
  function lzRemindUnconfirmed(){
    if(reviewMode)return;
    var n=openUnconfirmed().length,t=document.getElementById('lz-quiet-notice');
    if(!n){if(t&&t.getAttribute('data-lz-notice-kind')==='hold')t.remove();return;}
    lzQuietNotice('上一筆儲存結果尚未確認'+(n>1?'（'+n+' 筆）':''),'hold');
  }
  function releaseLock(){
    clearTimeout(idleTimer);clearTimeout(slowTimer);
    try{var row=stored();if(row&&(row.owner===tab||(batch&&row.requestId===batch.id)))sessionStorage.removeItem(KEY);}catch(_){}
    releaseStored();closeBox();batch=null;ownNativeNavigation=false;recent.clear();
    document.documentElement.removeAttribute('data-strict-save-blocked');
    window.dispatchEvent(new CustomEvent('admin:strict-save-acknowledged'));
  }
  // Unknown result: release the screen, keep request id + fingerprints, show a toast.
  function quietRelease(detail){
    var id=batch?String(batch.id||''):'';
    if(!rememberUnconfirmed(id,batch&&batch.keys,detail,!!(batch&&batch.anyKey)))return false; // cannot persist -> keep old modal as last resort
    releaseLock();lzRemindUnconfirmed();
    try{window.dispatchEvent(new CustomEvent('admin:strict-save-unconfirmed',{detail:{requestId:id}}));}catch(_){}
    return true;
  }
  function queryReceipt(rec){
    var none={phase:'unknown'};
    if(typeof receiptQuery!=='function')return Promise.resolve(none);
    try{return Promise.resolve(receiptQuery(rec.requestId)).then(function(r){return r&&['success','error'].includes(r.phase)?r:none;},function(){return none;});}catch(_){return Promise.resolve(none);}
  }
  // Same content resubmitted while still unconfirmed: query the receipt first.
  // success -> do not resend; error (confirmed not saved) -> send; unknown -> hold, toast lets the user release it.
  // 20261018-fix3 P1: a write is sent only when NO applicable unconfirmed record is left.
  // Each round queries every record that applies to this content: any unknown -> hold (nothing sent);
  // any success -> do not resend; error (confirmed not saved) -> only that record is removed.
  // After removals the list is read again, so records added or changed while the queries ran are
  // checked too. fetch, XHR and native forms all go through this one function.
  var MAX_RESOLVE_ROUNDS=5;
  function holdNotice(recs){
    var ids=recs.map(function(r){return r.requestId;});
    lzQuietNotice('上一筆儲存結果尚未確認'+(ids.length>1?'（'+ids.length+' 筆）':'')+'，查不到回執，這次先不重送；核對原單後點這裡可放行重送','hold',function(){
      ids.forEach(releaseUnconfirmed);lzQuietNotice('已放行，請再按一次儲存','info');
    });
  }
  // 20261018-fix4: same content has a confirmed success AND records without a receipt. Nothing is sent;
  // the notice says a success already exists. Releasing here is an explicit, informed choice that
  // marks the success evidence AND the unknown records released (kept, not deleted).
  function successHoldNotice(okRecs,unkRecs){
    var okIds=okRecs.map(function(r){return r.requestId;}),unkIds=unkRecs.map(function(r){return r.requestId;});
    lzQuietNotice('相同內容已有 '+okIds.length+' 筆確認儲存成功'+(unkIds.length?'，另有 '+unkIds.length+' 筆查不到回執':'')+'，這次沒有重送。若確定要再存一筆相同內容，核對原單後點這裡放行（可能重複儲存）','hold',function(){
      okIds.concat(unkIds).forEach(releaseUnconfirmed);lzQuietNotice('已放行（含已成功的那筆），請再按一次儲存','info');
    });
  }
  function holdNoticeFor(recs){
    var ok=recs.filter(function(r){return r.okEvidence;}),rest=recs.filter(function(r){return !r.okEvidence;});
    if(ok.length)successHoldNotice(ok,rest);else holdNotice(recs);
  }
  function resolveDuplicate(key,canResend){
    var round=0,removed=0;
    function step(){
      var recs=unconfirmedAllFor(key);
      if(!recs.length){
        if(removed)lzQuietNotice((removed>1?'前 '+removed+' 筆都確認沒有儲存，':'上一筆確認沒有儲存，')+(canResend?'已重新送出':'請再按一次儲存'),'info');
        return Promise.resolve(!!canResend);
      }
      if(++round>MAX_RESOLVE_ROUNDS){holdNoticeFor(recs);return Promise.resolve(false);}
      return Promise.all(recs.map(function(rec){
        if(rec.okEvidence)return Promise.resolve({rec:rec,phase:'success'}); /* 20261018-fix4: already confirmed saved */
        return queryReceipt(rec).then(function(r){return {rec:rec,phase:r.phase};});
      })).then(function(results){
        var ok=[],bad=[],unk=[];
        results.forEach(function(x){(x.phase==='success'?ok:x.phase==='error'?bad:unk).push(x.rec);});
        bad.forEach(function(r){dropUnconfirmed(r.requestId);});
        removed+=bad.length;
        if(unk.length&&ok.length){ok.forEach(function(r){markOkEvidence(r.requestId);});successHoldNotice(ok,unk);return false;} /* 20261018-fix4 */
        if(unk.length){holdNotice(unk);return false;}
        ok.forEach(function(r){dropUnconfirmed(r.requestId);}); /* no unknown sibling left: success consumed (blocks this one save, as before) */
        if(ok.length){lzQuietNotice('上一筆已確認儲存成功，這次沒有重送','success');return false;}
        return step(); // every record in this round was confirmed not saved: re-check the whole list
      });
    }
    return step();
  }
  function heldError(){var e=new Error('上一筆相同內容的儲存結果尚未確認，已暫停重送。');e.name='SaveDuplicateHeldError';return e;}
  // Keep a provisional click lock alive while the receipt query runs (no double click meanwhile).
  function holdProvisional(){
    var h=batch&&batch.phase==='pending'&&!batch.native?batch:null;
    if(h){clearTimeout(idleTimer);h.pending++;}
    return function(){if(h&&batch===h){h.pending=Math.max(0,h.pending-1);settle();}};
  }
  // 20261018-fix2 P1-1: fields this guard adds to a form (submitter copy, _strict_save_id) are marked
  // and removed right after the native submit() has captured the form data, so they never leak into
  // the next fingerprint of the same form (bfcache back + same button) or into a later submission.
  var SYNTH_ATTR='data-strict-save-synth', synthByForm=new WeakMap();
  function addSynth(form,el){el.setAttribute(SYNTH_ATTR,'');form.appendChild(el);var l=synthByForm.get(form);if(!l){l=[];synthByForm.set(form,l);}l.push(el);return el;}
  function clearSynth(form){
    var l=synthByForm.get(form)||[];synthByForm.delete(form);
    try{if(form.querySelectorAll)Array.prototype.forEach.call(form.querySelectorAll('['+SYNTH_ATTR+']'),function(el){if(l.indexOf(el)<0)l.push(el);});}catch(_){}
    l.forEach(function(el){try{el.remove();}catch(_){}});
  }
  function formKey(form,submitter){
    try{
      var rows=[];new FormData(form).forEach(function(v,k){if(!/^(?:_strict_save_id|_?csrf.*|_?token)$/i.test(k))rows.push([k,typeof v==='string'?v:[v.name,v.size,v.lastModified]]);});
      if(submitter&&submitter.name)rows.push([submitter.name,submitter.value]);
      var action=(submitter&&submitter.getAttribute&&submitter.getAttribute('formaction'))||form.getAttribute('action')||location.href;
      return 'FORM '+new URL(action,location.href).href+' '+JSON.stringify(rows);
    }catch(_){return '';}
  }
  function show(phase, detail) {
    // LZ_QUIET_SAVE_20261013：儲存中／儲存成功不再跳全螢幕擋住畫面，只在右下角小提示；失敗或結果不明才跳出來。
    if(phase==='pending'||phase==='native'||phase==='success'){
      if(batch){batch.phase=phase;batch.detail=detail||'';publish();}
      if(box)closeBox();
      lzQuietSaveToast(phase);
      if(phase==='success')setTimeout(function(){if(batch&&batch.phase==='success'&&!batch.pending)finishAcknowledgment();},30);
      return;
    }
    // 20261018-quietrestore: 峰志 10/18 14:13 — no full-screen modal for unknown or error. Toast only, screen stays usable.
    if(phase==='error'){releaseLock();lzQuietNotice('本次未儲存成功：'+String(detail||'伺服器拒絕本次資料，請檢查畫面上的原因').slice(0,200),'error');return;}
    if(phase==='unknown'&&quietRelease(detail))return;
    ensureBox();if(!box)return; // last resort only: the unconfirmed record could not be persisted
    var titles={pending:'儲存處理中，請勿重複操作',native:'儲存處理中，請勿重複操作',success:'儲存成功',error:'本次未儲存成功',unknown:'尚未確認儲存結果'};
    box.querySelector('[data-strict-save-title]').textContent=titles[phase]||titles.unknown;
    box.querySelector('[data-strict-save-detail]').textContent=detail||'正在等待伺服器完整回覆；完成後會顯示結果，現在不能開始下一筆。';
    box.querySelector('[data-strict-save-reference]').textContent=batch ? '本次操作識別：'+batch.id : '';
    var ack=box.querySelector('button'), review=box.querySelector('label');
    ack.hidden=!['success','error','unknown'].includes(phase); review.hidden=phase!=='unknown';
    box.querySelector('[data-strict-save-open-review]').hidden=phase!=='unknown';
    ack.textContent=phase==='unknown'?'已核對正式紀錄，解除鎖定':phase==='success'?'立即繼續操作':'返回檢查資料';
    ack.disabled=phase==='unknown'&&!box.querySelector('input').checked;
    if(!ack.hidden && !ack.disabled) ack.focus();
    if(batch){batch.phase=phase;publish();}
    box.querySelector('[data-strict-save-countdown]').hidden=phase!=='success';
    if (phase==='success') startSuccessCountdown(); else clearSuccessCountdown();
  }
  function closeBox() {
    clearTimeout(idleTimer);clearTimeout(slowTimer);clearSuccessCountdown();
    if(box){box.remove();box=null;}
    inerts.forEach(function(value,el){el.inert=value;});inerts.clear();
    if(oldFocus && oldFocus.isConnected && oldFocus.focus) oldFocus.focus();
    oldFocus=null;document.documentElement.removeAttribute('data-strict-save-blocked');
  }
  function begin(native) {
    if(!batch && stored()) restore();
    if(batch){if(batch.phase!=='pending')throw busyError();clearTimeout(idleTimer);return;}
    batch={id:'SAVE-'+Date.now()+'-'+Math.random().toString(36).slice(2,8),phase:'pending',pending:0,writes:0,outcomes:[],native:!!native,keys:[]}; /* 20261018-quietrestore */
    document.documentElement.setAttribute('data-strict-save-blocked','');publish();show('pending');
    slowTimer=setTimeout(function(){if(batch && batch.phase==='pending')show('pending','伺服器仍未完整回覆，鎖定會繼續保留。請勿重送或重新整理；目前不能認定成功或失敗。');},20000);
  }
  function finishOne(outcome) {
    if(!batch)return;
    batch.outcomes.push(outcome);batch.pending=Math.max(0,batch.pending-1);settle();
  }
  function settle() {
    clearTimeout(idleTimer);
    if(!batch||batch.pending||batch.native)return;
    // Let the original handlers parse their result, update their UI, and start
    // dependent writes. A chain is one operation, not a success per request.
    idleTimer=setTimeout(function(){
      if(!batch||batch.pending||batch.native)return;
      if(!batch.writes){releaseStored();closeBox();batch=null;return;}
      clearTimeout(slowTimer);
      var unknown=batch.outcomes.find(function(x){return x.phase==='unknown';});
      var error=batch.outcomes.find(function(x){return x.phase==='error';});
      if(unknown)show('unknown',(unknown.detail||'連線中斷或回覆不完整，不能確認是否已寫入。')+'\n請先另行核對原單與庫存；不會自動重送。');
      else if(error)show('error',error.detail||'伺服器拒絕本次資料，請檢查畫面的原因。');
      else show('success','伺服器已完整回覆並確認本次 '+batch.writes+' 個寫入步驟成功。3 秒後自動關閉提示並恢復本頁操作。');
    },350);
  }
  function track(promise) {
    if(!promise || typeof promise.then!=='function' || !batch || batch.phase!=='pending')return promise;
    var working=batch;clearTimeout(idleTimer);working.pending++;
    return Promise.resolve(promise).finally(function(){if(batch===working){working.pending=Math.max(0,working.pending-1);settle();}});
  }
  function bodyInfo(body) {
    if(typeof body==='string'){try{return JSON.parse(body);}catch(_){return Object.fromEntries(new URLSearchParams(body));}}
    if(body instanceof URLSearchParams || (typeof FormData!=='undefined'&&body instanceof FormData))return Object.fromEntries(body.entries());
    return {};
  }
  function requestInfo(input,options) {
    options=options||{};
    var url;try{url=new URL(typeof input==='string'||input instanceof URL?String(input):input.url,location.href);}catch(_){return null;}
    if(url.origin!==location.origin)return null;
    var method=String(options.method||(input&&input.method)||'GET').toUpperCase();
    if(['GET','HEAD','OPTIONS'].includes(method))return null;
    var data=bodyInfo(options.body),action=String(data.action||url.searchParams.get('action')||'').toLowerCase();
    if(url.searchParams.get('login')==='1'||url.searchParams.get('logout')==='1'||['login','logout','control_video_activity','heartbeat','session-check'].includes(action))return null;
    if(/(?:address-helper-api|business-logistics-page-api|admin-freight-search-api|ops-product-search|ops-product-index|ops-delivery-search)\.php$/i.test(url.pathname))return null;
    if(/^(?:get|list|read|load|search|lookup|query|preview|check)(?:[-_]|$)/.test(action)||/[-_]preview$/.test(action))return null;
    return {url:url.href,method:method,action:action,data:data,body:options.body};
  }
  function fingerprint(info) {
    var body=info.body;
    if(body instanceof FormData)body=Array.from(body.entries()).map(function(pair){var v=pair[1];return [pair[0],typeof v==='string'?v:[v.name,v.size,v.lastModified]];});
    else if(body instanceof URLSearchParams)body=body.toString();
    else if(typeof body==='string'){try{var d=JSON.parse(body);delete d.clientMutationId;delete d.requestId;body=JSON.stringify(d);}catch(_) {}}
    return info.method+' '+info.url+' '+JSON.stringify(body||'');
  }
  function startWrite(info) {
    if(reviewMode)throw new Error('唯讀核對模式不可儲存；请回到原頁確認本次結果後再操作。');
    begin();var key=fingerprint(info);
    if(recent.has(key))throw busyError();
    recent.set(key,true);(batch.keys=batch.keys||[]).push(key);batch.pending++; /* 20261018-quietrestore */batch.writes++;publish();return key;
  }
  function parsedOutcome(status,json,text) {
    var detail=json&&(json.error||json.message||json.notice||json.detail)||'';
    if(status===0||status>=500)return {phase:'unknown',detail:detail||('伺服器未確認結果（'+(status?'HTTP '+status:'連線中斷')+'）。')};
    if(status<200||status>=300 || json&&(json.ok===false||json.success===false||json.status==='error'||json.error))return {phase:'error',detail:String(detail||'伺服器拒絕本次資料（HTTP '+status+'）。').slice(0,500)};
    if(json && (json.pending===true||json.complete===false||['pending','processing'].includes(json.status)))return {phase:'unknown',detail:'伺服器表示仍在處理，尚未確認全部寫入。'};
    if(json && (Array.isArray(json.warnings)&&json.warnings.some(function(w){return /sync_pending|pending_sync/.test(String(w.code||''));}) || json.contactSync&&json.contactSync.ok===false || json.memberPhotoSync&&json.memberPhotoSync.ok===false))return {phase:'unknown',detail:'原單主要資料已儲存，但伺服器表示後續同步尚未完成；請核對原单，不要重建。'};
    if(json && (json.ok===true||json.success===true||['success','ok'].includes(json.status)))return {phase:'success'};
    // Native Baohui controllers return HTML. Only their explicit POST result
    // marker counts; a login page, arbitrary HTTP 200, or HTML error does not.
    if(text && /data-strict-save-receipt/.test(text)){
      try{var doc=new DOMParser().parseFromString(text,'text/html');var r=doc.querySelector('[data-strict-save-receipt]');var receipt=JSON.parse(r.textContent);if(receipt.submitted)return nativeOutcome(receipt.notice);}catch(_){}
    }
    return {phase:'unknown',detail:'沒有收到可核對的成功回覆，不能只憑 HTTP 200 判定已儲存。'};
  }
  function nativeOutcome(notice) {
    notice=String(notice||'');
    if(/失敗|錯誤|找不到|無法|不能|請先|請填|請至少|不足|未(?:儲存|完整|變更|建立|完成)|拒絕|略過.*不正確|沒有再次/.test(notice))return {phase:'error',detail:notice};
    if(/成功|已(?:儲存|更新|建立|新增|刪除|完成|核准|取消|記錄|登記|確認|同步|合併)|已由管理者核准|並入庫/.test(notice))return {phase:'success',detail:notice};
    return {phase:'unknown',detail:notice||'此頁未提供本次儲存的明確成功結果，請核對正式紀錄。'};
  }
  if(originalFetch)window.fetch=function(input,options){
    var info=requestInfo(input,options);
    if(!info){
      // An order submit may first check the customer or current stock. Keep
      // its provisional lock through that async validation, not just the POST.
      var reading=batch&&batch.phase==='pending'&&!batch.native?batch:null;
      if(!reading)return originalFetch(input,options);
      clearTimeout(idleTimer);reading.pending++;
      function readDone(){if(batch===reading){reading.pending=Math.max(0,reading.pending-1);settle();}}
      return originalFetch(input,options).then(function(response){return response.clone().arrayBuffer().then(function(){readDone();return response;},function(){readDone();return response;});},function(e){readDone();throw e;});
    }
    // 20261018-quietrestore: identical content still unconfirmed -> query receipt before (maybe) sending.
    var fkey=fingerprint(info);
    if(unconfirmedFor(fkey)){var unhold=holdProvisional();return resolveDuplicate(fkey,true).then(function(send){
      if(send&&unconfirmedFor(fkey)){send=false;holdNoticeFor(unconfirmedAllFor(fkey));} /* 20261018-fix3: final re-check right before sending */
      if(!send){unhold();throw heldError();}
      var p=guardedFetch(input,options,info);unhold();return p;
    },function(e){unhold();throw e;});}
    return guardedFetch(input,options,info);
  };
  function guardedFetch(input,options,info){
    try{startWrite(info);}catch(e){return Promise.reject(e);}
    return originalFetch(input,options).then(function(response){
      return response.clone().text().then(function(text){var json=null;try{json=JSON.parse(text);}catch(_){}finishOne(parsedOutcome(response.status,json,text));return response;},function(){finishOne({phase:'unknown',detail:'回覆讀取中斷，尚未確認儲存結果。'});return response;});
    },function(error){finishOne({phase:'unknown',detail:'連線中斷或請求逾時，尚未確認儲存結果。'});throw error;});
  }
  var xp=XMLHttpRequest.prototype, xopen=xp.open, xsend=xp.send;
  xp.open=function(method,url,async){this._strictSaveRequest={method:method,url:url,async:async!==false};return xopen.apply(this,arguments);};
  xp.send=function(body){
    var x=this, req=x._strictSaveRequest, info=req&&requestInfo(req.url,{method:req.method,body:body});
    if(!info)return xsend.apply(x,arguments);
    var xkey=fingerprint(info); /* 20261018-quietrestore */
    if(unconfirmedFor(xkey)){resolveDuplicate(xkey,false);throw heldError();} /* 20261018-fix3: all applicable records */
    startWrite(info);var finished=false;
    function done(){if(finished)return;finished=true;var json=null,text='';try{if(x.responseType==='json')json=x.response;else {text=x.responseText||'';try{json=JSON.parse(text);}catch(_){}}}catch(_){}finishOne(parsedOutcome(x.status,json,text));}
    x.addEventListener('loadend',done,{once:true});
    try{var result=xsend.apply(x,arguments);if(!req.async)done();return result;}catch(e){done();throw e;}
  };
  function saveButton(target) {
    var el=target&&target.closest&&target.closest('button,input[type="submit"],input[type="button"],a[role="button"]');
    if(!el || el.closest('[data-strict-save-dialog]'))return null;
    var text=String(el.innerText||el.value||'');
    var attrs=Array.from(el.attributes||[]).map(function(a){return a.name;}).join(' ');
    return /儲存|保存|存檔|送出|確認.*(?:入庫|回庫|刪除|建立|出貨)|正式.*(?:入庫|寄庫)|\bsave\b|\bsubmit\b/i.test(text)||/data-[^ ]*(?:save|submit|receive-confirm)/.test(attrs)?el:null;
  }
  function blockEvent(event) {
    if(reviewMode){if(event.type==='click'&&saveButton(event.target)){event.preventDefault();event.stopImmediatePropagation();}return;}
    if(event.target&&event.target.closest&&event.target.closest('[data-strict-save-dialog]')){
      if(event.type==='keydown'&&event.key==='Escape'){event.preventDefault();event.stopImmediatePropagation();}return;
    }
    if(blocked()) {event.preventDefault();event.stopImmediatePropagation();if(!batch)restore();return;}
    if(event.type==='click'&&saveButton(event.target)){
      try{begin();}catch(_){event.preventDefault();event.stopImmediatePropagation();}
      // Validation may reject locally without any request. Never call that a
      // save success; remove only the provisional click lock after the turn.
      setTimeout(settle,0);
    }
  }
  ['click','dblclick','pointerdown','keydown','beforeinput'].forEach(function(type){window.addEventListener(type,blockEvent,true);});
  function submitNative(form,submitter) {
    var method=String((submitter&&submitter.formMethod)||form.method||'get').toUpperCase();
    if(method==='GET'||method==='DIALOG')return originalSubmit.call(form);
    if(reviewMode)throw new Error('唯讀核對模式不可儲存。');
    clearSynth(form); /* 20261018-fix2 P1-1: never fingerprint leftovers of an earlier submit */
    var fkey=formKey(form,submitter),dup=unconfirmedFor(fkey); /* 20261018-quietrestore */
    if(dup){var unhold=holdProvisional();resolveDuplicate(fkey,true).then(function(send){unhold();if(send)submitNative(form,submitter);},function(){unhold();});return;} /* 20261018-fix3: all records; submitNative re-checks before sending */
    begin(true);if(fkey)(batch.keys=batch.keys||[]).push(fkey);batch.native=true;batch.writes++;batch.phase='native';ownNativeNavigation=true;
    var input=form.querySelector('input[name="_strict_save_id"]');if(!input){input=document.createElement('input');input.type='hidden';input.name='_strict_save_id';addSynth(form,input);}input.value=batch.id;
    // native submit() does not include the clicked submitter. Preserve its
    // name/value, action, target, method and enctype exactly — for THIS submit only.
    var savedAttrs=null;
    if(submitter){if(submitter.name){var value=document.createElement('input');value.type='hidden';value.name=submitter.name;value.value=submitter.value;addSynth(form,value);}
      savedAttrs={};['action','method','target','enctype'].forEach(function(k){if(submitter.hasAttribute('form'+k)){savedAttrs[k]=form.hasAttribute(k)?form.getAttribute(k):null;form.setAttribute(k,submitter.getAttribute('form'+k));}});}
    publish();show('native');
    try{originalSubmit.call(form);}
    finally{
      // submit() has already built the form data set; restore the form to exactly what the page had.
      clearSynth(form);
      if(savedAttrs)Object.keys(savedAttrs).forEach(function(k){if(savedAttrs[k]===null)form.removeAttribute(k);else form.setAttribute(k,savedAttrs[k]);});
    }
  }
  window.addEventListener('submit',function(event){
    var form=event.target;
    if(reviewMode && String(form.method).toLowerCase()==='post'){event.preventDefault();event.stopImmediatePropagation();return;}
    // A click on this submit button may have started only a provisional lock.
    if(batch&&batch.pending===0&&batch.writes===0&&batch.phase==='pending')return;
    if(blocked()){event.preventDefault();event.stopImmediatePropagation();return;}
    if(String(form.method).toLowerCase()==='post'){begin();setTimeout(settle,0);}
  },true);
  window.addEventListener('submit',function(event){
    if(event.defaultPrevented||String(event.target.method).toLowerCase()!=='post')return;
    event.preventDefault();submitNative(event.target,event.submitter);
  });
  HTMLFormElement.prototype.submit=function(){submitNative(this,null);};
  window.addEventListener('beforeunload',function(e){if(batch&&!ownNativeNavigation){e.preventDefault();e.returnValue='資料仍在處理或尚未核對，請勿重送。';}});
  window.addEventListener('pagehide',function(){if(batch&&!batch.native){batch.phase='unknown';publish();}});
  window.addEventListener('pageshow',function(e){if(e.persisted && batch){batch.pending=0;batch.native=false;ownNativeNavigation=false;
    if(!batch.writes){releaseLock();return;} /* 20261018-fix2: only a provisional click lock, nothing was sent */
    if(batch.phase==='success'){finishAcknowledgment();return;} /* 20261018-quietrestore: confirmed success finishes as before */
    show('unknown','此頁由瀏覽器返回，上一筆儲存結果仍需核對；不要直接重送。'); /* 20261018-quietrestore: -> quiet toast, record kept */
  }});
  // A native POST may return in another window. Only its matching initiating
  // page receives the confirmed result; unrelated tabs never become blocked.
  if(receiptChannel)receiptChannel.onmessage=function(event){
    var result=event.data;
    if(!reviewMode&&result&&result.sender!==tab&&['success','error'].includes(result.phase))confirmUnconfirmed(String(result.requestId||''),result.phase); /* 20261018-fix4: phase passed */ /* 20261018-quietrestore */
    if(reviewMode||!batch||!batch.native||!result||result.requestId!==batch.id
      ||result.sender===tab||!['success','error','unknown'].includes(result.phase))return;
    batch.native=false;ownNativeNavigation=false;
    finishOne({phase:result.phase,detail:String(result.detail||'')});
  };
  setInterval(function(){if(batch)publish();},10000);
  document.addEventListener('visibilitychange',function(){if(document.visibilityState==='visible'&&batch&&batch.phase==='success')updateSuccessCountdown();});
  new MutationObserver(inertBackground).observe(document.documentElement,{childList:true,subtree:true});
  function restore() {
    if(reviewMode){var banner=document.createElement('div');banner.setAttribute('role','status');banner.style.cssText='position:sticky;top:0;z-index:2147483646;padding:12px;background:#fae8ab;color:#172122;font:700 16px system-ui';banner.textContent='唯讀核對模式：可查詢原單與庫存，禁止儲存、入庫、出貨或刪除。';document.body.prepend(banner);return;}
    if(batch){show(batch.phase);return;}
    var marker=document.querySelector('[data-strict-save-receipt]'),receipt=null;
    try{receipt=marker&&JSON.parse(marker.textContent);}catch(_){}
    var row=stored();
    if(row && copiedFromOpener(row.owner) && !(receipt&&receipt.submitted&&receipt.requestId===row.requestId)) {
      try{sessionStorage.removeItem(KEY);}catch(_){} row=null;
    }
    if(!row)try{
      var legacy=JSON.parse(localStorage.getItem(LEGACY_KEY)||'null');
      if(legacy && previousTab && legacy.owner===previousTab && !copiedFromOpener(legacy.owner)){row=legacy;legacyOwner=legacy.owner;}
    }catch(_){}
    // A new native target has its own sessionStorage. Its server-supplied
    // receipt is enough to identify this operation without a site-wide lock.
    var nativeReceipt=receipt&&receipt.submitted&&/^SAVE-/.test(String(receipt.requestId||''))
      &&(!row||row.requestId===receipt.requestId);
    // 20261018-quietrestore: a server receipt (success / explicit failure) clears the matching unconfirmed record.
    if(receipt&&receipt.submitted&&receipt.requestId&&nativeOutcome(receipt.notice).phase!=='unknown')confirmUnconfirmed(String(receipt.requestId),nativeOutcome(receipt.notice).phase); /* 20261018-fix4 */
    if(!row&&!nativeReceipt){lzRemindUnconfirmed();return;}
    // 20261018-fix2 P1-2: a v2 row that says writes:0 sent nothing (provisional click lock) -> drop it.
    // A row without writes/keys (older guard, legacy v1 lock) or with writes but no keys is migrated
    // conservatively: its unconfirmed record holds every write (anyKey) until receipt or manual release.
    if(!nativeReceipt&&row&&row.writes===0&&row.phase!=='success'){try{if(stored()&&stored().requestId===row.requestId)sessionStorage.removeItem(KEY);}catch(_){}releaseStored();lzRemindUnconfirmed();return;}
    var rowKeys=row&&Array.isArray(row.keys)?row.keys.filter(Boolean):[];
    batch={id:nativeReceipt?receipt.requestId:row.requestId,phase:'unknown',pending:0,writes:1,outcomes:[],native:false,keys:rowKeys,anyKey:!nativeReceipt&&!rowKeys.length}; publish(); /* LZ_GUARD_ADOPT_20261017：接手上一頁留下的鎖，解除時才刪得掉 */
    document.documentElement.setAttribute('data-strict-save-blocked','');
    if(nativeReceipt){
      var result=nativeOutcome(receipt.notice);show(result.phase,result.detail||'伺服器已確認本次表單儲存成功，3 秒後自動恢復操作。');
      if(receiptChannel)receiptChannel.postMessage({sender:tab,requestId:receipt.requestId,phase:result.phase,detail:result.detail||''});
    }
    else if(row.phase==='success')show('success','上次操作已收到伺服器成功確認，3 秒後自動關閉提示。');
    else show('unknown','上次操作的儲存結果尚未核對。請先核對原單與庫存，勿直接重新送出。'); /* 20261018-quietrestore: -> quiet toast */
    if(!batch)lzRemindUnconfirmed();
  }
  // Event listeners often await file processing or several dependent APIs.
  // Hold the lock for their returned promise, not a guessed network timeout.
  var add=EventTarget.prototype.addEventListener,remove=EventTarget.prototype.removeEventListener, listeners=new WeakMap();
  EventTarget.prototype.addEventListener=function(type,listener,options){
    if(!listener||!['click','submit','change'].includes(type))return add.apply(this,arguments);
    var capture=typeof options==='boolean'?options:!!(options&&options.capture),byListener=listeners.get(this);
    if(!byListener){byListener=new Map();listeners.set(this,byListener);}
    var map=byListener.get(listener);if(!map){map=new Map();byListener.set(listener,map);}
    var key=type+':'+capture,wrapped=map.get(key);
    if(!wrapped){wrapped=function(event){var result=typeof listener==='function'?listener.call(this,event):listener.handleEvent(event);var pending=track(result);if(pending&&pending!==result)pending.catch(function(){});return result;};map.set(key,wrapped);}
    return add.call(this,type,wrapped,options);
  };
  EventTarget.prototype.removeEventListener=function(type,listener,options){var m=listeners.get(this),row=m&&m.get(listener),capture=typeof options==='boolean'?options:!!(options&&options.capture);return remove.call(this,type,row&&row.get(type+':'+capture)||listener,options);};
  function fetchWorkerPreparation(input,options) {
    options=options||{};
    var url=new URL(String(input),location.href),data=bodyInfo(options.body);
    if(reviewMode || url.origin!==location.origin || url.pathname!=='/ad-posting-api.php'
        || url.searchParams.get('action')!=='prepare_shift' || options.method!=='POST'
        || data.protocol!==2 || !/^(?:[01]\d|2[0-3]):00$/.test(String(data.slot||''))) {
      return Promise.reject(new Error('不符合自動發文準備契約，未送出。'));
    }
    // Only prepare the server-owned, single-attempt advertising reservation.
    // Do not acknowledge, release or overwrite any human/company save lock.
    // Claim/start/submit/complete remain owned by the authenticated v2 worker.
    return originalFetch(input,options);
  }
  window.StrictSaveGuard={blocked:blocked,track:track,version:'20261005-tab-local-3s-1',classify:parsedOutcome,fetchWorkerPreparation:fetchWorkerPreparation,
    unconfirmed:function(){return unconfirmedList();},setReceiptQuery:function(fn){receiptQuery=typeof fn==='function'?fn:null;}}; /* 20261018-quietrestore */
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',restore,{once:true});else restore();
}());
</script>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>廣告發文列｜寶輝後台</title>
<style>
:root{color-scheme:light;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#182230;background:#f4f7fb}*{box-sizing:border-box}body{margin:0}.top{height:64px;background:#0f172a;color:#fff;display:flex;align-items:center;justify-content:space-between;padding:0 24px;position:sticky;top:0;z-index:2}.top a{color:#fff;text-decoration:none;border:1px solid #64748b;border-radius:8px;padding:8px 12px}.wrap{max-width:1500px;margin:auto;padding:22px}.summary{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px}.metric,.panel{background:#fff;border:1px solid #dfe5ee;border-radius:14px;box-shadow:0 4px 16px #26364d10}.metric{padding:16px}.metric b{display:block;font-size:26px;margin-top:4px}.layout{display:grid;grid-template-columns:minmax(360px,1fr) minmax(500px,1.25fr);gap:16px}.panel{overflow:hidden}.panel h2{font-size:18px;margin:0;padding:17px 18px;border-bottom:1px solid #e6ebf2}.list{max-height:calc(100vh - 210px);overflow:auto}.row{display:grid;grid-template-columns:62px 1fr 92px 92px;gap:10px;align-items:center;padding:13px 16px;border-bottom:1px solid #edf0f4;cursor:pointer}.row:hover,.row.active{background:#eef5ff}.id{font-weight:800;color:#1d4ed8}.small{font-size:12px;color:#667085}.badge{font-size:12px;padding:5px 8px;border-radius:999px;background:#eef2f7;text-align:center}.badge.done{background:#dcfce7;color:#166534}.badge.working{background:#dbeafe;color:#1d4ed8}.detail{padding:18px}.warn{padding:12px;border-radius:10px;background:#fff7e6;color:#8a4b00;margin-bottom:14px}.box{border:1px solid #dfe5ee;border-radius:10px;padding:13px;margin:12px 0;background:#fafcff}.copy{white-space:pre-wrap;line-height:1.6}.preview{width:100%;max-height:420px;object-fit:contain;border-radius:10px;background:#eef1f5}.actions{display:flex;gap:9px;flex-wrap:wrap;margin:14px 0}.btn{border:1px solid #cbd5e1;background:#fff;border-radius:9px;padding:10px 14px;font-weight:650;cursor:pointer}.btn.primary{background:#1667d9;color:#fff;border-color:#1667d9}.btn.good{background:#15803d;color:#fff;border-color:#15803d}.btn.warnbtn{background:#b45309;color:#fff;border-color:#b45309}.note{width:100%;padding:11px;border:1px solid #cbd5e1;border-radius:9px}.toast{position:fixed;right:20px;bottom:20px;background:#111827;color:#fff;padding:12px 16px;border-radius:9px;display:none}@media(max-width:900px){.summary{grid-template-columns:repeat(2,1fr)}.layout{grid-template-columns:1fr}.list{max-height:380px}.row{grid-template-columns:55px 1fr 80px}.row .approval{display:none}}
.publish-modal{position:fixed;inset:0;z-index:50;display:none;align-items:center;justify-content:center;padding:24px;background:#0f172acc}.publish-modal.open{display:flex}.publish-dialog{width:min(1180px,100%);max-height:94vh;overflow:auto;background:#fff;border-radius:18px;box-shadow:0 24px 70px #02061766}.publish-head{position:sticky;top:0;z-index:2;display:flex;align-items:center;justify-content:space-between;gap:16px;padding:17px 20px;background:#0f172a;color:#fff}.publish-head strong{display:block;font-size:20px}.publish-head small{display:block;margin-top:4px;color:#cbd5e1}.publish-close{border:1px solid #64748b;background:#fff;color:#0f172a;border-radius:9px;padding:9px 15px;font-weight:700;cursor:pointer}.publish-body{display:grid;grid-template-columns:1fr 1fr;gap:18px;padding:20px}.publish-step{min-width:0;border:1px solid #dfe5ee;border-radius:13px;padding:16px;background:#f8fafc}.publish-step h3{margin:0 0 12px;font-size:17px}.publish-copy{height:360px;overflow:auto;white-space:pre-wrap;line-height:1.65;border:1px solid #d7dee8;border-radius:10px;padding:14px;background:#fff}.publish-image{display:block;width:100%;height:360px;object-fit:contain;border:1px solid #d7dee8;border-radius:10px;background:#fff}.publish-tools{display:flex;flex-wrap:wrap;gap:9px;margin-top:12px}.publish-guide{margin-top:10px;color:#475467;font-size:13px;line-height:1.55}.publish-foot{position:sticky;bottom:0;display:flex;align-items:center;justify-content:space-between;gap:14px;padding:14px 20px;border-top:1px solid #dfe5ee;background:#fff}.publish-foot span{color:#475467;font-size:13px}.publish-facebook{border:0;background:#1877f2;color:#fff;border-radius:10px;padding:12px 18px;font-weight:800;cursor:pointer}.material-chooser{grid-column:1/-1;border:2px solid #93c5fd;border-radius:12px;padding:13px;background:#eff6ff}.material-chooser label{display:block;font-weight:800;margin-bottom:6px}.material-chooser select{width:100%;padding:10px;border:1px solid #93c5fd;border-radius:9px;background:#fff}.manager{margin-bottom:18px;border:1px solid #bfdbfe;border-radius:14px;background:#eff6ff;overflow:hidden}.manager summary{cursor:pointer;padding:15px 18px;font-weight:800;color:#1e3a8a}.manager-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;padding:0 18px 18px}.manager-card{border:1px solid #d7e3f3;border-radius:12px;padding:14px;background:#fff}.manager-card h3{margin:0 0 10px;font-size:16px}.manager-card label{display:block;margin:10px 0 5px;font-size:13px;font-weight:700;color:#344054}.manager-card input,.manager-card textarea,.manager-card select{width:100%;border:1px solid #cbd5e1;border-radius:9px;padding:10px;font:inherit}.manager-card textarea{min-height:190px;resize:vertical;line-height:1.5}.manager-preview{width:100%;height:190px;object-fit:contain;border:1px solid #e2e8f0;border-radius:9px;background:#f8fafc}.manager-help{font-size:12px;color:#667085;line-height:1.5;margin-top:7px}.reminder{margin-bottom:18px;padding:15px 18px;border-radius:14px;border:2px solid #f59e0b;background:#fffbeb}.reminder.ok{border-color:#86efac;background:#f0fdf4}.reminder h3{margin:0 0 7px;font-size:17px}.shift-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:18px}.shift-card{border:1px solid #d7dee8;border-left:5px solid #94a3b8;border-radius:12px;background:#fff;padding:13px}.shift-card.late{border-left-color:#dc2626;background:#fff7f7}.shift-card.active{border-left-color:#2563eb;background:#eff6ff}.shift-card.done{border-left-color:#16a34a;background:#f0fdf4}.shift-card b{font-size:20px}.shift-meta{margin-top:7px;font-size:12px;line-height:1.55;color:#475467}.material-status{display:flex;flex-wrap:wrap;gap:5px;margin:8px 0}.material-chip{font-size:11px;padding:4px 7px;border-radius:999px;background:#fee2e2;color:#991b1b}.material-chip.ready{background:#dcfce7;color:#166534}@media(max-width:900px){.publish-modal{padding:8px}.publish-dialog{max-height:98vh}.publish-body{grid-template-columns:1fr;padding:12px}.publish-copy,.publish-image{height:280px}.publish-foot{align-items:stretch;flex-direction:column}.publish-facebook{width:100%}.manager-grid{grid-template-columns:1fr;padding:0 12px 12px}.shift-grid{grid-template-columns:1fr}}
.helper-state{display:inline-block;margin-left:7px;padding:3px 7px;border-radius:999px;background:#fee2e2;color:#991b1b;font-size:11px;font-weight:800}.helper-state.ready{background:#dcfce7;color:#166534}.helper-install-guide{grid-column:1/-1;border:2px solid #dc2626;border-radius:12px;padding:14px;background:#fff7f7;color:#7f1d1d;line-height:1.65}.helper-install-guide[hidden]{display:none}.helper-install-guide b{display:block;font-size:17px;margin-bottom:5px}
.row-action{border:1px solid #93c5fd;background:#eff6ff;color:#1d4ed8;border-radius:999px;padding:7px 10px;font-size:12px;font-weight:800;cursor:pointer}.row-action:hover,.row-action:focus{background:#1667d9;color:#fff;outline:2px solid #bfdbfe}.row-action.done{border-color:#86efac;background:#dcfce7;color:#166534}
.region-toolbar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:16px;padding:14px;background:#fff;border:1px solid #dfe5ee;border-radius:14px;box-shadow:0 4px 16px #26364d10}.region-toolbar strong{margin-right:4px}.region-filter{border:1px solid #cbd5e1;background:#fff;color:#344054;border-radius:999px;padding:8px 13px;font-weight:800;cursor:pointer}.region-filter.active{border-color:#0f766e;background:#0f766e;color:#fff}.region-heading{display:flex;align-items:center;justify-content:space-between;padding:9px 16px;background:#e8f1f6;color:#164e63;font-size:13px;font-weight:900;letter-spacing:.02em}.region-heading span{font-weight:700;color:#476575}.row{grid-template-columns:58px minmax(180px,1fr) 108px 104px}.region-label{display:inline-flex;margin-top:5px;padding:3px 7px;border-radius:999px;background:#e0f2fe;color:#075985;font-size:11px;font-weight:800}.quota-line{display:flex;justify-content:space-between;gap:12px;margin:12px 0 7px;font-size:13px;color:#475467}.quota-line strong{color:#0f766e}.progress-track{height:10px;overflow:hidden;border-radius:999px;background:#e2e8f0}.progress-track i{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,#0f766e,#22c55e)}.accept-box{border:2px solid #86efac;border-radius:12px;padding:14px;background:#f0fdf4;margin:14px 0}.accept-box.pending{border-color:#fbbf24;background:#fffbeb}.accept-box b{display:block;margin-bottom:5px}.empty-region{padding:28px;text-align:center;color:#667085}.btn.accept{background:#0f766e;color:#fff;border-color:#0f766e}.manager-card.wide{grid-column:1/-1}.target-format{min-height:240px!important;font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:13px}@media(max-width:900px){.region-toolbar{align-items:stretch}.region-filter{flex:1 1 auto}.row{grid-template-columns:48px minmax(150px,1fr) 92px}.row .approval{display:none}.manager-card.wide{grid-column:auto}}
.material-chip.approved{background:#dcfce7;color:#166534;border:1px solid #86efac}.material-chip.pending-approval{background:#fff7ed;color:#9a3412;border:1px solid #fdba74}.material-chip.incomplete{background:#f1f5f9;color:#64748b;border:1px solid #cbd5e1}.material-chip{cursor:pointer;user-select:none}.material-chip:hover{filter:brightness(.96);transform:translateY(-1px)}.material-chip.selected{outline:3px solid #2563eb;outline-offset:2px;font-weight:900}
.shift-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.control-guide{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:13px}.control-guide a{display:block;border:1px solid #d6deea;border-radius:10px;background:#fff;padding:11px 12px;color:#1e3a8a;text-decoration:none;font-weight:800}.control-guide small{display:block;margin-top:4px;color:#64748b;font-weight:600;line-height:1.45}.manager[open] summary{border-bottom:1px solid #bfdbfe;background:#dbeafe}.manager summary::after{content:"（點這裡展開／收合）";margin-left:8px;color:#64748b;font-size:12px;font-weight:600}.manager-card{min-width:0}.manager-card textarea{max-width:100%}@media(max-width:900px){.control-guide{grid-template-columns:1fr}}@media(max-width:700px){.shift-grid{grid-template-columns:1fr}}
.shift-regions{display:flex;gap:5px;flex-wrap:wrap;margin:9px 0}.shift-region{display:inline-flex;padding:4px 8px;border-radius:999px;background:#e0f2fe;color:#075985;font-size:12px;font-weight:850}.shift-region.zero{background:#f1f5f9;color:#64748b}
.identity-group-card{border:1px solid #bfdbfe;border-radius:12px;padding:14px;margin:14px 0;background:#f8fbff}.identity-group-card h4{margin:0;font-size:17px}.identity-membership-list{margin-top:12px;padding:12px;border:1px solid #cbd5e1;border-radius:9px;background:#fff}.identity-membership-list[hidden]{display:none}.identity-membership-items{max-height:360px;overflow:auto;margin-top:8px}.identity-membership-row{display:flex;gap:14px;align-items:center;padding:8px;border-bottom:1px solid #edf2f7}.identity-membership-row span{font-size:12px;color:#475467;text-align:right;margin-left:auto}.identity-membership-row a{color:#1d4ed8;text-decoration:none;min-width:0}.identity-membership-row .btn{width:auto;flex:0 0 auto;padding:5px 8px;font-size:12px}
.blocked-panel{margin-bottom:18px;border:1px solid #fecaca;border-radius:14px;background:#fff;overflow:hidden}.blocked-panel summary{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 17px;background:#fff1f2;color:#991b1b;cursor:pointer;font-size:18px;font-weight:800;list-style:none}.blocked-panel summary::-webkit-details-marker{display:none}.blocked-panel summary::after{content:"展開查看";flex:0 0 auto;padding:6px 10px;border:1px solid #fca5a5;border-radius:999px;background:#fff;color:#991b1b;font-size:12px}.blocked-panel[open] summary{border-bottom:1px solid #fecaca}.blocked-panel[open] summary::after{content:"收合"}.blocked-list{display:grid;gap:8px;padding:13px}.blocked-row{display:grid;grid-template-columns:minmax(200px,1fr) minmax(220px,1.4fr) auto;gap:12px;align-items:center;border:1px solid #fee2e2;border-radius:10px;padding:11px}.blocked-row small{display:block;color:#667085;margin-top:4px}.blocked-row .btn{white-space:nowrap}@media(max-width:700px){.blocked-row{grid-template-columns:1fr}.blocked-panel summary{font-size:16px}}
.database-overview{margin-bottom:18px;border:1px solid #bfdbfe;border-radius:14px;background:#fff;overflow:hidden;box-shadow:0 4px 16px #26364d10}.database-head{padding:15px 17px;background:#eff6ff;border-bottom:1px solid #bfdbfe}.database-head h2{margin:0 0 5px;font-size:18px;color:#1e3a8a}.database-head p{margin:0;color:#475467;font-size:13px;line-height:1.5}.database-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;padding:13px}.database-card{border:1px solid #dbe3ee;border-radius:11px;padding:12px;background:#fff}.database-card b{display:block;font-size:16px}.database-card strong{display:block;font-size:25px;color:#0f766e;margin:5px 0}.database-card small{display:block;color:#667085;line-height:1.5}.schedule-note{margin:0 13px 13px;padding:11px 13px;border-radius:10px;background:#f0fdf4;color:#166534;font-size:13px;font-weight:750}@media(max-width:900px){.database-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:520px){.database-grid{grid-template-columns:1fr}}
.role-matrix{margin-bottom:18px;border:1px solid #bfdbfe;border-radius:14px;background:#fff;overflow:hidden;box-shadow:0 4px 16px #26364d10}.role-matrix h2{margin:0;padding:15px 17px;background:#eff6ff;border-bottom:1px solid #bfdbfe;font-size:18px;color:#1e3a8a}.role-matrix p{margin:0;padding:10px 17px 0;color:#475467;font-size:13px;line-height:1.55}.role-matrix table{width:100%;border-collapse:collapse;font-size:13px}.role-matrix th,.role-matrix td{padding:10px 14px;border-top:1px solid #e8eef6;text-align:left;vertical-align:top}.role-matrix th{background:#f8fafc;color:#334155}.role-matrix .lane{white-space:nowrap;font-weight:800;color:#0f766e}
</style></head><body>
<header class="top"><strong>寶輝後台｜廣告發文列</strong><span><a href="admin-product-posting.php">產品販售發文管理</a> <a href="admin.php">返回總部管理系統</a></span></header>
<main class="wrap"><section class="summary" id="summary"></section><section class="role-matrix" id="roleMatrix"><h2>廣告發文列｜身分與工作列</h2><p>沿用本頁既有發文元件與峰志 Chrome 助手，不另開一套海報。Kuo Feng Cheng 的產品上架／競標貼文提醒仍走既有排程工作列。</p><table><thead><tr><th>身分</th><th>類型</th><th>上架文章</th><th>工作列</th></tr></thead><tbody><tr><td>YUKI LIU</td><td>人員</td><td>可</td><td class="lane">廣告發文列；另負責通州截標（臉書當日日報／結標）</td></tr><tr><td>Kuo Feng Cheng</td><td>人員</td><td>否（一般廣告已移出）</td><td class="lane">上架產品、競標貼文提醒（既有排程／提醒工作列）</td></tr><tr><td>寶輝科技官方網站 粉絲團</td><td>粉絲團</td><td>可</td><td class="lane">廣告發文列／產品販售發文</td></tr><tr><td>海賊團行政專員</td><td>發文身分</td><td>可</td><td class="lane">廣告發文列</td></tr></tbody></table></section><section id="reminder"></section><section id="evaluation"></section><section id="manager"></section><section id="blockedGroups"></section><section id="databaseOverview"></section><section class="region-toolbar" id="regionToolbar"></section><section class="layout">
<div class="panel"><h2 id="todayListTitle">今日自動排程社團</h2><div class="list" id="targetList"></div></div>
<div class="panel"><h2>Facebook 社團自動輪發作業</h2><div class="detail" id="detail"><p>請從左側選擇社團。</p></div></div>
</section></main><div class="toast" id="toast"></div>
<div class="publish-modal" id="publishModal" aria-hidden="true" onclick="if(event.target===this)closePublishModal()">
  <div class="publish-dialog" role="dialog" aria-modal="true" aria-labelledby="publishModalTitle">
    <div class="publish-head"><div><strong id="publishModalTitle">Facebook 發文工作視窗</strong><small id="publishTargetName"></small></div><button type="button" class="publish-close" onclick="closePublishModal()">關閉</button></div>
    <div class="publish-body">
      <div class="material-chooser"><label for="publishMaterialSelect">本社團使用哪一組文案與廣告圖？</label><select id="publishMaterialSelect" onchange="selectPublishMaterial(this.value)"></select><div class="publish-guide">同一小時每組素材只用於一個社團；下一個小時可重新輪播。</div></div>
      <div class="helper-install-guide" id="helperInstallGuide" hidden><b>這台瀏覽器尚未連上最新版「寶輝發文助手」</b><strong>不要移除原本的擴充功能。</strong>下載新版 ZIP 後，將其中 <code>baohui-posting-helper</code> 資料夾的檔案覆蓋到原本載入的同名資料夾；再到 <code>chrome://extensions</code>，於原本同一張「寶輝 Facebook 發文助手」卡片按 <b>↻ 重新載入</b>。版本會原地升級，元件 ID、開關與既有設定都會保留。完成後回本頁按重新偵測。<div class="publish-tools"><a class="btn primary" href="baohui-posting-helper.zip" download>下載新版（覆蓋原資料夾）</a><button class="btn" type="button" onclick="location.reload()">更新完成，重新偵測</button></div></div>
      <section class="publish-step"><h3>1. 複製發文文字</h3><div class="publish-copy" id="publishCopyText"></div><div class="publish-tools"><button type="button" class="btn primary" onclick="copyPublishText()">複製完整文案</button></div><div class="publish-guide">到 Facebook 貼文輸入區按 Ctrl + V，即可貼上完整文字。系統會固定在文末附上寶輝科技官方網站。</div></section>
      <section class="publish-step"><h3>2. 準備上傳圖片</h3><img class="publish-image" id="publishImage" alt="本輪 Facebook 廣告圖片"><div class="publish-tools"><button type="button" class="btn primary" onclick="copyPublishImage()">複製圖片（Facebook 按 Ctrl+V）</button><a class="btn" id="publishDownload" download>下載圖片備用</a></div><div class="publish-guide">優先複製圖片後到 Facebook 按 Ctrl + V；若瀏覽器不支援，再下載圖片並從 Facebook 選取上傳。</div></section>
    </div>
    <div class="publish-foot"><span>即時發布由人員自行確認，不會計入自動排程；自動發佈才會更新時段進度。<i class="helper-state" id="helperState">偵測助手中</i> <a href="baohui-posting-helper.zip" download>原地更新助手（不要移除）</a></span><div class="publish-tools"><button type="button" class="btn" onclick="startFacebookPublish(false)">即時發布（人工，不列入排程）</button><button type="button" class="publish-facebook" onclick="startFacebookPublish(true)">自動排程發佈</button></div></div>
  </div>
</div>
<script>
let state=null,selected='',selectedMaterialId='',regionFilter='全部區域',autoLaunchBusy=false;
const helperRequests=new Map();
const AUTO_SLOTS=Array.from({length:24},(_,hour)=>String(hour).padStart(2,'0')+':00');
function currentAdSlot(now=new Date()){
  const parts=new Intl.DateTimeFormat('en-GB',{timeZone:'Asia/Taipei',hour:'2-digit',minute:'2-digit',hourCycle:'h23'}).formatToParts(now);
  const hour=Number(parts.find(part=>part.type==='hour')?.value||0),minute=Number(parts.find(part=>part.type==='minute')?.value||0);
  return String(hour).padStart(2,'0')+':00';
}
const AD_POSTING_IDENTITIES=['YUKI LIU','海賊團行政專員','寶輝科技官方網站 粉絲團']; // New work only; historical 頭城竹安店 rows stay valid.
const AD_POSTING_IDENTITY_ALIASES={'寶輝科技有限公司 頭城竹安店':'寶輝科技官方網站 粉絲團','KUO GFENG CHENG':'Kuo Feng Cheng','KUO FENG CHENG':'Kuo Feng Cheng'};
function normalizeAdIdentity(name){const n=String(name||'').replace(/\s+/g,' ').trim();return AD_POSTING_IDENTITY_ALIASES[n]||n}
function adIdentityAllowed(name){const raw=String(name||'').replace(/\s+/g,' ').trim();return AD_POSTING_IDENTITIES.includes(normalizeAdIdentity(raw))||raw==='寶輝科技有限公司 頭城竹安店'}
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const COMPANY_WEBSITE='https://www.s1214098.com.tw/';
const AD_CONTACT_NOTICE='【防詐騙／收購聯絡方式】\n近期詐騙訊息很多，本帳號是收購方，不是賣家，不會主動向您推銷商品或要求付款。\n留言只寫「要」、「私」或要求私訊者，我們不再逐一查看與回覆。若您確實有物品要出售，請直接加 LINE：s1214098，並傳送品名、型號、照片與所在地區。';
function withCompanyWebsite(copy){let text=String(copy??'').trim();if(!text)return text;if(!text.includes('【防詐騙／收購聯絡方式】'))text+='\n\n'+AD_CONTACT_NOTICE;if(!/https?:\/\/(?:www\.)?s1214098\.com\.tw\/?/i.test(text))text+='\n\n官方網站：'+COMPANY_WEBSITE;return text}
async function api(action='list',body){const r=await fetch('ad-posting-api.php?action='+action,{method:body?'POST':'GET',headers:{'Content-Type':'application/json'},body:body?JSON.stringify(body):undefined});if(r.status===401){location.href='admin.php';throw Error('尚未登入')}const j=await r.json();if(!j.ok)throw Error(j.error||'操作失敗');state=j.state;state.can_manage=!!j.can_manage;render();return j}
function show(msg){const t=document.getElementById('toast');t.textContent=msg;t.style.display='block';setTimeout(()=>t.style.display='none',2200)}
const showRaw=show;show=msg=>showRaw(String(msg??'').replace(/1\.10\.(96|188|192|194|195|196|197|198|199|200|201|202|203|204|205|206)/g,'1.10.266'));
function sendToPostingHelper(payload){const requiredVisibleName=adIdentityAllowed(String(payload.requiredVisibleName||''))?String(payload.requiredVisibleName||'').replace(/\s+/g,' ').trim():'';if(!requiredVisibleName)return Promise.reject(Error('發文身分未核准'));payload={...payload,kind:'advertising_post',requiredVisibleName};return new Promise((resolve,reject)=>{const requestId=(crypto.randomUUID&&crypto.randomUUID())||('req-'+Date.now()+'-'+Math.random()),timer=setTimeout(()=>{helperRequests.delete(requestId);reject(Error('發文助手沒有回應，請重新載入擴充功能'))},8000);helperRequests.set(requestId,{resolve,reject,timer});window.postMessage({source:'baohui-ad-posting',type:'PREPARE_FACEBOOK_POST',payload:{...payload,requestId}},location.origin)})}
function regionList(){return[...new Set((state&&state.targets||[]).filter(t=>t.scheduled_today).map(t=>t.region||'全台／未指定'))].sort((a,b)=>a.localeCompare(b,'zh-Hant'))}
function adTargetCannotPost(t){const identity=t.posting_identity||'寶輝科技有限公司 頭城竹安店';return !!t.blocked||t.identity_eligibility?.[identity]?.status==='無法發文'}
function scheduledTargets(){const today=state&&state.today||{},schedule=today.schedule||{},slotById={};Object.entries(schedule).forEach(([slot,ids])=>(Array.isArray(ids)?ids:[]).forEach(id=>slotById[String(id)]=slot));return(state&&state.targets||[]).filter(t=>t.scheduled_today||slotById[String(t.id)]).map(t=>({...t,slot:t.slot||slotById[String(t.id)]||''}))}
function scheduledTargetsForSlot(slot){const today=state&&state.today||{},ids=today.schedule&&today.schedule[slot]||[],records=today.hourly_targets&&today.hourly_targets[slot]||{},byId=Object.fromEntries((state&&state.targets||[]).map(t=>[String(t.id),t]));return ids.map(id=>({...byId[String(id)],...(records[String(id)]||{}),id:String(id),slot})).filter(t=>t.name)}
function shiftRegionBadges(items,slot){const regions=[['北','北部地區'],['中','中部地區'],['南','南部地區'],['東','東部地區']],counts=Object.fromEntries(regions.map(([,key])=>[key,0])),assignments=state&&state.today&&state.today.schedule_regions&&state.today.schedule_regions[slot]||{};let nationwide=0;items.forEach(t=>{const region=assignments[String(t.id)]||t.rotation_region||t.region||'全台／未指定';if(Object.prototype.hasOwnProperty.call(counts,region))counts[region]++;else nationwide++});return regions.map(([short,key])=>`<span class="shift-region ${counts[key]?'':'zero'}">${short} ${counts[key]}</span>`).join('')+(nationwide?`<span class="shift-region">全台 ${nationwide}</span>`:'')}
function openManagerSection(focusId){const details=document.querySelector('#manager details');if(details)details.open=true;setTimeout(()=>{const target=document.getElementById(focusId)||details;if(target)target.scrollIntoView({behavior:'smooth',block:'start'})},30)}
function setRegionFilter(region){regionFilter=region;const visible=(state.targets||[]).filter(t=>region==='全部區域'||(t.region||'全台／未指定')===region);if(visible.length&&!visible.some(t=>String(t.id)===String(selected)))selected=String(visible[0].id);render()}
function renderDatabaseOverview(all,scheduled){
  all=all.filter(t=>adIdentityAllowed(t.posting_identity));
  const qualified=t=>{const owner=t.posting_identity||'',status=t.identity_eligibility?.[owner]?.status||'';return !t.blocked&&!t.policy_blocked&&(Number(t.account_public_audit?.pending_count)||0)===0&&t.membership_evidence?.identity===owner&&t.membership_evidence?.status==='已加入'&&status==='已核實可發文'&&['已確認可發文','自動輪排／遭拒即停'].includes(t.approval)&&/facebook\.com\/groups\/[^/?#]+/i.test(t.url||'')};
  const usable=all.filter(qualified),scheduledIds=new Set(scheduled.map(t=>String(t.id))),regions=['北部地區','中部地區','南部地區','東部地區','全台／未指定'];
  const cards=regions.map(region=>{const rows=usable.filter(t=>(t.region||'全台／未指定')===region),today=rows.filter(t=>scheduledIds.has(String(t.id))).length;return`<div class="database-card"><b>${esc(region)}</b><strong>${rows.length}</strong><small>今天排入 ${today} 個<br>後續輪排 ${Math.max(0,rows.length-today)} 個</small></div>`}).join('');
  const stopped=all.filter(t=>t.blocked||t.policy_blocked).length,invalid=all.filter(t=>!/facebook\.com\/groups\/[^/?#]+/i.test(t.url||'')).length,waiting=Math.max(0,all.length-usable.length-stopped-invalid),accountCounts=AD_POSTING_IDENTITIES.map(owner=>`${owner}：清單 ${all.filter(t=>normalizeAdIdentity(t.posting_identity)===owner).length}／資格已核實 ${usable.filter(t=>normalizeAdIdentity(t.posting_identity)===owner).length}`).join('；');
  const todayState=state&&state.today||{},byId=Object.fromEntries(all.map(t=>[String(t.id),t]));let eastScheduled=0,eastPublished=0;
  Object.entries(todayState.schedule||{}).forEach(([slot,ids])=>(Array.isArray(ids)?ids:[]).forEach(rawId=>{const id=String(rawId),target=byId[id],region=todayState.schedule_regions?.[slot]?.[id]||target?.region||'';if(region!=='東部地區')return;eastScheduled++;const record=todayState.hourly_targets?.[slot]?.[id]||{},evidence=Array.isArray(record.published_evidence)?record.published_evidence:[];if(evidence.some(x=>x&&x.verified_visible&&x.post_url))eastPublished++}));
  document.getElementById('databaseOverview').innerHTML=`<div class="database-overview"><div class="database-head"><h2>Facebook 社團資料庫：${usable.length} 個帳號／社團資格已核實</h2><p>${esc(accountCounts)}。另有 ${waiting} 筆待核實、${stopped} 筆停投${invalid>0?'、'+invalid+' 筆非社團入口':''}。已加入還須符合分類、素材與時段規則。</p></div><div class="database-grid">${cards}</div><div class="schedule-note">全天 24 小時每個整點啟動；新工作由 YUKI LIU、海賊團行政專員與寶輝科技官方網站 粉絲團上架文章；每小時北中南東各最多 2 篇，合計最多 8 篇；名額不足保留缺額；同一整點同一社團只排一次。東部今日已排 ${eastScheduled}／10、正式發文 ${eastPublished}／10；未取得 Facebook 正式證據的不算完成。資格不足不換帳號代發。Kuo Feng Cheng 只負責上架產品與競標貼文提醒，不投放一般廣告。通州截標由 YUKI LIU 負責。</div></div>`;
  const title=document.getElementById('todayListTitle');if(title)title.textContent=`今日每小時廣告（寶輝每區最多 2 則／合計最多 8 則；不足保留缺額）`;
}
function render(){
  const all=state.targets||[],a=scheduledTargets(),regions=[...new Set(a.map(t=>t.region||'全台／未指定'))].sort((x,y)=>x.localeCompare(y,'zh-Hant')),visible=a.filter(t=>regionFilter==='全部區域'||(t.region||'全台／未指定')===regionFilter);
  const scheduledInstances=AUTO_SLOTS.flatMap(slot=>scheduledTargetsForSlot(slot)),totalPosts=scheduledInstances.length,published=scheduledInstances.filter(t=>t.status==='已完成').length;
  document.getElementById('summary').innerHTML=[['社團資料庫',all.length],['今日自動排入',a.length],['已發佈',published],['待發佈',Math.max(0,totalPosts-published)]].map(x=>`<div class="metric"><span class="small">${x[0]}</span><b>${x[1]}</b></div>`).join('');
  renderDatabaseOverview(all,a);
  document.getElementById('regionToolbar').innerHTML='<strong>今日排程區域</strong>'+['全部區域',...regions].map(r=>`<button type="button" class="region-filter ${r===regionFilter?'active':''}" onclick="setRegionFilter('${esc(r)}')">${esc(r)} <small>${r==='全部區域'?a.length:a.filter(t=>(t.region||'全台／未指定')===r).length}</small></button>`).join('');
  const groups={};visible.forEach(t=>{const r=t.region||'全台／未指定';(groups[r]||(groups[r]=[])).push(t)});
  document.getElementById('targetList').innerHTML=Object.keys(groups).sort((x,y)=>x.localeCompare(y,'zh-Hant')).map(region=>{const items=groups[region],quota=items.reduce((n,t)=>n+Math.max(1,Number(t.batch_count||1)),0);return`<div class="region-heading">${esc(region)}<span>${items.length} 個社團／${quota} 則</span></div>`+items.map(t=>`<div class="row ${String(t.id)===String(selected)?'active':''}" data-target-id="${esc(t.id)}"><div class="id">${esc(t.id)}</div><div><strong>${esc(t.name)}</strong><span class="region-label">${esc(t.region||'全台／未指定')}</span><span class="region-label">身分：${esc(t.posting_identity||'寶輝科技有限公司 頭城竹安店')}</span><div class="small">${esc(t.topic||'地方生活／綜合')} · ${esc(t.slot)} · 本批 ${Number(t.published_count||0)}/${Math.max(1,Number(t.batch_count||1))}${t.operator?' · '+esc(t.operator):''}</div></div><div class="approval badge">${esc(t.approval)}</div><div class="badge ${t.status==='已完成'?'done':['執行中','已接受'].includes(t.status)?'working':''}">${esc(t.status)}</div></div>`).join('')}).join('')||'<div class="empty-region">此區域目前沒有社團。</div>';
  const t=a.find(x=>String(x.id)===String(selected))||visible[0]||a[0];if(!t){document.getElementById('detail').innerHTML='<p>目前時段尚未排入合格社團；僅寶輝科技有限公司每區最多 2 篇／小時、合計最多 8 篇，東部合計每日目標 10 篇。請核對帳號與社團資格。</p>';return}const m=(t.material_id&&materialById(t.material_id))||nextMaterialForTarget(t)||{};
  const quota=Math.max(1,Number(t.batch_count||1)),done=Math.min(quota,Number(t.published_count||0)),accepted=!!t.accepted_at,next=Math.min(quota,done+1);
  document.getElementById('detail').innerHTML=`<h3>${esc(t.id)}｜${esc(t.name)}</h3><div class="small">${esc(t.region||'全台／未指定')} · 預定 ${esc(t.slot)} · 發文身分：${esc(t.posting_identity||'寶輝科技有限公司 頭城竹安店')} · ${esc(t.approval)}</div><div class="quota-line"><span>本批進度</span><strong>${done}／${quota} 則</strong></div><div class="progress-track"><i style="width:${quota?Math.round(done/quota*100):0}%"></i></div><div class="accept-box ${accepted?'':'pending'}"><b>${accepted?'已建立發文工作紀錄':'文章核准後即可開始發文'}</b><span>${accepted?'可繼續選擇已核准文章；每一則都會留下素材與完成紀錄。':'不需要再次核准本批；按下後直接選擇已核准文章。'}</span><div class="actions"><button class="btn primary" ${done>=quota?'disabled':''} onclick="${accepted?`start('${esc(t.id)}','${esc(t.url)}')`:`acceptTargetBatch('${esc(t.id)}')`}">${done>=quota?'本批已完成':accepted?'發送第 '+next+'／'+quota+' 則':'選擇已核准文章並開始發文'}</button></div></div><div class="box"><strong>本輪文案</strong><div class="copy" id="copyText">${esc(withCompanyWebsite(m.copy||''))}</div></div><div class="box"><strong>本輪圖片</strong><img class="preview" src="${esc(m.image||'')}" alt="本輪廣告圖"></div><div class="actions"><button class="btn" onclick="copyText()">複製文案</button><a class="btn" href="${esc(m.image||'#')}" download>下載圖片</a></div><input class="note" id="note" placeholder="備註：管理員審核中、禁止商業貼文、失敗原因" value="${esc(t.note||'')}"><div class="actions"><button class="btn good" onclick="updateStatus('已完成')">確認本則已發布</button><button class="btn warnbtn" onclick="updateStatus('等待社團審核')">等待社團審核</button><button class="btn" onclick="updateStatus('失敗')">標記失敗</button><button class="btn" onclick="updateStatus('禁止商業貼文')">禁止商業貼文</button>${t.last_post_id?'<button class="btn" onclick="markRemovedAfterSubmit()">撤銷 Facebook 已移除貼文</button>':''}</div>`
  if(Number(t.published_count||0)>0&&t.last_post_id){const pendingButton=document.querySelector('#detail .warnbtn');if(pendingButton)pendingButton.onclick=async()=>{try{await api('admin_revoke_pending_false_completion',{task_date:state.today.date,id:String(t.id),slot:String(t.slot),post_id:String(t.last_post_id),facebook_pending_confirmed:true,reason:document.getElementById('note').value||'Facebook 顯示貼文已提交社團管理員審核，尚未確認公開'});show('已改為等待社團審核；撤銷錯誤完成計數，禁止重發')}catch(error){show(error.message||'待審狀態修正失敗')}}}
}
function currentTarget(){const t=state&&(state.targets.find(x=>x.id===selected)||state.targets[0]);if(!t)return t;const day=state.today||{},hourlyTargets=day.hourly_targets||{},nowSlot=new Date().toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit',hour12:false}).slice(0,2)+':00',scheduledIds=day.schedule&&day.schedule[nowSlot]||[];const slot=scheduledIds.includes(t.id)?nowSlot:t.slot;const hourly=slot&&hourlyTargets[slot]&&hourlyTargets[slot][t.id]||Object.values(hourlyTargets).map(records=>records&&records[t.id]).find(record=>record&&record.accepted_at);return hourly?Object.assign({},t,{slot},hourly):Object.assign({},t,{slot})}
function start(id,url){selected=id;openPublishModal()}
function materialById(id){return(state&&state.material_library||[]).find(m=>m.id===id)}
function selectedMaterial(){return materialById(selectedMaterialId)}
function materialRotation(target){const used=state.today&&state.today.used_materials||{},already=target&&target.material_ids||[],list=(state.material_library||[]).filter(m=>m.active&&m.approved&&!used[m.id]&&!already.includes(m.id));if(!list.length)return[];const date=state.today&&state.today.date||'',seed=[...date].reduce((n,c)=>n+c.charCodeAt(0),0)%list.length,rotated=list.slice(seed).concat(list.slice(0,seed)),region=target&&target.region||'';return rotated.filter(m=>(m.title+' '+m.copy).includes(region)).concat(rotated.filter(m=>!(m.title+' '+m.copy).includes(region)))}
function nextMaterialForTarget(target){return materialRotation(target)[0]||null}
function openPublishModal(){const t=currentTarget();if(!t)return;if(!t.accepted_at)return show('請先由小姐按「接受本批」');const used=state.today&&state.today.used_materials||{},already=t.material_ids||[],quota=Math.max(1,Number(t.batch_count||1)),retryId=t.status==='執行中'&&Number(t.published_count||0)<quota?String(t.material_id||''):'',retryMaterial=retryId?materialById(retryId):null,available=retryMaterial&&retryMaterial.active&&retryMaterial.approved?[retryMaterial]:materialRotation(t);const options=(state.material_library||[]).map(m=>{const retry=String(m.id)===retryId,disabled=!m.active||(!retry&&(!!used[m.id]||already.includes(m.id)));return`<option value="${esc(m.id)}" ${disabled?'disabled':''}>${esc(m.id+'｜'+m.title+(retry?'（續跑未完成發文）':disabled?(m.active?'（今天已使用）':'（尚未建立）'):''))}</option>`}).join('');document.getElementById('publishMaterialSelect').innerHTML=options;selectedMaterialId=available[0]&&available[0].id||'';if(selectedMaterialId)document.getElementById('publishMaterialSelect').value=selectedMaterialId;document.getElementById('publishTargetName').textContent=t.id+'｜'+t.name+'｜'+(t.region||'全台／未指定')+'｜身分：'+(t.posting_identity||'寶輝科技有限公司 頭城竹安店')+'｜'+(t.topic||'地方生活／綜合')+'｜本批 '+Number(t.published_count||0)+'/'+quota;selectPublishMaterial(selectedMaterialId);const modal=document.getElementById('publishModal');modal.classList.add('open');modal.setAttribute('aria-hidden','false');document.body.style.overflow='hidden';if(!selectedMaterialId)show('今天已沒有未使用素材，請管理者補充新的文案與圖片')}
function selectPublishMaterial(id){selectedMaterialId=id;const m=materialById(id);document.getElementById('publishCopyText').textContent=m?withCompanyWebsite(m.copy):'請先選擇可用素材';document.getElementById('publishImage').src=m&&m.image||'';document.getElementById('publishDownload').href=m&&m.image||'#'}
function closePublishModal(){const modal=document.getElementById('publishModal');modal.classList.remove('open');modal.setAttribute('aria-hidden','true');document.body.style.overflow=''}
async function copyPublishText(){const m=selectedMaterial();if(!m)return show('請先選擇可用素材');await navigator.clipboard.writeText(withCompanyWebsite(m.copy));show('完整文案與官網網址已複製，可到 Facebook 按 Ctrl+V')}
async function copyPublishImage(){try{const m=selectedMaterial();if(!m)throw Error('missing');const r=await fetch(m.image,{cache:'no-store'}),blob=await r.blob();if(!window.ClipboardItem||!navigator.clipboard.write)throw Error('unsupported');await navigator.clipboard.write([new ClipboardItem({[blob.type||'image/png']:blob})]);show('圖片已複製，可到 Facebook 按 Ctrl+V')}catch(e){show('無法直接複製圖片，請改按「下載圖片備用」')}}
function postingHelperVersion(){return document.documentElement.dataset.baohuiPostingHelper||''}
function postingHelperReady(){const v=postingHelperVersion().split('.').map(Number);return v.length===3&&(v[0]>1||(v[0]===1&&(v[1]>10||(v[1]===10&&v[2]>=266))))}
function refreshHelperState(){const badge=document.getElementById('helperState'),guide=document.getElementById('helperInstallGuide');if(!badge)return;const version=postingHelperVersion(),ready=postingHelperReady();badge.textContent=ready?'寶輝發文助手 '+version+' 已連線':version?'助手 '+version+' 過舊，請更新 1.10.266':'尚未連上發文助手 1.10.266';badge.classList.toggle('ready',ready);document.querySelectorAll('.publish-facebook').forEach(button=>button.disabled=!ready);if(guide)guide.hidden=ready}
window.addEventListener('message',event=>{if(event.source===window&&event.origin===location.origin&&event.data?.source==='baohui-posting-helper'&&event.data?.type==='HELPER_READY')refreshHelperState()});
setInterval(refreshHelperState,5000);
async function startFacebookPublish(autoPost=false){const t=currentTarget(),m=selectedMaterial();if(!t||!m)return show('請先選擇文案與圖片');if(autoPost&&!t.accepted_at)return show('請先由小姐接受本批任務');if(t.approval!=='已確認可發文')return show('此社團尚未核准可發商業貼文，不能派送');if(/\/groups\/onecheep(?:\/|$)|海賊|一元競標|競標場|大家來喊價/i.test(`${t.url||''} ${t.name||''}`))return show('海賊團／一元競標場只供競標，不可刊登一般廣告');if(!postingHelperReady()){const guide=document.getElementById('helperInstallGuide');if(guide){guide.hidden=false;guide.scrollIntoView({behavior:'smooth',block:'center'})}return show('請更新並重新載入發文助手 1.10.96')};let targetPath='';try{targetPath=new URL(t.url).pathname}catch(e){}if(!/\/groups\//i.test(targetPath))return show('這不是可發文的 Facebook 社團網址，請管理者改成 https://www.facebook.com/groups/…，尚未記錄評比');const next=Math.min(Math.max(1,Number(t.batch_count||1)),Number(t.published_count||0)+1),mode=autoPost?'自動排程：先核對／切換指定身分，再自動發布並關閉分頁':'即時發布：由人員檢查後按「發佈」，不列入自動排程';if(!confirm(`確定發送「${t.name}」${autoPost?'第 '+next+' 則':''}？\n發文身分：${t.posting_identity||'寶輝科技有限公司 頭城竹安店'}\n模式：${mode}`))return;try{await sendToPostingHelper({kind:'advertising_post',contentType:'facebook_advertising_post',targetId:String(t.id),targetName:t.name,groupUrl:t.url,copy:withCompanyWebsite(m.copy),imageUrl:new URL(m.image,location.href).href,materialId:m.id,requiredVisibleName:t.posting_identity||'寶輝科技有限公司 頭城竹安店',slot:t.slot||'',groupApproved:true,recordMode:autoPost?'automatic':'manual',autoPublish:autoPost,forceImmediate:true,publishDelayMs:10000,autoClose:autoPost});if(autoPost)await api('start',{id:t.id,slot:t.slot||'',material_id:m.id});closePublishModal();show(autoPost?'Facebook 任務已實際派送，取得可見貼文後才會完成':'Facebook 分頁已開啟；本次只記人工發布，不影響自動排程')}catch(e){show(e.message||'無法派送 Facebook 任務')}}
async function launchShift(slot,force=false,autoPost=false){if(autoLaunchBusy||!state||!AUTO_SLOTS.includes(slot))return;if(!postingHelperReady()){const guide=document.getElementById('helperInstallGuide'),modal=document.getElementById('publishModal');if(modal){modal.classList.add('open');modal.setAttribute('aria-hidden','false');document.body.style.overflow='hidden'}if(guide){guide.hidden=false;guide.scrollIntoView({behavior:'smooth',block:'center'})}return show(postingHelperVersion()?`目前是助手 ${postingHelperVersion()}，請更新 1.10.203`:'請先安裝寶輝發文助手 1.10.203')};const key='ad-auto-open-'+state.today.date+'-'+slot,scheduledAt=new Date(state.today.date+'T'+slot+':00'),savedAt=Date.parse(localStorage.getItem(key)||'');if(!force&&Number.isFinite(savedAt)&&savedAt>=scheduledAt.getTime())return;autoLaunchBusy=true;try{const j=await api('prepare_shift',{slot}),tasks=j.tasks||[];if(!tasks.length){if(new Date()>=scheduledAt)localStorage.setItem(key,new Date().toISOString());show(j.notice||`${slot} 本時段沒有已核准且可派送的社團`);return}let opened=0,failed=[];for(let index=0;index<tasks.length;index++){const task=tasks[index];try{await sendToPostingHelper({targetId:String(task.targetId),targetName:task.targetName,groupUrl:task.groupUrl,copy:withCompanyWebsite(task.copy),imageUrl:new URL(task.image,location.href).href,materialId:task.materialId,requiredVisibleName:task.requiredVisibleName,slot,groupApproved:task.groupApproved===true,recoveryOnly:task.recoveryOnly===true,autoPublish:autoPost,publishDelayMs:10000,autoClose:autoPost});await api('start',{id:String(task.targetId),slot,material_id:String(task.materialId)});opened++}catch(error){failed.push(task.targetName+'：'+error.message)}}if(opened&&autoPost&&new Date()>=scheduledAt)localStorage.setItem(key,new Date().toISOString());if(failed.length)show(`已實際派送 ${opened} 頁，${failed.length} 頁失敗；請查看錯誤清單`);else show(autoPost?`${slot} 已實際派送 ${opened} 頁；系統逐筆切換指定身分，完成後才換下一頁`:`${slot} 已確認開啟 ${opened} 個社團，請逐頁按 Facebook「發佈」`);if(failed.length)alert('下列社團未派送：\n\n'+failed.join('\n'))}catch(e){show(e.message||`${slot} 時段無法準備`)}finally{autoLaunchBusy=false}}
function launchShiftAuto(slot){const rule='僅寶輝科技有限公司每區最多 2 篇／小時、合計最多 8 篇、東部合計每日 10 篇；只執行有資格且未嘗試的任務';if(!confirm(`確定自動發佈 ${slot} 本時段廣告（${rule}）？\n系統使用共用佇列；目前文章完成並停留 10 秒後才處理下一筆。`))return;launchShift(slot,true,true)}
// Background alarms may open this page, but opening or refreshing a page must never
// publish on the operator's behalf.  The final Facebook dispatch remains behind the
// visible "確認自動發佈" action and its confirmation dialog.
function checkAutoLaunch(){return}
function checkTestAutoRun(){const test=state&&state.automation&&state.automation.test_run;if(!test||!test.enabled||!test.run_at||!postingHelperReady()||autoLaunchBusy)return;const dueAt=new Date(test.run_at),key='ad-auto-test-'+String(test.id||test.run_at);if(new Date()<dueAt||localStorage.getItem(key))return;const slot=AUTO_SLOTS.find(value=>scheduledTargetsForSlot(value).some(target=>target.status!=='已完成'));if(!slot){localStorage.setItem(key,'no-pending-slot');return show('測試時間已到，但目前沒有尚未完成的時段')};localStorage.setItem(key,'started');show(`測試開始：${slot} 時段將由指定帳號依序發送合格任務`);launchShift(slot,true,true)}
window.addEventListener('message',e=>{if(e.source!==window||e.origin!==location.origin||!e.data||e.data.source!=='baohui-posting-helper'||e.data.type!=='PREPARE_FACEBOOK_ACK')return;const pending=helperRequests.get(String(e.data.requestId||''));if(!pending)return;clearTimeout(pending.timer);helperRequests.delete(String(e.data.requestId||''));e.data.ok?pending.resolve(e.data):pending.reject(Error(e.data.error||'助手無法建立 Facebook 分頁'))})
window.addEventListener('message',async e=>{if(e.source!==window||e.origin!==location.origin||!e.data||e.data.source!=='baohui-posting-helper'||e.data.type!=='FACEBOOK_POST_SUBMITTED')return;const id=String(e.data.targetId||''),slot=String(e.data.slot||'');if(!id)return;const manual=e.data.recordMode==='manual',evidence={post_url:String(e.data.postUrl||''),post_id:String(e.data.postId||''),verified_identity:String(e.data.verifiedIdentity||''),verified_visible:e.data.verifiedVisible===true,event_id:String(e.data.eventId||'')};try{if(manual){await api('record_manual',{id,material_id:String(e.data.materialId||''),...evidence,note:'人員於 Facebook 即時發布'});show('即時發布已記錄；自動排程進度未變更')}else{await api('update',{id,slot,status:'已完成',material_id:String(e.data.materialId||''),...evidence,note:'寶輝發文助手：已取得 Facebook 可見貼文證據'});show('Facebook 可見貼文已回寫，本時段進度已累加')}}catch(err){show('Facebook 已發佈，但證據回寫失敗：'+(err.message||'未知錯誤'))}})
window.addEventListener('message',async e=>{if(e.source!==window||e.origin!==location.origin||!e.data||e.data.source!=='baohui-posting-helper'||e.data.type!=='GROUP_REJECTION_DETECTED')return;try{const reasonType=String(e.data.reasonType||'admin_rejected'),result=await api('report_rejection',{group_name:String(e.data.groupName||''),target_id:String(e.data.targetId||''),group_url:String(e.data.groupUrl||''),notice:String(e.data.notice||''),fingerprint:String(e.data.fingerprint||''),reason_type:reasonType});window.postMessage({source:'baohui-ad-posting',type:'GROUP_REJECTION_RECORDED',fingerprint:String(e.data.fingerprint||'')},location.origin);show(result.matched?(reasonType==='cannot_post'?'偵測到社團無法發文，已移除後續輪排':'已記錄管理員拒絕，該社團已停止後續輪排'):'收到停用通知，但找不到對應社團；已留下紀錄供管理者核對')}catch(err){show('社團停用通知回寫失敗：'+err.message)}})
document.addEventListener('keydown',e=>{if(e.key==='Escape')closePublishModal()});
async function acceptTargetBatch(id){const t=(state.targets||[]).find(x=>String(x.id)===String(id));if(!t)return;if(!confirm(`確定接受「${t.name}」本批 ${Math.max(1,Number(t.batch_count||1))} 則發文任務？\n接受後立即進入選擇圖文與開啟 Facebook 的下一步。`))return;try{selected=String(id);await api('accept_batch',{id:String(id)});show('已接受，現在請按「一鍵帶入圖文並開啟社團」');setTimeout(openPublishModal,80)}catch(e){show(e.message||'無法接受任務')}}
async function updateStatus(status){const t=currentTarget();if(status==='已完成'&&!confirm(`確定「${t&&t.name||''}」本則已完成上架？\n完成後會累加本批進度。`))return;const completedRecord=Object.entries(state.today&&state.today.hourly_targets||{}).map(([slot,records])=>({slot,record:records&&records[selected]})).find(({record})=>record&&record.status==='已完成'&&record.last_post_id);if(status==='等待社團審核'&&completedRecord){await api('admin_revoke_pending_false_completion',{task_date:state.today.date,id:String(selected),slot:completedRecord.slot,post_id:String(completedRecord.record.last_post_id),facebook_pending_confirmed:true,reason:document.getElementById('note').value||'Facebook 顯示貼文已提交社團管理員審核，尚未確認公開'});return show('已改為等待社團審核；撤銷錯誤完成計數，禁止重發')}if(status==='失敗'&&t&&t.status==='已完成'){await api('admin_mark_removed_after_submit',{id:String(t.id),slot:String(t.slot||''),post_id:String(t.last_post_id||''),reason:document.getElementById('note').value||'Facebook 已自動移除貼文；正式連結無法查看'});return show('已撤銷錯誤完成紀錄；這篇不算成功，也不會自動重發')}await api('update',{id:selected,slot:t&&t.slot||'',status,material_id:t&&t.material_id||'',note:document.getElementById('note').value});show(status==='已完成'?'本批完成數已加 1':'已更新：'+status)}
async function markRemovedAfterSubmit(){const t=currentTarget();if(!t||!t.last_post_id)return show('這筆沒有可撤銷的正式貼文編號');if(!confirm(`確定 Facebook 已移除「${t.name}」這篇貼文？\n只會撤銷後台錯誤完成紀錄，不會重新發文。`))return;await api('admin_mark_removed_after_submit',{id:String(t.id),slot:String(t.slot||''),post_id:String(t.last_post_id),reason:'Facebook 已自動移除貼文；正式連結無法查看'});show('已撤銷錯誤完成紀錄；這篇不算成功，也不會自動重發')}
async function copyText(){await navigator.clipboard.writeText(withCompanyWebsite(state.material.copy));show('文案與官網網址已複製')}
function renderManager(){const box=document.getElementById('manager');if(!state||!state.can_manage){box.innerHTML='';return}const identities=AD_POSTING_IDENTITIES,rows=state.targets.map(t=>[t.name,t.url,t.region||'全台／未指定',t.topic||'地方生活／綜合',t.posting_identity||'寶輝科技有限公司 頭城竹安店'].join(' | ')).join('\n'),library=state.material_library||[],m=library[0]||{},chips=library.map(x=>`<span class="material-chip ${x.active?'ready':''}">${esc(x.id)} ${x.active?'可使用':'待建立'}</span>`).join(''),options=library.map(x=>`<option value="${esc(x.id)}">${esc(x.id+'｜'+x.title+(x.active?'（可使用）':'（待建立）'))}</option>`).join(''),identityCounts=identities.map(name=>`<span class="material-chip ready">${esc(name)}：${state.targets.filter(t=>(t.posting_identity||'寶輝科技有限公司 頭城竹安店')===name).length} 個社團</span>`).join('');box.innerHTML=`<details class="manager"><summary>管理寶輝科技有限公司的社團清單與 ${library.length} 組廣告素材（管理者）</summary><div class="manager-grid"><div class="manager-card" id="materialSettings"><h3>${library.length} 組廣告素材輪播庫</h3><div class="material-status">${chips}</div><p class="manager-help">全天每小時輪發；由 YUKI LIU、海賊團行政專員與寶輝科技官方網站 粉絲團使用各自核准的社團清單上架文章；每小時執行，北中南東各最多 2 則，合計最多 8 則；名額不足保留缺額。</p><label>選擇編輯位置</label><select id="materialSlot" onchange="loadMaterialEditor(this.value)">${options}</select><label>素材名稱</label><input id="materialTitle"><label>發文文案</label><textarea id="materialCopy"></textarea><label>更換廣告圖（JPG／PNG／WebP，10MB 內）</label><input id="materialImage" type="file" accept="image/jpeg,image/png,image/webp"><img class="manager-preview" id="materialPreview" alt="目前廣告圖"><div class="publish-tools"><button class="btn primary" type="button" onclick="saveMaterial()">儲存這組文案與圖片</button></div></div><div class="manager-card wide" id="targetSettings"><h3>Facebook 社團、區域、主題與發文身分資料庫</h3><div class="material-status">${identityCounts}</div><label>每行一個社團；帳號由所屬清單固定指定。未列出的舊紀錄仍保留，刪行不代表停投；既有區域變更須另行核對。</label>${identities.map((identity,index)=>`<section><h4>${esc(identity)}｜獨立社團清單</h4><textarea class="target-format" id="targetRows${index}" data-target-identity="${esc(identity)}">${esc(state.targets.filter(t=>t.posting_identity===identity).map(t=>[t.name,t.url,t.region||'全台／未指定',t.topic||'地方生活／綜合',identity].join(' | ')).join('\n'))}</textarea></section>`).join('')}<div class="manager-help"><b>格式：</b>社團名稱 | Facebook 社團網址 | 投放區域 | 主題分類 | 發文身分<br><b>可用身分：</b>${esc(identities.join('、'))}<br><b>範例：</b>宜蘭撿便宜 | https://www.facebook.com/groups/… | 東部地區 | 回收／二手交易 | 寶輝科技有限公司 頭城竹安店<br>Kuo Feng Cheng 已移出一般廣告發文，仍走既有上架產品／競標貼文提醒工作列。YUKI LIU、海賊團行政專員與寶輝科技官方網站 粉絲團可上架文章。元件會逐筆核對並自主切換指定身分；找不到身分或切換後驗證失敗時停止該筆，不會用錯帳號代發。</div><div class="publish-tools"><button class="btn primary" type="button" onclick="saveTargetList()">儲存各帳號社團清單（保留舊任務）</button></div></div></div></details>`;loadMaterialEditor(m.id||'A01')}
function loadMaterialEditor(id){const m=materialById(id)||{};const slot=document.getElementById('materialSlot');if(slot)slot.value=id;document.getElementById('materialTitle').value=m.title||'';document.getElementById('materialCopy').value=m.copy||'';document.getElementById('materialPreview').src=m.image||''}
async function saveMaterial(){const materialId=document.getElementById('materialSlot').value,title=document.getElementById('materialTitle').value.trim(),copy=document.getElementById('materialCopy').value.trim(),file=document.getElementById('materialImage').files[0];if(!copy)return show('請先填寫發文文案');const form=new FormData();form.append('material_id',materialId);form.append('title',title);form.append('copy',copy);if(file)form.append('image',file);try{const r=await adFetch('ad-posting-api.php?action=save_material',{method:'POST',body:form}),j=await r.json();if(!j.ok)throw Error(j.error||'儲存失敗');state=j.state;state.can_manage=!!j.can_manage;render();show(materialId+' 文案與廣告圖已儲存')}catch(e){show(e.message||'儲存失敗')}}
async function saveTargetList(){const lines=[...document.querySelectorAll('[data-target-identity]')].flatMap(box=>box.value.split(/\r?\n/).map(x=>x.trim()).filter(Boolean).map(line=>{const parts=line.split('|').map(x=>x.trim());parts[4]=box.dataset.targetIdentity;return parts.join(' | ')})),rows=[];for(const line of lines){const parts=line.split('|').map(x=>x.trim());if(parts.length<2)continue;const postingIdentity=parts[4]||'寶輝科技官方網站 粉絲團';if(!adIdentityAllowed(postingIdentity))return show(`發文身分不正確：${postingIdentity}`);rows.push({name:parts[0],url:parts[1],region:parts[2]||'全台／未指定',topic:parts[3]||'地方生活／綜合',posting_identity:postingIdentity})}if(!rows.length)return show('請依格式填入至少一個 Facebook 社團');try{await api('save_targets',{rows});show('各帳號社團清單已儲存；舊任務保留，新帳號社團須獨立核實資格')}catch(e){show(e.message||'清單儲存失敗')}}
function formatTime(value){if(!value)return'尚未';try{return new Date(value).toLocaleString('zh-TW',{hour12:false})}catch(e){return value}}
function renderBlockedGroups(){const box=document.getElementById('blockedGroups'),blocked=(state&&state.targets||[]).filter(t=>t.blocked);if(!blocked.length){box.innerHTML='';return}box.innerHTML=`<details class="blocked-panel"><summary>已停止投放的社團（${blocked.length}）</summary><div class="blocked-list">${blocked.map(t=>`<div class="blocked-row"><div><b>${esc(t.name)}</b><small>${esc(t.region||'全台／未指定')}｜拒絕 ${Number(t.rejection_count||0)} 次｜${esc(formatTime(t.blocked_at))}</small></div><div>${esc(t.blocked_reason||'已停止投放')}<small>${esc(t.last_rejection_notice||'')}</small></div>${state.can_manage?`<button class="btn" type="button" onclick="restoreTarget('${esc(t.id)}','${esc(t.name)}')">管理者恢復投放</button>`:'<span class="badge">等待管理者</span>'}</div>`).join('')}</div></details>`}
async function restoreTarget(id,name){if(!confirm(`確定恢復「${name}」的廣告投放？\n恢復後可能再次被社團管理員拒絕，請先確認該社團規則允許商業貼文。`))return;try{await api('restore_target',{id});show('社團已恢復，會從後續輪排重新加入')}catch(e){show(e.message||'無法恢復社團')}}
function renderEvaluation(){const box=document.getElementById('evaluation'),today=state.today||{},shifts=today.shifts||{},now=new Date();box.innerHTML='<div class="shift-grid">'+AUTO_SLOTS.map(slot=>{const s=shifts[slot]||{},items=scheduledTargets().filter(t=>t.slot===slot),done=items.filter(t=>t.status==='已完成').length,due=new Date((today.date||'')+'T'+slot+':00'),late=now>due&&!s.accepted_at,cls=s.completed_at?'done':s.accepted_at?'active':late?'late':'',delay=s.accepted_at?Math.max(0,Math.round((new Date(s.accepted_at)-due)/60000)):'';return`<div class="shift-card ${cls}"><b>${slot} 發文班</b><div>${done}／${items.length} 個社團完成（本時段上限 8）</div><div class="shift-regions" aria-label="北中南東輪替分配">${shiftRegionBadges(items,slot)}</div><div class="shift-meta">接受：${esc(formatTime(s.accepted_at))}${delay!==''?'（延遲 '+delay+' 分）':''}<br>完成：${esc(formatTime(s.completed_at))}<br>負責：${esc(s.accepted_by||'尚未接受')}<br>評比：${s.completed_at?'本班已完成':late&&!s.accepted_at?'逾時不補發':s.accepted_at?'逐頁發佈中':'等待時段'}</div><div class="publish-tools"><button class="btn primary" type="button" onclick="launchShift('${slot}',true)">${s.accepted_at?'再次開啟未完成社團':'接受並開啟本時段 '+items.length+' 個社團'}</button><button class="btn good" type="button" onclick="launchShiftAuto('${slot}')">確認自動發佈，每則停留 10 秒</button></div></div>`}).join('')+'</div>'}
function renderReminder(){const box=document.getElementById('reminder'),today=state.today||{},now=new Date(),due=AUTO_SLOTS.filter(slot=>{const s=today.shifts&&today.shifts[slot]||{},scheduledAt=new Date((today.date||'')+'T'+slot+':00');return now>=scheduledAt&&!s.completed_at}),pending=due.length,library=state.material_library||[],ready=library.filter(x=>x.active&&x.approved).length,identityCounts=Object.fromEntries(AD_POSTING_IDENTITIES.map(name=>[name,(state.targets||[]).filter(t=>(t.posting_identity||'寶輝科技有限公司 頭城竹安店')===name&&!t.blocked&&!t.policy_blocked&&(Number(t.account_public_audit?.pending_count)||0)===0&&t.membership_evidence?.identity===name&&t.membership_evidence?.status==='已加入'&&t.identity_eligibility?.[name]?.status==='已核實可發文'&&['已確認可發文','自動輪排／遭拒即停'].includes(t.approval)).length]));box.innerHTML=`<div class="reminder ${pending?'':'ok'}"><h3>${pending?'⏰ 本整點仍有待發項目':'✓ 全天每小時輪發已啟用'}</h3><div>${pending?'只處理目前有效整點，不補發已逾時時段。':'每個整點依序執行。'}由 YUKI LIU、海賊團行政專員與寶輝科技官方網站 粉絲團上架文章；每小時執行，北中南東各最多 2 則，合計最多 8 則；名額不足保留缺額；未核實指定身分與社團資格不派送。Kuo Feng Cheng 不進一般廣告。<br><small>可排社團：${AD_POSTING_IDENTITIES.map(name=>name+' '+identityCounts[name]+' 個').join('／')}。Kuo Feng Cheng 已移出一般廣告發文。通州截標由 YUKI LIU 負責。每則必須取得 Facebook permalink／post ID 並回寫後台，才記為已發佈；排程、開頁或登入都不算完成。</small></div><div class="control-guide"><a href="#manager" onclick="openManagerSection('targetSettings');return false">① 社團與帳號<small>北／中／南／東分類，並逐社團指定發文身分。</small></a><a href="#manager" onclick="openManagerSection('materialSettings');return false">② 廣告輪播<small>已核准 ${ready}／${library.length} 組；同一時段最多使用 8 組不同素材。</small></a><a href="#evaluation" onclick="document.getElementById('evaluation').scrollIntoView({behavior:'smooth'});return false">③ 全天整點<small>00:00–23:00，每小時執行一次。</small></a></div><div class="publish-tools"><button class="btn primary" type="button" onclick="enableNotifications()">開啟桌面提醒</button><button class="btn good" type="button" onclick="launchImmediateAdvertising()">執行目前有效整點</button><button class="btn" type="button" onclick="openManagerSection('materialSettings')">查看廣告素材</button></div></div>`;checkReminders();setTimeout(()=>{checkAutoLaunch();checkImmediateAdvertisingRequest()},500)}
async function enableNotifications(){if(!('Notification'in window))return show('此瀏覽器不支援桌面通知');const result=await Notification.requestPermission();show(result==='granted'?'桌面提醒已開啟':'尚未允許桌面提醒')}
function checkReminders(){if(!state||!state.today||!('Notification'in window)||Notification.permission!=='granted')return;const now=new Date(),date=state.today.date;AUTO_SLOTS.forEach(slot=>{const shift=state.today.shifts&&state.today.shifts[slot]||{},key='ad-reminder-'+date+'-'+slot;if(now>=new Date(date+'T'+slot+':00')&&!shift.accepted_at&&!localStorage.getItem(key)){new Notification(slot+' Facebook 發文任務',{body:'後台正在準備本時段社團頁，請逐頁檢查並按發佈。'});localStorage.setItem(key,'1')}})}
function nextImmediateAdvertisingSlot(){const current=String(new Date().getHours()).padStart(2,'0')+':00';return scheduledTargetsForSlot(current).some(item=>item.status!=='已完成')?current:(AUTO_SLOTS.find(slot=>slot>current&&scheduledTargetsForSlot(slot).some(item=>item.status!=='已完成'))||'')}
nextImmediateAdvertisingSlot=function(){const current=currentAdSlot();return current&&scheduledTargetsForSlot(current).some(item=>item.status!=='已完成')?current:(AUTO_SLOTS.find(slot=>slot>current&&scheduledTargetsForSlot(slot).some(item=>item.status!=='已完成'))||'')};
async function launchImmediateAdvertising(){if(!state||autoLaunchBusy)return;const slot=nextImmediateAdvertisingSlot();if(!slot)return show('今天沒有尚未完成的廣告文');show(`正在把 ${slot} 未完成廣告加入共用佇列`);await launchShift(slot,true,true)}
function checkImmediateAdvertisingRequest(){return}
function selectTarget(id,openWork=false){selected=String(id);const chosen=(state.targets||[]).find(x=>String(x.id)===selected);selectedMaterialId=String(chosen?.material_id||'');render();const t=currentTarget();if(openWork){openPublishModal();return}show('已選擇：'+(t?t.name:id));if(window.innerWidth<=1100){const panel=document.getElementById('detail').closest('.panel');if(panel)panel.scrollIntoView({behavior:'smooth',block:'start'})}}
function enhanceTargetRows(){const rows=[...document.querySelectorAll('#targetList .row[data-target-id]')];rows.forEach(row=>{const t=(state.targets||[]).find(x=>String(x.id)===String(row.dataset.targetId));if(!t)return;row.setAttribute('role','button');row.tabIndex=0;row.onclick=()=>selectTarget(t.id);row.onkeydown=e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();selectTarget(t.id)}};const old=row.lastElementChild,button=document.createElement('button');button.type='button';button.className='row-action'+(t.status==='已完成'?' done':'');button.textContent=t.status==='已完成'?'查看':t.accepted_at?'繼續發送':'先接受';button.onclick=e=>{e.stopPropagation();selectTarget(t.id,!!t.accepted_at)};old.replaceWith(button)})}
const oldRenderEvaluation=renderEvaluation;renderEvaluation=function(){const box=document.getElementById('evaluation'),today=state.today||{},shifts=today.shifts||{},now=new Date();box.innerHTML='<div class="shift-grid">'+AUTO_SLOTS.map(slot=>{const s=shifts[slot]||{},items=scheduledTargetsForSlot(slot),done=items.filter(t=>t.status==='已完成').length,due=new Date((today.date||'')+'T'+slot+':00'),expires=new Date(due.getTime()+60*60*1000),late=now>=expires&&!s.accepted_at,activeIdentity=items[0]?.posting_identity||'尚未排入';return`<div class="shift-card ${s.completed_at?'done':s.accepted_at?'active':late?'late':''}"><b>${slot} 發文班</b><div>${done}／${items.length} 完成（寶輝每區最多 2 則／合計最多 8 則；不足保留缺額）</div><div class="small">已排身分：${esc(activeIdentity)}</div><div class="shift-regions">${shiftRegionBadges(items,slot)}</div><div class="shift-meta">接受：${esc(formatTime(s.accepted_at))}<br>完成：${esc(formatTime(s.completed_at))}<br>狀態：${s.completed_at?'本時段完成':s.accepted_at?(now>=expires?'已過時段，保留舊批次待核對':'本時段處理中'):late?'已逾時，不補發':now>=due?'目前有效整點':'等待整點'}</div><div class="publish-tools"><button class="btn primary" type="button" onclick="launchShift('${slot}',true)">開啟未完成 ${Math.max(0,items.length-done)} 篇</button><button class="btn good" type="button" onclick="launchShiftAuto('${slot}')">確認自動發佈</button></div></div>`}).join('')+'</div>'};
const renderReminderBase=renderReminder;renderReminder=function(){renderReminderBase()};
const renderBase=render;render=function(){
  const scheduled=scheduledTargets();
  if(scheduled.length&&!scheduled.some(t=>String(t.id)===String(selected)))selected=String(scheduled[0].id);
  renderBase();enhanceTargetRows();renderReminder();renderEvaluation();renderManager();renderBlockedGroups();
  refreshControlledPostingLabels();refreshMaterialApprovalBadges();enhanceMaterialApproval();simplifyBatchAcceptance();
  const reminder=document.getElementById('reminder');
  if(reminder)reminder.innerHTML=reminder.innerHTML.replace(/1\.10\.(96|188|192|194|195|196|197|198|199|200|201|202|203|204|205|206)/g,'1.10.266');
  setTimeout(()=>{refreshHelperState();checkAutoLaunch();checkTestAutoRun();checkImmediateAdvertisingRequest()},0)
};
// 受控輪發：文章須由管理者核准；同一社團在同一整點只排一次；遭拒絕即停用；逐篇至少間隔 10 秒。
const controlledRenderReminder = renderReminder;
renderReminder = function(){
  controlledRenderReminder();
  const box=document.getElementById('reminder');
  if(box) box.innerHTML=box.innerHTML
    .replaceAll('每隔至少 1 分鐘發布一頁並關閉','每隔至少 10 秒發布一頁並關閉')
    .replaceAll('同一時段 8 則使用不同素材','同時段 8 則使用不同素材')
    .replaceAll('⏰ 有固定時段待發或待補發','⏰ 本整點仍有待發項目');
};
const controlledRenderEvaluation = renderEvaluation;
renderEvaluation = function(){
  controlledRenderEvaluation();
};
const approvedSendToPostingHelper = sendToPostingHelper;
sendToPostingHelper = function(payload){
  const next = {...payload};
  if(next.slot){
    next.groupApproved = next.groupApproved === true;
    next.publishDelayMs = 10000;
    next.protocol = 2;
    next.task_date = String(state&&state.today&&state.today.date||'');
    next.autoPublish = true;
  }
  return approvedSendToPostingHelper(next);
};
const contractApi = api;
api = async function(action='list',body){
  // v2 助手會在真正取得 Facebook 工作分頁後自行 claim/start/complete；
  // 前端不得再送一次舊版 start，否則會被憑證閘門判定為無主工作。
  if(action==='start'&&body&&body.slot)return {ok:true,state};
  return contractApi(action,body);
};
const approvedStartFacebookPublish = startFacebookPublish;
startFacebookPublish = function(autoPost=false){
  const material=selectedMaterial();
  if(!material||!material.approved)return show('此文章尚未經管理者核准，請先按「核准此文章可以發布」');
  return approvedStartFacebookPublish(autoPost);
};
async function approveCommercialTarget(id,name){
  if(!confirm(`只需確認一次：確定「${name}」的社團規則允許商業貼文？\n核准後系統才會把它加入自動輪發；日後若被拒絕會自動停用。`))return;
  try{await api('approve_target',{id});show('已核准此社團，後續可加入每小時輪發')}catch(e){show(e.message||'無法核准社團')}
}
function enhanceCommercialApproval(){
  const t=currentTarget(),detail=document.getElementById('detail');
  if(!t||!detail||t.approval!=='待確認'||detail.querySelector('.approve-commercial'))return;
  const warning=detail.querySelector('.warn');
  if(!warning)return;
  const button=document.createElement('button');
  button.type='button';button.className='btn good approve-commercial';button.style.marginLeft='10px';
  button.textContent='確認此社團允許商業貼文';
  button.onclick=()=>approveCommercialTarget(String(t.id),String(t.name));
  warning.appendChild(button);
}
function refreshControlledPostingLabels(){
  const overview=document.getElementById('databaseOverview');
  if(overview)overview.innerHTML=overview.innerHTML
    .replaceAll('下方顯示今天最多 160 個社團；每時段北、中、南、東各 10 個，合計 40 個。','下方顯示全天每個整點；兩廣告帳號各最多 4 篇／小時，合計最多 8 篇，東部合計每日目標 10 篇。')
    .replaceAll('每時段北、中、南、東各 10 個，合計 40 個','兩廣告帳號各最多 4 篇／小時，合計最多 8 篇，東部合計每日目標 10 篇');
  const title=document.getElementById('todayListTitle');
  if(title)title.textContent=title.textContent.replace(/四時段各最多 40 個|每日五時段各 8 篇/,'全天每帳號每小時最多 4 篇');
  const manager=document.getElementById('manager');
  if(manager&&manager.innerHTML.includes('06:00–23:59 每小時每區 3 篇'))manager.innerHTML=manager.innerHTML
    .replaceAll('06:00–23:59 每小時每區 3 篇，00:00–05:59 每小時每社團 1 篇','全天兩廣告帳號每小時各最多 4 篇，合計最多 8 篇；東部兩帳號合計每日 10 篇，同一社團同一整點只排一次，遭拒即停');
}
async function approveMaterialForPosting(id){
  if(!confirm(`確定已檢查 ${id} 的文字、圖片與商品資訊，可交由系統發布？\n之後若再修改內容，系統會自動取消核准。`))return;
  const scrollY=window.scrollY,currentIndex=(state.material_library||[]).findIndex(item=>String(item.id)===String(id));
  try{
    const response=await adFetch('ad-posting-api.php?action=approve_material',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({material_id:id})});
    const result=await response.json();if(!result.ok)throw Error(result.error||'無法核准文章');
    state=result.state;state.can_manage=!!result.can_manage;render();
    const materials=state.material_library||[],after=materials.slice(currentIndex+1),before=materials.slice(0,currentIndex+1);
    const next=[...after,...before].find(item=>item.active&&!item.approved)||materials[currentIndex]||materials[0];
    const details=document.querySelector('#manager details');if(details)details.open=true;
    if(next)loadMaterialEditor(next.id);
    refreshMaterialApprovalBadges();enhanceMaterialApproval();
    requestAnimationFrame(()=>window.scrollTo({top:scrollY,left:0,behavior:'auto'}));
    show(next&&next.id!==id?`${id} 已核准，已切換到下一個待核准素材 ${next.id}`:`${id} 已核准，目前沒有其他待核准素材`);
  }catch(e){show(e.message||'無法核准文章')}
}
function enhanceMaterialApproval(){
  const card=document.getElementById('materialSettings'),select=document.getElementById('materialSlot');
  if(!card||!select)return;
  const material=materialById(select.value),old=card.querySelector('.material-approval-control');
  if(old)old.remove();
  if(!material)return;
  const box=document.createElement('div');box.className='material-approval-control accept-box '+(material.approved?'':'pending');
  box.innerHTML=material.approved
    ? `<b>✓ 此文章已由 ${esc(material.approved_by||'管理者')} 核准</b><span>可進入自動發文；若重新儲存文字或圖片會取消核准。</span>`
    : `<b>尚未核准發布</b><span>請先查看文字及圖片，確認後才會進入自動排程。</span><div class="actions"><button class="btn good" type="button">核准此文章可以發布</button></div>`;
  const button=box.querySelector('button');if(button)button.onclick=()=>approveMaterialForPosting(String(material.id));
  card.appendChild(box);
}
function refreshMaterialApprovalBadges(){
  const materials=state&&state.material_library||[];
  document.querySelectorAll('#materialSettings .material-chip').forEach((chip,index)=>{
    const material=materials[index];if(!material)return;
    chip.classList.remove('ready','approved','pending-approval','incomplete','selected');
    if(material.approved){chip.classList.add('approved');chip.textContent=material.id+' 已核准'}
    else if(material.copy&&material.image){chip.classList.add('pending-approval');chip.textContent=material.id+' 待核准'}
    else{chip.classList.add('incomplete');chip.textContent=material.id+' 未完整'}
    if(document.getElementById('materialSlot')?.value===material.id)chip.classList.add('selected');
    chip.title=(material.approved?`已由 ${material.approved_by||'管理者'} 查看並核准`:(material.active?'內容完整，但尚未由管理者核准':'文字或圖片尚未完整'))+'；點一下載入內容';
    chip.setAttribute('role','button');chip.tabIndex=0;
    chip.onclick=()=>selectMaterialChip(material.id);
    chip.onkeydown=event=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();selectMaterialChip(material.id)}};
  });
  const select=document.getElementById('materialSlot');
  if(select)Array.from(select.options).forEach(option=>{
    const material=materials.find(item=>String(item.id)===String(option.value));if(!material)return;
    const status=material.approved?'已核准':material.copy&&material.image?'待核准':'未完整';
    option.textContent=`${material.id}｜${material.title}（${status}）`;
  });
}
function selectMaterialChip(id){
  loadMaterialEditor(id);refreshMaterialApprovalBadges();enhanceMaterialApproval();
  const selectedChip=[...document.querySelectorAll('#materialSettings .material-chip')].find(chip=>chip.textContent.startsWith(id+' '));
  if(selectedChip)selectedChip.classList.add('selected');
  show(id+' 內容已載入，可直接查看或修改');
}
// 文章已由管理者核准，不再要求第二次彈窗確認；保留接受時間只供工作紀錄。
acceptTargetBatch = async function(id){
  const target=(state.targets||[]).find(item=>String(item.id)===String(id));if(!target)return;
  try{
    selected=String(id);
    await api('accept_batch',{id:String(id),slot:String(target.slot||'')});
    setTimeout(openPublishModal,50);
  }catch(e){show(e.message||'無法開始發文')}
};
function simplifyBatchAcceptance(){
  const target=currentTarget(),box=document.querySelector('#detail .accept-box');
  if(box&&target&&!target.accepted_at){
    const title=box.querySelector('b'),description=box.querySelector('span'),button=box.querySelector('button');
    if(title)title.textContent='文章核准後即可開始發文';
    if(description)description.textContent='不需要再次核准本批；按下後直接選擇已核准文章。';
    if(button){button.textContent='選擇已核准文章並開始發文';button.onclick=()=>acceptTargetBatch(String(target.id))}
  }
  document.querySelectorAll('#targetList .row[data-target-id]').forEach(row=>{
    const item=(state.targets||[]).find(target=>String(target.id)===String(row.dataset.targetId)),button=row.querySelector('.row-action');
    if(!item||!button||item.status==='已完成')return;
    if(adTargetCannotPost(item)){
      button.disabled=true;
      button.textContent='已停投，待核對';
      button.onclick=null;
      return;
    }
    button.textContent=item.accepted_at?'繼續發送':'開始發文';
    button.onclick=event=>{event.stopPropagation();item.accepted_at?selectTarget(item.id,true):acceptTargetBatch(item.id)};
  });
  if(box&&target){
    const button=box.querySelector('button');
    if(button&&(adTargetCannotPost(target)||target.dispatch_attempted_at||target.claim)){
      button.disabled=true;
      button.textContent=adTargetCannotPost(target)?'已停投，待核對':'已嘗試，先核對 Facebook';
      button.onclick=null;
    }
  }
}
</script><script src="ad-admin-v2.js?v=20261016-baohui-only"></script><script src="ad-category-admin.js?v=20261005-category-gate"></script><script src="ad-identity-admin.js?v=20261016-baohui-only"></script><script src="ad-readiness-admin.js?v=20261016-baohui-only"></script><script>
setTimeout(refreshHelperState,800);
setInterval(()=>{if(state){checkReminders();checkAutoLaunch();checkTestAutoRun();checkImmediateAdvertisingRequest()}},5000);
api().catch(e=>show(e.message));
</script><script src="tools-guide.js?v=20261004-4" data-guide-scope="baohui" defer></script>
</body></html>

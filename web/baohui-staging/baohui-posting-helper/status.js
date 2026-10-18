document.title = `Bao Hui helper ${chrome.runtime.getManifest().version}`;
document.addEventListener('DOMContentLoaded', async () => {
  const out = document.createElement('pre');
  document.body.appendChild(out);
  try {
    const keys = ['baohuiAutoPostingActive', 'baohuiAutoPostingQueue', 'baohuiPendingFacebookPosts','baohuiTaskHandoffReceipts','baohuiAuctionBackendHold','baohuiAdvertisingWritebackError','baohuiAdvertisingNextAllowedAt','baohuiAdvertisingIdentityCooldowns'];
    const data = await chrome.storage.local.get(keys);
    const active=data[keys[0]];
    const alarms=await chrome.alarms.getAll();
    out.textContent = JSON.stringify({checkedAt: new Date().toISOString(), version: chrome.runtime.getManifest().version,
      active:active?{tabId:active.tabId,kind:active.kind,startedAt:active.startedAt,claimedAt:active.claimedAt,paused:!!active.paused,handoffHold:!!active.handoffHold,error:active.error||''}:null,
      queueCount: (data[keys[1]] || []).length, pendingCount: Object.keys(data[keys[2]] || {}).length,
      acknowledgedResults:Object.values(data[keys[3]]||{}).filter(r=>r.writebackConfirmed&&r.resultKnown).length,
      backendHold:data[keys[4]]||null,writebackError:data[keys[5]]||null,
      advertisingNextAllowedAt:data[keys[6]]||null,identityCooldowns:data[keys[7]]||{},
      nextAlarms:alarms.filter(a=>a.name.startsWith('baohui-shift-')||a.name==='baohui-auction-poll')
        .sort((a,b)=>a.scheduledTime-b.scheduledTime).slice(0,4)
        .map(a=>({name:a.name,at:new Date(a.scheduledTime).toISOString()}))}, null, 2);
  } catch (error) {
    out.textContent = JSON.stringify({error: String(error)});
  }
});

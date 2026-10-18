import {AdContractClient} from './ad-contract-client.mjs';
import {ChromeContractStore,createBackendContractTransport} from './chrome-contract-adapter.mjs';

// Tokens and CSRF never leave this service worker. Only an owned task tab can
// rendezvous with its claim; restarting loses all click permits, not evidence.
export function createAdLiveBridge({chromeApi,fetchImpl=fetch,onFinish,timeoutMs=150000}) {
  const sessions=new Map();let clientPromise;
  async function client(){
    if(!clientPromise)clientPromise=(async()=>{
      const key='ad-v2-worker-identity';let owner=(await chromeApi.storage.local.get(key))[key];
      if(!owner){owner={workerId:'worker-'+crypto.randomUUID(),workerSession:'session-'+crypto.randomUUID()};await chromeApi.storage.local.set({[key]:owner});}
      const request=async body=>{
        let auth;
        for(let attempt=0;attempt<2;attempt++){
          const response=await fetchImpl('https://baohui.paohui.org/ad-posting-api.php?action=claim_csrf',{credentials:'include',cache:'no-store'});
          const raw=await response.text();
          try{auth=JSON.parse(raw);}catch{
            if(attempt===0){await new Promise(resolve=>setTimeout(resolve,750));continue;}
            throw Error(`backend_claim_non_json_response:${response.status}`);
          }
          if(!response.ok||!auth.ok||auth.protocol!==2)throw Error(auth?.error||'backend_auth_or_protocol_failed');
          break;
        }
        return createBackendContractTransport({csrf:auth.csrf,fetchImpl})(body);
      };
      return new AdContractClient({request,storage:new ChromeContractStore(chromeApi.storage.local),...owner});
    })();
    return clientPromise;
  }
  function deferred(){let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b});promise.catch(()=>{});return {promise,resolve,reject};}
  async function recordError(tabId,error){await chromeApi.storage.local.set({['ad-v2-tab-error:'+tabId]:{error:String(error.message||error),at:Date.now()}});}
  function readyFingerprint(payload,observed){
    return JSON.stringify({
      targetId:String(payload.targetId||''),
      slot:String(payload.slot||''),
      task_date:String(payload.task_date||''),
      requiredVisibleName:String(payload.requiredVisibleName||''),
      groupUrl:String(observed.groupUrl||'').replace(/\/$/,'')
    });
  }
  async function ready(tabId,payload,observed,reply){
    if(payload.protocol!==2||!payload.task_date||observed.identity!==payload.requiredVisibleName||observed.groupUrl.replace(/\/$/,'')!==payload.groupUrl.replace(/\/$/,''))throw Error('identity_or_target_mismatch');
    const claimant=String(observed.claimant||'');
    if(!claimant)throw Error('claimant_required');
    const fingerprint=readyFingerprint(payload,observed);
    const existing=sessions.get(tabId);
    if(existing){
      if(existing.fingerprint!==fingerprint)throw Error('duplicate_identity_ready_conflict');
      if(existing.actual){reply({ok:true,protocol:2,duplicate:true,binding:existing.binding||null});return;}
      existing.claimant=claimant;
      const binding=existing.binding||await existing.bindingReady.promise;
      const owns=existing.claimant===claimant;
      reply({ok:true,protocol:2,duplicate:!owns,binding,takeover:owns});
      return;
    }
    const session={key:'',fingerprint,claimant,originalClaimant:claimant,bindingReady:deferred(),prepared:deferred(),result:deferred(),permit:false,clicked:false,reply};sessions.set(tabId,session);
    let c,key;
    try{
      c=await client();
      key=await c.enqueue({id:String(payload.targetId),slot:payload.slot,task_date:payload.task_date});
      session.key=key;
    }catch(error){session.bindingReady.reject(error);sessions.delete(tabId);throw error;}
    session.timer=setTimeout(()=>fail(tabId,Error('ad_phase_timeout_lookup_only')),timeoutMs);
    c.run(key,{
      async prepare(binding){session.binding=binding;session.bindingReady.resolve(binding);reply({ok:true,protocol:2,binding,duplicate:session.claimant!==session.originalClaimant});session.reply=null;await session.prepared.promise;},
      async inspect(){return session.actual;},
      async publish(){session.permit=true;session.submitReply({ok:true,allowed:true});session.submitReply=null;return session.result.promise;},
      async lookup(){return null;}
    }).then(async result=>{
      await chromeApi.storage.local.set({baohuiAdvertisingLastResult:{targetId:payload.targetId,slot:payload.slot,task_date:payload.task_date,...result,at:Date.now(),writebackConfirmed:result.state==='complete'}});
      await onFinish(tabId,result.state==='complete',{
        writebackConfirmed:['complete','pending_review'].includes(result.state),
        resultKnown:['complete','pending_review'].includes(result.state),safeStopped:true,phase:result.state
      });
    }).catch(async error=>{
      session.reply?.({ok:false,error:error.message});session.submitReply?.({ok:false,error:error.message});await recordError(tabId,error);
      let phase='unknown';
      try{
        const record=await c.storage.get(key);
        if(record?.task?.claim_token&&!record.outbox)phase=(await c.call(record.task,'lookup')).phase;
      }catch{}
      const known=['complete','pending_review','failed_before_submit','rejected_after_submit'].includes(phase);
      await onFinish(tabId,false,{writebackConfirmed:known,resultKnown:known,safeStopped:true,phase});
    })
      .finally(()=>{clearTimeout(session.timer);sessions.delete(tabId);});
  }
  function prepared(tabId,claimant,actual,reply){const s=sessions.get(tabId);if(!s)throw Error('no_claimed_composer');if(s.claimant!==claimant){reply({ok:true,allowed:false,duplicate:true});return;}if(s.actual)throw Error('no_claimed_composer');s.actual=actual;s.submitReply=reply;s.prepared.resolve();}
  function consumeClick(tabId){const s=sessions.get(tabId);if(!s?.permit||s.clicked)throw Error('publish_gate_not_authorized');s.clicked=true;s.permit=false;}
  function result(tabId,claimant,evidence){const s=sessions.get(tabId);if(!s?.clicked)throw Error('no_recorded_publish_click');if(s.claimant!==claimant)return false;s.result.resolve(evidence);return true;}
  function fail(tabId,error){const s=sessions.get(tabId);if(s){s.permit=false;s.prepared.reject(error);s.result.reject(error);}return recordError(tabId,error);}
  async function recover(){
    const c=await client();
    for(const [key,r]of await c.storage.entries()){
      if(r.phase==='complete'||[...sessions.values()].some(s=>s.key===key))continue;
      if(r.outbox){
        try{
          await c.flushOne(key);
          const pending=(await chromeApi.storage.local.get('baohuiPendingFacebookPosts')).baohuiPendingFacebookPosts||{};
          for(const [tabId,p]of Object.entries(pending)){
            if(String(p.targetId)===String(r.task.id)&&p.task_date===r.task.task_date&&p.slot===r.task.slot)
              await onFinish(Number(tabId),true,{writebackConfirmed:true,resultKnown:true,safeStopped:true,phase:'complete'});
          }
        }catch(e){await recordError('writeback',e);}
      }else{r.phase='lookup_only';await c.storage.set(key,r);}
    }
  }
  return {ready,prepared,consumeClick,result,fail,recover,has:tabId=>sessions.has(tabId)};
}

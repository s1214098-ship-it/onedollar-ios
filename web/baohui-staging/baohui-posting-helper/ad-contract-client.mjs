// Shared front-end / MV3 worker contract. Dependency injection is for storage and
// transport only; the same gate controls a real click or the mock sender in tests.
export class AdContractClient {
  constructor({request,storage,workerId,workerSession}) { Object.assign(this,{request,storage,workerId,workerSession});this.chain=Promise.resolve(); }
  serial(fn){const next=this.chain.then(fn);this.chain=next.catch(()=>{});return next;}
  key(task){return [task.task_date,task.slot,task.id].join('|');}
  async call(task,operation,extra={}) {
    return this.request({...task,...extra,protocol:2,operation,worker_id:this.workerId,worker_session:this.workerSession});
  }
  enqueue(task){return this.serial(async()=>{
    const key=this.key(task);if(await this.storage.get(key))throw Error('already_local_lookup_only');
    // Persist intent before network. An unknown claim response must not be retried.
    let record={task:{...task},phase:'claim_requested'};await this.storage.set(key,record);
    try { const claim=await this.call(task,'claim');record={task:{...task,claim_token:claim.claim_token,binding_hash:claim.binding_hash,binding:claim.binding},phase:'queued'};await this.storage.set(key,record);return key; }
    catch(e){record.phase='lookup_only';record.error=e.message;await this.storage.set(key,record);throw e;}
  });}
  async lookup(record,io) {
    if(!record.task.claim_token)return {state:'manual_lookup_required'};
    await this.call(record.task,'lookup');
    const evidence=await io.lookup(record.task.binding);
    if(evidence) {const body={...record.task,...evidence};record.outbox=body;record.phase='outbox';await this.storage.set(this.key(record.task),record);return this.flushOne(this.key(record.task));}
    return {state:'lookup_only'};
  }
  run(key,io){return this.serial(async()=>{
    const record=await this.storage.get(key);if(!record)throw Error('not_queued');
    if(record.phase!=='queued')return this.lookup(record,io);
    record.phase='start_requested';await this.storage.set(key,record);
    try {
      await this.call(record.task,'start');
      await io.prepare(record.task.binding);
      // The live composer must still have exactly the frozen destination/identity/material.
      const actual=await io.inspect();
      for(const field of ['groupUrl','requiredVisibleName','materialId','copy','imageUrl','imageSha256'])if(actual[field]!==record.task.binding[field])throw Error('composer_binding_mismatch:'+field);
      record.phase='submit_requested';await this.storage.set(key,record);
      // A timeout here NEVER permits a click, even if the server may have committed.
      const gate=await this.call(record.task,'submitting');if(gate.phase!=='submitting')throw Error('submission_not_authorized');
      record.phase='submitting';await this.storage.set(key,record);
      const result=await io.publish();
      if(result?.pendingReview){record.phase='lookup_only';await this.storage.set(key,record);await this.call(record.task,'pending_review');return {state:'pending_review'};}
      if(!result?.post_id){record.phase='lookup_only';await this.storage.set(key,record);await this.call(record.task,'submitted_unknown');return {state:'lookup_only'};}
      record.outbox={...record.task,...result};record.phase='outbox';await this.storage.set(key,record);
      return await this.flushOne(key);
    }catch(e){
      if(!record.outbox){
        try{
          const remote=await this.call(record.task,'lookup');
          if(['claimed','started'].includes(remote.phase))await this.call(record.task,'failed_before_submit',{error:e.message});
          else if(remote.phase==='submitting'&&/facebook_rate_limit/i.test(e.message))await this.call(record.task,'rejected_after_submit',{error:e.message});
          else if(remote.phase==='submitting')await this.call(record.task,'submitted_unknown');
        }catch(reportError){record.reportingError=reportError.message;}
      }
      if(record.phase!=='outbox')record.phase='lookup_only';record.error=e.message;await this.storage.set(key,record);throw e;
    }
  });}
  async flushOne(key){
    const record=await this.storage.get(key);if(!record?.outbox)return {state:'no_outbox'};
    // Original task_date is preserved; never substitute the replay date.
    const result=await this.call(record.outbox,'complete');record.phase='complete';delete record.outbox;await this.storage.set(key,record);return {state:'complete',...result};
  }
  recoverAll(io){return this.serial(async()=>{
    const results=[];for(const [key,record] of await this.storage.entries()){
      if(record.phase==='complete')continue;
      if(record.outbox)results.push(await this.flushOne(key));
      else{record.phase='lookup_only';await this.storage.set(key,record);results.push(await this.lookup(record,io));}
    }return results;
  });}
}

export function createHttpTransport({url,csrf,fetchImpl=fetch}){
  return async body=>{
    const response=await fetchImpl(url,{method:'POST',credentials:'include',headers:{'Content-Type':'application/json','X-Ad-CSRF':csrf},body:JSON.stringify(body)});
    const raw=await response.text();
    let result;
    try{result=JSON.parse(raw);}catch{throw Error(`backend_non_json_response:${response.status}`);}
    if(!response.ok||!result.ok)throw Error(result.error||`request_failed:${response.status}`);
    return result.result;
  };
}
export async function queueFromFrontend(task,helper){
  if(helper.protocol!==2)throw Error('helper_protocol_upgrade_required');
  // A queue acknowledgement exists only after a durable server claim.
  return helper.enqueue({id:task.id,slot:task.slot,task_date:task.task_date});
}

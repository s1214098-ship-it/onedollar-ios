import {AdContractClient,createHttpTransport} from './ad-contract-client.mjs';

// Candidate integration adapter. Not installed in the user's live extension.
export class ChromeContractStore {
  constructor(area){this.area=area;this.prefix='ad-v2-task:';}
  async get(key){return (await this.area.get(this.prefix+key))[this.prefix+key];}
  async set(key,value){await this.area.set({[this.prefix+key]:value});}
  async entries(){return Object.entries(await this.area.get(null)).filter(([key])=>key.startsWith(this.prefix)).map(([key,value])=>[key.slice(this.prefix.length),value]);}
}
export async function createChromeContractAdapter({chromeApi,request,ioFactory}){
  const identityKey='ad-v2-worker-identity';let identity=(await chromeApi.storage.local.get(identityKey))[identityKey];
  if(!identity){identity={workerId:'worker-'+crypto.randomUUID(),workerSession:'session-'+crypto.randomUUID()};await chromeApi.storage.local.set({[identityKey]:identity});}
  const client=new AdContractClient({request,storage:new ChromeContractStore(chromeApi.storage.local),...identity});
  return {
    protocol:2,client,
    async receive(message,sender){
      // Only the authenticated admin page may propose task IDs. It cannot supply
      // arbitrary copy, destination or credentials; those come from the claim.
      const url=new URL(sender?.url||'about:blank');
      if(url.origin!=='https://baohui.paohui.org'||url.pathname!=='/admin-ad-posting.php')throw Error('sender_rejected');
      if(message.protocol!==2||message.type!=='AD_V2_ENQUEUE')throw Error('helper_protocol_upgrade_required');
      const t=message.task;
      const key=await client.enqueue({id:t.id,slot:t.slot,task_date:t.task_date});return {ok:true,queued:true,key,protocol:2};
    },
    async run(key){return client.run(key,ioFactory());},
    async restart(){return client.recoverAll(ioFactory());}
  };
}

// A production caller must obtain CSRF from authenticated claim_csrf, then call
// this transport. Never take a CSRF token, owner token or result from a FB page.
export function createBackendContractTransport({csrf,fetchImpl=fetch}){
  return async body=>{
    const action=body.operation==='complete'?'update':body.operation;
    const request=createHttpTransport({url:`https://baohui.paohui.org/ad-posting-api.php?action=${action}`,csrf,fetchImpl});
    return request(body.operation==='complete'?{...body,status:'已完成'}:body);
  };
}

(async()=>{
  const s=await chrome.storage.local.get(['baohuiAutoPostingActive','baohuiAutoPostingQueue','baohuiPendingFacebookPosts']);
  if(s.baohuiAutoPostingActive||(s.baohuiAutoPostingQueue||[]).length||Object.keys(s.baohuiPendingFacebookPosts||{}).length){
    document.body.textContent='助手仍有工作，未重新載入；保留目前任務，請等待安全交接。';
    return;
  }
  chrome.runtime.reload();
})().catch(error=>{document.body.textContent='助手未重新載入：'+error.message;});

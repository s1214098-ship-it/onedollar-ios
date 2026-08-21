<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Taipei');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Facebook-Worker-Key, X-Facebook-Worker-Id');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if(($_SERVER['REQUEST_METHOD']??'GET')==='OPTIONS'){http_response_code(204);exit;}
require_once __DIR__.'/facebook-listing-lib.php';

$legacyDbFile=__DIR__.'/../data/shared-db.json';
$dbFile=__DIR__.'/../data/facebook-publish-data.json';
$settingsFile=__DIR__.'/../data/facebook-group-candidates.json';
const FB_WORKER_CLAIM_GAP_SECONDS = 90;
const FB_WORKER_STALE_RUNNING_SECONDS = 300;

function respond($data,int $status=200):void{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE);exit;}
function textv($value):string{return trim((string)($value??''));}
function safeid($value):string{return preg_replace('/[^A-Za-z0-9_-]/','',textv($value));}
function workerKey():string{return textv($_GET['key']??($_SERVER['HTTP_X_FACEBOOK_WORKER_KEY']??''));}
function expectedKey(string $file):string{$data=json_decode(@file_get_contents($file)?:'{}',true);return textv($data['settings']['workerKey']??'');}
function decodeRows($value):array{if(is_string($value))$value=json_decode($value,true);return is_array($value)?array_values($value):[];}
function workerIdFromRequest():string{return safeid($_GET['workerId']??($_SERVER['HTTP_X_FACEBOOK_WORKER_ID']??'chrome-ext-fengzhi'))?:'chrome-ext-fengzhi';}
function withLockedDb(string $file,callable $callback){$handle=fopen($file,'c+');if(!$handle)respond(['ok'=>false,'message'=>'共用資料庫無法開啟'],500);flock($handle,LOCK_EX);rewind($handle);$raw=stream_get_contents($handle);$db=json_decode($raw?:'{}',true);if(!is_array($db))$db=[];$result=$callback($db);if(!empty($result['write'])){ftruncate($handle,0);rewind($handle);fwrite($handle,json_encode($db,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));fflush($handle);}flock($handle,LOCK_UN);fclose($handle);return$result;}

function fb_worker_normalize_url(string $url):string{
  $url=trim($url);
  $url=preg_replace('#^https://web\.facebook\.com#i','https://www.facebook.com',$url)??$url;
  $url=preg_replace('#^https://m\.facebook\.com#i','https://www.facebook.com',$url)??$url;
  $url=preg_replace('#^http://#i','https://',$url)??$url;
  return $url;
}

function fb_worker_is_allowed_destination(string $url):bool{
  $url=fb_worker_normalize_url($url);
  if($url==='')return false;
  if(!preg_match('#^https://(www\.)?facebook\.com/#i',$url))return false;
  if(preg_match('#facebook\.com/groups/[^/?#]+#i',$url))return true;
  if(preg_match('#facebook\.com/profile\.php\?[^#]*id=\d+#i',$url))return true;
  if(preg_match('#facebook\.com/pages/[^/?#]+#i',$url))return true;
  if(preg_match('#id=61571273973945#',$url))return true;
  $blocked=['watch','marketplace','reel','reels','stories','login','sharer','dialog','adsmanager','photo.php','permalink.php','share','stories.php'];
  if(preg_match('#facebook\.com/([^/?#]+)#i',$url,$m)){
    $seg=strtolower($m[1]);
    if($seg!=='' && $seg!=='groups' && $seg!=='profile.php' && !in_array($seg,$blocked,true))return true;
  }
  return false;
}

if(!is_file($dbFile)){$legacy=json_decode(@file_get_contents($legacyDbFile)?:'{}',true);if(!is_array($legacy))$legacy=[];$seed=json_decode(@file_get_contents(__DIR__.'/../data/facebook-group-registry-seed.json')?:'[]',true);$initial=['facebookPublishSchedules'=>[],'facebookPublishJobs'=>[],'facebookGroupRegistry'=>$legacy['facebookGroupRegistry']??(is_array($seed)?$seed:[]),'updatedAt'=>date('c')];file_put_contents($dbFile,json_encode($initial,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),LOCK_EX);}

$expected=expectedKey($settingsFile);$provided=workerKey();
if($expected===''||!hash_equals($expected,$provided))respond(['ok'=>false,'message'=>'執行器金鑰不正確'],403);
$method=$_SERVER['REQUEST_METHOD']??'GET';$action=textv($_GET['action']??'');

if($method==='GET'&&$action==='backups'){$patterns=[dirname($dbFile).'/shared-db*',dirname($dbFile).'/*.bak',dirname($dbFile).'/*.tmp',dirname(dirname($dbFile)).'/backup*/*shared*',dirname(dirname($dbFile)).'/backups/*shared*'];$files=[];foreach($patterns as$pattern)foreach(glob($pattern)?:[]as$file)if(is_file($file))$files[realpath($file)?:$file]=['name'=>basename($file),'path'=>$file,'bytes'=>filesize($file),'modifiedAt'=>date('c',filemtime($file))];respond(['ok'=>true,'files'=>array_values($files)]);}

if($method==='GET'&&$action==='health'){$raw=@file_get_contents($dbFile);$decoded=is_string($raw)?json_decode($raw,true):null;$jobs=is_array($decoded)?decodeRows($decoded['facebookPublishJobs']??[]):[];$queued=array_values(array_filter($jobs,fn($row)=>($row['status']??'')==='queued'));usort($queued,fn($a,$b)=>(strtotime(textv($a['scheduledAt']??''))?:PHP_INT_MAX)<=>(strtotime(textv($b['scheduledAt']??''))?:PHP_INT_MAX));$next=$queued[0]??null;$today=date('Y-m-d');$windowStart=strtotime($today.' 06:00:00');$now=time();$eligible=array_values(array_filter($queued,function($row)use($windowStart,$now){$scheduled=strtotime(textv($row['scheduledAt']??''));return $scheduled&&$scheduled>=$windowStart&&$scheduled<=$now;}));usort($eligible,fn($a,$b)=>(strtotime(textv($a['scheduledAt']??''))?:PHP_INT_MAX)<=>(strtotime(textv($b['scheduledAt']??''))?:PHP_INT_MAX));$nextEligible=$eligible[0]??null;$statusCounts=[];foreach($jobs as$row){$s=textv($row['status']??'');$statusCounts[$s]=($statusCounts[$s]??0)+1;}$todayDone=count(array_filter($jobs,function($row)use($today){return in_array($row['status']??'',['published','pending_review'],true)&&(str_starts_with(textv($row['publishedAt']??''),$today)||str_starts_with(textv($row['updatedAt']??''),$today));}));$running=array_values(array_filter($jobs,fn($row)=>($row['status']??'')==='running'));$runningJob=$running[0]??null;respond(['ok'=>true,'readable'=>is_string($raw),'bytes'=>is_string($raw)?strlen($raw):0,'jsonValid'=>is_array($decoded),'jsonError'=>json_last_error_msg(),'keys'=>is_array($decoded)?count($decoded):0,'hasProperties'=>is_array($decoded)&&array_key_exists('properties',$decoded),'hasSchedules'=>is_array($decoded)&&array_key_exists('facebookPublishSchedules',$decoded),'hasJobs'=>is_array($decoded)&&array_key_exists('facebookPublishJobs',$decoded),'serverTime'=>date('c'),'serverEpoch'=>time(),'queuedCount'=>count($queued),'eligibleQueuedCount'=>count($eligible),'todayDoneCount'=>$todayDone,'statusCounts'=>$statusCounts,'nextQueuedAt'=>textv($next['scheduledAt']??''),'nextQueuedEpoch'=>strtotime(textv($next['scheduledAt']??'')),'nextEligibleQueuedAt'=>textv($nextEligible['scheduledAt']??''),'nextEligibleQueuedId'=>textv($nextEligible['id']??''),'runningCount'=>count($running),'runningJobId'=>textv($runningJob['id']??''),'runningUpdatedAt'=>textv($runningJob['updatedAt']??''),'runningDestination'=>textv($runningJob['destinationName']??''),'claimGapSeconds'=>FB_WORKER_CLAIM_GAP_SECONDS,'staleRunningSeconds'=>FB_WORKER_STALE_RUNNING_SECONDS,'extensionVersion'=>'0.5.33']);}

if($method==='GET'&&$action==='claim'){
  $workerId=workerIdFromRequest();
  $result=withLockedDb($dbFile,function(&$db)use($workerId){
    $jobs=decodeRows($db['facebookPublishJobs']??[]);
    $now=time();
    $today=date('Y-m-d');
    $windowStart=strtotime($today.' 06:00:00');
    $job=null;
    $changed=false;

    foreach($jobs as&$row){
      $updated=strtotime(textv($row['updatedAt']??$row['scheduledAt']??''))?:0;
      if(($row['status']??'')==='running'&&$updated<$now-FB_WORKER_STALE_RUNNING_SECONDS){
        $row['status']='queued';
        $row['note']='執行器逾時，已自動重新排隊';
        $changed=true;
      }
      $note=textv($row['note']??'');
      if(($row['status']??'')==='blocked' && (str_contains($note,'只支援 facebook.com/groups') || str_contains($note,'非社團頁另走人工'))){
        $destUrl=textv($row['destinationUrl']??'');
        $account=textv($row['account']??'');
        $dest=textv($row['destinationName']??'').' '.$destUrl;
        if(fb_worker_is_allowed_destination($destUrl) && !fb_unverified_account($account) && !fb_overseas_text($dest)){
          $row['status']='queued';
          $row['note']='已改支援粉絲團與社團，重新排隊';
          $row['updatedAt']=date('c');
          $changed=true;
        }
      }
    }
    unset($row);

    foreach($jobs as$row){
      if(($row['status']??'')==='running' && safeid($row['workerId']??'')===$workerId){
        if($changed)$db['facebookPublishJobs']=json_encode($jobs,JSON_UNESCAPED_UNICODE);
        return['write'=>$changed,'job'=>$row];
      }
    }

    $paused=(bool)array_filter($jobs,fn($row)=>($row['status']??'')==='running');
    $lastClaim=strtotime(textv($db['facebookPublishWorkerLastClaimAt']??''));
    if(!$paused&&(!$lastClaim||$lastClaim<=$now-FB_WORKER_CLAIM_GAP_SECONDS)){
      $claimIndex=null;
      $claimTime=PHP_INT_MAX;
      foreach($jobs as$i=>$row){
        if(($row['status']??'')!=='queued')continue;
        $scheduled=strtotime(textv($row['scheduledAt']??''));
        if(!$scheduled||$scheduled>$now)continue;
        if($scheduled<$windowStart)continue;
        $account=textv($row['account']??'');
        $dest=textv($row['destinationName']??'').' '.textv($row['destinationUrl']??'');
        $destUrl=textv($row['destinationUrl']??'');
        if(fb_unverified_account($account)||fb_overseas_text($dest)){
          $jobs[$i]['status']='blocked';
          $jobs[$i]['note']=trim(textv($row['note']??'').'；執行器拒絕：身分未核准或海外社團');
          $jobs[$i]['updatedAt']=date('c');
          $changed=true;
          continue;
        }
        if(!fb_worker_is_allowed_destination($destUrl)){
          $jobs[$i]['status']='blocked';
          $jobs[$i]['note']=trim(textv($row['note']??'').'；執行器拒絕：不是可發佈的 Facebook 社團或粉絲團網址');
          $jobs[$i]['updatedAt']=date('c');
          $changed=true;
          continue;
        }
        $priority=(textv($row['lane']??'')==='dayou_toucheng_10_each'||textv($row['scheduleId']??'')==='SCH-DAYOU-TOUCHENG-10EACH-20260821-150752')?0:1;
        $score=$priority*10000000000+$scheduled;
        if($score>=$claimTime)continue;
        $claimTime=$score;
        $claimIndex=$i;
      }
      if($claimIndex!==null){
        $jobs[$claimIndex]['status']='running';
        $jobs[$claimIndex]['updatedAt']=date('c');
        $jobs[$claimIndex]['claimAt']=date('c');
        $jobs[$claimIndex]['workerId']=$workerId;
        $jobs[$claimIndex]['note']='Chrome 發文執行器 0.5.33 已接收';
        $job=$jobs[$claimIndex];
        $db['facebookPublishWorkerLastClaimAt']=date('c');
        $changed=true;
      }
    }
    if($changed)$db['facebookPublishJobs']=json_encode($jobs,JSON_UNESCAPED_UNICODE);
    return['write'=>$changed,'job'=>$job];
  });
  respond(['ok'=>true,'job'=>$result['job']]);
}

if($method==='POST'){
  $payload=json_decode(file_get_contents('php://input')?:'{}',true);if(!is_array($payload))respond(['ok'=>false,'message'=>'JSON格式錯誤'],400);
  if(textv($payload['action']??'')==='clear_queue'){$result=withLockedDb($dbFile,function(&$db){$oldSchedules=count(decodeRows($db['facebookPublishSchedules']??[]));$oldJobs=count(decodeRows($db['facebookPublishJobs']??[]));$db['facebookPublishSchedules']=[];$db['facebookPublishJobs']=[];$db['facebookPublishWorkerLastClaimAt']='';$db['updatedAt']=date('c');return['write'=>true,'oldSchedules'=>$oldSchedules,'oldJobs'=>$oldJobs];});respond(['ok'=>true,'clearedSchedules'=>$result['oldSchedules'],'clearedJobs'=>$result['oldJobs']]);}
  if(textv($payload['action']??'')==='restore_same_store_seed'){$seedFile=dirname(dirname($dbFile)).'/原始營業員抓取清單-羅東文化盛群最新.json';$seedRaw=@file_get_contents($seedFile);$seed=is_string($seedRaw)?json_decode($seedRaw,true):null;if(!is_array($seed)||count($seed)<100)respond(['ok'=>false,'message'=>'同店正式來源不完整，已停止恢復'],409);$dbRaw=@file_get_contents($dbFile);$db=is_string($dbRaw)?json_decode($dbRaw,true):null;if(!is_array($db))respond(['ok'=>false,'message'=>'共用資料庫目前無法讀取'],500);$db['sameStoreItems']=json_encode(array_values($seed),JSON_UNESCAPED_UNICODE);$encoded=json_encode($db,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);$tmp=$dbFile.'.same-store-restore-tmp';if(!is_string($encoded)||file_put_contents($tmp,$encoded,LOCK_EX)===false||!rename($tmp,$dbFile))respond(['ok'=>false,'message'=>'同店資料恢復寫入失敗'],500);respond(['ok'=>true,'restored'=>count($seed),'bytes'=>strlen($encoded)]);}
  if(textv($payload['action']??'')==='retry'){$jobId=safeid($payload['jobId']??'');$caseId=safeid($payload['caseId']??'');$result=withLockedDb($dbFile,function(&$db)use($jobId,$caseId){$jobs=decodeRows($db['facebookPublishJobs']??[]);$found=false;foreach($jobs as&$row){$matches=($jobId!==''&&safeid($row['id']??'')===$jobId)||($caseId!==''&&safeid($row['caseId']??'')===$caseId);if(!$matches||!in_array($row['status']??'',['pending_review','blocked','failed'],true))continue;$row['status']='queued';$row['note']='修正後重新排隊';$row['updatedAt']=date('c');$found=true;break;}unset($row);if($found){$db['facebookPublishJobs']=json_encode($jobs,JSON_UNESCAPED_UNICODE);$db['facebookPublishWorkerLastClaimAt']='';}return['write'=>$found,'found'=>$found];});if(empty($result['found']))respond(['ok'=>false,'message'=>'找不到可重試任務'],404);respond(['ok'=>true]);}
  if(textv($payload['action']??'')!=='report')respond(['ok'=>false,'message'=>'不支援的動作'],400);
  $jobId=safeid($payload['jobId']??'');$status=textv($payload['status']??'pending_review');$allowed=['published','pending_review','blocked','failed'];if($jobId===''||!in_array($status,$allowed,true))respond(['ok'=>false,'message'=>'任務或狀態不正確'],400);
  $result=withLockedDb($dbFile,function(&$db)use($jobId,$status,$payload){$jobs=decodeRows($db['facebookPublishJobs']??[]);$found=false;foreach($jobs as&$row){if(safeid($row['id']??'')!==$jobId)continue;$row['status']=$status;$row['note']=textv($payload['note']??'');$row['postUrl']=textv($payload['postUrl']??'');$row['updatedAt']=date('c');$row['resultAt']=date('c');$row['workerId']=safeid($payload['workerId']??($row['workerId']??'chrome-ext-fengzhi'));if(in_array($status,['published','pending_review'],true))$row['publishedAt']=date('c');$found=true;break;}unset($row);if($found){$db['facebookPublishJobs']=json_encode($jobs,JSON_UNESCAPED_UNICODE);}return['write'=>$found,'found'=>$found];});
  if(empty($result['found']))respond(['ok'=>false,'message'=>'找不到發文任務'],404);respond(['ok'=>true]);
}
respond(['ok'=>false,'message'=>'Method not allowed'],405);

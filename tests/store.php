<?php
require __DIR__.'/../plugin/NodeQuality/Services/Store.php';
require __DIR__.'/../plugin/NodeQuality/Services/Control.php';
use Plugin\NodeQuality\Services\{Store,Control};
function check($ok,$msg){if(!$ok)throw new RuntimeException($msg);echo "PASS $msg\n";}
function fails(callable $f,int $code,string $msg){try{$f();throw new Exception('did not fail');}catch(RuntimeException $e){check($e->getCode()===$code,$msg);}}
$root=sys_get_temp_dir().'/nq-test-'.bin2hex(random_bytes(6));$s=new Store($root);$c=new Control($s);
$m=$c->createMachine('test');$cred=bin2hex(random_bytes(32));$en=$c->enroll($m['enroll_token'],$cred);check($en['machine_id']===$m['machine_id'],'register machine');
check($c->enroll($m['enroll_token'],$cred)===$en,'lost enrollment response can retry same credential');
fails(fn()=>$c->enroll($m['enroll_token'],bin2hex(random_bytes(32))),403,'enrollment cannot be reused by another credential');
check($c->authenticate($cred)['id']===$m['machine_id'],'credential hashed authentication');
$target=$c->target(['name'=>'public','host'=>'example.com','port'=>443]);
fails(fn()=>$c->target(['name'=>'private','host'=>'127.0.0.1','port'=>80]),422,'reject private target');
$at=intdiv(time()-600,300)*300;
$f=['id'=>hash('sha256','one'),'profile_id'=>$m['profile_id'],'observed_at'=>$at,'kind'=>'scheduled','results'=>[['target_id'=>$target,'revision'=>1,'family'=>4,'tcp'=>[['status'=>'ok','ms'=>20.5],['status'=>'timeout'],['status'=>'ok','ms'=>21.5]],'icmp'=>[['status'=>'timeout']]]]];
fails(fn()=>$s->ingest($f,'other-machine'),403,'machine cannot submit another profile');
$s->ingest($f,$m['machine_id']);$s->ingest($f,$m['machine_id']);
check(Store::query($s->raw($at),'SELECT count(*) FROM frames')->fetchColumn()==1,'duplicate raw frame is idempotent');
$h=$s->history($m['profile_id'],$at-3600,$at+3600);$bucket=array_values($h[0]['data'])[0];check($bucket['count']===3&&$bucket['ok']===2,'rollup preserves failures and does not double count');
$conflict=$f;$conflict['results'][0]['tcp'][0]['ms']=99;fails(fn()=>$s->ingest($conflict,$m['machine_id']),409,'conflicting duplicate rejected');
$bad=$f;$bad['id']=hash('sha256','bad');$bad['observed_at']=time()-73*3600;fails(fn()=>$s->ingest($bad,$m['machine_id']),422,'late frame outside 72h rejected');
Store::query($s->raw($at),'UPDATE frames SET pending=1');$s->maintenance();check(Store::query($s->raw($at),'SELECT pending FROM frames')->fetchColumn()==0,'pending outbox repair');
$h=$s->history($m['profile_id'],$at-3600,$at+3600);check(array_values($h[0]['data'])[0]['count']===3,'repair is idempotent');
$task=$c->manual(1,$m['profile_id'],$target);check($c->manual(2,$m['profile_id'],$target)['id']===$task['id'],'users share a deduplicated probe');
$manual=$f;$manual['id']=hash('sha256','manual');$manual['kind']='manual';$manual['observed_at']=time()-5;$manual['task_id']=$task['id'];$manual['results'][0]['tcp']=[['status'=>'ok','ms'=>9]];$s->ingest($manual,$m['machine_id']);check($s->latest($m['profile_id'])['scheduled']['results'][0]['tcp'][0]['ms']===9,'manual results overlay display');$h=$s->history($m['profile_id'],$at-3600,$at+3600);check(array_values($h[0]['data'])[0]['count']===3,'manual probes excluded from scheduled scoring');
$t2=$c->target(['name'=>'other','host'=>'example.org','port'=>443]);fails(fn()=>$c->manual(1,$m['profile_id'],$t2),429,'per-user cooldown');
$c->manual(2,$m['profile_id'],$t2);$p=$c->plan($c->authenticate($cred));check(count($p['tasks'])===1 && $p['tasks'][0]['target_id']===$t2,'completed task omitted and pending task leased');check(count($c->plan($c->authenticate($cred))['tasks'])===0,'leased task not duplicated');
$c->bind(12,$m['profile_id']);$m2=$c->createMachine('second');$c->bind(12,$m2['profile_id']);check(Store::query($s->db,'SELECT count(*) FROM bindings WHERE node_id=12')->fetchColumn()==2,'rebind preserves historical intervals');
$c->unbind(12);check(!Store::query($s->db,'SELECT 1 FROM bindings WHERE node_id=12 AND valid_to IS NULL')->fetchColumn(),'unbind ends current association');check(Store::query($s->db,'SELECT count(*) FROM bindings WHERE node_id=12')->fetchColumn()==2,'unbind preserves binding history');
$q=['id'=>hash('sha256','q1'),'profile_id'=>$m['profile_id'],'observed_at'=>time()-100,'kind'=>'quality','quality'=>['egress'=>['4'=>'1.1.1.1']]];$s->ingest($q,$m['machine_id']);$q['id']=hash('sha256','q2');$q['observed_at']+=10;$q['quality']['egress']['4']='8.8.8.8';$s->ingest($q,$m['machine_id']);check(Store::query($s->db,"SELECT count(*) FROM events WHERE kind='ip_changed'")->fetchColumn()==1,'IP change event');
$red=Control::redact(['ip'=>'1.2.3.4','ipv6'=>'2001:4860:4860::8888','error_detail'=>'secret','raw'=>'secret']);check($red['ip']==='1.2.*.*'&&!isset($red['raw'])&&!isset($red['error_detail']),'redact IP and raw reports');
$low=new Store($root,fn()=>[400*1024*1024,20*1024*1024*1024]);fails(fn()=>$low->ingest($f,$m['machine_id']),507,'disk reserve rejects ingestion before acknowledging');
$warn=new Store($root,fn()=>[800*1024*1024,20*1024*1024*1024]);check($warn->capacity()['heavy_paused']&&!$warn->capacity()['ingest_paused'],'low disk pauses heavy collection before hard stop');
$slice=$s->boundedHistory($m['profile_id'],$at-30,$at+30);check(count($slice)===1&&array_values($slice[0]['data'])[0]['count']===3,'partial first hour shows measured samples');check($s->boundedHistory($m['profile_id'],$at+1,$at+60)===[],'partial hour excludes samples before binding');
$backup=$s->backup();$b=new SQLite3($backup.'/metadata.sqlite',SQLITE3_OPEN_READONLY);check($b->querySingle('PRAGMA integrity_check')==='ok','online backup integrity');$b->close();
$locker=$s->open($root.'/raw/'.gmdate('Y-m-d',$at).'.sqlite');$locker->exec('BEGIN IMMEDIATE');$locked=$f;$locked['id']=hash('sha256','after-lock');$busy=false;try{$s->ingest($locked,$m['machine_id']);}catch(PDOException $e){$busy=true;}finally{$locker->exec('ROLLBACK');}check($busy,'real SQLite write contention returns retryable failure');$s->ingest($locked,$m['machine_id']);check(Store::query($s->raw($at),'SELECT count(*) FROM frames WHERE id=?',[$locked['id']])->fetchColumn()==1,'retry after lock release succeeds once');
Store::query($s->db,'UPDATE machines SET active=0 WHERE id=?',[$m['machine_id']]);fails(fn()=>$c->authenticate($cred),401,'revoked machine denied');
echo "TEST_ROOT=$root\n";

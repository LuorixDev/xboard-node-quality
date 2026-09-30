<?php
require __DIR__.'/../plugin/NodeQuality/Services/Store.php';require __DIR__.'/../plugin/NodeQuality/Services/Control.php';
use Plugin\NodeQuality\Services\{Store,Control};
$root=sys_get_temp_dir().'/nq-scale-'.bin2hex(random_bytes(4));$s=new Store($root);$c=new Control($s);$targets=[];for($i=0;$i<20;$i++)$targets[]=$c->target(['name'=>'Target '.$i,'host'=>'example.com','port'=>443]);
$machines=[];for($i=0;$i<100;$i++)$machines[]=$c->createMachine('Machine '.$i);
$samples=[];foreach($targets as $target)foreach([4,6] as $af)$samples[]=['target_id'=>$target,'revision'=>1,'family'=>$af,'icmp'=>array_fill(0,4,['status'=>'ok','ms'=>36.1]),'tcp'=>[['status'=>'ok','ms'=>40.2],['status'=>'ok','ms'=>39.8],['status'=>'timeout']]];
$start=microtime(true);$base=intdiv(time()-3600,300)*300;
foreach($machines as $m)for($slot=0;$slot<12;$slot++)$s->ingest(['id'=>hash('sha256',$m['profile_id'].':'.$slot),'profile_id'=>$m['profile_id'],'observed_at'=>$base+$slot*300,'kind'=>'scheduled','results'=>$samples],$m['machine_id']);
$elapsed=microtime(true)-$start;$bytes=0;foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $f)if($f->isFile())$bytes+=$f->getSize();
$n=0;foreach(glob($root.'/raw/*.sqlite') as $f)$n+=(int)Store::query($s->open($f),'SELECT count(*) FROM frames')->fetchColumn();if($n!==1200)throw new RuntimeException('lost frames');
echo json_encode(['profiles'=>100,'targets'=>20,'families'=>2,'batches'=>1200,'individual_samples'=>1200*40*7,'elapsed_seconds'=>round($elapsed,3),'stored_bytes'=>$bytes,'peak_memory_bytes'=>memory_get_peak_usage(true),'note'=>'Local sequential synthetic ingestion; not a production concurrency or 48-hour soak test'],JSON_PRETTY_PRINT),"\n";

<?php
declare(strict_types=1);
namespace Plugin\NodeQuality\Services;
use RuntimeException;

final class Control
{
    public function __construct(public Store $store) {}
    public function defaultRoutes(): array
    {
        $out=[];foreach(['bj'=>'北京','sh'=>'上海','gd'=>'广东'] as $code=>$city)foreach(['ct'=>'电信','cu'=>'联通','cm'=>'移动'] as $isp=>$name)$out[]=['name'=>$city.$name,'host'=>"$code-$isp-v4.ip.zstaticcdn.com",'host6'=>"$code-$isp-v6.ip.zstaticcdn.com"];
        return $out;
    }
    public function createMachine(string $name,?string $inventoryKey=null,array $nodeIds=[]): array
    {
        if(trim($name)==='' || strlen($name)>120) throw new RuntimeException('机器名称无效',422);
        $id=Store::id();$secret=bin2hex(random_bytes(32));$profile=Store::id();$until=time()+1800;$bound=[];$skipped=[];
        Store::transaction($this->store->db,function()use($id,$name,$secret,$profile,$until,$inventoryKey,$nodeIds,&$bound,&$skipped){
            if($inventoryKey && Store::query($this->store->db,'SELECT 1 FROM machines WHERE inventory_key=? AND active=1',[$inventoryKey])->fetchColumn())throw new RuntimeException('该服务器已经接入监测，请使用现有探针',409);
            Store::query($this->store->db,'INSERT INTO machines(id,name,enroll_hash,enroll_until,inventory_key) VALUES(?,?,?,?,?)',[$id,$name,hash('sha256',$secret),$until,$inventoryKey]);
            Store::query($this->store->db,'INSERT INTO profiles(id,machine_id,name,created_at) VALUES(?,?,?,?)',[$profile,$id,'默认出口',time()]);
            foreach(array_unique($nodeIds) as $nodeId){if(Store::query($this->store->db,'SELECT 1 FROM bindings WHERE node_id=? AND valid_to IS NULL',[$nodeId])->fetchColumn()){$skipped[]=$nodeId;continue;}$boundAt=max(time(),(int)Store::query($this->store->db,'SELECT MAX(valid_from) FROM bindings WHERE node_id=?',[$nodeId])->fetchColumn()+1);Store::query($this->store->db,'INSERT INTO bindings VALUES(?,?,?,NULL)',[$nodeId,$profile,$boundAt]);$bound[]=$nodeId;}
        });
        return ['machine_id'=>$id,'profile_id'=>$profile,'enroll_token'=>$secret,'expires_at'=>$until,'bound_nodes'=>$bound,'skipped_nodes'=>$skipped];
    }
    public function enroll(string $secret,string $credential): array
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$credential))throw new RuntimeException('凭据格式错误',422);
        // Agent generates and fsyncs credential BEFORE enrolling; retrying after a lost response is safe.
        return Store::transaction($this->store->db,function()use($secret,$credential){
            $m=Store::query($this->store->db,'SELECT * FROM machines WHERE enroll_hash=?',[hash('sha256',$secret)])->fetch();
            if(!$m || !$m['active'] || $m['enroll_until']<time())throw new RuntimeException('注册码无效或过期',403);
            $hash=hash('sha256',$credential);
            if($m['token_hash'] && !hash_equals($m['token_hash'],$hash))throw new RuntimeException('注册码已使用',403);
            Store::query($this->store->db,'UPDATE machines SET token_hash=? WHERE id=?',[$hash,$m['id']]);
            return ['machine_id'=>$m['id']];
        });
    }
    public function authenticate(string $token): array
    {
        if(strlen($token)!==64)throw new RuntimeException('Agent认证失败',401);
        $m=Store::query($this->store->db,'SELECT * FROM machines WHERE token_hash=? AND active=1',[hash('sha256',$token)])->fetch();
        if(!$m)throw new RuntimeException('Agent认证失败',401);return $m;
    }
    public function plan(array $machine,array $caps=[]): array
    {
        $now=time();Store::query($this->store->db,'UPDATE machines SET last_seen=?,capabilities=? WHERE id=?',[$now,json_encode($caps),$machine['id']]);
        $profiles=Store::query($this->store->db,'SELECT id,name,source,interface FROM profiles WHERE machine_id=? AND active=1',[$machine['id']])->fetchAll();
        $targets=Store::query($this->store->db,'SELECT id,revision,name,host,port FROM targets WHERE active=1 ORDER BY id')->fetchAll();
        foreach($targets as &$t){$t['id']=(int)$t['id'];$t['revision']=(int)$t['revision'];$t['port']=(int)$t['port'];}unset($t);
        $tasks=Store::transaction($this->store->db,function()use($profiles,$now){
            $out=[];foreach($profiles as $p){foreach(Store::query($this->store->db,"SELECT * FROM tasks WHERE profile_id=? AND status='pending' AND expires_at>? AND lease_until<? LIMIT 4",[$p['id'],$now,$now]) as $t){Store::query($this->store->db,'UPDATE tasks SET lease_until=? WHERE id=?',[$now+120,$t['id']]);$out[]=['id'=>$t['id'],'profile_id'=>$t['profile_id'],'target_id'=>(int)$t['target_id'],'expires_at'=>(int)$t['expires_at']];}}return $out;
        });
        $routes=json_decode(Store::query($this->store->db,'SELECT value FROM state WHERE key=?',['route_targets'])->fetchColumn()?:json_encode($this->defaultRoutes()),true);
        return ['disabled_sources'=>json_decode(Store::query($this->store->db,'SELECT value FROM state WHERE key=?',['disabled_sources'])->fetchColumn()?:'[]',true),'schema_version'=>1,'server_time'=>$now,'expires_at'=>$now+86400,'interval'=>300,'heavy_paused'=>$this->store->capacity()['heavy_paused'],'profiles'=>$profiles,'targets'=>$targets,'route_targets'=>$routes,'tasks'=>$tasks];
    }
    public static function validHost(string $host): bool
    {
        if(filter_var($host,FILTER_VALIDATE_IP))return filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)!==false;
        return strlen($host)<=253 && preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/Di',$host) && !preg_match('/\.(localhost|local|internal|home|test|invalid)$/i',$host);
    }
    public function target(array $in): int
    {
        $host=strtolower(trim($in['host']??''));$port=(int)($in['port']??443);$name=trim($in['name']??'');
        if(!self::validHost($host)||$port<1||$port>65535||$name===''||strlen($name)>100)throw new RuntimeException('目标须为公网域名/IP、有效端口和名称',422);
        return Store::transaction($this->store->db,function()use($in,$host,$port,$name){
            $id=(int)($in['id']??0);$old=$id?Store::query($this->store->db,'SELECT * FROM targets WHERE id=?',[$id])->fetch():false;
            if($id&&!$old)throw new RuntimeException('目标不存在',404);
            $active=(int)(bool)($in['active']??true);
            if($active && (!$old || !$old['active']) && Store::query($this->store->db,'SELECT count(*) FROM targets WHERE active=1')->fetchColumn()>=20)throw new RuntimeException('最多20个启用目标',422);
            $rev=$old?(int)$old['revision']+1:1;
            if($old)Store::query($this->store->db,'UPDATE targets SET revision=?,name=?,host=?,port=?,active=? WHERE id=?',[$rev,$name,$host,$port,$active,$id]);
            else {Store::query($this->store->db,'INSERT INTO targets(revision,name,host,port,active,created_at) VALUES(?,?,?,?,?,?)',[$rev,$name,$host,$port,$active,time()]);$id=(int)$this->store->db->lastInsertId();}
            Store::query($this->store->db,'INSERT INTO target_versions VALUES(?,?,?)',[$id,$rev,json_encode(['id'=>$id,'revision'=>$rev,'name'=>$name,'host'=>$host,'port'=>$port])]);return $id;
        });
    }
    public function bind(int $node,string $profile): void
    {
        Store::transaction($this->store->db,function()use($node,$profile){
            if(!Store::query($this->store->db,'SELECT 1 FROM profiles WHERE id=? AND active=1',[$profile])->fetchColumn())throw new RuntimeException('出口不存在',404);
            $old=Store::query($this->store->db,'SELECT * FROM bindings WHERE node_id=? AND valid_to IS NULL',[$node])->fetch();if($old && $old['profile_id']===$profile)return;
            $at=max(time(),(int)($old['valid_from']??0)+1);
            Store::query($this->store->db,'UPDATE bindings SET valid_to=? WHERE node_id=? AND valid_to IS NULL',[$at,$node]);
            Store::query($this->store->db,'INSERT INTO bindings VALUES(?,?,?,NULL)',[$node,$profile,$at]);
        });
    }
    public function unbind(int $node):void
    {
        Store::transaction($this->store->db,function()use($node){
            $old=Store::query($this->store->db,'SELECT * FROM bindings WHERE node_id=? AND valid_to IS NULL',[$node])->fetch();
            if(!$old)throw new RuntimeException('节点尚未绑定',404);
            Store::query($this->store->db,'UPDATE bindings SET valid_to=? WHERE node_id=? AND valid_to IS NULL',[max(time(),(int)$old['valid_from']+1),$node]);
        });
    }
    public function manual(int $user,string $profile,int $target): array
    {
        return Store::transaction($this->store->db,function()use($user,$profile,$target){
            $now=time();if(!Store::query($this->store->db,'SELECT 1 FROM targets WHERE id=? AND active=1',[$target])->fetchColumn())throw new RuntimeException('目标未启用',422);
            $recent=Store::query($this->store->db,'SELECT * FROM tasks WHERE profile_id=? AND target_id=? AND created_at>? AND status!=? ORDER BY created_at DESC LIMIT 1',[$profile,$target,$now-300,'expired'])->fetch();
            if($recent)return $recent;
            $times=Store::query($this->store->db,'SELECT created_at FROM requests WHERE user_id=? AND created_at>?',[$user,$now-3600])->fetchAll(\PDO::FETCH_COLUMN);
            if(count($times)>=10 || ($times && max($times)>$now-60))throw new RuntimeException('补测请求过于频繁',429);
            $id=Store::id();Store::query($this->store->db,'INSERT INTO requests VALUES(?,?)',[$user,$now]);Store::query($this->store->db,'INSERT INTO tasks(id,profile_id,target_id,created_at,expires_at) VALUES(?,?,?,?,?)',[$id,$profile,$target,$now,$now+300]);
            return ['id'=>$id,'status'=>'pending','expires_at'=>$now+300];
        });
    }
    public static function redact(mixed $value): mixed
    {
        if(is_array($value)){foreach($value as $k=>$v){if(in_array(strtolower((string)$k),['raw','error_detail','command','token','interface','head','map','latitude','longitude','dms'],true)){unset($value[$k]);continue;}$value[$k]=self::redact($v);}return $value;}
        if(!is_string($value))return $value;
        if(filter_var($value,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)){ $parts=explode(':',$value);return implode(':',array_slice($parts,0,2)).':…'; }
        $value=preg_replace_callback('/[0-9a-fA-F]*:[0-9a-fA-F:]+/',fn($m)=>filter_var($m[0],FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)?implode(':',array_slice(explode(':',$m[0]),0,2)).':…':$m[0],$value);
        return preg_replace_callback('/\b(?:\d{1,3}\.){3}\d{1,3}\b/',fn($m)=>filter_var($m[0],FILTER_VALIDATE_IP)?implode('.',array_slice(explode('.',$m[0]),0,2)).'.*.*':$m[0],$value);
    }
}

<?php
declare(strict_types=1);
namespace Plugin\NodeQuality\Controllers;
use Illuminate\Http\Request;
use Plugin\NodeQuality\Services\{Store,Control,Theme,Inventory};
use App\Models\{User,Server,Plugin};
use App\Services\{UserService,ServerService};

class Api extends \App\Http\Controllers\Controller
{
    private function run(callable $fn)
    {
        if(!Plugin::where('code','node_quality')->where('is_enabled',true)->exists())return response()->json(['message'=>'插件未启用'],404);
        $lock=null;
        try{
            $s=new Store(storage_path('node-quality'));$lock=fopen($s->root.'/lifecycle.lock','c');
            if(!flock($lock,LOCK_SH|LOCK_NB))throw new \RuntimeException('维护中，请稍后重试',503);
            return response()->json(['data'=>$fn($s,new Control($s))])->header('Cache-Control','no-store');
        }catch(\Throwable $e){$code=(int)$e->getCode();if($code<400||$code>599)$code=503;return response()->json(['message'=>$code===503?'监测存储暂时不可用，请重试':$e->getMessage()],$code)->header('Retry-After','15');}
        finally{if($lock){flock($lock,LOCK_UN);fclose($lock);}}
    }
    private function userNodes():array
    {
        $u=\Illuminate\Support\Facades\Auth::guard('sanctum')->user();
        if(!$u || !(new UserService())->isAvailable($u))return [];
        $out=[];foreach(ServerService::getAvailableServers($u) as $n)$out[(int)$n['id']]=array_intersect_key($n,array_flip(['id','name','rate','tags','is_online','last_check_at']));return $out;
    }
    private function binding(Store $s,int $node):array
    {
        if(!isset($this->userNodes()[$node]))throw new \RuntimeException('节点不可访问',404);
        $b=Store::query($s->db,'SELECT * FROM bindings WHERE node_id=? AND valid_to IS NULL',[$node])->fetch();if(!$b)throw new \RuntimeException('节点尚未绑定探针',404);return $b;
    }
    public function enroll(Request $r){return $this->run(fn($s,$c)=>$c->enroll((string)$r->input('enroll_token'),(string)$r->input('credential')));}
    public function sync(Request $r){return $this->run(fn($s,$c)=>$c->plan($c->authenticate((string)$r->bearerToken()),array_intersect_key((array)$r->input('capabilities',[]),array_flip(['version','arch','nexttrace','quality','gaps']))));}
    public function ingest(Request $r)
    {
        return $this->run(function($s,$c)use($r){$m=$c->authenticate((string)$r->bearerToken());$frames=$r->input('frames',[]);if(!is_array($frames)||count($frames)>8||strlen($r->getContent())>4*1048576)throw new \RuntimeException('上报过大',413);
            $ack=[];$rejected=[];foreach($frames as $f){try{$ack[]=$s->ingest($f,$m['id']);}catch(\Throwable $e){if(in_array((int)$e->getCode(),[403,409,413,422]))$rejected[]=['id'=>$f['id']??'','status'=>$e->getCode()];else throw $e;}}return ['ack'=>$ack,'rejected'=>$rejected];});
    }
    public function nodes(Request $r)
    {
        return $this->run(function($s){$out=[];foreach($this->userNodes() as $n){$b=Store::query($s->db,'SELECT b.*,m.last_seen,m.active FROM bindings b JOIN profiles p ON p.id=b.profile_id JOIN machines m ON m.id=p.machine_id WHERE node_id=? AND valid_to IS NULL',[$n['id']])->fetch();$n['monitor']=$b?['agent_online'=>(bool)$b['active'] && $b['last_seen']>time()-90,'latest'=>$s->latest($b['profile_id']),'score'=>$this->score($s,$b['profile_id'])]:null;$out[]=Control::redact($n);}return ['is_admin'=>(bool)\Illuminate\Support\Facades\Auth::guard('sanctum')->user()?->is_admin,'admin_url'=>(bool)\Illuminate\Support\Facades\Auth::guard('sanctum')->user()?->is_admin?'/'.admin_setting('secure_path',admin_setting('frontend_admin_path',hash('crc32b',config('app.key')))).'#/plugins/node_quality/overview':null,'nodes'=>$out,'targets'=>Store::query($s->db,'SELECT id,revision,name FROM targets WHERE active=1')->fetchAll(),'scope'=>'机器出口，不代表用户完整代理链路'];});
    }
    private function score(Store $s,string $profile):array
    {
        // Last 24 complete UTC hours, not a partial first/last bucket.
        $to=intdiv(time(),3600)*3600-1;$from=$to+1-86400;
        $rows=$s->history($profile,$from,$to);$ok=0;$count=0;$slots=0;$families=[];
        foreach($rows as $row)foreach($row['data'] as $v){$ok+=$v['ok'];$count+=$v['count'];if($v['count']>0){$slots+=count($v['slots']);$families[$v['family']]=true;}}
        $targets=(int)Store::query($s->db,'SELECT count(*) FROM targets WHERE active=1')->fetchColumn();
        // Unsupported address families remain visible but are excluded from the availability denominator.
        $expected=max(1,$targets*count($families)*288);$coverage=min(1,$slots/$expected);
        return ['value'=>$coverage>=0.8&&$count>=20?round(100*$ok/$count,2):null,'coverage'=>round($coverage*100,1),'samples'=>$count,'from'=>$from,'to'=>$to];
    }
    public function detail(Request $r,int $node)
    {
        return $this->run(function($s)use($r,$node){$current=$this->binding($s,$node);$days=max(1,min(90,(int)$r->query('days',1)));$to=time();$from=$to-$days*86400;$raw=$r->query('resolution')==='raw';if($raw)$from=$to-86400;
            $rows=[];$events=[];$qualityHistory=[];
            foreach(Store::query($s->db,'SELECT * FROM bindings WHERE node_id=? AND valid_from<=? AND (valid_to IS NULL OR valid_to>=?) ORDER BY valid_from',[$node,$to,$from]) as $b){
                $lo=max($from,(int)$b['valid_from']);$hi=min($to,$b['valid_to']===null?$to:(int)$b['valid_to']-1);$qualityHistory=array_merge($qualityHistory,$s->qualityHistory($b['profile_id'],$lo,$hi));
                foreach($s->boundedHistory($b['profile_id'],$lo,$hi,$raw,2200) as $row){$row['binding_from']=(int)$b['valid_from'];$rows[]=$row;}
                foreach(Store::query($s->db,'SELECT observed_at,kind,body FROM events WHERE profile_id=? AND observed_at>=? AND observed_at<? ORDER BY observed_at LIMIT 200',[$b['profile_id'],$lo,$hi+1]) as $e){$e['body']=json_decode($e['body'],true);$events[]=$e;}
            }
            return Control::redact(['node_id'=>$node,'latest'=>$s->latest($current['profile_id']),'history'=>array_slice($rows,0,2200),'events'=>array_slice($events,0,200),'score'=>$this->score($s,$current['profile_id']),'resolution'=>$raw?'raw':'hour','quality_history'=>$qualityHistory,'targets'=>Store::query($s->db,'SELECT body FROM target_versions')->fetchAll(\PDO::FETCH_COLUMN)]);
        });
    }
    public function manual(Request $r,int $node){return $this->run(function($s,$c)use($r,$node){$b=$this->binding($s,$node);return $c->manual((int)\Illuminate\Support\Facades\Auth::guard('sanctum')->id(),$b['profile_id'],(int)$r->input('target_id'));});}
    public function task(Request $r,string $task){return $this->run(function($s)use($task){$t=Store::query($s->db,'SELECT * FROM tasks WHERE id=?',[$task])->fetch();if(!$t)throw new \RuntimeException('任务不存在',404);$allowed=false;foreach(array_keys($this->userNodes()) as $node)if(Store::query($s->db,'SELECT 1 FROM bindings WHERE node_id=? AND profile_id=? AND valid_to IS NULL',[$node,$t['profile_id']])->fetchColumn())$allowed=true;if(!$allowed)throw new \RuntimeException('任务不存在',404);return array_intersect_key($t,array_flip(['id','status','expires_at','result_id']));});}
    public function admin(Request $r)
    {
        return $this->run(function($s,$c){return ['inventory'=>Inventory::candidates($s),'admin_url'=>'/'.admin_setting('secure_path',admin_setting('frontend_admin_path',hash('crc32b',config('app.key')))).'#/plugins/node_quality/overview','tasks'=>Store::query($s->db,'SELECT id,profile_id,target_id,created_at,expires_at,status FROM tasks ORDER BY created_at DESC LIMIT 10')->fetchAll(),'machines'=>Store::query($s->db,'SELECT id,name,active,last_seen,capabilities,inventory_key FROM machines')->fetchAll(),'profiles'=>Store::query($s->db,'SELECT * FROM profiles')->fetchAll(),'targets'=>Store::query($s->db,'SELECT * FROM targets')->fetchAll(),'bindings'=>Store::query($s->db,'SELECT * FROM bindings WHERE valid_to IS NULL')->fetchAll(),'nodes'=>Server::query()->select(['id','name','machine_id'])->get()->toArray(),'disabled_sources'=>json_decode(Store::query($s->db,'SELECT value FROM state WHERE key=?',['disabled_sources'])->fetchColumn()?:'[]',true),'capacity'=>$s->capacity(),'route_targets'=>json_decode(Store::query($s->db,'SELECT value FROM state WHERE key=?',['route_targets'])->fetchColumn()?:json_encode($c->defaultRoutes()),true)];});
    }
    public function adminAction(Request $r,string $action)
    {
        return $this->run(function($s,$c)use($r,$action){switch($action){
            case 'machine':
                $key=$r->input('inventory_key');$name=trim((string)$r->input('name'));$nodeIds=[];
                if($key){$selected=Inventory::resolve($s,(string)$key);$allowed=array_map('intval',array_column($selected['nodes'],'id'));$submitted=$r->input('node_ids',$allowed);if(!is_array($submitted))throw new \RuntimeException('请选择要绑定的节点',422);$nodeIds=array_map('intval',$submitted);if(array_diff($nodeIds,$allowed))throw new \RuntimeException('所选节点不属于这台服务器',422);if($name==='')$name=mb_strcut($selected['name'],0,120,'UTF-8');}
                $m=$c->createMachine($name,$key?(string)$key:null,$nodeIds);$origin=rtrim($r->getSchemeAndHttpHost(),'/');$m['install_command']='curl -fsSL '.escapeshellarg($origin.'/plugins/node_quality/install.sh').' -o /tmp/node-quality-install.sh && sudo bash /tmp/node-quality-install.sh --panel '.escapeshellarg($origin).' --enroll-token '.escapeshellarg($m['enroll_token']);return $m;
            case 'rename':$name=trim((string)$r->input('name'));if($name===''||strlen($name)>120)throw new \RuntimeException('名称需要1至120字节',422);if(!Store::query($s->db,'SELECT 1 FROM machines WHERE id=?',[(string)$r->input('id')])->fetchColumn())throw new \RuntimeException('探针不存在',404);Store::query($s->db,'UPDATE machines SET name=? WHERE id=?',[$name,(string)$r->input('id')]);return true;
            case 'unbind':$c->unbind((int)$r->input('node_id'));return true;
            case 'revoke':Store::query($s->db,'UPDATE machines SET active=0,token_hash=NULL,enroll_hash=NULL WHERE id=?',[(string)$r->input('id')]);return true;
            case 'target':return ['id'=>$c->target($r->all())];
            case 'bind':if(!Server::find((int)$r->input('node_id')))throw new \RuntimeException('节点不存在',404);$c->bind((int)$r->input('node_id'),(string)$r->input('profile_id'));return true;
            case 'profile':$id=(string)$r->input('id');$source=trim((string)$r->input('source',''));$iface=trim((string)$r->input('interface',''));if(($source!==''&&!filter_var($source,FILTER_VALIDATE_IP))||($iface!==''&&!preg_match('/^[a-zA-Z0-9_.:-]{1,32}$/D',$iface)))throw new \RuntimeException('源地址或网卡无效',422);if($id===''){$machine=(string)$r->input('machine_id');if(!Store::query($s->db,'SELECT 1 FROM machines WHERE id=? AND active=1',[$machine])->fetchColumn())throw new \RuntimeException('机器不存在',404);$id=Store::id();Store::query($s->db,'INSERT INTO profiles(id,machine_id,name,source,interface,created_at) VALUES(?,?,?,?,?,?)',[$id,$machine,substr((string)$r->input('name','独立出口'),0,100),$source,$iface,time()]);}else{if(!Store::query($s->db,'SELECT 1 FROM profiles WHERE id=?',[$id])->fetchColumn())throw new \RuntimeException('出口不存在',404);$name=trim((string)$r->input('name'));if($name!==''){if(strlen($name)>100)throw new \RuntimeException('出口名称过长',422);Store::query($s->db,'UPDATE profiles SET name=?,source=?,interface=? WHERE id=?',[$name,$source,$iface,$id]);}else Store::query($s->db,'UPDATE profiles SET source=?,interface=? WHERE id=?',[$source,$iface,$id]);}return ['id'=>$id];
            case 'routes':$routes=$r->input('targets',[]);if(!is_array($routes)||count($routes)>9)throw new \RuntimeException('最多9个回程目标',422);foreach($routes as $t)if(!Control::validHost($t['host']??'')||empty($t['name'])||(!empty($t['host6'])&&!Control::validHost($t['host6'])))throw new \RuntimeException('回程目标无效',422);Store::query($s->db,'INSERT OR REPLACE INTO state VALUES(?,?)',['route_targets',json_encode($routes)]);return true;
            case 'sources':$disabled=$r->input('disabled',[]);$allowed=['maxmind','ipinfo','scamalytics','ipregistry','ipapi','abuseipdb','ip2location','dbip','ipdata','ipqs'];if(!is_array($disabled)||array_diff($disabled,$allowed))throw new \RuntimeException('无效来源',422);Store::query($s->db,'INSERT OR REPLACE INTO state VALUES(?,?)',['disabled_sources',json_encode(array_values($disabled))]);return true;
            case 'theme':Theme::apply(true);return true;
            default:throw new \RuntimeException('未知操作',404);
        }});
    }
}

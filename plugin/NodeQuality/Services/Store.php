<?php
declare(strict_types=1);
namespace Plugin\NodeQuality\Services;

use PDO;
use RuntimeException;

/** No Laravel dependencies: also exercised against real SQLite in CLI tests. */
final class Store
{
    public PDO $db;
    public function __construct(public string $root, private ?\Closure $diskProbe=null)
    {
        if (!extension_loaded('pdo_sqlite') || !extension_loaded('sqlite3') || !extension_loaded('zlib')) throw new RuntimeException('需要 pdo_sqlite、sqlite3、zlib');
        foreach (['', '/raw', '/rollups', '/backups'] as $dir) {
            if (!is_dir($root.$dir) && !mkdir($root.$dir, 0700, true)) throw new RuntimeException('无法创建监测目录');
        }
        if(in_array($this->filesystem(),['nfs','nfs4','cifs','smb3','9p'],true))throw new RuntimeException('监测SQLite必须放在本地文件系统，不能使用网络共享');
        $this->db = $this->open($root.'/metadata.sqlite');
        if(version_compare($this->db->query('SELECT sqlite_version()')->fetchColumn(),'3.35.0','<'))throw new RuntimeException('需要SQLite 3.35或更新版本');
        $this->db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS machines(id TEXT PRIMARY KEY,name TEXT NOT NULL,token_hash TEXT UNIQUE,enroll_hash TEXT UNIQUE,enroll_until INTEGER,active INTEGER NOT NULL DEFAULT 1,last_seen INTEGER DEFAULT 0,capabilities TEXT DEFAULT '{}');
CREATE TABLE IF NOT EXISTS profiles(id TEXT PRIMARY KEY,machine_id TEXT NOT NULL,name TEXT NOT NULL,source TEXT DEFAULT '',interface TEXT DEFAULT '',created_at INTEGER NOT NULL,active INTEGER DEFAULT 1);
CREATE TABLE IF NOT EXISTS bindings(node_id INTEGER NOT NULL,profile_id TEXT NOT NULL,valid_from INTEGER NOT NULL,valid_to INTEGER,PRIMARY KEY(node_id,valid_from));
CREATE INDEX IF NOT EXISTS binding_node ON bindings(node_id,valid_from,valid_to);
CREATE TABLE IF NOT EXISTS targets(id INTEGER PRIMARY KEY AUTOINCREMENT,revision INTEGER NOT NULL,name TEXT NOT NULL,host TEXT NOT NULL,port INTEGER NOT NULL,active INTEGER DEFAULT 1,created_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS target_versions(id INTEGER,revision INTEGER,body TEXT NOT NULL,PRIMARY KEY(id,revision));
CREATE TABLE IF NOT EXISTS tasks(id TEXT PRIMARY KEY,profile_id TEXT NOT NULL,target_id INTEGER NOT NULL,created_at INTEGER NOT NULL,expires_at INTEGER NOT NULL,lease_until INTEGER DEFAULT 0,status TEXT DEFAULT 'pending',result_id TEXT);
CREATE TABLE IF NOT EXISTS requests(user_id INTEGER NOT NULL,created_at INTEGER NOT NULL);
CREATE INDEX IF NOT EXISTS request_user ON requests(user_id,created_at);
CREATE TABLE IF NOT EXISTS latest(profile_id TEXT NOT NULL,kind TEXT NOT NULL,observed_at INTEGER NOT NULL,body BLOB NOT NULL,PRIMARY KEY(profile_id,kind));
CREATE TABLE IF NOT EXISTS events(id TEXT PRIMARY KEY,profile_id TEXT NOT NULL,observed_at INTEGER NOT NULL,kind TEXT NOT NULL,body TEXT NOT NULL);
CREATE INDEX IF NOT EXISTS event_profile ON events(profile_id,observed_at);
CREATE TABLE IF NOT EXISTS state(key TEXT PRIMARY KEY,value TEXT NOT NULL);
SQL);
        if(!in_array('inventory_key',array_column($this->db->query('PRAGMA table_info(machines)')->fetchAll(),'name'),true))self::transaction($this->db,function(){
            if(!in_array('inventory_key',array_column($this->db->query('PRAGMA table_info(machines)')->fetchAll(),'name'),true))$this->db->exec('ALTER TABLE machines ADD COLUMN inventory_key TEXT');
        });
        $this->db->exec('CREATE UNIQUE INDEX IF NOT EXISTS machine_inventory ON machines(inventory_key) WHERE active=1 AND inventory_key IS NOT NULL');
    }
    public static function id(): string { return bin2hex(random_bytes(16)); }
    private function filesystem(): string
    {
        $best='unknown';$length=0;$root=realpath($this->root)?:$this->root;
        foreach(@file('/proc/mounts',FILE_IGNORE_NEW_LINES)?:[] as $line){$p=preg_split('/\s+/',$line);if(count($p)<3)continue;$mount=str_replace('\\040',' ',$p[1]);if(($root===$mount||str_starts_with($root,rtrim($mount,'/').'/'))&&strlen($mount)>=$length){$length=strlen($mount);$best=$p[2];}}
        return $best;
    }
    public function open(string $path): PDO
    {
        $p = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $p->exec('PRAGMA busy_timeout=3000; PRAGMA journal_mode=WAL; PRAGMA synchronous=FULL; PRAGMA foreign_keys=ON;');
        chmod($path, 0600);
        return $p;
    }
    public static function query(PDO $p, string $sql, array $args=[]): \PDOStatement
    { $s=$p->prepare($sql); $s->execute($args); return $s; }
    public static function transaction(PDO $p, callable $fn): mixed
    {
        $p->exec('BEGIN IMMEDIATE');
        try { $v=$fn(); $p->exec('COMMIT'); return $v; }
        catch (\Throwable $e) { $p->exec('ROLLBACK'); throw $e; }
    }
    public function capacity(): array
    {
        [$free,$total]=$this->diskProbe ? ($this->diskProbe)($this->root) : [disk_free_space($this->root),disk_total_space($this->root)];
        if ($free===false || $total===false) throw new RuntimeException('无法检查磁盘容量');
        return ['free_bytes'=>$free,'heavy_paused'=>$free < 1073741824 || $free/max(1,$total)<0.1,'ingest_paused'=>$free<536870912,'filesystem'=>$this->filesystem(),'sqlite_version'=>$this->db->query('SELECT sqlite_version()')->fetchColumn(),'storage_path'=>$this->root,'maintenance_at'=>(int)(self::query($this->db,'SELECT value FROM state WHERE key=?',['maintenance_at'])->fetchColumn()?:0)];
    }
    public function raw(int $at): PDO
    {
        $p=$this->open($this->root.'/raw/'.gmdate('Y-m-d',$at).'.sqlite');
        $p->exec('CREATE TABLE IF NOT EXISTS frames(id TEXT PRIMARY KEY,profile_id TEXT NOT NULL,observed_at INTEGER NOT NULL,kind TEXT NOT NULL,body BLOB NOT NULL,pending INTEGER NOT NULL DEFAULT 1); CREATE INDEX IF NOT EXISTS frame_profile ON frames(profile_id,observed_at); CREATE INDEX IF NOT EXISTS frame_pending ON frames(pending);');
        return $p;
    }
    public function ingest(array $frame, string $machineId, ?int $now=null): string
    {
        $now ??= time();
        if ($this->capacity()['ingest_paused']) throw new RuntimeException('磁盘空间不足',507);
        foreach (['id','profile_id','observed_at','kind'] as $k) if (!isset($frame[$k])) throw new RuntimeException('缺少字段 '.$k,422);
        if (!preg_match('/^[a-f0-9]{32,64}$/D',(string)$frame['id'])) throw new RuntimeException('无效记录ID',422);
        $at=filter_var($frame['observed_at'],FILTER_VALIDATE_INT);
        if ($at===false || $at<$now-72*3600 || $at>$now+120) throw new RuntimeException('记录时间超出接收窗口',422);
        if (!in_array($frame['kind'],['scheduled','manual','quality','gap'],true)) throw new RuntimeException('无效记录类型',422);
        $profile=self::query($this->db,'SELECT * FROM profiles WHERE id=? AND machine_id=? AND active=1',[$frame['profile_id'],$machineId])->fetch();
        if (!$profile) throw new RuntimeException('出口不属于此机器',403);
        if (in_array($frame['kind'],['scheduled','manual'])) $this->validateSamples($frame);
        $json=json_encode($frame,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        if (strlen($json)>1048576) throw new RuntimeException('结果超过1MiB',413);
        $p=$this->raw($at);
        self::transaction($p,function()use($p,$frame,$at,$json){
            $old=self::query($p,'SELECT body FROM frames WHERE id=?',[$frame['id']])->fetchColumn();
            if ($old!==false && gzdecode($old)!==$json) throw new RuntimeException('记录ID冲突',409);
            self::query($p,'INSERT OR IGNORE INTO frames(id,profile_id,observed_at,kind,body) VALUES(?,?,?,?,?)',[$frame['id'],$frame['profile_id'],$at,$frame['kind'],gzencode($json,6)]);
        });
        // Raw commit is the acknowledgement boundary. Recovery never depends on this attempt.
        try { $this->derive($frame); self::query($p,'UPDATE frames SET pending=0 WHERE id=?',[$frame['id']]); } catch (\Throwable $e) { /* durable outbox, repaired by maintenance */ }
        return $frame['id'];
    }
    private function validateSamples(array $f): void
    {
        if (!isset($f['results']) || !is_array($f['results']) || count($f['results'])>40) throw new RuntimeException('无效探测列表',422);
        $seen=[];
        foreach($f['results'] as $r){
            $key=($r['target_id']??'').':'.($r['family']??'');
            if(isset($seen[$key]) || !in_array($r['family']??0,[4,6],true)) throw new RuntimeException('重复或无效地址族',422);
            $seen[$key]=true;
            if(!self::query($this->db,'SELECT 1 FROM target_versions WHERE id=? AND revision=?',[$r['target_id']??0,$r['revision']??0])->fetchColumn()) throw new RuntimeException('未知目标版本',422);
            foreach(['icmp'=>4,'tcp'=>3] as $proto=>$max){
                if(!isset($r[$proto]) || !is_array($r[$proto]) || count($r[$proto])>$max) throw new RuntimeException('无效样本',422);
                foreach($r[$proto] as $sample){
                    if(!in_array($sample['status']??'',['ok','timeout','refused','dns_error','unsupported','unavailable','error','blocked'],true)) throw new RuntimeException('无效样本状态',422);
                    if(($sample['status']??'')==='ok' && (!is_numeric($sample['ms']??null) || $sample['ms']<0 || $sample['ms']>60000)) throw new RuntimeException('无效时延',422);
                }
            }
        }
    }
    private function derive(array $f): void
    {
        self::transaction($this->db,function()use($f){
            $kind=$f['kind']==='manual'?'manual:'.($f['results'][0]['target_id']??0):$f['kind'];
            self::query($this->db,'INSERT INTO latest VALUES(?,?,?,?) ON CONFLICT(profile_id,kind) DO UPDATE SET observed_at=excluded.observed_at,body=excluded.body WHERE excluded.observed_at>latest.observed_at',[$f['profile_id'],$kind,$f['observed_at'],gzencode(json_encode($f,JSON_THROW_ON_ERROR),6)]);
            if($f['kind']==='manual' && isset($f['task_id'])) self::query($this->db,'UPDATE tasks SET status=?,result_id=? WHERE id=? AND profile_id=?',['complete',$f['id'],$f['task_id'],$f['profile_id']]);
            if($f['kind']==='quality') $this->qualityEvents($f);
            if($f['kind']==='gap') self::query($this->db,'INSERT OR IGNORE INTO events VALUES(?,?,?,?,?)',[$f['id'],$f['profile_id'],$f['observed_at'],'data_gap','{}']);
        });
        if($f['kind']==='scheduled') $this->rollup($f);
    }
    private function qualityEvents(array $f): void
    {
        foreach(($f['quality']['egress']??[]) as $family=>$ip){
            if(!filter_var($ip,FILTER_VALIDATE_IP)) continue;
            $key='ip:'.$f['profile_id'].':'.$family;
            $old=json_decode(self::query($this->db,'SELECT value FROM state WHERE key=?',[$key])->fetchColumn()?:'null',true);
            if($old && $old['at']>=$f['observed_at']) continue;
            if($old && $old['ip']!==$ip) self::query($this->db,'INSERT OR IGNORE INTO events VALUES(?,?,?,?,?)',[hash('sha256',$f['id'].$key),$f['profile_id'],$f['observed_at'],'ip_changed',json_encode(['family'=>$family,'before'=>$old['ip'],'after'=>$ip])]);
            self::query($this->db,'INSERT OR REPLACE INTO state VALUES(?,?)',[$key,json_encode(['ip'=>$ip,'at'=>$f['observed_at']])]);
        }
        foreach(($f['quality']['routes']??[]) as $route){
            if(($route['status']??'')!=='ok' || empty($route['path'])) continue;
            $key='route:'.$f['profile_id'].':'.($route['name']??'').':'.($route['family']??4);
            $hash=hash('sha256',json_encode($route['path']));
            $s=json_decode(self::query($this->db,'SELECT value FROM state WHERE key=?',[$key])->fetchColumn()?:'null',true);
            if($s && ($s['at']??0)>=$f['observed_at']) continue;
            if(!$s) $s=['stable'=>$hash,'candidate'=>null,'count'=>0];
            if($hash===$s['stable']) {$s['candidate']=null;$s['count']=0;}
            else {$s['count']=($s['candidate']===$hash)?$s['count']+1:1;$s['candidate']=$hash; if($s['count']>=2){self::query($this->db,'INSERT OR IGNORE INTO events VALUES(?,?,?,?,?)',[hash('sha256',$f['id'].$key),$f['profile_id'],$f['observed_at'],'route_changed',json_encode(['name'=>$route['name'],'family'=>$route['family']??4])]);$s['stable']=$hash;$s['count']=0;}}
            $s['at']=$f['observed_at'];self::query($this->db,'INSERT OR REPLACE INTO state VALUES(?,?)',[$key,json_encode($s)]);
        }
    }
    private function rollup(array $f): void
    {
        $p=$this->open($this->root.'/rollups/'.gmdate('Y-m',$f['observed_at']).'.sqlite');
        $p->exec('CREATE TABLE IF NOT EXISTS applied(id TEXT PRIMARY KEY); CREATE TABLE IF NOT EXISTS hours(profile_id TEXT,hour INTEGER,body BLOB,PRIMARY KEY(profile_id,hour));');
        self::transaction($p,function()use($p,$f){
            if(self::query($p,'SELECT 1 FROM applied WHERE id=?',[$f['id']])->fetchColumn())return;
            $hour=intdiv($f['observed_at'],3600)*3600;
            $prev=self::query($p,'SELECT body FROM hours WHERE profile_id=? AND hour=?',[$f['profile_id'],$hour])->fetchColumn();
            $data=$prev?json_decode(gzdecode($prev),true):[];
            foreach($f['results'] as $r){
                $key=$r['target_id'].':'.$r['revision'].':'.$r['family'];
                $s=$data[$key]??['target_id'=>$r['target_id'],'revision'=>$r['revision'],'family'=>$r['family'],'count'=>0,'ok'=>0,'sum_ms'=>0,'max_ms'=>null,'icmp_sent'=>0,'icmp_ok'=>0,'icmp_sum_ms'=>0,'slots'=>[]];
                $slot=(string)(intdiv($f['observed_at'],300)*300);
                if(isset($s['slots'][$slot]))continue;
                $s['slots'][$slot]=true;
                foreach($r['tcp'] as $a){if(in_array($a['status'],['unsupported','unavailable'],true))continue;$s['count']++;if($a['status']==='ok'){$s['ok']++;$s['sum_ms']+=$a['ms'];$s['max_ms']=max($s['max_ms']??0,$a['ms']);}}
                foreach($r['icmp'] as $a){if(in_array($a['status'],['unsupported','unavailable'],true))continue;$s['icmp_sent']++;if($a['status']==='ok'){$s['icmp_ok']++;$s['icmp_sum_ms']+=$a['ms'];}}
                $data[$key]=$s;
            }
            self::query($p,'INSERT OR REPLACE INTO hours VALUES(?,?,?)',[$f['profile_id'],$hour,gzencode(json_encode($data),6)]);
            self::query($p,'INSERT INTO applied VALUES(?)',[$f['id']]);
        });
    }
    public function latest(string $profile): array
    {
        $out=[];foreach(self::query($this->db,'SELECT kind,body FROM latest WHERE profile_id=?',[$profile]) as $r)$out[$r['kind']]=json_decode(gzdecode($r['body']),true);
        if(isset($out['scheduled'])){
            $merged=[];foreach($out['scheduled']['results'] as $r)$merged[$r['target_id'].':'.$r['family']]=$r;
            foreach($out as $kind=>$frame){if(!str_starts_with($kind,'manual:')||$frame['observed_at']<=$out['scheduled']['observed_at'])continue;
                foreach($frame['results'] as $r){$r['observed_at']=$frame['observed_at'];$merged[$r['target_id'].':'.$r['family']]=$r;}
            }
            $out['scheduled']['results']=array_values($merged);
        }
        return $out;
    }
    public function history(string $profile,int $from,int $to,bool $raw=false,int $limit=1000): array
    {
        if($to<$from || $to-$from>366*86400 || ($raw && $to-$from>86400)) throw new RuntimeException('查询范围过大',422);
        $out=[];$dir=$raw?'raw':'rollups';$format=$raw?'Y-m-d':'Y-m';$seen=[];
        for($day=intdiv($from,86400)*86400;$day<=$to;$day+=86400){
            $path=$this->root.'/'.$dir.'/'.gmdate($format,$day).'.sqlite';if(isset($seen[$path])||!is_file($path))continue;$seen[$path]=true;
            $p=$this->open($path);$sql=$raw?'SELECT observed_at AS at,body FROM frames WHERE profile_id=? AND observed_at>=? AND observed_at<=? ORDER BY observed_at LIMIT ?':'SELECT hour AS at,body FROM hours WHERE profile_id=? AND hour>=? AND hour<=? ORDER BY hour LIMIT ?';
            $stmt=$p->prepare($sql);foreach([$profile,$from,$to,$limit-count($out)] as $i=>$v)$stmt->bindValue($i+1,$v,is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);$stmt->execute();
            foreach($stmt as $r)$out[]=['at'=>(int)$r['at'],'data'=>json_decode(gzdecode($r['body']),true)];
            if(count($out)>=$limit)break;
        }
        return $out;
    }
    public function boundedHistory(string $profile,int $from,int $to,bool $raw=false,int $limit=2200):array
    {
        if($raw)return $this->history($profile,$from,$to,true,$limit);
        if($to<$from)return [];
        $first=intdiv($from,3600)*3600;$last=intdiv($to,3600)*3600;$rows=[];
        if($from>$first || $first===$last){$piece=$this->hourSlice($profile,$from,min($to,$first+3599));if($piece)$rows[]=$piece;}
        $fullStart=$from===$first?$first:$first+3600;
        if($fullStart<$last)$rows=array_merge($rows,$this->history($profile,$fullStart,$last-1,false,$limit));
        if($first!==$last){$piece=$this->hourSlice($profile,$last,$to);if($piece)$rows[]=$piece;}
        return array_slice($rows,0,$limit);
    }
    private function hourSlice(string $profile,int $from,int $to):?array
    {
        $data=[];
        foreach($this->history($profile,$from,$to,true,1000) as $row){$f=$row['data'];if(($f['kind']??'')!=='scheduled')continue;
            foreach($f['results'] as $r){$key=$r['target_id'].':'.$r['revision'].':'.$r['family'];$v=$data[$key]??['target_id'=>$r['target_id'],'revision'=>$r['revision'],'family'=>$r['family'],'count'=>0,'ok'=>0,'sum_ms'=>0,'icmp_sent'=>0,'icmp_ok'=>0,'icmp_sum_ms'=>0,'slots'=>[]];$slot=(string)(intdiv($f['observed_at'],300)*300);if(isset($v['slots'][$slot]))continue;$v['slots'][$slot]=true;
                foreach($r['tcp'] as $a){if(in_array($a['status'],['unsupported','unavailable'],true))continue;$v['count']++;if($a['status']==='ok'){$v['ok']++;$v['sum_ms']+=$a['ms'];}}
                foreach($r['icmp'] as $a){if(in_array($a['status'],['unsupported','unavailable'],true))continue;$v['icmp_sent']++;if($a['status']==='ok'){$v['icmp_ok']++;$v['icmp_sum_ms']+=$a['ms'];}}
                $data[$key]=$v;
            }
        }
        return $data?['at'=>intdiv($from,3600)*3600,'data'=>$data]:null;
    }
    public function maintenance(): array
    {
        $repaired=0;$start=microtime(true);
        foreach(glob($this->root.'/raw/*.sqlite') as $file){
            if(microtime(true)-$start>15)break;
            $p=$this->open($file);
            foreach(self::query($p,'SELECT id,body FROM frames WHERE pending=1 LIMIT 100') as $r){$this->derive(json_decode(gzdecode($r['body']),true));self::query($p,'UPDATE frames SET pending=0 WHERE id=?',[$r['id']]);$repaired++;}
            $p->exec('PRAGMA wal_checkpoint(PASSIVE)');
        }
        self::query($this->db,'DELETE FROM requests WHERE created_at<?',[time()-3600]);
        self::query($this->db,"UPDATE tasks SET status='expired' WHERE expires_at<? AND status='pending'",[time()]);
        self::query($this->db,'DELETE FROM tasks WHERE created_at<?',[time()-7*86400]);
        self::query($this->db,'DELETE FROM events WHERE observed_at<?',[time()-90*86400]);
        // Serialize maintenance/backup/deletion; readers use the same shared lock in controllers.
        foreach(['raw'=>gmdate('Y-m-d',time()-90*86400),'rollups'=>gmdate('Y-m',time()-366*86400)] as $dir=>$cutoff){
            foreach(glob($this->root.'/'.$dir.'/*.sqlite') as $file){
                if(basename($file,'.sqlite') >= $cutoff)continue;
                $p=$this->open($file);$p->exec('PRAGMA wal_checkpoint(TRUNCATE)');$p=null;unlink($file);
            }
        }
        self::query($this->db,'INSERT OR REPLACE INTO state VALUES(?,?)',['maintenance_at',(string)time()]);
        return ['repaired'=>$repaired,'capacity'=>$this->capacity()];
    }
    public function qualityHistory(string $profile,int $from,int $to):array
    {
        if($to-$from>90*86400)throw new RuntimeException('最多90天',422);$out=[];
        for($day=intdiv($from,86400)*86400;$day<=$to;$day+=86400){$file=$this->root.'/raw/'.gmdate('Y-m-d',$day).'.sqlite';if(!is_file($file))continue;$p=$this->open($file);
            foreach(self::query($p,"SELECT observed_at,body FROM frames WHERE profile_id=? AND kind='quality' AND observed_at>=? AND observed_at<=? ORDER BY observed_at LIMIT 4",[$profile,$from,$to]) as $r){$f=json_decode(gzdecode($r['body']),true);$q=$f['quality']??[];$reports=[];foreach(($q['reports']??[]) as $af=>$v)$reports[$af]=['status'=>$v['status']??'unknown','source'=>$v['source']??'IPQuality','report'=>array_intersect_key($v['report']??[],array_flip(['Score','Info','Type','Media']))];$out[]=['at'=>(int)$r['observed_at'],'egress'=>$q['egress']??[],'reports'=>$reports];}
        }return array_slice($out,-180);
    }
    public function backup(): string
    {
        $totalBytes=0;foreach(array_merge([$this->root.'/metadata.sqlite'],glob($this->root.'/raw/*.sqlite'),glob($this->root.'/rollups/*.sqlite')) as $source)$totalBytes+=filesize($source);if(disk_free_space($this->root)<$totalBytes*1.2+536870912)throw new RuntimeException('备份空间不足',507);
        $dest=$this->root.'/backups/'.gmdate('Ymd-His').'-'.substr(self::id(),0,8);mkdir($dest,0700);
        foreach(array_merge([$this->root.'/metadata.sqlite'],glob($this->root.'/raw/*.sqlite'),glob($this->root.'/rollups/*.sqlite')) as $file){
            $relative=substr($file,strlen($this->root)+1);if(!is_dir(dirname($dest.'/'.$relative)))mkdir(dirname($dest.'/'.$relative),0700,true);
            $src=new \SQLite3($file,SQLITE3_OPEN_READONLY);$dst=new \SQLite3($dest.'/'.$relative);if(!$src->backup($dst))throw new RuntimeException('备份失败');$dst->close();$src->close();chmod($dest.'/'.$relative,0600);
        }
        return $dest;
    }
}

<?php
namespace Plugin\NodeQuality\Commands;
use Illuminate\Console\Command;
use Plugin\NodeQuality\Runtime\V040\Services\Store;
class PurgeHistory extends Command
{
    protected $signature='node-quality:purge-history {--confirm=}';
    protected $description='清空监测历史，保留机器、绑定、目标、凭据和备份';
    public function handle():int
    {
        if($this->option('confirm')!=='DELETE-HISTORY'){$this->error('需要 --confirm=DELETE-HISTORY；操作不可撤销');return 1;}
        $s=new Store(storage_path('node-quality'));$lock=fopen($s->root.'/lifecycle.lock','c');if(!flock($lock,LOCK_EX|LOCK_NB)){$this->error('存储忙，请稍后重试');return 1;}
        try{
            foreach(['raw','rollups'] as $dir)foreach(glob($s->root.'/'.$dir.'/*.sqlite') as $file){$p=$s->open($file);$p->exec('PRAGMA wal_checkpoint(TRUNCATE)');$p=null;unlink($file);}
            Store::transaction($s->db,function()use($s){$s->db->exec("DELETE FROM latest; DELETE FROM events; UPDATE tasks SET status='expired'; DELETE FROM state WHERE key LIKE 'ip:%' OR key LIKE 'route:%';");});
            $this->info('历史已清空；配置和备份保留');return 0;
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
}

<?php
namespace Plugin\NodeQuality\Commands;
use Illuminate\Console\Command;
use Plugin\NodeQuality\Runtime\V040\Services\Store;
use Plugin\NodeQuality\Runtime\V040\Services\Theme;
class Maintain extends Command
{
    protected $signature='node-quality:maintain {--backup} {--check}';
    protected $description='节点质量：修复派生数据、汇总、清理、检查或一致性备份';
    public function handle():int
    {
        try{
            $s=new Store(storage_path('node-quality'));$lock=fopen($s->root.'/lifecycle.lock','c');
            if(!flock($lock,LOCK_EX|LOCK_NB)){ $this->warn('维护正在执行');return 0; }
            try{$result=$this->option('check')?$s->capacity():($this->option('backup')?['backup'=>$s->backup()]:$s->maintenance());Theme::apply(true);$this->line(json_encode($result,JSON_UNESCAPED_UNICODE));}
            finally{flock($lock,LOCK_UN);fclose($lock);}return 0;
        }catch(\Throwable $e){$this->error($e->getMessage());return 1;}
    }
}

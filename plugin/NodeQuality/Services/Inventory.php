<?php
declare(strict_types=1);
namespace Plugin\NodeQuality\Services;
use App\Models\Server;
use App\Models\ServerMachine;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
final class Inventory
{
    public static function candidates(Store $store):array
    {
        $nodes=Server::query()->orderBy('id')->get(['id','name','host','type','machine_id'])->toArray();
        $linked=Store::query($store->db,'SELECT id,inventory_key FROM machines WHERE active=1 AND inventory_key IS NOT NULL')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $byKey=array_flip($linked);$out=[];
        if(Schema::hasTable('v2_server_machine'))foreach(ServerMachine::query()->orderBy('id')->get(['id','name','is_active','last_seen_at']) as $m){
            $children=array_values(array_filter($nodes,fn($n)=>(int)$n['machine_id']===(int)$m->id));$key='machine:'.$m->id;
            $out[]=['key'=>$key,'source'=>'machine','name'=>$m->name,'active'=>(bool)$m->is_active,'last_seen'=>$m->last_seen_at,'nodes'=>$children,'monitor_machine_id'=>$byKey[$key]??null];
        }
        foreach($nodes as $n)if(empty($n['machine_id'])){$key='node:'.$n['id'];$out[]=['key'=>$key,'source'=>'legacy_node','name'=>$n['name'],'active'=>true,'nodes'=>[$n],'monitor_machine_id'=>$byKey[$key]??null];}
        return $out;
    }
    public static function resolve(Store $store,string $key):array
    {
        foreach(self::candidates($store) as $c)if($c['key']===$key){if(!$c['active'])throw new RuntimeException('该服务器已停用',422);return $c;}
        throw new RuntimeException('所选服务器或节点已不存在，请刷新后重试',404);
    }
}

<?php
// Real Laravel router, Eloquent models, and XBoard authorization middleware; fixture auth guard only.
$xboard=getenv('XBOARD_PATH')?:__DIR__.'/../../Xboard';
$loader=require $xboard.'/vendor/autoload.php';$loader->addPsr4('Plugin\\NodeQuality\\',__DIR__.'/../plugin/NodeQuality');
$app=new Illuminate\Foundation\Application(realpath($xboard));
$tmp=sys_get_temp_dir().'/nq-laravel-'.bin2hex(random_bytes(5));mkdir($tmp,0700);$app->useStoragePath($tmp);if(getenv('NQ_TEST_LEGACY_RUNTIME'))new Plugin\NodeQuality\Services\Store($tmp.'/node-quality');
$app->instance('config',new Illuminate\Config\Repository(['app'=>['key'=>'base64:'.base64_encode(str_repeat('x',32)),'cipher'=>'AES-256-CBC','debug'=>true],'database'=>['default'=>'sqlite','connections'=>['sqlite'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'']]],'cache'=>['default'=>'array','stores'=>['array'=>['driver'=>'array'],'redis'=>['driver'=>'array']]],'view'=>['paths'=>[],'compiled'=>$tmp],'logging'=>['default'=>'stderr','channels'=>['stderr'=>['driver'=>'monolog','handler'=>Monolog\Handler\StreamHandler::class,'with'=>['stream'=>'php://stderr']]]]]));
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
class_alias(Illuminate\Support\Facades\Auth::class,"Auth");
foreach([Illuminate\Filesystem\FilesystemServiceProvider::class,Illuminate\Events\EventServiceProvider::class,Illuminate\Database\DatabaseServiceProvider::class,Illuminate\Cache\CacheServiceProvider::class,Illuminate\Routing\RoutingServiceProvider::class,Illuminate\View\ViewServiceProvider::class,Illuminate\Log\LogServiceProvider::class] as $provider)$app->register($provider);
$guard=new class {public $u=null;function user(){return $this->u;}function check(){return $this->u!==null;}function id(){return $this->u?->id;}};
$app->instance('auth',new class($guard){function __construct(public $g){}function guard($name=null){return $this->g;}});$app->boot();
$db=$app['db']->connection();
$db->statement('CREATE TABLE v2_plugins(id INTEGER PRIMARY KEY,code TEXT,name TEXT,version TEXT,type TEXT,is_enabled INTEGER,config TEXT,installed_at TEXT,created_at TEXT,updated_at TEXT)');
$db->statement('CREATE TABLE v2_server(id INTEGER PRIMARY KEY,name TEXT,host TEXT,group_ids TEXT,show INTEGER,transfer_enable INTEGER,u INTEGER,d INTEGER,sort INTEGER,port TEXT,type TEXT,rate REAL,tags TEXT,protocol_settings TEXT,created_at TEXT,updated_at TEXT,machine_id INTEGER)');
$db->statement('CREATE TABLE v2_server_machine(id INTEGER PRIMARY KEY,name TEXT,is_active INTEGER,last_seen_at INTEGER,token TEXT)');
$db->table('v2_server_machine')->insert(['id'=>10,'name'=>'Existing host','is_active'=>1,'last_seen_at'=>time(),'token'=>'core-token-must-stay-secret']);
$db->statement('CREATE TABLE v2_settings(id INTEGER PRIMARY KEY,name TEXT UNIQUE,value TEXT,created_at TEXT,updated_at TEXT)');
$db->table('v2_plugins')->insert(['code'=>'node_quality','name'=>'Node Quality','version'=>'0.1.0','type'=>'feature','is_enabled'=>1]);
foreach([1,2] as $id)$db->table('v2_server')->insert(['id'=>$id,'name'=>'Node '.$id,'group_ids'=>json_encode([(string)$id]),'show'=>1,'transfer_enable'=>0,'u'=>0,'d'=>0,'sort'=>$id,'port'=>'443','type'=>'vless','rate'=>1,'tags'=>'[]','protocol_settings'=>'{}','host'=>'node'.$id.'.example.com','machine_id'=>$id===1?10:null]);
$router=$app['router'];$router->aliasMiddleware('user',App\Http\Middleware\User::class);$router->aliasMiddleware('admin',App\Http\Middleware\Admin::class);$router->aliasMiddleware('throttle',Illuminate\Routing\Middleware\ThrottleRequests::class);
require __DIR__.'/../plugin/NodeQuality/routes/api.php';
function req($path,$method='GET',$data=[]){global $app,$router;$r=Illuminate\Http\Request::create('/api/v1/node-quality/'.$path,$method,$data);$app->instance('request',$r);return $router->dispatch($r);}
function ok($v,$m){if(!$v)throw new RuntimeException($m);echo "PASS $m\n";}
$guard->u=new App\Models\User(['id'=>1,'group_id'=>1,'uuid'=>'fixture-secret','banned'=>0,'transfer_enable'=>100,'expired_at'=>time()+3600,'is_admin'=>false]);
$r=req('nodes');ok($r->getStatusCode()===200,'Laravel loads plugin routes');$d=json_decode($r->getContent(),true);ok(count($d['data']['nodes'])===1 && $d['data']['nodes'][0]['id']===1,'XBoard group visibility enforced');ok(!str_contains($r->getContent(),'fixture-secret'),'subscription credential excluded');
ok(req('nodes/2')->getStatusCode()===404,'cross-plan history denied');ok(req('nodes/2/probe','POST',['target_id'=>1])->getStatusCode()===404,'cross-plan manual probe denied');ok(req('admin')->getStatusCode()===403,'non-admin management denied');
$guard->u->is_admin=true;ok(req('admin')->getStatusCode()===200,'admin management allowed');
$created=json_decode(req('admin/machine','POST',['name'=>'Integration probe'])->getContent(),true)['data'];
ok(req('admin/rename','POST',['id'=>$created['machine_id'],'name'=>'Renamed probe'])->getStatusCode()===200,'admin can rename probe');
ok(req('admin/profile','POST',['id'=>$created['profile_id'],'name'=>'Renamed outlet','source'=>'','interface'=>''])->getStatusCode()===200,'admin can edit outlet name');
ok(req('admin/bind','POST',['node_id'=>1,'profile_id'=>$created['profile_id']])->getStatusCode()===200,'admin binding action');
ok(req('admin/unbind','POST',['node_id'=>1])->getStatusCode()===200,'admin unbinding action');
admin_setting(['theme_Xboard'=>['custom_html'=>'<p>existing</p>']]);Plugin\NodeQuality\Services\Theme::apply(true);Plugin\NodeQuality\Services\Theme::apply(true);$html=admin_setting('theme_Xboard')['custom_html'];ok(substr_count($html,'node-quality:start')===1 && str_contains($html,'existing'),'theme merge idempotent');Plugin\NodeQuality\Services\Theme::apply(false);ok(admin_setting('theme_Xboard')['custom_html']==='<p>existing</p>','theme removal preserves existing content');
$inventory=json_decode(req('admin')->getContent(),true)['data']['inventory'];ok(count($inventory)===2,'inventory includes existing machine and legacy node');ok(!str_contains(json_encode($inventory),'core-token-must-stay-secret'),'inventory never exposes core machine token');
$joined=json_decode(req('admin/machine','POST',['inventory_key'=>'machine:10','node_ids'=>[1]])->getContent(),true);ok(!empty($joined['data']['machine_id']),'create probe from existing machine');
ok(req('admin/machine','POST',['inventory_key'=>'machine:10','node_ids'=>[1]])->getStatusCode()===409,'duplicate inventory registration rejected');
ok(req('admin/machine','POST',['inventory_key'=>'node:2','node_ids'=>[1]])->getStatusCode()===422,'cannot bind another inventory node during registration');
$app['view']->addLocation(realpath($xboard.'/resources/views'));$app->register(Plugin\NodeQuality\Providers\PluginServiceProvider::class);
$html=$app['view']->make('admin',['title'=>'Test admin','version'=>'fixture','logo'=>'','secure_path'=>'admin-test'])->render();ok(str_contains($html,'id="root"')&&substr_count($html,'admin-bridge.js?v=0.4.0')===1,'provider preserves original admin shell and injects bridge once');
$db->table('v2_plugins')->update(['is_enabled'=>0]);ok(req('nodes')->getStatusCode()===404,'disabled plugin denies stale loaded routes');
echo "TEST_STORAGE=$tmp\n";
$native=$app['view']->make('admin',['title'=>'Test admin','version'=>'fixture','logo'=>'','secure_path'=>'admin-test'])->render();ok(!str_contains($native,'admin-bridge.js'),'disabled plugin leaves admin shell unchanged');

<?php
namespace Plugin\NodeQuality\Providers;
use App\Models\Plugin;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Plugin\NodeQuality\Runtime\V040\Services\Theme;
class AdminShellV040 extends ServiceProvider
{
    public function boot():void
    {
        // Registered once by Laravel, even under Octane. No core template modifications.
        if(Plugin::where('code','node_quality')->where('is_enabled',true)->exists())Theme::apply(true);
        View::composer('theme::Xboard.dashboard',function($view){
            if(!Plugin::where('code','node_quality')->where('is_enabled',true)->exists())return;
            $config=$view->getData()['theme_config']??[];$config['custom_html']=Theme::merge($config['custom_html']??'',true);$view->with('theme_config',$config);
        });
        View::composer('admin',function($view){
            if(Plugin::where('code','node_quality')->where('is_enabled',true)->exists())$view->setPath(__DIR__.'/../resources/views/admin-shell.blade.php');
        });
    }
}

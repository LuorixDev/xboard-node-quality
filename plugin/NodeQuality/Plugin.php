<?php
namespace Plugin\NodeQuality;
use App\Services\Plugin\AbstractPlugin;
use Illuminate\Console\Scheduling\Schedule;
use Plugin\NodeQuality\Runtime\V040\Services\Store;
use Plugin\NodeQuality\Runtime\V040\Services\Theme;
class Plugin extends AbstractPlugin
{
    public function install():void {new Store(storage_path('node-quality'));Theme::apply(true);}
    public function boot():void
    {
        // No writes on normal requests; manager loads routes automatically.
    }
    public function cleanup():void {Theme::apply(false);}
    public function update(string $oldVersion,string $newVersion):void
    {
        new Store(storage_path('node-quality'));
        \Illuminate\Support\Facades\File::copyDirectory(__DIR__.'/resources/assets',public_path('plugins/node_quality'));
        Theme::apply(true);
    }
    public function schedule(Schedule $schedule):void
    {
        $schedule->command('node-quality:maintain')->everyMinute()->withoutOverlapping(5);
    }
}

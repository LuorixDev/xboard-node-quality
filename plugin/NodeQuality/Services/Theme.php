<?php
namespace Plugin\NodeQuality\Services;
final class Theme
{
    public static function merge(string $html,bool $enable):string
    {
        $html=preg_replace('/<!-- node-quality:start -->.*?<!-- node-quality:end -->/s','',$html);
        if($enable)$html.='<!-- node-quality:start --><script defer src="/plugins/node_quality/adapter.js?v=0.4.0"></script><!-- node-quality:end -->';
        return $html;
    }
    public static function apply(bool $enable):void
    {
        $config=admin_setting('theme_Xboard',[]);if(!is_array($config))$config=[];
        $html=self::merge($config['custom_html']??'',$enable);
        if($html!==($config['custom_html']??'')){$config['custom_html']=$html;admin_setting(['theme_Xboard'=>$config]);}
    }
}

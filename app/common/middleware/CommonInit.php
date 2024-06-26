<?php

declare(strict_types=1);

namespace app\common\middleware;

use Closure;
use think\Request;
use think\Response;
use think\facade\Env;
use think\facade\Config;

class CommonInit
{
    /**
     * 框架初始化.
     *
     * @param Request $request
     * @param Closure $next
     *
     * @return Response
     */
    public function handle($request, Closure $next)
    {
        // 设置mbstring字符编码
        mb_internal_encoding('UTF-8');
        // 加载插件默认语言包
        $this->initDefaultLang();
        // 设置替换内容
        $this->initReplaceString();
        //设置DEBUG环境
        $this->initDebugEnv();
        // Form别名
        if (!class_exists('Form')) {
            class_alias('util\\Form', 'Form');
        }

        return $next($request);
    }

    /**
     * 加载插件默认语言包
     */
    private function initDefaultLang()
    {
        // 加载插件语言包
        $lang = app()->lang->getLangSet();
        $lang = preg_match("/^([a-zA-Z\-_]{2,10})\$/i", $lang) ? $lang : 'zh-cn';
        app()->lang->load(base_path() . 'common/lang/' . $lang . '/addon.php');
    }

    /**
     * 模板内容替换
     */
    private function initReplaceString()
    {
        // 设置替换字符串
        $url = ltrim(dirname(app()->request->root()), DIRECTORY_SEPARATOR);
        // 如果未设置__CDN__则自动匹配得出
        $tpl_replace_string = Config::get('view.tpl_replace_string');
        if (!Config::get('view.tpl_replace_string.__CDN__')) {
            $tpl_replace_string['__CDN__'] = $url;
        }
        // 如果未设置__PUBLIC__则自动匹配得出
        if (!Config::get('view.tpl_replace_string.__PUBLIC__')) {
            $tpl_replace_string['__PUBLIC__'] = $url . '/';
        }
        // 如果未设置__ROOT__则自动匹配得出
        if (!Config::get('view.tpl_replace_string.__ROOT__')) {
            $tpl_replace_string['__ROOT__'] = preg_replace("/\/public\/$/", '', $url . '/');
        }
        Config::set(['tpl_replace_string' => $tpl_replace_string], 'view');
        if (!Config::get('site.cdnurl')) {
            Config::set(['cdnurl' => $url], 'site');
        }
        // 如果未设置cdnurl则自动匹配得出
        if (!Config::get('upload.cdnurl')) {
            Config::set(['cdnurl' => $url], 'upload');
        }
        // Form别名
        if (!class_exists('Form')) {
            class_alias('util\\Form', 'Form');
        }
    }

    /**
     * 调试模式缓存
     */
    private function initDebugEnv()
    {
        if (Env::get('APP_DEBUG')) {
            // 如果是调试模式将version置为当前的时间戳可避免缓存
            Config::set(['version' => time()], 'site');
            //如果是调试模式将关闭视图缓存
            Config::set(['tpl_cache' => false], 'view');
            // 如果是开发模式那么将异常模板修改成官方的
            Config::set(['exception_tmpl' => app()->getThinkPath() . 'tpl/think_exception.tpl'], 'app');
        }
    }
}

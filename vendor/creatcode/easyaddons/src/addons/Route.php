<?php

namespace creatcode\easyaddons\addons;

use think\facade\Event;
use think\exception\HttpException;

class Route
{
    public static function execute($addon = null, $controller = null, $action = null)
    {
        $request = request();
        $convert = config('url_convert', true);
        $filter = $convert ? 'strtolower' : 'trim';

        $addon = $addon !== null && $addon !== '' ? trim(call_user_func($filter, (string)$addon)) : '';
        $controller = $controller !== null && $controller !== '' ? trim(call_user_func($filter, (string)$controller)) : 'index';
        $action = $action !== null && $action !== '' ? trim(call_user_func($filter, (string)$action)) : 'index';

        if (
            !preg_match('/^[a-zA-Z0-9]+$/', $addon) ||
            !preg_match('/^[a-zA-Z][a-zA-Z0-9_]*(?:\.[a-zA-Z][a-zA-Z0-9_]*)*$/', $controller) ||
            !preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $action) ||
            strpos($action, '__') === 0
        ) {
            throw new HttpException(404, __('addon route not found'));
        }

        Event::trigger('addon_begin', $request);

        if (!empty($addon) && !empty($controller) && !empty($action)) {
            $info = get_addon_info($addon);
            if (!$info) {
                throw new HttpException(404, __('addon %s not found', $addon));
            }
            if (!$info['state']) {
                throw new HttpException(500, __('addon %s is disabled', $addon));
            }
            // 验证插件授权
            if (Service::addonConfig('addon_auth_check', false) && !Service::checkAddonAuthorization($addon)) {
                throw new HttpException(403, __('addon %s is not authorized', $addon));
            }

            $request->setController($controller)->setAction($action);
            Event::trigger('addon_module_init', $request);

            $class = get_addon_class($addon, 'controller', $controller);
            if (!$class) {
                throw new HttpException(404, __('addon controller %s not found', parse_name($controller, 1)));
            }

            $instance = new $class(app());
            $vars = [];

            if (is_callable([$instance, $action])) {
                $call = [$instance, $action];
            } elseif (is_callable([$instance, '_empty'])) {
                $call = [$instance, '_empty'];
                $vars = [$action];
            } else {
                throw new HttpException(404, __('addon action %s not found', get_class($instance) . '->' . $action . '()'));
            }

            Event::trigger('addon_action_begin', $call);
            return app()->invokeMethod($call, $vars);
        }

        abort(500, lang('addon can not be empty'));
    }
}

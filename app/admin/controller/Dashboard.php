<?php

namespace app\admin\controller;

use app\admin\model\Admin;
use app\admin\model\User;
use app\common\controller\Backend;
use app\common\model\Attachment;
use Carbon\Carbon;
use think\facade\Db;

/**
 * 控制台
 *
 * @icon   fa fa-dashboard
 * @remark 用于展示当前系统中的统计数据、统计报表及重要实时数据
 */
class Dashboard extends Backend
{

    /**
     * 查看
     */
    public function index()
    {
        try {
            Db::execute("SET @@sql_mode='';");
        } catch (\Exception $e) {
        }
        $column = [];
        $starttime = Carbon::now()->startOfDay()->subDays(6)->getTimestamp();
        $endtime = Carbon::now()->endOfDay()->getTimestamp();
        $joinlist = Db::name("user")->where('jointime', 'between time', [$starttime, $endtime])
            ->field('jointime, status, COUNT(*) AS nums, DATE_FORMAT(FROM_UNIXTIME(jointime), "%Y-%m-%d") AS join_date')
            ->group('join_date')
            ->select();
        for ($time = $starttime; $time <= $endtime;) {
            $column[] = date("Y-m-d", $time);
            $time += 86400;
        }
        $userlist = array_fill_keys($column, 0);
        foreach ($joinlist as $k => $v) {
            $userlist[$v['join_date']] = $v['nums'];
        }

        $dbTableList = Db::query("SHOW TABLE STATUS");
        $addonList = get_addon_list();
        $totalworkingaddon = 0;
        $totaladdon = count($addonList);
        foreach ($addonList as $index => $item) {
            if ($item['state']) {
                $totalworkingaddon += 1;
            }
        }

        // 先取值再组装，避免同一指标重复查询
        $totaluser = User::count();
        $totaladmin = Admin::count();
        $totalcategory = \app\common\model\Category::count();
        $todayusersignup = User::whereTime('jointime', 'today')->count();
        $todayuserlogin = User::whereTime('logintime', 'today')->count();
        $sevendau = User::whereTime('jointime|logintime|prevtime', '-7 days')->count();
        $thirtydau = User::whereTime('jointime|logintime|prevtime', '-30 days')->count();
        $threednu = User::whereTime('jointime', '-3 days')->count();
        $sevendnu = User::whereTime('jointime', '-7 days')->count();
        $dbtablenums = count($dbTableList);
        $dbsize = array_sum(array_map(function ($item) {
            return ($item['Data_length'] ?? 0) + ($item['Index_length'] ?? 0);
        }, $dbTableList));
        $attachmentnums = Attachment::count();
        $attachmentsize = Attachment::sum('filesize');
        $picturenums = Attachment::where('mimetype', 'like', 'image/%')->count();
        $picturesize = Attachment::where('mimetype', 'like', 'image/%')->sum('filesize');

        $this->view->assign([
            'totaluser'         => $totaluser,
            'totaladdon'        => $totaladdon,
            'totaladmin'        => $totaladmin,
            'totalcategory'     => $totalcategory,
            'todayusersignup'   => $todayusersignup,
            'todayuserlogin'    => $todayuserlogin,
            'sevendau'          => $sevendau,
            'thirtydau'         => $thirtydau,
            'threednu'          => $threednu,
            'sevendnu'          => $sevendnu,
            'dbtablenums'       => $dbtablenums,
            'dbsize'            => $dbsize,
            'totalworkingaddon' => $totalworkingaddon,
            'attachmentnums'    => $attachmentnums,
            'attachmentsize'    => $attachmentsize,
            'picturenums'       => $picturenums,
            'picturesize'       => $picturesize,

            // 指标卡：顺序即展示顺序
            'metricCards'       => [
                [
                    'title' => '累计用户',
                    'value' => $totaluser,
                    'desc'  => '平台注册会员总量',
                    'icon'  => 'fa fa-users',
                    'theme' => 'blue',
                ],
                [
                    'title' => '近 7 日新增',
                    'value' => $sevendnu,
                    'desc'  => '近 7 日新增注册会员',
                    'icon'  => 'fa fa-user-plus',
                    'theme' => 'cyan',
                ],
                [
                    'title' => '今日登录',
                    'value' => $todayuserlogin,
                    'desc'  => '今日产生登录行为的会员',
                    'icon'  => 'fa fa-sign-in',
                    'theme' => 'violet',
                ],
                [
                    'title' => '插件包',
                    'value' => $totaladdon,
                    'desc'  => '已安装插件，其中 ' . $totalworkingaddon . ' 个已启用',
                    'icon'  => 'fa fa-cubes',
                    'theme' => 'orange',
                ],
                [
                    'title' => '云端附件',
                    'value' => $attachmentnums,
                    'desc'  => '累计上传附件，占用 ' . format_bytes($attachmentsize, '', 0),
                    'icon'  => 'fa fa-cloud-upload',
                    'theme' => 'rose',
                ],
                [
                    'title' => '管理员',
                    'value' => $totaladmin,
                    'desc'  => '后台管理账号总数',
                    'icon'  => 'fa fa-user-circle-o',
                    'theme' => 'gold',
                ],
            ],

            // 系统资源总览
            'resourceSummary'   => [
                ['label' => '数据库占用', 'value' => format_bytes($dbsize, '', 0), 'desc' => $dbtablenums . ' 张数据表'],
                ['label' => '云端文件', 'value' => format_bytes($attachmentsize, '', 0), 'desc' => $attachmentnums . ' 个附件'],
                ['label' => '媒体资源', 'value' => $picturenums, 'desc' => format_bytes($picturesize, '', 0) . ' 图片占用'],
            ],

            // 活跃度概览：拆成两个顶层数组，避免模板 volist 走点号取值
            'activePills'       => [
                ['label' => '七日活跃会员', 'value' => $sevendau],
                ['label' => '三十日活跃会员', 'value' => $thirtydau],
            ],
            'activeLines'       => [
                ['label' => '今日新注册', 'value' => $todayusersignup],
                ['label' => '三日留存指标', 'value' => $threednu],
                ['label' => '七日趋势新增', 'value' => $sevendnu],
            ],
        ]);

        $this->assignconfig('column', array_keys($userlist));
        $this->assignconfig('userdata', array_values($userlist));

        return $this->view->fetch();
    }
}

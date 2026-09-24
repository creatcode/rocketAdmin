<?php

namespace app\admin\controller;

use app\common\controller\Backend;
use think\facade\Db;

/**
 * 关于系统
 *
 * @icon   fa fa-info-circle
 * @remark 展示系统版本与服务器运行环境信息
 */
class About extends Backend
{

    /**
     * 无需鉴权的方法,但需要登录
     * @var array
     */
    protected $noNeedRight = ['index'];

    /**
     * 查看
     */
    public function index()
    {
        $dbVersion = '';
        $tableCount = 0;
        try {
            $result = Db::query('SELECT VERSION() AS version');
            $dbVersion = $result[0]['version'] ?? '';
            $tableCount = count(Db::query('SHOW TABLE STATUS'));
        } catch (\Exception $e) {
        }

        $dbConfig = config('database.connections.' . config('database.default'));
        $maxExecTime = (int)ini_get('max_execution_time');

        // MySQL 运行参数与状态
        $dbVars = $dbStatus = [];
        $dbSize = '';
        try {
            $rows = Db::query("SHOW VARIABLES WHERE Variable_name IN ('max_connections','default_storage_engine','innodb_buffer_pool_size','max_allowed_packet')");
            $dbVars = array_column($rows, 'Value', 'Variable_name');
            $rows = Db::query("SHOW STATUS WHERE Variable_name IN ('Threads_connected','Uptime')");
            $dbStatus = array_column($rows, 'Value', 'Variable_name');
            $rows = Db::query("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 1) AS mb FROM information_schema.TABLES WHERE table_schema = DATABASE()");
            $dbSize = isset($rows[0]['mb']) ? $rows[0]['mb'] . ' MB' : '';
        } catch (\Exception $e) {
        }

        $dbUptime = (int)($dbStatus['Uptime'] ?? 0);
        if ($dbUptime >= 86400) {
            $dbUptimeText = floor($dbUptime / 86400) . ' ' . __('Days');
        } elseif ($dbUptime >= 3600) {
            $dbUptimeText = floor($dbUptime / 3600) . ' ' . __('Hours');
        } else {
            $dbUptimeText = floor($dbUptime / 60) . ' ' . __('Minutes');
        }

        $docRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
        $diskFree = $diskTotal = 0.0;
        if ($docRoot) {
            try {
                $diskFree = (float)disk_free_space($docRoot);
                $diskTotal = (float)disk_total_space($docRoot);
            } catch (\Throwable $e) {
            }
        }

        // Redis 运行状态,连接参数见 config/cache.php 的 redis 段
        $redis = [
            'connected'  => false,
            'version'    => '',
            'mode'       => '',
            'memory'     => '',
            'memoryPeak' => '',
            'maxMemory'  => '',
            'keys'       => 0,
            'clients'    => 0,
            'hitRate'    => '',
            'aof'        => false,
            'uptime'     => '',
        ];
        $redisConfig = config('cache.stores.redis') ?: [];
        if ($redisConfig && extension_loaded('redis')) {
            try {
                $client = new \Redis();
                $client->connect(
                    (string)($redisConfig['host'] ?? ''),
                    (int)($redisConfig['port'] ?? 6379),
                    (float)($redisConfig['timeout'] ?? 1.5)
                );
                $password = (string)($redisConfig['password'] ?? '');
                if ($password !== '') {
                    $client->auth($password);
                }
                $info = $client->info();
                $client->close();

                $redis['connected'] = true;
                $redis['version'] = (string)($info['redis_version'] ?? '');
                $redis['memory'] = (string)($info['used_memory_human'] ?? '');
                $redis['memoryPeak'] = (string)($info['used_memory_peak_human'] ?? '');
                $redis['clients'] = (int)($info['connected_clients'] ?? 0);
                $redis['aof'] = !empty($info['aof_enabled']);
                $modeMap = ['standalone' => __('Standalone'), 'sentinel' => __('Sentinel'), 'cluster' => __('Cluster')];
                $redis['mode'] = $modeMap[$info['redis_mode'] ?? ''] ?? (string)($info['redis_mode'] ?? '');
                $maxMemory = (int)($info['maxmemory'] ?? 0);
                $redis['maxMemory'] = $maxMemory > 0 ? format_bytes($maxMemory, '', 0) : __('Unlimited');
                $hits = (int)($info['keyspace_hits'] ?? 0);
                $misses = (int)($info['keyspace_misses'] ?? 0);
                $redis['hitRate'] = ($hits + $misses) > 0 ? round($hits / ($hits + $misses) * 100, 2) . '%' : '';
                // 键数量为所有库之和,INFO 的 dbN 行为 keys=N,expires=N,...
                foreach ($info as $key => $value) {
                    if (preg_match('/^db\d+$/', $key) && preg_match('/keys=(\d+)/', (string)$value, $match)) {
                        $redis['keys'] += (int)$match[1];
                    }
                }
                $seconds = (int)($info['uptime_in_seconds'] ?? 0);
                if ($seconds >= 86400) {
                    $redis['uptime'] = floor($seconds / 86400) . ' ' . __('Days');
                } elseif ($seconds >= 3600) {
                    $redis['uptime'] = floor($seconds / 3600) . ' ' . __('Hours');
                } else {
                    $redis['uptime'] = floor($seconds / 60) . ' ' . __('Minutes');
                }
            } catch (\Throwable $e) {
                $redis['connected'] = false;
            }
        }

        $this->view->assign([
            'debugEnabled'   => app()->isDebug(),
            // 运行环境
            'phpVersion'     => PHP_VERSION,
            'thinkVersion'   => \think\App::VERSION,
            'serverSoftware' => $_SERVER['SERVER_SOFTWARE'] ?? '',
            'osInfo'         => PHP_OS . ' ' . php_uname('m'),
            'phpSapi'        => php_sapi_name(),
            'docRoot'        => $docRoot,
            // 服务器
            'serverIp'       => $_SERVER['SERVER_ADDR'] ?? '',
            'hostName'       => function_exists('gethostname') ? (string)gethostname() : '',
            'serverTime'     => date('Y-m-d H:i:s'),
            'diskSpace'      => $diskTotal > 0
                ? format_bytes($diskFree, '', 0) . ' / ' . format_bytes($diskTotal, '', 0)
                : '',
            // 文件与路径
            'phpIni'         => php_ini_loaded_file() ?: '',
            'extensionDir'   => (string)ini_get('extension_dir'),
            'uploadTmpDir'   => (string)ini_get('upload_tmp_dir') ?: __('System default'),
            'sessionPath'    => (string)ini_get('session.save_path'),
            'errorLog'       => (string)ini_get('error_log'),
            // 数据库
            'dbDriver'       => (string)config('database.default'),
            'dbVersion'      => $dbVersion,
            'dbName'         => (string)($dbConfig['database'] ?? ''),
            'dbHost'         => ($dbConfig['hostname'] ?? '') . ':' . ($dbConfig['hostport'] ?? ''),
            'dbCharset'      => (string)($dbConfig['charset'] ?? ''),
            'dbPrefix'       => (string)($dbConfig['prefix'] ?? ''),
            'tableCount'     => $tableCount,
            // MySQL 运行参数
            'dbMaxConn'      => (string)($dbVars['max_connections'] ?? ''),
            'dbThreads'      => (string)($dbStatus['Threads_connected'] ?? ''),
            'dbUptime'       => $dbUptimeText,
            'dbEngine'       => (string)($dbVars['default_storage_engine'] ?? ''),
            'dbBufferPool'   => isset($dbVars['innodb_buffer_pool_size']) ? round($dbVars['innodb_buffer_pool_size'] / 1048576) . ' MB' : '',
            'dbMaxPacket'    => isset($dbVars['max_allowed_packet']) ? round($dbVars['max_allowed_packet'] / 1048576) . ' MB' : '',
            'dbSize'         => $dbSize,
            // Redis
            'redis'          => $redis,
            // PHP
            'memoryLimit'    => (string)ini_get('memory_limit'),
            'uploadMax'      => (string)ini_get('upload_max_filesize'),
            'postMax'        => (string)ini_get('post_max_size'),
            'maxExecTime'    => $maxExecTime > 0 ? $maxExecTime . ' s' : __('Unlimited'),
            'displayErrors'  => (bool)ini_get('display_errors'),
            'maxFileUploads' => (string)ini_get('max_file_uploads'),
            'timezone'       => config('app.default_timezone') ?: date_default_timezone_get(),
        ]);

        return $this->view->fetch();
    }
}

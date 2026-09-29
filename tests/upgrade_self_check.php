<?php

/**
 * 升级详情与备份导出的隔离自检，不连接数据库、不操作项目升级现场。
 *
 * 运行：php tests/upgrade_self_check.php
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use app\admin\service\UpgradeService;
use think\facade\Config;

/**
 * 检查实际结果，不依赖可能被禁用的 PHP assert 配置。
 *
 * @param bool $condition 检查条件
 * @param string $message 失败说明
 * @return void
 */
function upgrade_expect($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * 确认操作被拒绝，且异常信息包含预期原因。
 *
 * @param callable $operation 待检查操作
 * @param string $message 预期异常内容
 * @return void
 */
function upgrade_reject(callable $operation, string $message)
{
    try {
        $operation();
    } catch (RuntimeException $e) {
        upgrade_expect(strpos($e->getMessage(), $message) !== false, '拒绝原因不符：' . $e->getMessage());
        return;
    }
    throw new RuntimeException('操作未被拒绝：' . $message);
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rocket-upgrade-check-' . bin2hex(random_bytes(8));
mkdir($root);
mkdir($root . '/runtime');
mkdir($root . '/config');
file_put_contents($root . '/config/rocket.php', "<?php return ['version' => '1.0'];\n");
$service = new UpgradeService($root, $root . '/runtime');
$reflection = new ReflectionClass($service);
$batch = '20260928000000-1234abcd';
$path = $root . '/runtime/upgrade/' . $batch . '/';

/**
 * 调用隔离服务的内部步骤，构造与检查真实批次数据。
 *
 * @param string $name 方法名
 * @param array $arguments 方法参数
 * @return mixed
 */
$invoke = function ($name, ...$arguments) use ($reflection, $service) {
    $method = $reflection->getMethod($name);
    $method->setAccessible(true);
    return $method->invokeArgs($service, $arguments);
};

$oldBinary = getenv('MYSQL_BINARY');
try {
    $invoke('ensureDirectory', $path);
    $status = ['batch' => $batch, 'state' => 'done', 'from_version' => '1.0', 'to_version' => '1.1'];
    $invoke('saveStatus', $batch, $status);
    $old = $service->detail($batch);
    upgrade_expect($old['sql_logs'] === [] && !$old['backup']['exportable'], '旧批次兼容失败');
    upgrade_expect($service->progress($batch)['state'] === 'done', '已结束批次无法查询最终状态');
    upgrade_expect($service->progress('', $batch) === null, '误把历史批次当成本次结果');
    upgrade_expect($service->progress('', '0')['batch'] === $batch, '请求断开后无法找回最终批次');
    upgrade_reject(function () use ($service) { $service->progress('', '../outside'); }, '批次编号无效');
    upgrade_reject(function () use ($service) { $service->detail('../config'); }, '批次编号无效');

    $invoke('ensureDirectory', $root . '/app');
    file_put_contents($root . '/app/example.php', 'original');
    $plan = [['path' => 'app/example.php', 'action' => 'write', 'existed' => true, 'mode' => 0644, 'backup_mode' => 0644]];
    $invoke('prepareJournal', $batch, $plan);
    $invoke('ensureDirectory', $path . 'staging/app');
    file_put_contents($path . 'staging/app/example.php', 'updated');
    $invoke('applyFileChanges', $batch, $path . 'staging');
    file_put_contents($path . 'database.sql', 'offline database backup');
    $status['database_backup'] = ['sha256' => hash_file('sha256', $path . 'database.sql')];
    $status['sql_logs'] = [['version' => '1.1', 'file' => 'upgrade/sql/001.sql', 'state' => 'running', 'started_at' => '', 'finished_at' => '', 'message' => '<script>test</script>']];
    $invoke('saveStatus', $batch, $status);
    $detail = $service->detail($batch);
    upgrade_expect($detail['backup']['files_count'] === 2 && $detail['backup']['database_size'] > 0, '备份信息不符');
    upgrade_expect($detail['sql_logs'][0]['state'] === 'interrupted', '中断 SQL 仍显示执行中');

    $exported = '';
    $service->exportBackup($batch, function ($file, $name) use ($service, $batch, &$exported) {
        $exported = $file;
        $zip = new ZipArchive();
        upgrade_expect($zip->open($file) === true, '导出 ZIP 不可读');
        upgrade_expect($zip->getFromName('files/app/example.php') === 'original', '导出的不是升级前原件');
        upgrade_expect($zip->getFromName('database.sql') === 'offline database backup', '整库备份导出失败');
        upgrade_expect($zip->locateName('files/config/rocket.php') !== false && $zip->locateName('journal.json') !== false, '恢复清单或配置缺失');
        upgrade_expect($zip->locateName('client.cnf') === false && $zip->numFiles === 5, '导出混入额外文件');
        $zip->close();
        upgrade_reject(function () use ($service, $batch) { $service->deleteBatches([$batch]); }, '另一个升级操作');
    });
    upgrade_expect(!is_file($exported), '导出临时 ZIP 未清理');
    upgrade_reject(function () use ($service, $batch) {
        $service->exportBackup($batch, function () { throw new RuntimeException('模拟发送失败'); });
    }, '模拟发送失败');
    upgrade_expect(glob($root . '/runtime/upgrade/export-*.zip') === [], '发送失败残留 ZIP');

    file_put_contents($path . 'database.sql', 'tampered');
    upgrade_reject(function () use ($service, $batch) { $service->exportBackup($batch, function () {}); }, '备份校验失败');
    file_put_contents($path . 'database.sql', 'offline database backup');
    $journal = $invoke('loadJournal', $batch);
    $badJournal = $journal;
    $badJournal['files'][0]['path'] = '../outside.php';
    $invoke('saveJournal', $batch, $badJournal);
    upgrade_expect($service->detail($batch)['backup']['error'] !== '', '不安全备份未提示');
    upgrade_reject(function () use ($service, $batch) { $service->exportBackup($batch, function () {}); }, '越界');
    $invoke('saveJournal', $batch, $journal);
    $status['state'] = 'running_sql';
    $invoke('saveStatus', $batch, $status);
    upgrade_expect(!$service->detail($batch)['backup']['exportable'], '未结束批次开放导出');
    upgrade_expect($service->progress($batch)['recoverable'], '中断现场没有恢复入口');
    $activeLock = $invoke('acquireLock');
    upgrade_expect(!$service->progress($batch)['recoverable'], '运行中的批次误允许恢复');
    $invoke('releaseLock', $activeLock);
    upgrade_reject(function () use ($service, $batch) { $service->exportBackup($batch, function () {}); }, '仅可导出');

    // 用隔离的命令脚本模拟 MySQL 客户端，实际不创建数据库连接。
    $app = new think\App(dirname(__DIR__) . DIRECTORY_SEPARATOR);
    think\Container::setInstance($app);
    Config::set(['connections' => ['mysql' => ['database' => 'offline', 'hostname' => 'unused', 'username' => 'unused', 'password' => '', 'hostport' => 3306]]], 'database');
    $client = $root . (DIRECTORY_SEPARATOR === '\\' ? '/mysql.cmd' : '/mysql.sh');
    file_put_contents($client, DIRECTORY_SEPARATOR === '\\' ? "@echo off\r\nexit /b 0\r\n" : "#!/bin/sh\nexit 0\n");
    if (DIRECTORY_SEPARATOR !== '\\') {
        chmod($client, 0700);
    }
    putenv('MYSQL_BINARY=' . $client);
    $package = $path . 'sql.zip';
    $zip = new ZipArchive();
    $zip->open($package, ZipArchive::CREATE);
    $zip->addFromString('upgrade/sql/001.sql', 'SELECT 1;');
    $zip->close();
    $bundle = ['sql' => [['version' => '1.1', 'path' => 'upgrade/sql/001.sql', 'sha256' => hash('sha256', 'SELECT 1;')]]];
    $status['sql_completed'] = [];
    $status['sql_logs'] = [];
    $invoke('saveStatus', $batch, $status);
    $invoke('executeSqlFiles', $batch, $package, $bundle);
    $completed = $invoke('loadStatus', $batch);
    $log = $completed['sql_logs']['upgrade/sql/001.sql'];
    upgrade_expect($log['state'] === 'success' && $log['version'] === '1.1' && $log['finished_at'] !== '', 'SQL 成功详情缺失');
    upgrade_expect(count($completed['sql_completed']) === 1 && !is_file($path . 'client.cnf'), 'SQL 成功记录或凭证清理失败');

    file_put_contents($client, DIRECTORY_SEPARATOR === '\\' ? "@echo off\r\necho simulated sql error 1>&2\r\nexit /b 1\r\n" : "#!/bin/sh\necho simulated sql error >&2\nexit 1\n");
    $status['sql_completed'] = [];
    $invoke('saveStatus', $batch, $status);
    upgrade_reject(function () use ($invoke, $batch, $package, $bundle) { $invoke('executeSqlFiles', $batch, $package, $bundle); }, 'SQL 执行失败');
    $failed = $invoke('loadStatus', $batch);
    upgrade_expect($failed['sql_logs']['upgrade/sql/001.sql']['state'] === 'error'
        && strpos($failed['sql_logs']['upgrade/sql/001.sql']['message'], 'simulated sql error') !== false
        && $failed['sql_completed'] === [], 'SQL 失败详情或停止行为不符');
    upgrade_expect(!is_file($path . 'client.cnf') && glob($path . 'sql-execution/*.sql') === [], '失败后临时 SQL 或凭证残留');

    $middleware = new app\common\middleware\UpgradeMaintenance();
    $method = new ReflectionMethod($middleware, 'isUpgradeRequest');
    $method->setAccessible(true);
    foreach (['system.upgrade/detail', 'system/upgrade/backup'] as $url) {
        $request = new think\Request();
        $request->setPathinfo($url);
        upgrade_expect($method->invoke($middleware, $request), '维护期间无法访问详情或备份');
    }
    echo "Upgrade offline self-check passed.\n";
} finally {
    putenv($oldBinary === false ? 'MYSQL_BINARY' : 'MYSQL_BINARY=' . $oldBinary);
    // 清理仅限本次生成的随机临时目录，不触及项目运行目录。
    upgrade_expect(strpos(realpath($root), realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR) === 0, '临时目录越界');
    $invoke('removeContents', $root);
    rmdir($root);
}

// 可选真实 MySQL 联调：php tests/upgrade_self_check.php --mysql；仅操作随机测试库和临时目录。
if (in_array('--mysql', $_SERVER['argv'] ?? [])) {
    require_once dirname(__DIR__) . '/vendor/topthink/framework/src/helper.php';
    $app=new think\App(dirname(__DIR__).DIRECTORY_SEPARATOR);think\Container::setInstance($app);
    $app->env->load(dirname(__DIR__).'/.env');
    $dbConfig=require dirname(__DIR__).'/config/database.php';$c=$dbConfig['connections']['mysql'];
    $admin=new PDO('mysql:host='.$c['hostname'].';port='.$c['hostport'].';charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $suffix=bin2hex(random_bytes(8));$database='rocket_upgrade_check_'.$suffix;
    $root=sys_get_temp_dir().DIRECTORY_SEPARATOR.'rocket-upgrade-mysql-check-'.$suffix;
    $created=false;$service=null;
    try{
        mkdir($root);mkdir($root.'/runtime');mkdir($root.'/config');mkdir($root.'/public');
        Config::set(['default'=>'file','stores'=>['file'=>['type'=>'File','path'=>$root.'/runtime/cache/']]],'cache');
        Config::set(['close'=>true,'default'=>'file','channels'=>['file'=>['type'=>'File','path'=>$root.'/runtime/log/','close'=>true]]],'log');
        $rocket=require dirname(__DIR__).'/config/rocket.php';
        file_put_contents($root.'/config/rocket.php',file_get_contents(dirname(__DIR__).'/config/rocket.php'));
        $info=json_decode(file_get_contents(dirname(__DIR__).'/public/upgrade.json'),true,512,JSON_THROW_ON_ERROR);
        copy(dirname(__DIR__).'/public/upgrade.json',$root.'/public/upgrade.json');
        copy(dirname(__DIR__).'/public/'.basename($info['url']),$root.'/public/'.basename($info['url']));
        $rocket['upgrade']['version_info_url']='/upgrade.json';$rocket['upgrade']['keep_batches']=0;
        $rocket['upgrade']['mysql_binary']='E:/phpstudy_pro/Extensions/MySQL8.0.12/bin/mysql.exe';
        $rocket['upgrade']['mysqldump_binary']='E:/phpstudy_pro/Extensions/MySQL8.0.12/bin/mysqldump.exe';
        Config::set($rocket,'rocket');
        $admin->exec('CREATE DATABASE '.$database.' CHARACTER SET utf8mb4');$created=true;
        $dbConfig['connections']['mysql']['database']=$database;$dbConfig['connections']['mysql']['trigger_sql']=false;Config::set($dbConfig,'database');
        $db=new PDO('mysql:host='.$c['hostname'].';port='.$c['hostport'].';dbname='.$database.';charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $db->exec("CREATE TABLE upgrade_probe (id INT PRIMARY KEY, value VARCHAR(100)) ENGINE=InnoDB");
        $stmt=$db->prepare('INSERT INTO upgrade_probe VALUES (?,?)');$stmt->execute([1,'original']);
        $db->exec("CREATE TRIGGER probe_insert BEFORE INSERT ON upgrade_probe FOR EACH ROW SET NEW.value=CONCAT(NEW.value,'!')");
        $db->exec('CREATE PROCEDURE probe_count() SELECT COUNT(*) FROM upgrade_probe');
        $service=new UpgradeService($root,$root.'/runtime');$reflection=new ReflectionClass($service);
        /**
         * 调用隔离实例内部步骤，构造进程中断现场。
         * @param string $name 方法名
         * @param array $args 方法参数
         * @return mixed
         */
        $invoke=function($name,...$args)use($reflection,$service){$m=$reflection->getMethod($name);$m->setAccessible(true);return $m->invokeArgs($service,$args);};
        $result=$service->run($info['version']);
        upgrade_expect($result['sql_executed']===0&&is_file($root.'/public/upgrade-demo.txt'),'file upgrade');
        upgrade_expect((include $root.'/config/rocket.php')['version']===$info['version'],'file version commit');
        upgrade_expect($service->progress($result['batch'])['state']==='done','file state');
        $originalDemo=file_get_contents($root.'/public/upgrade-demo.txt');
        echo "PASS file-only package upgrade\n";
        /**
         * 生成隔离测试包与元数据。
         * @param string $version 目标版本
         * @param string $from 起始版本
         * @param array $sql SQL 文件内容
         * @return void
         */
        $package=function($version,$from,$sql)use($root){
            $path=$root.'/public/package-'.$version.'.zip';$zip=new ZipArchive();
            upgrade_expect($zip->open($path,ZipArchive::CREATE|ZipArchive::EXCL)===true,'package creation');
            $zip->addEmptyDir('payload');$zip->addFromString('payload/public/upgrade-demo.txt','updated-'.$version);$entries=[];
            foreach($sql as $i=>$contents){$file='upgrade/sql/'.sprintf('%03d',$i+1).'.sql';$zip->addFromString($file,$contents);$entries[]=['version'=>$version,'file'=>$file];}
            $zip->addFromString('upgrade/upgrade.json',json_encode(['version'=>$version,'min_version'=>$from,'delete'=>[],'sql'=>$entries],JSON_THROW_ON_ERROR));$zip->close();
            file_put_contents($root.'/public/upgrade.json',json_encode(['version'=>$version,'url'=>'/'.basename($path),'notes'=>'isolated MySQL check'],JSON_THROW_ON_ERROR));
        };
        $v='1.6.1.20250430.2';
        $package($v,$info['version'],["UPDATE upgrade_probe SET value='升级成功' WHERE id=1; CREATE TABLE upgrade_added (id INT PRIMARY KEY); INSERT INTO upgrade_added VALUES (7);"]);
        $success=$service->run($v);$status=$invoke('loadStatus',$success['batch']);
        upgrade_expect($db->query('SELECT value FROM upgrade_probe WHERE id=1')->fetchColumn()==='升级成功','real SQL data');
        upgrade_expect($db->query('SELECT id FROM upgrade_added')->fetchColumn()==7,'real DDL');
        $backup=$root.'/runtime/upgrade/'.$success['batch'].'/database.sql';
        upgrade_expect(is_file($backup)&&hash_file('sha256',$backup)===$status['database_backup']['sha256'],'real dump checksum');
        upgrade_expect($service->detail($success['batch'])['sql_logs'][0]['state']==='success','SQL success log');
        echo "PASS real mysqldump, SQL DML/DDL, version commit and SQL log\n";
        $package('1.6.1.20250430.3',$v,["UPDATE upgrade_probe SET value='broken' WHERE id=1; ALTER TABLE upgrade_probe ADD COLUMN staged INT DEFAULT 0; CREATE TABLE upgrade_transient (id INT);",'INVALID_UPGRADE_SQL;']);
        $failed=false;
        try{$service->run('1.6.1.20250430.3');}catch(RuntimeException $e){$failed=strpos($e->getMessage(),'SQL 执行失败')!==false;if(!$failed){throw $e;}}
        upgrade_expect($failed,'invalid SQL rejected');
        $history=$service->history();$failure=$invoke('loadStatus',$history[0]['batch']);
        upgrade_expect($failure['state']==='failed_rolled_back'&&!empty($failure['database_restored']),'automatic database recovery');
        upgrade_expect($db->query('SELECT value FROM upgrade_probe WHERE id=1')->fetchColumn()==='升级成功','DML rollback');
        upgrade_expect($db->query("SHOW COLUMNS FROM upgrade_probe LIKE 'staged'")->fetch()===false,'new column rollback');
        upgrade_expect($db->query("SHOW TABLES LIKE 'upgrade_transient'")->fetch()===false,'new table rollback');
        upgrade_expect($db->query('SELECT id FROM upgrade_added')->fetchColumn()==7,'existing table preserved');
        upgrade_expect((include $root.'/config/rocket.php')['version']===$v,'failed version rollback');
        upgrade_expect(file_get_contents($root.'/public/upgrade-demo.txt')==='updated-'.$v,'failed file rollback');
        upgrade_expect($failure['sql_logs']['upgrade/sql/002.sql']['state']==='error','failed SQL log');
        upgrade_expect($service->dashboard()['interrupted']===null,'automatic maintenance cleanup');
        echo "PASS SQL failure: DML/DDL rollback, file/version rollback and failure log\n";
        // 仅修改隔离批次，模拟升级进程中断。
        $status['state']='running_sql';$invoke('saveStatus',$success['batch'],$status);$invoke('writeMaintenanceLock',$success['batch']);
        file_put_contents($root.'/public/upgrade-demo.txt','interrupted');
        $service->recover($success['batch']);
        upgrade_expect($db->query('SELECT value FROM upgrade_probe WHERE id=1')->fetchColumn()==='original','manual original data restore');
        upgrade_expect($db->query("SHOW TABLES LIKE 'upgrade_added'")->fetch()===false,'manual added table restore');
        upgrade_expect((include $root.'/config/rocket.php')['version']===$info['version'],'manual version restore');
        upgrade_expect(file_get_contents($root.'/public/upgrade-demo.txt')===$originalDemo,'manual file restore');
        upgrade_expect($db->query("SHOW TRIGGERS LIKE 'upgrade_probe'")->fetch()!==false,'trigger restore');
        $r=$db->prepare('SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema=? AND routine_name=?');$r->execute([$database,'probe_count']);upgrade_expect($r->fetchColumn()==1,'routine restore');
        upgrade_expect($service->dashboard()['interrupted']===null,'manual maintenance cleanup');
        upgrade_expect(glob($root.'/runtime/upgrade/*/client.cnf')===[],'temporary credentials removed');
        echo "PASS interrupted recovery: database, triggers/routines, files/version and maintenance cleanup\nReal MySQL integration passed.\n";
    }finally{
        if($created){upgrade_expect(preg_match('/^rocket_upgrade_check_[a-f0-9]{16}$/D',$database)===1,'database boundary');$admin->exec('DROP DATABASE '.$database);echo "Test database removed.\n";}
        if(is_dir($root)){
            upgrade_expect(strpos(realpath($root),realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR)===0&&basename($root)==='rocket-upgrade-mysql-check-'.$suffix,'temporary directory boundary');
            if(!$service){$service=new UpgradeService($root,$root.'/runtime');$reflection=new ReflectionClass($service);}
            $m=$reflection->getMethod('removeContents');$m->setAccessible(true);$m->invoke($service,$root);rmdir($root);echo "Test directory removed.\n";
        }
    }
}


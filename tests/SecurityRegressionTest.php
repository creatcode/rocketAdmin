<?php

// 执行：php tests/SecurityRegressionTest.php。使用独立临时数据库，退出时清理，不修改业务库。
namespace app\common\library {
    // CLI 没有 HTTP 上传临时文件，仅替换文件落盘动作，其余上传流程使用真实实现。
    function move_uploaded_file($source, $target)
    {
        return rename($source, $target);
    }
}

namespace {
    require dirname(__DIR__) . '/vendor/autoload.php';
    require dirname(__DIR__) . '/vendor/topthink/framework/src/helper.php';
    require dirname(__DIR__) . '/app/common.php';

    use think\facade\Db;

    function check($condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    class TestOutcome extends RuntimeException
    {
        public bool $ok;
        public function __construct(bool $ok, string $message = '')
        {
            parent::__construct($message);
            $this->ok = $ok;
        }
    }

    trait ControllerOutcome
    {
        protected function token() {}
        protected function success($msg = '', $url = null, $data = '', $wait = 3, array $header = []): void
        {
            throw new TestOutcome(true, $msg);
        }
        protected function error($msg = '', $url = null, $data = '', $wait = 3, array $header = []): void
        {
            throw new TestOutcome(false, $msg);
        }
    }

    class MemberController extends \app\admin\controller\user\User
    {
        use ControllerOutcome;
        public function __construct($app, bool $fail = false)
        {
            $this->app = $app;
            $this->request = $app->request;
            $this->model = $fail ? new FailingMember() : new \app\admin\model\User();
        }
    }

    class FailingMember extends \app\admin\model\User
    {
        public static function onAfterUpdate($row)
        {
            throw new RuntimeException('模拟写入失败');
        }
    }

    class AttachmentController extends \app\admin\controller\general\Attachment
    {
        use ControllerOutcome;
        public function __construct($app)
        {
            $this->app = $app;
            $this->request = $app->request;
            $this->model = new \app\common\model\Attachment();
        }
    }

    class ApiPasswordController extends \app\api\controller\User
    {
        public function __construct($app, $auth)
        {
            $this->request = $app->request;
            $this->auth = $auth;
        }
        protected function success($msg = '', $data = null, $code = 1, $type = null, array $header = []): void
        {
            throw new TestOutcome(true);
        }
        protected function error($msg = '', $data = null, $code = 0, $type = null, array $header = []): void
        {
            throw new TestOutcome(false, $msg);
        }
    }

    class ApiTokenController extends \app\api\controller\Token
    {
        public function __construct($app, $auth)
        {
            $this->request = $app->request;
            $this->auth = $auth;
        }
        protected function success($msg = '', $data = null, $code = 1, $type = null, array $header = []): void
        {
            throw new TestOutcome(true);
        }
        protected function error($msg = '', $data = null, $code = 0, $type = null, array $header = []): void
        {
            throw new TestOutcome(false, $msg);
        }
    }

    class ImportRecord extends \think\Model
    {
        protected $name = 'import_record';
        protected $autoWriteTimestamp = false;
        public static function onBeforeInsert($row)
        {
            if ($row['name'] === 'FAIL') {
                throw new RuntimeException('模拟导入失败');
            }
        }
    }

    class ImportAuth extends \app\admin\library\AdminAuth
    {
        public bool $allowEdit;
        public function __get($name) { return $name === 'id' ? 1 : null; }
        public function isSuperAdmin() { return false; }
        public function isLogin() { return true; }
        public function check($name, $uid = '', $relation = 'or', $mode = 'url') { return $this->allowEdit; }
    }

    class ImportController extends \app\common\controller\Backend
    {
        use ControllerOutcome;
        public function __construct($app, bool $allowEdit)
        {
            $this->request = $app->request;
            $this->model = new ImportRecord();
            $this->auth = new ImportAuth();
            $this->auth->allowEdit = $allowEdit;
            $this->dataLimit = 'personal';
            $this->importHeadType = 'name';
        }
        public function import() { parent::import(); }
    }

    function importRows($app, string $path, array $rows, bool $allowEdit = true, string $encoding = 'UTF-8'): TestOutcome
    {
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, ['id', 'name', 'code', 'admin_id']);
        foreach ($rows as $row) {
            fputcsv($stream, $row);
        }
        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);
        file_put_contents($path, mb_convert_encoding($contents, $encoding, 'UTF-8'));
        $app->request->controllerPath = 'sample';
        $app->request->withPost(['file' => '/uploads/' . basename($path)]);
        $property = new ReflectionProperty($app->request, 'request');
        $property->setAccessible(true);
        $property->setValue($app->request, ['file' => '/uploads/' . basename($path)]);
        return outcome(function () use ($app, $allowEdit) { (new ImportController($app, $allowEdit))->import(); });
    }

    function mysqlTokens(): \app\common\library\token\driver\Mysql
    {
        // 跳过构造器的每日缓存清理，测试直接使用独立测试库的真实连接。
        $reflection = new ReflectionClass(\app\common\library\token\driver\Mysql::class);
        $driver = $reflection->newInstanceWithoutConstructor();
        $property = $reflection->getProperty('handler');
        $property->setAccessible(true);
        $property->setValue($driver, Db::name('user_token'));
        return $driver;
    }

    function outcome(callable $action): TestOutcome
    {
        try {
            $action();
        } catch (TestOutcome $result) {
            return $result;
        }
        throw new RuntimeException('控制器未返回结果');
    }

    function memberEdit($app, array $row, array $original, bool $fail = false): TestOutcome
    {
        $app->request->withPost(['row' => $row, 'original' => $original]);
        return outcome(function () use ($app, $fail) { (new MemberController($app, $fail))->edit(1); });
    }

    function runWorkers(PDO $pdo, string $database, string $scenario, int $count): array
    {
        Db::name('barrier')->delete(true);
        $pdo->beginTransaction();
        if ($scenario === 'rotate') {
            $pdo->query("SELECT token FROM `{$database}`.t_user_token WHERE user_id=1 FOR UPDATE");
        } else {
            $table = $scenario === 'login' ? 't_admin' : 't_user';
            $pdo->query("SELECT id FROM `{$database}`.`{$table}` WHERE id=1 FOR UPDATE");
        }
        $workers = [];
        try {
            for ($i = 0; $i < $count; $i++) {
                $process = proc_open([PHP_BINARY, __FILE__, '--worker', $database, $scenario, (string)$i],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                check(is_resource($process), '无法启动并发测试进程');
                fclose($pipes[0]);
                $workers[] = [$process, $pipes];
            }
            $deadline = microtime(true) + 15;
            while (Db::name('barrier')->count() < $count && microtime(true) < $deadline) {
                usleep(10000);
            }
            check(Db::name('barrier')->count() === $count, '并发测试进程未就绪');
            usleep(100000);
        } finally {
            if ($scenario === 'rotate') {
                $pdo->exec("DELETE FROM `{$database}`.t_user_token WHERE user_id=1");
                $pdo->commit();
            } else {
                $pdo->rollBack();
            }
        }
        $results = [];
        foreach ($workers as [$process, $pipes]) {
            $output = trim(stream_get_contents($pipes[1]));
            $error = trim(stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            check(proc_close($process) === 0, '并发测试失败：' . $error . $output);
            $results[] = $output;
        }
        return $results;
    }

    $root = dirname(__DIR__) . DIRECTORY_SEPARATOR;
    $app = new \think\App($root);
    $app->env->load($root . '.env');
    $config = require $root . 'config/database.php';
    $connection = $config['connections'][$config['default']];
    $worker = ($argv[1] ?? '') === '--worker';
    $database = $worker ? $argv[2] : 'rocket_security_test_' . bin2hex(random_bytes(6));
    check(preg_match('/^rocket_security_test_[a-f0-9]{12}$/D', $database), '测试库名称无效');
    $pdo = new PDO('mysql:host=' . $connection['hostname'] . ';port=' . $connection['hostport'] . ';charset=utf8mb4',
        $connection['username'], $connection['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $connection['database'] = $database;
    $connection['prefix'] = 't_';
    $connection['trigger_sql'] = false;
    $config['connections'] = ['mysql' => $connection];
    $config['default'] = 'mysql';
    $app->config->set($config, 'database');
    $app->config->set(['login_failure_retry' => true], 'rocket');
    $app->config->set(['expire' => 3600, 'max_expire' => 3600, 'hashalgo' => 'ripemd160', 'key' => 'regression-test-only'], 'token');
    $app->config->set(['maxsize' => '8b', 'mimetype' => 'txt', 'savekey' => '/security-test.txt'], 'upload');
    $db = new \think\Db();
    $db->setConfig($app->config);
    $app->instance('db', $db);
    \think\Model::setDb($db);
    \think\Model::setEvent($app->event);
    \think\Model::setInvoker([$app, 'invoke']);
    \think\Validate::maker(function ($validate) use ($db, $app) {
        $validate->setDb($db);
        $validate->setLang($app->lang);
        $validate->setRequest($app->request);
    });
    $app->instance('request', (new \app\Request())->setMethod('POST')->withServer(['REMOTE_ADDR' => '127.0.0.1']));
    $app->instance('session', new class {
        public function get($key, $default = null) { return $default; }
        public function set($key, $value) {}
    });

    if ($worker) {
        Db::name('barrier')->insert(['id' => null]);
        if ($argv[3] === 'login') {
            check(!(new \app\admin\library\AdminAuth())->login('auditor', 'wrong-password'), '错误密码被接受');
            echo 'rejected';
        } elseif ($argv[3] === 'rotate') {
            echo mysqlTokens()->rotate('queued', 'after-revoke', 1, 60) ? 'issued' : 'rejected';
        } else {
            $result = memberEdit($app, ['username' => 'member', 'nickname' => 'Member', 'email' => 'member@example.invalid',
                'money' => 120 + (int)$argv[4], 'score' => 100], ['money' => '100.00', 'score' => 100]);
            echo $result->ok ? 'saved' : 'conflict: ' . $result->getMessage();
        }
        exit;
    }

    $temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $database;
    $publicFile = public_path() . 'uploads/.' . $database . '.txt';
    $importFile = public_path() . 'uploads/.' . $database . '.csv';
    $uploadsExisted = is_dir(dirname($publicFile));
    mkdir($temporary, 0700);
    try {
        $pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4");
        $pdo->exec("USE `{$database}`");
        $schemaFile = is_file($root . 'rocketadmin.sql') ? $root . 'rocketadmin.sql' : $root . '数据库.sql';
        $schema = file_get_contents($schemaFile);
        foreach (['admin', 'user', 'user_money_log', 'user_score_log', 'attachment', 'sms', 'user_token'] as $table) {
            check(preg_match('/CREATE TABLE `[^`]*_' . $table . '`\s*\([\s\S]*?\)\s*ENGINE[^;]+;/i', $schema, $matches), '缺少测试表结构：' . $table);
            $pdo->exec(preg_replace('/^CREATE TABLE `[^`]+`/', 'CREATE TABLE `t_' . $table . '`', $matches[0]));
        }
        $pdo->exec('CREATE TABLE t_barrier (id INT AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE t_import_record (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, code VARCHAR(50) NOT NULL UNIQUE, admin_id INT NOT NULL) ENGINE=InnoDB');
        $auth = new \app\admin\library\AdminAuth();
        Db::name('admin')->insert(['id' => 1, 'username' => 'auditor', 'password' => $auth->getEncryptPassword('correct-password', 'testsalt'),
            'salt' => 'testsalt', 'status' => 'normal', 'loginfailure' => 0, 'updatetime' => time()]);
        foreach ([1, 2] as $id) {
            Db::name('user')->insert(['id' => $id, 'username' => $id === 1 ? 'member' : 'other', 'nickname' => $id === 1 ? 'Member' : 'Other',
                'email' => $id === 1 ? 'member@example.invalid' : 'other@example.invalid', 'money' => 100, 'score' => 100, 'status' => 'normal']);
        }

        $probe = new class extends \app\common\library\Auth {
            public string $password = '';
            public function keeptime($seconds = 0) {}
            public function login($account, $password) { $this->password = $password; return true; }
            public function register($username, $password, $email = '', $mobile = '', $extend = []) { $this->password = $password; return true; }
            public function direct($id) { return true; }
            public function changepwd($password, $old = '', $ignore = false) { $this->password = $password; return true; }
            public function getUserinfo() { return []; }
        };
        $password = 'ab&<>cd123';
        $app->request->filter(['trim', 'strip_tags', 'htmlspecialchars']);
        $app->request->withPost(['account' => 'member', 'password' => $password]);
        check(outcome(function () use ($app, $probe) { (new ApiPasswordController($app, $probe))->login(); })->ok && $probe->password === $password, 'API 登录改写了密码');
        Db::name('sms')->insert(['mobile' => '13800000000', 'event' => 'register', 'code' => '123456', 'times' => 0, 'createtime' => time()]);
        foreach ([['username' => 'created', 'password' => 'a'], ['username' => 'ab', 'password' => $password], ['username' => 'created', 'password' => str_repeat('a', 31)]] as $invalid) {
            $app->request->withPost($invalid + ['mobile' => '13800000000', 'code' => '123456']);
            check(!outcome(function () use ($app, $probe) { (new ApiPasswordController($app, $probe))->register(); })->ok, '非法注册凭据被接受');
        }
        check(Db::name('sms')->count() === 1, '非法凭据消耗了短信验证码');
        $app->request->withPost(['username' => 'created', 'password' => $password, 'mobile' => '13800000000', 'code' => '123456']);
        check(outcome(function () use ($app, $probe) { (new ApiPasswordController($app, $probe))->register(); })->ok && $probe->password === $password, 'API 注册改写了密码');
        Db::name('user')->where('id', 1)->update(['mobile' => '13800000000']);
        Db::name('sms')->insert(['mobile' => '13800000000', 'event' => 'resetpwd', 'code' => '654321', 'times' => 0, 'createtime' => time()]);
        $app->request->withPost(['newpassword' => $password, 'mobile' => '13800000000', 'captcha' => '654321']);
        check(outcome(function () use ($app, $probe) { (new ApiPasswordController($app, $probe))->resetpwd(); })->ok && $probe->password === $password, 'API 重置密码改写了密码');
        $app->request->filter([]);
        echo "PASS API password login/register/reset\n";

        runWorkers($pdo, $database, 'login', 12);
        check((int)Db::name('admin')->where('id', 1)->value('loginfailure') === 10, '并发失败次数未准确锁定在十次');
        check(!$auth->login('auditor', 'correct-password'), '被锁定账户仍可登录');
        Db::name('admin')->where('id', 1)->update(['updatetime' => time() - 86401]);
        check(!$auth->login('auditor', 'wrong-password'), '错误密码被接受');
        check((int)Db::name('admin')->where('id', 1)->value('loginfailure') === 1, '过期失败次数未重置');
        check($auth->login('auditor', 'correct-password'), '正确密码登录失败');
        Db::name('admin')->where('id', 1)->update(['status' => 'hidden']);
        check(!$auth->login('auditor', 'correct-password') && !$auth->login('missing', 'correct-password'), '禁用或不存在的管理员被接受');
        echo "PASS concurrent admin login and lock expiry\n";

        $results = runWorkers($pdo, $database, 'edit', 2);
        check(count(array_filter($results, static fn($result) => $result === 'saved')) === 1, '并发余额修改必须只成功一次：' . implode('; ', $results));
        check(Db::name('user_money_log')->count() === 1, '失败的余额修改留下了日志');
        $balance = Db::name('user')->where('id', 1)->value('money');
        check(!memberEdit($app, ['money' => 90], ['money' => '100.00'])->ok, '旧余额表单被接受');
        check(!memberEdit($app, ['money' => 90], [])->ok, '缺少原值仍可修改余额');
        check(!memberEdit($app, ['money' => 'invalid'], ['money' => $balance])->ok, '非法余额被接受');
        check((string)Db::name('user')->where('id', 1)->value('money') === (string)$balance, '失败请求改变了余额');
        check(!memberEdit($app, ['username' => 'member', 'nickname' => 'Member', 'email' => 'member@example.invalid', 'money' => 90], ['money' => $balance], true)->ok, '写入失败未向上传递');
        check((string)Db::name('user')->where('id', 1)->value('money') === (string)$balance && Db::name('user_money_log')->count() === 1, '余额与日志未一起回滚');
        echo "PASS concurrent balance edits, stale forms and invalid input\n";

        $tokens = mysqlTokens();
        $tokens->set('old', 1, 60);
        check(!$tokens->rotate('old', 'wrong-owner', 2, 60) && $tokens->get('old'), '可轮换其他用户的令牌');
        check($tokens->rotate('old', 'new', 1, 60) && !$tokens->get('old') && $tokens->get('new'), 'MySQL 令牌轮换失败');
        check(!$tokens->rotate('old', 'replayed', 1, 60), '旧令牌被重复使用');
        $tokens->set('expired', 1, -1);
        check(!$tokens->rotate('expired', 'after-expiry', 1, 60), '过期令牌仍可轮换');
        $tokens->set('collision', 2, 60);
        try {
            $tokens->rotate('new', 'collision', 1, 60);
            throw new LogicException('重复令牌写入未失败');
        } catch (\think\db\exception\PDOException $e) {
            check($tokens->get('new'), '新令牌写入失败时旧令牌被删除');
        }
        $tokens->clear(1);
        $tokens->set('queued', 1, 60);
        check(runWorkers($pdo, $database, 'rotate', 1) === ['rejected'] && !$tokens->get('after-revoke'), '并发撤销后会话被重新签发');
        $memberAuth = \app\common\library\Auth::instance();
        foreach (['_user' => \app\common\model\User::find(1), '_token' => 'queued'] as $name => $value) {
            $property = new ReflectionProperty($memberAuth, $name);
            $property->setAccessible(true);
            $property->setValue($memberAuth, $value);
        }
        $app->request->tokenManager = new \app\common\library\token\Manager($app);
        \app\common\library\Token::$handler = $tokens;
        $app->request->withPost([]);
        check(!outcome(function () use ($app, $memberAuth) { (new ApiTokenController($app, $memberAuth))->refresh(); })->ok, '刷新接口复活了已撤销会话');
        $tokens->set('queued', 1, 60);
        check(outcome(function () use ($app, $memberAuth) { (new ApiTokenController($app, $memberAuth))->refresh(); })->ok && !$tokens->get('queued'), '正常刷新接口失败');
        $tokens->clear(1);
        $tokens->clear(2);
        echo "PASS MySQL token rotation, concurrent revocation and refresh endpoint\n";

        if ($redisPort = getenv('SECURITY_TEST_REDIS_PORT')) {
            $redisTokens = new \app\common\library\token\driver\Redis([
                'port' => (int)$redisPort, 'userprefix' => $database . ':user:', 'tokenprefix' => $database . ':token:',
            ]);
            try {
                $redisTokens->set('old', 1, 60);
                check(!$redisTokens->rotate('old', 'wrong-owner', 2, 60), 'Redis 可轮换其他用户的令牌');
                check($redisTokens->rotate('old', 'new', 1, 60) && !$redisTokens->get('old') && $redisTokens->get('new'), 'Redis 令牌轮换失败');
                check(!$redisTokens->rotate('old', 'replayed', 1, 60), 'Redis 接受重复轮换');
                $redisTokens->set('collision', 2, 60);
                check(!$redisTokens->rotate('new', 'collision', 1, 60) && $redisTokens->get('new'), 'Redis 轮换失败破坏了旧令牌');
                $redisTokens->clear(1);
                check(!$redisTokens->rotate('new', 'after-revoke', 1, 60) && !$redisTokens->get('after-revoke'), 'Redis 复活了已撤销会话');
                $redisTokens->set('permanent', 1, 0);
                check($redisTokens->rotate('permanent', 'permanent-new', 1, 0) && $redisTokens->get('permanent-new')['expiretime'] === 0, 'Redis 永久令牌轮换失败');
            } finally {
                $redisTokens->clear(1);
                $redisTokens->clear(2);
            }
            echo "PASS Redis token rotation, revocation and collision handling\n";
        } else {
            echo "SKIP Redis integration: set SECURITY_TEST_REDIS_PORT to enable\n";
        }

        $app->config->set(['savekey' => '/uploads/' . basename($publicFile)], 'upload');
        $memberAuth = \app\common\library\Auth::instance();
        $property = new ReflectionProperty($memberAuth, '_user');
        $property->setAccessible(true);
        $attachments = [];
        foreach ([1, 2] as $id) {
            $property->setValue($memberAuth, \app\common\model\User::find($id));
            $source = $temporary . DIRECTORY_SEPARATOR . 'upload-' . $id;
            file_put_contents($source, 'same');
            $upload = new \app\common\library\Upload(new \think\file\UploadedFile($source, 'same.txt', 'text/plain', null, true));
            $attachments[] = $upload->upload();
        }
        check($attachments[0]->id !== $attachments[1]->id && Db::name('attachment')->count() === 2, '不同上传者未获得独立附件记录');
        check((int)$attachments[0]->user_id === 1 && (int)$attachments[1]->user_id === 2, '附件归属错误');
        $deletions = 0;
        $app->event->listen('upload_delete', static function () use (&$deletions) { $deletions++; });
        check(outcome(function () use ($app, $attachments) { (new AttachmentController($app))->del((string)$attachments[0]->id); })->ok && is_file($publicFile), '删除附件破坏了其他用户的文件');
        check($deletions === 0, '共享文件仍有引用时触发了存储删除事件');
        $deleted = outcome(function () use ($app, $attachments) { (new AttachmentController($app))->del((string)$attachments[1]->id); });
        clearstatcache();
        check($deleted->ok && !is_file($publicFile), '最后一个附件删除后物理文件仍存在');
        check($deletions === 1, '最后一个引用未触发存储删除事件');
        echo "PASS identical uploads and shared-file deletion\n";

        $chunkId = '11111111-1111-4111-8111-111111111111';
        foreach ([0 => '12345', 1 => '6789'] as $index => $contents) {
            $source = $temporary . DIRECTORY_SEPARATOR . 'incoming-' . $index;
            file_put_contents($source, $contents);
            $upload = new \app\common\library\Upload(new \think\file\UploadedFile($source, 'blob', 'application/octet-stream', null, true));
            $upload->setChunkDir($temporary);
            try {
                $upload->chunk($chunkId, $index, 2);
                check($index === 0, '超限分片被接受');
            } catch (\app\common\exception\UploadException $e) {
                check($index === 1 && strpos($e->getMessage(), 'too big') !== false, '分片上传意外失败：' . $e->getMessage());
            }
        }
        file_put_contents($temporary . DIRECTORY_SEPARATOR . $chunkId . '-1.part', '6789');
        $upload = new \app\common\library\Upload();
        $upload->setChunkDir($temporary);
        try {
            $upload->merge($chunkId, 2, 'merged.txt');
            throw new RuntimeException('超限分片合并被接受');
        } catch (\app\common\exception\UploadException $e) {
            check(strpos($e->getMessage(), 'too big') !== false, '合并出现非预期错误：' . $e->getMessage());
        }
        file_put_contents($temporary . DIRECTORY_SEPARATOR . $chunkId . '-0.part', '1234');
        clearstatcache();
        check((int)$upload->merge($chunkId, 2, 'merged.txt')->filesize === 8, '达到大小上限的文件不能合并');
        echo "PASS cumulative chunk limit and exact-limit merge\n";

        Db::name('import_record')->insertAll([
            ['id' => 1, 'name' => 'own', 'code' => 'own', 'admin_id' => 1],
            ['id' => 2, 'name' => 'foreign', 'code' => 'foreign', 'admin_id' => 2],
        ]);
        check(!importRows($app, $importFile, [[2, 'changed', 'foreign', 1]])->ok, '导入越权更新他人记录');
        check(!importRows($app, $importFile, [[1, 'changed', 'own', 1]], false)->ok, '没有编辑权限仍可导入更新');
        $updated = importRows($app, $importFile, [[1, 'updated', 'own', 2]]);
        check($updated->ok, '合法导入更新失败：' . $updated->getMessage());
        check(Db::name('import_record')->where('id', 1)->value('name') === 'updated' && (int)Db::name('import_record')->where('id', 1)->value('admin_id') === 1, '导入改变了数据归属');
        check(!importRows($app, $importFile, [[999, 'missing', 'missing', 1]])->ok, '不存在的 ID 被当作更新接受');
        $csvName = "公司, ACME\nSecond \"quoted\" line";
        check(importRows($app, $importFile, [['', $csvName, 'CSV"1', 2]], false)->ok, 'CSV 引号或换行导致导入失败');
        $csvRow = Db::name('import_record')->where('code', 'CSV"1')->find();
        check($csvRow['name'] === $csvName && (int)$csvRow['admin_id'] === 1, 'CSV 内容或新增归属错误');
        check(importRows($app, $importFile, [['', '中文公司', 'GBK', 1]], true, 'GBK')->ok, 'GBK CSV 导入失败');
        check(Db::name('import_record')->where('code', 'GBK')->value('name') === '中文公司', 'GBK CSV 编码被破坏');
        check(!importRows($app, $importFile, [[1, 'rollback', 'own', 1], ['', 'inserted', 'rollback-new', 1], ['', 'FAIL', 'failure', 1]])->ok, '导入异常未传递');
        check(Db::name('import_record')->where('id', 1)->value('name') === 'updated' && !Db::name('import_record')->where('code', 'rollback-new')->find(), '整批导入没有同时回滚更新和新增');
        check(!importRows($app, $importFile, [['', 'duplicate', 'own', 1]])->ok && Db::name('import_record')->where('id', 1)->value('name') === 'updated', '新增导入覆盖了唯一键冲突的旧记录');
        echo "PASS import edit permission, data scope, full rollback and CSV formats\n";

        if (!class_exists('EasyWeChat\\Pay\\Application')) {
            $installer = new \app\common\service\InstallService();
            if (is_file($root . 'app/admin/command/Install/install.lock')) {
                check($installer->isInstalled() && $installer->getInstallInfo(), '网页安装器未识别已有 CLI 安装锁');
            }
            $property = new ReflectionProperty($installer, 'lockFile');
            $property->setAccessible(true);
            $property->setValue($installer, $temporary . DIRECTORY_SEPARATOR . '.install.lock');
            check(!$installer->isInstalled(), '缺少安装锁却判定为已安装');
            file_put_contents($installer->getLockFile(), '1');
            check($installer->isInstalled() && $installer->getInstallInfo()['lockFile'] === $installer->getLockFile(), '新安装锁或锁路径展示错误');
            $application = new \EasyWeChat\MiniProgram\Application([]);
            $application['app_code'] = new class {
                public function getUnlimit($scene, $payload) { return ['errcode' => 40097, 'errmsg' => 'invalid args']; }
            };
            $reflection = new ReflectionClass(\kernel\services\wechat\support\MiniAppService::class);
            $service = $reflection->newInstanceWithoutConstructor();
            $property = $reflection->getProperty('app');
            $property->setAccessible(true);
            $property->setValue($service, $application);
            try {
                $service->getUnlimitedQRCode('example');
                throw new LogicException('SDK 错误响应未被拒绝');
            } catch (RuntimeException $e) {
                check(strpos($e->getMessage(), 'invalid args') !== false, '小程序码丢失了 SDK 错误信息');
            }
            $application['app_code'] = new class {
                public function getUnlimit($scene, $payload) { return new \GuzzleHttp\Psr7\Response(200, [], 'image'); }
            };
            check((string)$service->getUnlimitedQRCode('example')->getBody() === 'image', '正常小程序码响应被破坏');
            echo "PASS TP6 legacy install lock and QR-code error\n";
        }
        echo "All security regression checks passed.\n";
    } finally {
        // 只清理本脚本生成的随机测试库和文件。
        $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
        foreach (new DirectoryIterator($temporary) as $file) {
            if ($file->isFile()) {
                unlink($file->getPathname());
            }
        }
        rmdir($temporary);
        if (is_file($publicFile)) {
            unlink($publicFile);
        }
        if (is_file($importFile)) {
            unlink($importFile);
        }
        if (!$uploadsExisted && is_dir(dirname($publicFile))) {
            rmdir(dirname($publicFile));
        }
    }
}

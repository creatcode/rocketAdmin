<?php

namespace app\common\service;

use Exception;
use PDO;
use think\facade\Config;
use think\facade\Db;
use util\Random;

class InstallService
{
    protected string $resourcePath;
    protected string $lockFile;

    public function __construct()
    {
        $this->resourcePath = app_path() . 'admin' . DIRECTORY_SEPARATOR . 'command' . DIRECTORY_SEPARATOR . 'Install' . DIRECTORY_SEPARATOR;
        $this->lockFile = root_path() . '.install.lock';
    }

    public function getLockFile(): string
    {
        return $this->lockFile;
    }

    public function isInstalled(): bool
    {
        return is_file($this->lockFile);
    }

    public function getInstallInfo(): array
    {
        if (!$this->isInstalled()) {
            return [];
        }

        $content = file_get_contents($this->lockFile);
        $data = json_decode((string)$content, true);

        return is_array($data) ? $data : ['installed' => true];
    }

    public function getEnvironmentStatus(): array
    {
        $checks = [];
        $checks[] = $this->makeCheck(
            'PHP',
            version_compare(PHP_VERSION, '7.4.0', '>='),
            sprintf('PHP %s / minimum 7.4.0', PHP_VERSION)
        );
        $checks[] = $this->makeCheck('PDO', extension_loaded('PDO'), 'PDO extension');
        $checks[] = $this->makeCheck('pdo_mysql', extension_loaded('pdo_mysql'), 'pdo_mysql extension');
        $checks[] = $this->makeCheck(
            'install.sql',
            is_file($this->getSqlFile()),
            __('Please go to the official website to download the full package or resource package and try to install')
        );

        foreach ($this->getRequiredDirectories() as $directory) {
            $checks[] = $this->makeCheck(
                $directory,
                is_dir(root_path() . $directory),
                __('Please go to the official website to download the full package or resource package and try to install')
            );
        }

        foreach ($this->getWritableTargets() as $target) {
            $checks[] = $this->makeCheck(
                $target['label'],
                $target['check'](),
                $target['message']
            );
        }

        return [
            'ok'    => !in_array(false, array_column($checks, 'passed')),
            'items' => $checks,
        ];
    }

    public function checkEnv(): void
    {
        $status = $this->getEnvironmentStatus();
        if ($status['ok']) {
            return;
        }

        foreach ($status['items'] as $item) {
            if (!$item['passed']) {
                throw new Exception($item['message']);
            }
        }

        throw new Exception('Environment check failed');
    }

    public function install(array $data, bool $force = false): array
    {
        if ($this->isInstalled() && !$force) {
            throw new Exception(__('The system has been installed. If you need to reinstall, please remove %s first', '.install.lock'));
        }

        $this->checkEnv();
        $this->validateInstallData($data);

        $connection = $this->importDatabase($data);
        $this->writeEnvFile($data);
        $this->refreshTokenKey();
        $this->initializeAccounts($connection, $data);
        $this->syncSiteConfig($connection, $data['siteName']);
        $this->writeLock($data);

        return [
            'adminPath' => '/admin/index/login',
            'lockFile'  => $this->lockFile,
        ];
    }

    protected function validateInstallData(array $data): void
    {
        if (empty($data['mysqlHostname'])) {
            throw new Exception(__('Please input correct database'));
        }
        if (!preg_match('/^[1-9]\d{0,4}$/', (string)$data['mysqlHostport']) || (int)$data['mysqlHostport'] > 65535) {
            throw new Exception(__('Please input correct database'));
        }
        if (empty($data['mysqlDatabase']) || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $data['mysqlDatabase'])) {
            throw new Exception(__('Please input correct database'));
        }
        if (empty($data['mysqlPrefix']) || !preg_match('/^[A-Za-z0-9_]{1,30}$/', $data['mysqlPrefix'])) {
            throw new Exception(__('Please input correct database'));
        }
        if (empty($data['mysqlUsername'])) {
            throw new Exception(__('Please input correct database'));
        }
        if (!preg_match('/^\w{3,30}$/', $data['adminUsername'])) {
            throw new Exception(__('Please input correct username'));
        }
        if (!preg_match('/^[\S]{6,30}$/', $data['adminPassword'])) {
            throw new Exception(__('Please input correct password'));
        }
        if (!filter_var($data['adminEmail'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception(__('Please input correct username'));
        }

        $weakPasswords = ['123456', '12345678', '123456789', '654321', '111111', '000000', 'password', 'qwerty', 'abc123', '1qaz2wsx'];
        if (in_array(strtolower($data['adminPassword']), $weakPasswords)) {
            throw new Exception(__('Password is too weak'));
        }

        if (empty($data['siteName']) || preg_match('/(fastadmin|rocketadmin)/i', $data['siteName'])) {
            throw new Exception(__('Please input correct website'));
        }
    }

    protected function importDatabase(array $data)
    {
        $default = Config::get('database.default');
        $config = Config::get('database.connections.' . $default);
        $sqlFile = $this->getSqlFile();
        if (!is_file($sqlFile)) {
            throw new Exception(__('Please go to the official website to download the full package or resource package and try to install'));
        }

        $sql = file_get_contents($sqlFile);
        if ($sql === false) {
            throw new Exception(__('Please go to the official website to download the full package or resource package and try to install'));
        }
        $sql = str_replace('`fa_', '`' . $data['mysqlPrefix'], $sql);
        $database = $this->quoteIdentifier($data['mysqlDatabase']);

        try {
            $pdo = new PDO(
                "{$config['type']}:host={$data['mysqlHostname']}" . ($data['mysqlHostport'] ? ";port={$data['mysqlHostport']}" : ''),
                $data['mysqlUsername'],
                $data['mysqlPassword']
            );
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS {$database} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;");
        } catch (\PDOException $e) {
            throw new Exception($e->getMessage());
        }

        $databaseConfig = Config::get('database');
        $databaseConfig['connections']['install'] = [
            'type'            => $config['type'],
            'hostname'        => $data['mysqlHostname'],
            'hostport'        => $data['mysqlHostport'],
            'database'        => $data['mysqlDatabase'],
            'username'        => $data['mysqlUsername'],
            'password'        => $data['mysqlPassword'],
            'charset'         => 'utf8mb4',
            'prefix'          => $data['mysqlPrefix'],
            'params'          => [],
            'fields_strict'   => true,
            'break_reconnect' => false,
        ];
        Config::set($databaseConfig, 'database');

        try {
            $connection = Db::connect('install');
            $connection->execute('SELECT 1');
            $connection->getPdo()->exec($sql);
        } catch (\PDOException $e) {
            throw new Exception($e->getMessage());
        }

        return $connection;
    }

    protected function writeEnvFile(array $data): void
    {
        $envFile = root_path() . '.env';
        $envSampleFile = root_path() . '.env.sample';

        if (!is_file($envFile)) {
            if (!copy($envSampleFile, $envFile)) {
                throw new Exception(__('Failed to copy %s to %s', '.env.sample', '.env'));
            }
        }

        $envText = file_get_contents($envFile);
        if ($envText === false) {
            throw new Exception(__('The current permissions are insufficient to write the file %s', '.env'));
        }

        $replaceMap = [
            'TYPE'     => 'mysql',
            'HOSTNAME' => $data['mysqlHostname'],
            'DATABASE' => $data['mysqlDatabase'],
            'USERNAME' => $data['mysqlUsername'],
            'PASSWORD' => $data['mysqlPassword'],
            'HOSTPORT' => $data['mysqlHostport'],
            'PREFIX'   => $data['mysqlPrefix'],
            'CHARSET'  => 'utf8mb4',
        ];

        foreach ($replaceMap as $key => $value) {
            $envText = $this->replaceEnvValue($envText, $key, (string)$value);
        }

        if (file_put_contents($envFile, $envText) === false) {
            throw new Exception(__('The current permissions are insufficient to write the file %s', '.env'));
        }
    }

    protected function refreshTokenKey(): void
    {
        $tokenConfigFile = config_path() . 'token.php';
        $tokenConfig = include $tokenConfigFile;
        $tokenConfig['key'] = Random::alnum(32);

        if (file_put_contents($tokenConfigFile, '<?php' . "\n\nreturn " . var_export_short($tokenConfig) . ";\n") === false) {
            throw new Exception(__('The current permissions are insufficient to write the file %s', 'config/token.php'));
        }
    }

    protected function initializeAccounts($connection, array $data): void
    {
        $avatar = '/assets/img/avatar.png';

        $adminSalt = substr(md5(uniqid((string)mt_rand(), true)), 0, 6);
        $adminPassword = md5(md5($data['adminPassword']) . $adminSalt);
        $connection->name('admin')->where('username', 'admin')->update([
            'username' => $data['adminUsername'],
            'email'    => $data['adminEmail'],
            'avatar'   => $avatar,
            'password' => $adminPassword,
            'salt'     => $adminSalt,
        ]);

        $userSalt = substr(md5(uniqid((string)mt_rand(), true)), 0, 6);
        $userPassword = md5(md5(Random::alnum(8)) . $userSalt);
        $connection->name('user')->where('username', 'admin')->update([
            'avatar'   => $avatar,
            'password' => $userPassword,
            'salt'     => $userSalt,
        ]);
    }

    protected function syncSiteConfig($connection, string $siteName): void
    {
        $connection->name('config')->where('name', 'name')->update(['value' => $siteName]);

        $siteConfigFile = config_path() . 'site.php';
        $siteConfig = include $siteConfigFile;
        $configList = $connection->name('config')->select()->toArray();

        foreach ($configList as $value) {
            if (in_array($value['type'], ['selects', 'checkbox', 'images', 'files'])) {
                $value['value'] = is_array($value['value']) ? $value['value'] : explode(',', $value['value']);
            }
            if ($value['type'] === 'array') {
                $value['value'] = (array)json_decode($value['value'], true);
            }
            $siteConfig[$value['name']] = $value['value'];
        }

        $siteConfig['name'] = $siteName;
        if (file_put_contents($siteConfigFile, '<?php' . "\n\nreturn " . var_export_short($siteConfig) . ";\n") === false) {
            throw new Exception(__('The current permissions are insufficient to write the file %s', 'config/site.php'));
        }
    }

    protected function writeLock(array $data): void
    {
        $payload = json_encode([
            'installed'   => true,
            'site'        => $data['siteName'],
            'database'    => $data['mysqlDatabase'],
            'prefix'      => $data['mysqlPrefix'],
            'admin_user'  => $data['adminUsername'],
            'lockFile'    => $this->lockFile,
            'installedAt' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        if (file_put_contents($this->lockFile, $payload) === false) {
            throw new Exception(__('The current permissions are insufficient to write the file %s', '.install.lock'));
        }
    }

    protected function replaceEnvValue(string $content, string $key, string $value): string
    {
        $pattern = '/(^\s*' . preg_quote($key, '/') . '\s*=\s*).*$/mi';
        $envValue = $this->formatEnvValue($value);
        $result = preg_replace_callback($pattern, function ($matches) use ($envValue) {
            return $matches[1] . $envValue;
        }, $content);

        if ($result !== null && $result !== $content) {
            return $result;
        }

        return rtrim($content) . PHP_EOL . strtoupper($key) . ' = ' . $envValue . PHP_EOL;
    }

    protected function getSqlFile(): string
    {
        return $this->resourcePath . 'fastadmin.sql';
    }

    protected function quoteIdentifier(string $value): string
    {
        return '`' . str_replace('`', '``', $value) . '`';
    }

    protected function formatEnvValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/\s|#|"|\'|\\\\/', $value)) {
            return '"' . addcslashes($value, "\\\"") . '"';
        }

        return $value;
    }

    protected function getRequiredDirectories(): array
    {
        return [
            'vendor',
            'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'libs',
        ];
    }

    protected function getWritableTargets(): array
    {
        return [
            [
                'label'   => '.env',
                'message' => __('The current permissions are insufficient to write the file %s', '.env'),
                'check'   => function () {
                    $envFile = root_path() . '.env';
                    $envSample = root_path() . '.env.sample';
                    return is_file($envFile) ? is_really_writable($envFile) : is_file($envSample) && is_really_writable(root_path());
                },
            ],
            [
                'label'   => 'config/token.php',
                'message' => __('The current permissions are insufficient to write the file %s', 'config/token.php'),
                'check'   => function () {
                    return is_really_writable(config_path() . 'token.php');
                },
            ],
            [
                'label'   => 'config/site.php',
                'message' => __('The current permissions are insufficient to write the file %s', 'config/site.php'),
                'check'   => function () {
                    return is_really_writable(config_path() . 'site.php');
                },
            ],
            [
                'label'   => '.install.lock',
                'message' => __('The current permissions are insufficient to write the file %s', '.install.lock'),
                'check'   => function () {
                    return is_really_writable(root_path());
                },
            ],
        ];
    }

    protected function makeCheck(string $name, bool $passed, string $message): array
    {
        return [
            'name'    => $name,
            'passed'  => $passed,
            'message' => $message,
        ];
    }
}

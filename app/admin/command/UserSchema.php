<?php

namespace app\admin\command;

use RuntimeException;
use PDO;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

/**
 * 为已有会员表补齐登录保护与身份唯一约束，不自动合并重复账号。
 */
class UserSchema extends Command
{
    protected function configure()
    {
        $this->setName('user:secure-schema')->setDescription('补齐会员登录保护字段和唯一约束');
    }

    protected function execute(Input $input, Output $output)
    {
        $this->upgrade();
        $output->info('会员表安全约束已更新');
        return 0;
    }

    public function upgrade(): void
    {
        $table = Db::name('user')->getTable();
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            throw new RuntimeException('会员表名称无效');
        }
        $connection = Db::connect();
        $connection->query('SELECT 1');
        $pdo = $connection->getPdo();
        $pdo->exec("LOCK TABLES `{$table}` WRITE");
        try {
            foreach (['username', 'email', 'mobile'] as $field) {
                $optional = $field === 'username' ? '' : " AND `{$field}` <> ''";
                if ($pdo->query("SELECT 1 FROM `{$table}` WHERE `{$field}` IS NOT NULL{$optional} GROUP BY `{$field}` HAVING COUNT(*) > 1 LIMIT 1")->fetchColumn()) {
                    throw new RuntimeException("会员 {$field} 存在重复记录，请先人工处理；本次未修改数据");
                }
            }
            $columns = $pdo->query("SHOW FULL COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
            $changes = [];
            foreach (['email', 'mobile'] as $field) {
                $column = $columns[$field];
                if ($column['Null'] !== 'YES' || $column['Default'] !== null) {
                    $changes[] = "MODIFY `{$field}` " . $column['Type'] . ' COLLATE ' . $column['Collation']
                        . ' NULL DEFAULT NULL COMMENT ' . $pdo->quote($column['Comment']);
                }
            }
            if (!isset($columns['loginfailuretime'])) {
                $changes[] = "ADD `loginfailuretime` bigint(16) NOT NULL DEFAULT 0 COMMENT '最后登录失败时间'";
            }
            if ($changes) {
                $pdo->exec("ALTER TABLE `{$table}` " . implode(', ', $changes));
            }
            foreach (['email', 'mobile'] as $field) {
                $pdo->exec("UPDATE `{$table}` SET `{$field}` = NULL WHERE `{$field}` = ''");
            }
            $indexes = [];
            foreach ($pdo->query("SHOW INDEX FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC) as $index) {
                $indexes[$index['Key_name']][] = $index;
            }
            $changes = [];
            foreach (['username', 'email', 'mobile'] as $field) {
                $unique = false;
                foreach ($indexes as $parts) {
                    if (count($parts) === 1 && $parts[0]['Column_name'] === $field && !$parts[0]['Non_unique'] && $parts[0]['Sub_part'] === null) {
                        $unique = true;
                    }
                }
                if ($unique) {
                    continue;
                }
                if (isset($indexes[$field])) {
                    if (count($indexes[$field]) !== 1 || $indexes[$field][0]['Column_name'] !== $field) {
                        throw new RuntimeException("索引 {$field} 与预期结构不符，请人工检查");
                    }
                    $changes[] = "DROP INDEX `{$field}`";
                }
                $changes[] = "ADD UNIQUE INDEX `{$field}` (`{$field}`)";
            }
            if ($changes) {
                $pdo->exec("ALTER TABLE `{$table}` " . implode(', ', $changes));
            }
        } finally {
            $pdo->exec('UNLOCK TABLES');
        }
    }
}

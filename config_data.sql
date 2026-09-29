-- ============================================================
-- 组合数据功能：数据表结构
-- 由 COMBINATION_DATA_IMPLEMENTATION_PLAN.md 实施产生，需手动执行，脚本不会自动运行
-- 表前缀按 .env 的 PREFIX（当前 im_）编写，若你的库前缀不同请整体替换
-- ============================================================

-- 1. 数据组定义
CREATE TABLE `im_system_group` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '数据组ID',
  `name` varchar(50) NOT NULL DEFAULT '' COMMENT '数据组标识',
  `title` varchar(100) NOT NULL DEFAULT '' COMMENT '数据组名称',
  `tip` varchar(255) NOT NULL DEFAULT '' COMMENT '用途说明',
  `fields` text NULL COMMENT '字段定义(JSON)',
  `weigh` int(10) NOT NULL DEFAULT 0 COMMENT '排序',
  `status` varchar(30) NOT NULL DEFAULT 'normal' COMMENT '状态:normal/hidden',
  `createtime` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '创建时间',
  `updatetime` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='组合数据组定义';

-- 2. 组内记录
CREATE TABLE `im_system_group_data` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '记录ID',
  `group_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '所属数据组ID',
  `value` text NULL COMMENT '记录值(JSON)',
  `weigh` int(10) NOT NULL DEFAULT 0 COMMENT '排序',
  `status` varchar(30) NOT NULL DEFAULT 'normal' COMMENT '状态:normal/hidden',
  `createtime` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '创建时间',
  `updatetime` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `group_id` (`group_id`,`status`,`weigh`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='组合数据记录';

-- 系统目录迁移：保留已有权限节点ID和角色授权，重复执行不会新增重复节点
START TRANSACTION;
UPDATE `im_auth_rule`
SET `name` = CONCAT('system/', `name`), `updatetime` = UNIX_TIMESTAMP()
WHERE `name` IN ('about', 'upgrade') OR `name` REGEXP '^(about|upgrade)/';

UPDATE `im_auth_rule`
SET `name` = CONCAT('system/', REPLACE(REPLACE(SUBSTRING(`name`, 9), 'systemgroupdata', 'system_group_data'), 'systemgroup', 'system_group')), `updatetime` = UNIX_TIMESTAMP()
WHERE `name` REGEXP '^general[/.]systemgroup(data)?(/|$)';

UPDATE `im_auth_rule` AS child
JOIN `im_auth_rule` AS parent ON parent.`name` = 'system'
SET child.`pid` = parent.`id`
WHERE child.`name` IN ('system/about', 'system/upgrade', 'system/system_group');

INSERT INTO `im_auth_rule` (`pid`, `name`, `title`, `icon`, `ismenu`, `status`, `createtime`, `updatetime`, `weigh`)
SELECT parent.`id`, 'system/system_group', '组合数据', 'fa fa-th-large', 1, 'normal', UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 50
FROM `im_auth_rule` AS parent
WHERE parent.`name` = 'system'
AND NOT EXISTS (SELECT 1 FROM `im_auth_rule` WHERE `name` = 'system/system_group');

INSERT INTO `im_auth_rule` (`pid`, `name`, `title`, `icon`, `ismenu`, `status`, `createtime`, `updatetime`)
SELECT parent.`id`, CONCAT(parent.`name`, '/', actions.`name`), actions.`title`, 'fa fa-circle-o', 0, 'normal', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM `im_auth_rule` AS parent
JOIN (SELECT 'index' AS `name`, '查看' AS `title` UNION ALL SELECT 'add', '添加' UNION ALL SELECT 'edit', '编辑' UNION ALL SELECT 'del', '删除' UNION ALL SELECT 'multi', '批量更新') AS actions
WHERE parent.`name` = 'system/system_group'
AND NOT EXISTS (SELECT 1 FROM `im_auth_rule` WHERE `name` = CONCAT(parent.`name`, '/', actions.`name`));

INSERT INTO `im_auth_rule` (`pid`, `name`, `title`, `icon`, `ismenu`, `status`, `createtime`, `updatetime`)
SELECT parent.`id`, 'system/system_group_data', '组合数据记录', 'fa fa-list-alt', 0, 'normal', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM `im_auth_rule` AS parent
WHERE parent.`name` = 'system/system_group'
AND NOT EXISTS (SELECT 1 FROM `im_auth_rule` WHERE `name` = 'system/system_group_data');

UPDATE `im_auth_rule` AS child
JOIN `im_auth_rule` AS parent ON parent.`name` = 'system/system_group'
SET child.`pid` = parent.`id`
WHERE child.`name` = 'system/system_group_data';

INSERT INTO `im_auth_rule` (`pid`, `name`, `title`, `icon`, `ismenu`, `status`, `createtime`, `updatetime`)
SELECT parent.`id`, CONCAT(parent.`name`, '/', actions.`name`), actions.`title`, 'fa fa-circle-o', 0, 'normal', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM `im_auth_rule` AS parent
JOIN (SELECT 'index' AS `name`, '查看' AS `title` UNION ALL SELECT 'add', '添加' UNION ALL SELECT 'edit', '编辑' UNION ALL SELECT 'del', '删除' UNION ALL SELECT 'multi', '批量更新') AS actions
WHERE parent.`name` = 'system/system_group_data'
AND NOT EXISTS (SELECT 1 FROM `im_auth_rule` WHERE `name` = CONCAT(parent.`name`, '/', actions.`name`));
COMMIT;

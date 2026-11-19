<?php

namespace app\common\library;

use think\Exception;
use think\Model;
use think\db\Query;
use util\Tree;

/**
 * SelectPage 下拉搜索查询构建器
 *
 * 仅负责参数规范化校验与查询执行，不依赖请求对象、认证或 HTTP 响应；
 * 数据范围条件由控制器从服务端授权体系计算后注入，count 与 list 查询分别完整应用。
 * 支持 Model 与 Db Query(setTable) 两种数据源。
 */
class SelectPage
{
    /**
     * 模型或查询对象
     * @var Model|Query
     */
    protected $model;

    /**
     * 服务端声明的可显示字段
     * @var array|string
     */
    protected $selectpageFields = '*';

    /**
     * 允许查询/排序/显示的字段（小写）
     * @var array
     */
    protected $allowedFields = [];

    /**
     * 服务端数据限制条件 [字段, 操作符, 值] 列表
     * 每次构建查询（count/list）时都会以 AND 重新注入
     * @var array
     */
    protected $limitConditions = [];

    /**
     * 允许的 custom 条件操作符
     * 与 think-orm Builder 支持的表达式一致，不含 EXP/EXISTS/COLUMN 等表达式能力
     * @var array
     */
    protected static $allowedOperators = [
        '=', '<>', '>', '>=', '<', '<=',
        'LIKE', 'NOT LIKE',
        'IN', 'NOT IN',
        'BETWEEN', 'NOT BETWEEN',
        'NULL', 'NOT NULL',
        '> TIME', '< TIME', '>= TIME', '<= TIME',
        'BETWEEN TIME', 'NOT BETWEEN TIME',
    ];

    /**
     * 树形/初始化模式的分页大小上限
     * @var integer
     */
    protected static $maxPageSize = 999999;

    /**
     * 构造查询器
     * @param Model|Query $model  模型或查询对象
     * @param mixed       $fields 服务端声明的可显示字段（'*'、逗号分隔字符串或数组）
     */
    public function __construct($model, $fields = '*')
    {
        $this->model = $model;
        $this->selectpageFields = ($fields === null || $fields === '') ? '*' : $fields;
        $this->allowedFields = array_map('strtolower', (array)$model->getTableFields());
        //服务端声明字段同时允许用于查询与排序
        foreach ($this->resolveSelectpageFields() as $field) {
            $this->allowedFields[] = strtolower($field);
        }
    }

    /**
     * 注入服务端数据限制条件
     * 条件由调用方基于授权体系生成，客户端参数不可影响；空授权集合保持“无可访问数据”语义
     * @param array $conditions [字段, 操作符, 值] 三元组列表
     * @return $this
     * @throws Exception
     */
    public function setLimitConditions(array $conditions)
    {
        foreach ($conditions as $condition) {
            if (!is_array($condition) || count($condition) < 3 || !is_string($condition[0])) {
                throw new Exception('Invalid parameters');
            }
        }
        $this->limitConditions = $conditions;

        return $this;
    }

    /**
     * 执行查询
     * @param array $params SelectPage 请求参数
     * @return array ['list' => array, 'total' => int]
     * @throws Exception 参数非法时抛出，由控制器转换为错误响应
     */
    public function execute(array $params)
    {
        $word = $this->getArrayParam($params, 'q_word');
        $page = max(1, (int)($params['pageNumber'] ?? 1));
        $pagesize = max(1, min(self::$maxPageSize, (int)($params['pageSize'] ?? 10)));
        $andor = strtoupper((string)($params['andOr'] ?? 'AND')) === 'OR' ? 'OR' : 'AND';
        $showField = trim((string)($params['showField'] ?? ''));
        $keyField = trim((string)($params['keyField'] ?? ''));
        $keyValue = array_key_exists('keyValue', $params) ? $params['keyValue'] : null;
        $searchField = $this->getArrayParam($params, 'searchField');
        $custom = isset($params['custom']) && is_array($params['custom']) ? $params['custom'] : [];
        $isTree = !empty($params['isTree']);
        $isHtml = !empty($params['isHtml']);

        $showField = $showField !== '' ? $showField : 'name';
        $keyField = $this->normalizeKeyField($keyField);

        //初始化传值与树形模式的分页及关键词处理
        $keyValues = null;
        if ($keyValue !== null) {
            $keyValues = $this->normalizeKeyValues($keyValue);
            $pagesize = self::$maxPageSize;
        } elseif ($isTree) {
            $word = [];
            $pagesize = self::$maxPageSize;
        }

        $order = $this->buildOrder($params['orderBy'] ?? []);
        $this->validateField($showField);
        $searchField = $this->validateSearchFields($searchField, $showField, $andor);
        $custom = $this->validateCustomConditions($custom);

        $where = $keyValues === null
            ? $this->buildSearchWhere($word, $andor, $showField, $searchField, $custom)
            //think-orm 数组条件：值为数组即 IN 语义，值经参数绑定
            : [$keyField => $keyValues];

        //查询条件不跨语句保留，count 与 list 分别构建并注入数据范围条件
        $total = $this->buildQuery($where, $order, $keyField, $keyValues)->count();
        if ($total <= 0) {
            return ['list' => [], 'total' => 0];
        }

        $datalist = $this->buildQuery($where, $order, $keyField, $keyValues)
            ->page($page, $pagesize)
            ->select();

        $list = $this->buildResultList($datalist, $keyField, $showField);

        if ($isTree && $keyValues === null) {
            $list = $this->buildTreeList($list, $showField, $isHtml);
        }

        return ['list' => $list, 'total' => $total];
    }

    /**
     * 校验主键字段并补充缺省值
     * @param string $keyField 请求的主键字段
     * @return string
     * @throws Exception
     */
    protected function normalizeKeyField($keyField)
    {
        if ($keyField === '') {
            $pk = $this->model->getPk();
            if (is_string($pk) && $pk !== '') {
                $keyField = $pk;
            }
        }
        $this->validateField($keyField);

        return $keyField;
    }

    /**
     * 规范化初始化主键值
     * 保留字符串主键、0、引号及中文，值经参数绑定处理，不做任何 SQL 转义拼接
     * @param mixed $keyValue
     * @return array
     * @throws Exception
     */
    protected function normalizeKeyValues($keyValue)
    {
        if (is_array($keyValue)) {
            $values = [];
            foreach ($keyValue as $value) {
                if (!is_scalar($value) && $value !== null) {
                    throw new Exception('Invalid parameters');
                }
                $values[] = (string)$value;
            }
        } elseif (is_scalar($keyValue)) {
            $values = explode(',', (string)$keyValue);
        } else {
            throw new Exception('Invalid parameters');
        }

        return array_unique($values);
    }

    /**
     * 获取数组参数，兼容逗号分隔字符串与非标量过滤
     * @param array  $params
     * @param string $key
     * @return array
     */
    protected function getArrayParam(array $params, $key)
    {
        $value = $params[$key] ?? [];
        if (is_array($value)) {
            $result = [];
            foreach ($value as $item) {
                //丢弃嵌套数组等非法层级，仅保留标量项
                if (is_scalar($item)) {
                    $result[] = (string)$item;
                }
            }

            return $result;
        }
        if (is_string($value) && strpos($value, ',') !== false) {
            return array_map('trim', explode(',', $value));
        }
        if (is_scalar($value) && (string)$value !== '') {
            return [(string)$value];
        }

        return [];
    }

    /**
     * 校验字段是否在允许列表中
     * @param string $field
     * @throws Exception
     */
    protected function validateField($field)
    {
        if ($field === '' || !in_array(strtolower($field), $this->allowedFields, true)) {
            throw new Exception('Invalid parameters');
        }
    }

    /**
     * 校验搜索字段，返回按 andOr 逻辑连接的有效字段串
     * 兼容 'a|b'、'a,b' 形式的组合传入；全部无效时回退到显示字段
     * @param array  $searchField
     * @param string $showField
     * @param string $andor
     * @return string
     * @throws Exception
     */
    protected function validateSearchFields(array $searchField, $showField, $andor)
    {
        $validFields = [];
        foreach ($searchField as $item) {
            foreach (preg_split('/[&|,]/', (string)$item) as $field) {
                $field = trim($field);
                if ($field === '' || !in_array(strtolower($field), $this->allowedFields, true)) {
                    if ($field !== '') {
                        throw new Exception('Invalid parameters');
                    }
                    continue;
                }
                $validFields[] = $field;
            }
        }
        if (empty($validFields)) {
            return $showField;
        }

        return implode($andor === 'AND' ? '&' : '|', $validFields);
    }

    /**
     * 校验并规范化自定义条件
     * 字段必须是真实表字段或服务端声明字段；操作符限定为受支持的比较操作
     * @param array $custom
     * @return array [字段 => 值] 或 [字段 => [操作符, 值]]
     * @throws Exception
     */
    protected function validateCustomConditions(array $custom)
    {
        $result = [];
        foreach ($custom as $field => $condition) {
            $field = (string)$field;
            $this->validateField($field);
            if (is_array($condition) && count($condition) >= 2) {
                $operator = strtoupper(trim((string)$condition[0]));
                if (!in_array($operator, self::$allowedOperators, true)) {
                    throw new Exception('Invalid parameters');
                }
                $result[$field] = [$operator, $condition[1]];
            } else {
                $result[$field] = is_scalar($condition) || $condition === null ? $condition : '';
            }
        }

        return $result;
    }

    /**
     * 构建排序，方向仅允许 ASC/DESC，非法字段静默忽略
     * @param array $orderby
     * @return array [字段 => ASC|DESC]
     */
    protected function buildOrder(array $orderby)
    {
        $order = [];
        foreach ($orderby as $item) {
            if (!is_array($item) || !isset($item[0], $item[1])) {
                continue;
            }
            $field = trim((string)$item[0]);
            $direction = strtoupper((string)$item[1]) === 'ASC' ? 'ASC' : 'DESC';
            if ($field !== '' && in_array(strtolower($field), $this->allowedFields, true)) {
                $order[$field] = $direction;
            }
        }

        return $order;
    }

    /**
     * 构建搜索条件闭包，关键词与 custom 均经参数绑定
     * @param array  $words
     * @param string $andor
     * @param string $showField
     * @param string $searchField
     * @param array  $custom
     * @return \Closure
     */
    protected function buildSearchWhere(array $words, $andor, $showField, $searchField, array $custom)
    {
        return function ($query) use ($words, $andor, $showField, $searchField, $custom) {
            $words = array_filter(array_unique($words));
            if (count($words) == 1) {
                $query->where($searchField, 'like', '%' . reset($words) . '%');
            } elseif ($words) {
                $query->where(function ($query) use ($words, $searchField) {
                    foreach ($words as $word) {
                        $query->whereOr(function ($query) use ($word, $searchField) {
                            $query->where($searchField, 'like', "%{$word}%");
                        });
                    }
                });
            }
            foreach ($custom as $field => $condition) {
                if (is_array($condition)) {
                    $query->where($field, $condition[0], $condition[1]);
                } else {
                    $query->where($field, '=', $condition);
                }
            }
        };
    }

    /**
     * 构建查询：搜索/初始化条件 + 服务端数据限制 + 排序
     * 排序使用参数绑定生成 FIELD()，不拼接转义字符串
     * @param \Closure|array $where
     * @param array          $order
     * @param string         $keyField
     * @param array|null     $keyValues
     * @return Query
     */
    protected function buildQuery($where, array $order, $keyField, $keyValues)
    {
        //Query 源每次从初始状态复制构建，count/list 及连续执行互不积累条件，也不修改调用方传入的对象
        if ($this->model instanceof Query) {
            $query = (clone $this->model)->where($where);
        } else {
            $query = $this->model->where($where);
        }
        foreach ($this->limitConditions as $condition) {
            $query->where($condition[0], $condition[1], $condition[2]);
        }
        if ($keyValues !== null && $keyValues) {
            $query->orderRaw('FIELD(`' . $keyField . '`, ' . implode(',', array_fill(0, count($keyValues), '?')) . ')', $keyValues);
        } else {
            $query->order($order);
        }

        return $query;
    }

    /**
     * 构建结果列表
     * @param mixed  $datalist
     * @param string $keyField
     * @param string $showField
     * @return array
     */
    protected function buildResultList($datalist, $keyField, $showField)
    {
        $list = [];
        foreach ($datalist as $item) {
            unset($item['password'], $item['salt']);
            if ($this->selectpageFields == '*') {
                $row = $item instanceof Model ? $item->toArray() : (array)$item;
                $result = [
                    $keyField  => $row[$keyField] ?? '',
                    $showField => $row[$showField] ?? '',
                ];
            } else {
                $row = $item instanceof Model ? $item->toArray() : (array)$item;
                $result = array_intersect_key($row, array_flip($this->resolveSelectpageFields()));
            }
            $result['pid'] = isset($row['pid']) ? $row['pid'] : (isset($row['parent_id']) ? $row['parent_id'] : 0);
            //复杂值不参与 HTML 编码，统一编码为空串
            $result = array_map(function ($value) {
                return is_scalar($value) ? htmlentities((string)$value, ENT_QUOTES, 'UTF-8') : '';
            }, $result);
            $list[] = $result;
        }

        return $list;
    }

    /**
     * 构建树形列表
     * @param array  $list
     * @param string $showField
     * @param bool   $isHtml
     * @return array
     */
    protected function buildTreeList(array $list, $showField, $isHtml)
    {
        $tree = Tree::instance();
        $tree->init($list, 'pid');
        $list = $tree->getTreeList($tree->getTreeArray(0), $showField);
        if (!$isHtml) {
            foreach ($list as &$item) {
                $item = str_replace('&nbsp;', ' ', $item);
            }
            unset($item);
        }

        return $list;
    }

    /**
     * 解析服务端声明的可显示字段
     * @return array
     */
    protected function resolveSelectpageFields()
    {
        if (is_array($this->selectpageFields)) {
            return $this->selectpageFields;
        }
        if ($this->selectpageFields && $this->selectpageFields != '*') {
            return array_map('trim', explode(',', $this->selectpageFields));
        }

        return [];
    }
}

<?php
/**
 * Created by PhpStorm.
 * User: LYi-Ho
 * Date: 2018/11/24
 * Time: 17:37
 */

namespace Common\Model;

use Library\Exception\InternalServerErrorException;
use function PhalApi\T;

trait Common
{
    /**
     * @var array
     */
    protected static $tablePrefixs = [];


    /**
     * 根据主键值返回对应的表名，注意分表的情况
     *
     * 默认表名为：[表前缀] + 全部小写的匹配表名
     * @throws InternalServerErrorException
     */
    public function getName($id = null)
    {
        $table = $this->getTableName($id);
        return $this->getTablePrefix($table) . $table;
    }

    /**
     * 根据表名获取表前缀
     *
     * - 考虑到配置中的表主键不一定是id，所以这里将默认自动装配数据库配置并匹配对应的主键名
     * - 如果不希望因自动匹配所带来的性能问题，可以在每个实现子类手工返回对应的主键名
     * - 注意分表的情况
     *
     * @param string $table 表名/分表名
     * @return string 主键名
     * @throws InternalServerErrorException
     */
    protected function getTablePrefix($table)
    {
        if (empty(static::$tablePrefixs)) {
            $this->loadTablePrefixs();
        }

        return isset(static::$tablePrefixs[$table]) ? static::$tablePrefixs[$table] : static::$tablePrefixs['__default__'];
    }

    /**
     * @throws InternalServerErrorException
     */
    protected function loadTablePrefixs()
    {
        $tables = self::DI()->config->get('dbs.tables');
        if (empty($tables)) {
            throw new InternalServerErrorException(T('dbs.tables should not be empty'));
        }

        foreach ($tables as $tableName => $tableConfig) {
            static::$tablePrefixs[$tableName] = $tableConfig['prefix'];
        }

    }

    /**
     * 不考虑分表的情况
     * @param int    $limit  记录数
     * @param int    $offset 开始数
     * @param array  $where  查询条件
     * @param string $order  排序
     * @param string $field  字段
     * @return array total：记录总数  rows:分段结果集
     */
    public function getList($limit = 10, $offset = 0, $where = [], $order = 'id desc', $field = '*', $id = null, $count = '*')
    {
        $total = $this->getORM($id)->where($where)->count($count);
        $lists = $this->getORM($id)->where($where)->select($field)->limit($offset, $limit)->order($order)->fetchAll();
        return ['total' => (int)$total, 'rows' => (array)$lists, 'offset' => (int)$offset, 'limit' => (int)$limit];
    }


    /**
     * 获取表的总数
     * @param array  $condition 查询条件
     * @param string $field
     * @return int
     */
    public function getCount($condition, $field = '*')
    {
        return $this->getORM()->where($condition)->count($field);
    }

    /**
     * 根据查询条件获取分页数据
     * @param array  $condition
     * @param string $field
     * @return mixed
     */
    public function getListLimitByWhere($limit = 10, $offset = 0, $condition = [], $order = 'id desc', $field = '*', $id = null)
    {
        return $this->getORM($id)->where($condition)->select($field)->limit($offset, $limit)->order($order)->fetchAll();
    }

    /**
     * 根据查询条件获取单条数据
     * @param array  $condition
     * @param string $field
     * @return mixed
     */
    public function getInfo($condition = [], $field = '*', $id = null)
    {
        return $this->getORM($id)->where($condition)->select($field)->fetchOne();
    }

    /**
     * 根据查询条件获取总数量
     * @param array  $condition
     * @param string $field
     * @return mixed
     */
    public function getSum($condition = [], $field = 'id', $id = null)
    {
        return $this->getORM($id)->where($condition)->sum($field);
    }

    /**
     * 根据查询条件获取最大值
     * @param array  $condition
     * @param string $field
     * @return mixed
     */
    public function getMax($condition = [], $field = 'id', $id = null)
    {
        return $this->getORM($id)->where($condition)->max($field);
    }

    /**
     * 根据查询条件排序获取单条数据
     * @param array  $condition
     * @param string $field
     * @return mixed
     */
    public function getInfoByOrder($condition = [], $field = '*', $order = 'id desc', $id = null)
    {
        return $this->getORM($id)->where($condition)->order($order)->select($field)->fetchOne();
    }

    /**
     * 根据查询条件获取所有数据
     * @param array  $condition
     * @param string $field
     * @return mixed
     */
    public function getListByWhere($condition = [], $field = '*', $order = 'id desc', $id = null)
    {
        return $this->getORM($id)->where($condition)->select($field)->order($order)->fetchAll();
    }

    /**
     * 根据查询条件更新数据
     * @param array $condition
     * @param array $data
     * @return boolean|int 执行成功返回影响条数，失败返回false
     */
    public function updateByWhere($where, $data, $id = null)
    {
        if (!is_array($where)) {//更新条件不是数组，不允许更新，防止造成全表的更新
            return false;
        }
        $this->formatExtData($data);
        return $this->getORM($id)->where($where)->update($data);
    }

    /**
     * 直接执行手写sql语句更新或者插入
     * @param string $sql    sql更新或者插入语句
     * @param array  $params 更新或者插入条件和更新或者插入数据
     * @return boolean|int 执行成功返回影响条数，失败返回false
     */
    public function queryExecute($sql, $params = [], $id = null)
    {
        $return = $this->getORM($id)->query($sql, $params);
        if (!$return) {
            return false;
        }
        return $return->rowCount();
    }

    /**
     * 直接执行手写sql语句查询
     * @param string $sql    直接执行手写sql语句查询
     * @param array  $params 更新或者插入条件和更新或者插入数据
     * @return boolean|array 执行成功返回影响条数，失败返回false
     */
    public function queryRows($sql, $params = [], $id = null)
    {
        return $this->getORM($id)->queryRows($sql, $params);
    }

    /**
     * 根据条件进行删除操作
     * @param array $condition 删除数据条件
     * @return boolean|int 执行成功返回影响条数，失败返回false
     * @throws Exception
     */
    public function deleteByWhere($condition = [], $id = null)
    {
        if (!is_array($condition)) {//删除条件不是数组，不允许删除，防止造成全表的删除
            return false;
        }
        return $this->getORM($id)->where($condition)->delete();
    }


    /**
     * 删除或者更新
     * @param array $unique
     * @param array $insert
     * @param array $update
     */
    public function insert_update(array $unique, array $insert, array $update = [])
    {
        return $this->getORM()->insert_update($unique, $insert, $update);
    }


    /**
     * 批量插入数据
     * @param array $data
     * @return int 返回影响行数
     */
    public function insert_multi($data)
    {
        return $this->getORM()->insert_multi($data);
    }
}

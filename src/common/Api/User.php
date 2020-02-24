<?php

namespace Common\Api;

/**
 * 用户模块接口服务
 * User
 * @author LYi-Ho 2018-11-24 16:06:44
 */
class User extends Base
{
    use Common;

    public function getRules()
    {
        $rules = parent::getRules();
        $rules['signIn'] = [
            'user_name' => ['name' => 'user_name', 'type' => 'string', 'require' => true, 'min' => 1, 'desc' => '用户名'],
            'password' => ['name' => 'password', 'type' => 'string', 'require' => true, 'min' => 6, 'desc' => '密码'],
            'remember' => ['name' => 'remember', 'type' => 'boolean', 'default' => false, 'desc' => '记住我'],
        ];
        $rules['signUp'] = [
            'user_name' => ['name' => 'user_name', 'type' => 'string', 'require' => true, 'min' => 1, 'desc' => '用户名'],
            'password' => ['name' => 'password', 'type' => 'string', 'require' => true, 'min' => 6, 'desc' => '密码'],
            'email' => ['name' => 'email', 'type' => 'string', 'require' => true, 'regex' => "/^([0-9A-Za-z\\-_\\.]+)@([0-9a-z]+\\.[a-z]{2,3}(\\.[a-z]{2})?)$/i", 'desc' => '邮箱'],
            'real_name' => ['name' => 'real_name', 'type' => 'string', 'require' => true, 'min' => 1, 'desc' => '姓名'],
            'birth_time' => ['name' => 'birth', 'type' => 'int', 'require' => true, 'desc' => '生日'],
            'sex' => ['name' => 'sex', 'type' => 'enum', 'range' => ['1', '2'], 'require' => true, 'desc' => '性别'],
        ];
        $rules['doInfo'] = [
            'id' => ['name' => 'id', 'type' => 'int', 'require' => true, 'min' => 1, 'desc' => '用户ID'],
            'user_name' => ['name' => 'user_name', 'type' => 'string', 'require' => true, 'min' => 1, 'desc' => '用户名'],
            'password' => ['name' => 'password', 'type' => 'string', 'require' => false, 'desc' => '密码'],
            'email' => ['name' => 'email', 'type' => 'string', 'require' => true, 'regex' => "/^([0-9A-Za-z\\-_\\.]+)@([0-9a-z]+\\.[a-z]{2,3}(\\.[a-z]{2})?)$/i", 'desc' => '邮箱'],
            'real_name' => ['name' => 'real_name', 'type' => 'string', 'require' => true, 'min' => 1, 'desc' => '姓名'],
            'birth_time' => ['name' => 'birth', 'type' => 'date', 'format' => 'timestamp', 'require' => true, 'desc' => '生日'],
            'sex' => ['name' => 'sex', 'type' => 'enum', 'range' => ['1', '2'], 'require' => true, 'desc' => '性别'],
        ];
        $rules['infoData'] = [
            'id' => ['name' => 'id', 'type' => 'int', 'require' => true, 'min' => 0, 'desc' => "查询ID"],
            'field' => ['name' => 'field', 'type' => 'string', 'default' => '*', 'desc' => '查询字段'],
        ];
        return $rules;
    }


    /**
     * 会员信息接口
     * @throws \Library\Exception\BadRequestException
     */
    public function infoData()
    {
        $user_id = $this->id;
        $domain_user = self::getDomain();
        if ($user_id == 0) {
            $user_info = $domain_user::$user;
        } else {
            $user_info = $domain_user::getInfo($user_id);
        }
        $user_id = $user_info['id'];
        $user_info = $domain_user::getCurrentUserInfo($user_info);
        if (!$user_info) {
            throw new \Library\Exception\BadRequestException(\PhalApi\T('没有找到该用户'));
        }
        $user_info['topic_count'] = self::getModel('Topic')->getCount(['user_id' => $user_id]);
        $user_info['reply_count'] = self::getModel('Reply')->getCount(['user_id' => $user_id]);
        return $user_info;
    }

    /**
     * 登录接口
     * @desc 根据账号和密码进行登录操作
     * @return array
     * @throws \Library\Exception\BadRequestException
     */
    public function signIn()
    {
        $data = get_object_vars($this);
        return self::getDomain()::doSignIn($data);
    }

    /**
     * 退出登录接口
     * @desc 退出登录接口
     */
    public function signOut()
    {
        self::getDomain()::doSignOut();
    }

    /**
     * 获取当前登录的会员信息
     * @return array
     */
    public function getCurrentUser()
    {
        return [
            'user' => self::getDomain()::getCurrentUserInfo(),
        ];
    }

    /**
     * 修改会员信息
     * @desc 修改会员信息
     * @throws \Library\Exception\BadRequestException
     */
    public function doInfo()
    {
        $update = [
            'id' => $this->id,
            'user_name' => $this->user_name,
            'email' => $this->email,
            'real_name' => $this->real_name,
            'birth_time' => $this->birth_time,
            'sex' => $this->sex,
        ];
        if (!empty($this->password)) {
            $update['a_pwd'] = \Common\encrypt($this->password);
            $update['password'] = \Common\pwd_hash($this->password);
        }
        self::getDomain()::doUpdate($update);
    }

    /**
     * 注册接口
     * @desc 根据提供信息进行注册操作
     * @return array
     */
    public function signUp()
    {
        $data = get_object_vars($this);
        return self::getDomain()::doSignUp($data);
    }
}

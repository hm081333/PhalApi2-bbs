<?php
/**
 * Created by PhpStorm.
 * User: LYi-Ho
 * Date: 2018-11-26
 * Time: 11:14:57
 */

namespace Common\Domain;

use function Common\decrypt;
use function Common\DI;

/**
 * 管理员 领域层
 * Admin
 * @package Common\Domain
 * @author  LYi-Ho 2018-11-26 11:14:57
 */
class Admin
{
    use Common;

    public static $admin;

    /**
     * 管理员登录
     * @param $data
     * @return array
     * @throws \Library\Exception\BadRequestException
     */
    public static function doSignIn($data)
    {
        DI()->response->setMsg(\PhalApi\T('登陆成功'));
        $user_name = $data['user_name'];   // 账号参数
        $password = $data['password'];   // 密码参数
        $remember = $data['remember'];   // 记住登录
        if (empty($user_name)) {
            throw new \Library\Exception\BadRequestException(\PhalApi\T('请输入账号'));// 抛出普通错误 T标签翻译
        } else if (empty($password)) {
            throw new \Library\Exception\BadRequestException(\PhalApi\T('请输入密码'));// 抛出普通错误 T标签翻译
        }
        $admin = self::getInfoByWhere(['user_name' => $user_name], '*');
        if (!$admin) {
            throw new \Library\Exception\BadRequestException(\PhalApi\T('用户不存在'));// 抛出客户端错误 T标签翻译
        } else if (!\Common\pwd_verify($password, $admin['password'])) {
            throw new \Library\Exception\BadRequestException(\PhalApi\T('密码错误'));
        } else {
            $update = [];
            $update['token'] = '';
            if (empty($admin['a_pwd'])) {
                $update['a_pwd'] = $admin['a_pwd'] = \Common\encrypt($password);
            }
            if ($remember) {
                $update['token'] = $admin['token'] = md5(USER_TOKEN . md5(uniqid(mt_rand())));
                // DI()->cookie->set(USER_TOKEN, \Common\encrypt(serialize($user)));
            }
            $update['id'] = $admin['id'];// 待更新的会员ID
            self::doUpdate($update);
            //将用户信息存入SESSION中
            self::setAdminToken($admin);
            return [
                'admin' => self::getCurrentAdminInfo($admin),
                'token' => $update['token'],
            ];
        }
    }

    /**
     * 设置管理员登录状态
     * @param array $user
     */
    public static function setAdminToken(array $user)
    {
        //将用户信息存入SESSION中
        $_SESSION[ADMIN_TOKEN] = \Common\encrypt(DI()->serialize->encrypt($user));// 保存在session
    }

    /**
     * 获取管理员登录状态
     * @return mixed
     */
    public static function getAdminToken()
    {
        return DI()->serialize->decrypt(\Common\decrypt($_SESSION[ADMIN_TOKEN] ?? ''));// Session中的管理员信息
    }

    /**
     * 注销管理员登录状态
     */
    public static function unsetAdminToken()
    {
        unset($_SESSION[ADMIN_TOKEN]);
    }

    /**
     * 管理员登出
     */
    public static function doSignOut()
    {
        DI()->response->setMsg(\PhalApi\T('退出成功'));
        self::unsetAdminToken();
    }

    /**
     * 取得当前登录管理员
     * @param bool $thr
     * @return array|mixed
     * @throws \Library\Exception\BadRequestException
     */
    public static function getCurrentAdmin(bool $thr = false)
    {
        if (!isset(self::$admin)) {
            $admin = self::getAdminToken();// 获取Session中存储的管理员信息
            if (!$admin) {
                // $admin_token = DI()->request->getHeader(ADMIN_TOKEN, false);// 获取header中携带的Token
                $auth = DI()->request->getHeader('Auth', false);// 获取header中携带的Token
                $admin_token = substr($auth, strlen(ADMIN_TOKEN));// 截取Token
                if (!empty($admin_token)) {
                    $admin = self::getInfoByWhere(['token' => $admin_token]);// 用Token换取管理员信息
                    if ($admin) {
                        self::setAdminToken($admin);
                    }
                }
            }
            self::$admin = !$admin ? [] : $admin;// 获取不到管理员时给空，注意不能不赋值
        }
        if ($thr && !self::$admin) {
            throw new \Library\Exception\BadRequestException(\PhalApi\T('请登录'), 2);
        }
        return self::$admin;
    }

    /**
     * 获取当前管理员信息
     * @param bool $user
     * @return array
     */
    public static function getCurrentAdminInfo($admin = false)
    {
        $admin = !$admin ? self::$admin : $admin;// 传入user或当前登录user
        if (!$admin) {
            return [];
        }
        return [
            'user_name' => $admin['user_name'],
            'auth' => $admin['auth'],
            'status' => $admin['status'],
        ];
    }

}

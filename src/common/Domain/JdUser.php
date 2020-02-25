<?php
/**
 * Created by PhpStorm.
 * User: LYi-Ho
 * Date: 2018-11-26
 * Time: 11:14:57
 */

namespace Common\Domain;

use EasyWeChat\Kernel\Exceptions\InvalidArgumentException;
use EasyWeChat\Kernel\Exceptions\InvalidConfigException;
use Library\Exception\BadRequestException;
use Library\Exception\Exception;
use Library\Exception\InternalServerErrorException;
use function PhalApi\T;

/**
 * 京东账号 领域层
 * JdUser
 * @package Common\Domain
 * @author  LYi-Ho 2018-11-26 11:14:57
 */
class JdUser
{
    use Common;

    /**
     * @return JdSign
     */
    public static function Domain_JdSign()
    {
        return self::getDomain('JdSign');
    }

    public static function statusNames($status = false)
    {
        $names = [
            0 => '未启用',
            1 => '正常',
            2 => '已过期',
        ];
        if ($status === false) return $names;
        return $names[$status];
    }

    /**
     * 添加、修改京东账号
     * @param array $data
     * @throws BadRequestException
     * @throws InternalServerErrorException
     * @throws Exception
     */
    public static function doInfo($data)
    {
        if (empty($data['pt_key']) || empty($data['pt_pin']) || empty($data['pt_token'])) {
            throw new BadRequestException(T('非法请求'));
        }
        // 该账号的id
        $jd_user_id = $data['id'];
        // 当前登录会员信息
        $user = User::getCurrentUser(true);
        $modelJdUser = self::getModel();
        // 获取京东用户信息
        $jd_user_info = self::Domain_JdSign()->getJDUserInfo($data);
        $data = [
            'jd_user_name' => $jd_user_info['user_name'],
            'jd_nick_name' => $jd_user_info['nick_name'],
            'jd_level_name' => $jd_user_info['level_name'],
            'pt_key' => $data['pt_key'],
            'pt_pin' => $data['pt_pin'],
            'pt_token' => $data['pt_token'],
            'status' => 1,
            'refresh_time' => NOW_TIME,
        ];
        if ($jd_user_id) {
            // 修改
            self::DI()->response->setMsg(T('修改成功'));

            $check = $modelJdUser->getInfo(['id' => $jd_user_id, 'user_id' => $user['id']], 'id');
            if (!$check) {
                throw new BadRequestException(T('不存在该账号信息'));
            }

            $update_rs = $modelJdUser->update($jd_user_id, $data);
            if ($update_rs === false) {
                throw new InternalServerErrorException(T('添加失败'));
            }
        } else {
            // 添加
            self::DI()->response->setMsg(T('添加成功'));

            $check = $modelJdUser->getInfo(['jd_user_name' => $data['jd_user_name']], 'id');
            if ($check) {
                throw new BadRequestException(T('该账号已经绑定过了'));
            }

            $insert_data = array_merge($data, [
                'user_id' => $user['id'],
                'add_time' => NOW_TIME,
            ]);
            $insert_rs = $modelJdUser->insert($insert_data);
            if ($insert_rs === false) {
                throw new InternalServerErrorException(T('添加失败'));
            }
        }

    }

    /**
     * 修改账号状态
     * @param     $id
     * @param int $status
     * @return int|TRUE
     */
    public static function changeStatus($id, $status = 2)
    {
        $modelJdUser = self::getModel();
        return $modelJdUser->update($id, ['status' => $status]);
    }

    /**
     * 登录状态过期
     * @param array $user_cookie
     * @return bool
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     * @throws BadRequestException
     * @throws InternalServerErrorException
     */
    public static function loginStatusExpired(array $user_cookie)
    {
        $status = 2;
        $modelJdUser = self::getModel();
        $info = $modelJdUser->getInfo([
            'pt_key' => $user_cookie['pt_key'],
            'pt_pin' => $user_cookie['pt_pin'],
            'pt_token' => $user_cookie['pt_token'],
        ]);
        if (!$info) return false;
        $res = self::changeStatus($info['id'], $status);
        if ($res === false) return false;
        /** @var $wechat WeChatPublicPlatform */
        $wechat = self::getDomain('WeChatPublicPlatform');
        $wechat->sendJDLoginStatusExpiredWarn($info);
        return true;
    }

}

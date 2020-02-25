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
use EasyWeChat\Kernel\Support\Collection;
use Exception;
use Library\Exception\BadRequestException;
use Library\Exception\InternalServerErrorException;
use PhalApi\Model\NotORMModel;
use Psr\Http\Message\ResponseInterface;
use function Common\DI;
use function Common\isWeChat;
use function PhalApi\T;

/**
 * 微信公众平台 领域层
 * Class WeChatMediaPlatform
 * @package Common\Domain
 * @author  LYi-Ho 2018-11-26 11:14:57
 */
class WeChatPublicPlatform
{
    use Common;
    private $appId;
    private $appSecret;

    public function __construct()
    {
        $config = $this->Domain_Setting()::getSetting('wechat');
        $this->appId = $config['app_id'] ?? '';
        $this->appSecret = $config['app_secret'] ?? '';
    }

    /**
     * @return Setting
     */
    protected function Domain_Setting()
    {
        return self::getDomain('Setting');
    }

    /**
     * @return \Common\Model\User|\Common\Model\Common|NotORMModel
     */
    protected function Model_User()
    {
        return self::getModel('User');
    }

    /**
     * @return JdSign
     */
    protected function Domain_JdSign()
    {
        return self::getDomain('JdSign');
    }

    /**
     * 拉取身份信息的唯一code
     * @param string $scope
     */
    public function getOpenIdCode($scope = 'snsapi_base')
    {
        if (isWeChat()) {
            //$scope = 'snsapi_userinfo';
            //若提示“该链接无法访问”，请检查参数是否填写错误，是否拥有scope参数对应的授权作用域权限。
            $redirect_uri = urlencode(URL_ROOT . 'tieba.php');
            $url = "https://open.weixin.qq.com/connect/oauth2/authorize?appid={$this->appId} &redirect_uri={$redirect_uri}&response_type=code&scope={$scope}&state=STATE#wechat_redirect";
            Header("Location: $url");
            die;
        }
    }

    /**
     * 通过code拉取openid和access_token
     * @param $code
     * @return mixed
     * @throws \Library\Exception\Exception
     * @throws \PhalApi\Exception\InternalServerErrorException
     */
    public function getOpenId($code)
    {
        $url = "https://api.weixin.qq.com/sns/oauth2/access_token?appid={$this->appId}&secret={$this->appSecret}&code={$code}&grant_type=authorization_code";
        $result = DI()->curl->get($url);
        $result = json_decode($result, true);
        if (!empty($result)) {
            if (isset($result['errmsg'])) {
                throw new \Library\Exception\Exception(T($result['errmsg']));
            }
            return $result;
        } else {
            throw new \Library\Exception\Exception(T('失败'));
        }
    }

    /**
     * $scope = 'snsapi_userinfo'的后续
     * @param $code
     * @return mixed
     * @throws \Library\Exception\Exception
     * @throws \PhalApi\Exception\InternalServerErrorException
     */
    public function getSnsApiUserInfo($code)
    {
        $url = "https://api.weixin.qq.com/sns/oauth2/access_token?appid={$this->appId}&secret={$this->appSecret}&code={$code}&grant_type=authorization_code";
        $result = DI()->curl->get($url);
        $result = json_decode($result, true);
        if (!empty($result)) {
            if (isset($result['errmsg'])) {
                throw new \Library\Exception\Exception(T($result['errmsg']));
            }
            $url = "https://api.weixin.qq.com/sns/userinfo?access_token={$result['access_token']}&openid={$result['open_id']}&lang=zh_CN";
            $result = DI()->curl->get($url);
            $result = json_decode($result, true);
            if (!empty($result)) {
                if (isset($result['errmsg'])) {
                    throw new \Library\Exception\Exception(T($result['errmsg']));
                }
                return $result;
            } else {
                throw new \Library\Exception\Exception(T('失败'));
            }
        } else {
            throw new \Library\Exception\Exception(T('失败'));
        }
    }

    /**
     * 通过获取的openid达到自动登陆的效果
     * @param $code
     * @throws \Library\Exception\Exception
     * @throws \PhalApi\Exception\InternalServerErrorException
     */
    public function openIdLogin($code)
    {
        $url = "https://api.weixin.qq.com/sns/oauth2/access_token?appid={$this->appId}&secret={$this->appSecret}&code={$code}&grant_type=authorization_code";
        $result = DI()->curl->get($url);
        $result = json_decode($result, true);
        if (!empty($result)) {
            if (isset($result['errmsg'])) {
                throw new \Library\Exception\Exception(T($result['errmsg']));
            }
            $open_id = $result['openid'];
            $user_model = $this->Model_User();
            $user = $user_model->getInfo(['open_id' => $open_id]);
            if ($user) {
                //将用户名存如SESSION中
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['user_name'];
                $_SESSION['user_auth'] = $user['auth'];
            }
        } else {
            throw new \Library\Exception\Exception(T('失败'));
        }
    }

    /**
     * 贴吧 领域层
     * @return \Common\Domain\TieBa
     */
    protected function Domain_TieBa()
    {
        return self::getDomain('TieBa');
    }

    /**
     * 公众号发送贴吧签到日志
     */
    public function sendTieBaSignDetailByCron()
    {
        $baidu_ids = $this->Model_User()->queryRows("SELECT
            --    `baiduid`.`id`,
            `baiduid`.`user_id`,
            `user`.`id`,
            `user`.`open_id`,
            `user`.`user_name`
        FROM
            `ly_baiduid` AS `baiduid`
        LEFT JOIN `ly_user` AS `user` ON `baiduid`.`user_id` = `user`.`id`
        WHERE
            `user`.`open_id` IS NOT NULL
            AND `user`.`open_id` != ''");
        foreach ($baidu_ids as $user) {
            try {
                $this->sendTiebaSignDetail($user);
            } catch (Exception $e) {
                DI()->logger->error($e->getMessage());
            }
        }
    }

    /**
     * 发送贴吧签到详情
     * @param array $user
     * @return array|Collection|object|ResponseInterface|string
     * @throws BadRequestException
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function sendTiebaSignDetail($user = [])
    {
        if (empty($user) || empty($user['open_id']) || empty($user['id'])) throw new BadRequestException(T('非法参数'));

        $info = $this->Domain_TieBa()->getSignStatus($user);
        if ($info == false) throw new InternalServerErrorException(T('获取状态失败'));
        $result = DI()->wechat->template_message->send([
            'touser' => $user['open_id'],
            'template_id' => 'Ogvc_rROWerSHvfgo1IOJIL103bso0H3jLYEAwTuKKg',
            'url' => 'http://bbs2.lyihe2.tk/tieba',
            // 'miniprogram' => [
            //     'appid' => 'xxxxxxx',
            //     'pagepath' => 'pages/xxx',
            // ],
            'data' => [
                'user_name' => [
                    'value' => $info['user_name'],
                    'color' => '#173177',
                ],
                'greeting' => [
                    'value' => $info['greeting'],
                    'color' => '#173177',
                ],
                'tieba_count' => [
                    'value' => $info['tieba_count'],
                    'color' => '#173177',
                ],
                'success_count' => [
                    'value' => $info['success_count'],
                    'color' => '#173177',
                ],
                'fail_count' => [
                    'value' => $info['fail_count'],
                    'color' => '#173177',
                ],
                'ignore_count' => [
                    'value' => $info['ignore_count'],
                    'color' => '#173177',
                ],
            ],
        ]);

        DI()->logger->debug('微信推送结果', $result);

        return $result;
    }

    /**
     * 发送京东登录状态过期警告
     * @param array $jd_user_info
     * @return array|Collection|object|ResponseInterface|string
     * @throws BadRequestException
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    public function sendJDLoginStatusExpiredWarn($jd_user_info = [])
    {
        if (empty($jd_user_info)) throw new BadRequestException(T('非法请求'));
        /** @var $user_model \Common\Model\User */
        $user_model = $this->Model_User();
        $user_info = $user_model->get(intval($jd_user_info['user_id']));
        if (!$user_info) throw new InternalServerErrorException(T('获取状态失败'));

        $openid = $user_info['open_id'];
        if (empty($openid)) return false;

        $h = date('G', NOW_TIME);
        if ($h < 11) {
            $greeting = '早上好！';
        } else if ($h < 13) {
            $greeting = '中午好！';
        } else if ($h < 17) {
            $greeting = '下午好！';
        } else {
            $greeting = '晚上好！';
        }

        $result = DI()->wechat->template_message->send([
            'touser' => $openid,
            'template_id' => 'm4MFI5pcseC177w2-a-Tm-6XvhWsYf02pNCEIr2eCeo',
            'url' => 'http://bbs2.lyihe2.tk/sign',
            'data' => [
                'user_name' => [
                    'value' => $user_info['user_name'],
                    'color' => '#173177',
                ],
                'greeting' => [
                    'value' => $greeting,
                    'color' => '#173177',
                ],
                'jd_user_name' => [
                    'value' => $jd_user_info['jd_user_name'],
                    'color' => '#173177',
                ],
            ],
        ]);

        DI()->logger->debug('微信推送结果', $result);

        return $result;
    }

    /**
     * 公众号发送京东签到日志
     */
    public function sendJDSignDetailByCron()
    {
        $users = $this->Model_User()->queryRows("SELECT
            `jd_user`.`id`,
            `jd_user`.`user_id`,
            `user`.`open_id`,
            `user`.`user_name`
            FROM
            `ly_jd_user` AS `jd_user`
            LEFT JOIN `ly_user` AS `user` ON `jd_user`.`user_id` = `user`.`id` 
        WHERE
            `jd_user`.`status` = 1 
            AND `user`.`open_id` IS NOT NULL
            AND `user`.`open_id` != ''");
        foreach ($users as $user) {
            try {
                $this->sendJdSignDetail($user);
            } catch (Exception $e) {
                DI()->logger->error($e->getMessage());
            }
        }
    }

    /**
     * 发送贴吧签到详情
     * @param array $user
     * @return array|Collection|object|ResponseInterface|string
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     * @throws BadRequestException
     * @throws InternalServerErrorException
     */
    private function sendJdSignDetail($user = [])
    {
        if (empty($user) || empty($user['open_id']) || empty($user['id'])) throw new BadRequestException(T('非法参数'));

        $info = $this->Domain_JdSign()->getSignStatus($user);
        if ($info == false) {
            throw new InternalServerErrorException(T('获取推送信息失败'));
        }

        $result = DI()->wechat->template_message->send([
            'touser' => $user['open_id'],
            'template_id' => 'MZGfek36AHUS3WVteDPQXpFqoM2x1c9NtlHYGtWiSXc',
            'url' => 'http://bbs2.lyihe2.tk/sign',
            // 'miniprogram' => [
            //     'appid' => 'xxxxxxx',
            //     'pagepath' => 'pages/xxx',
            // ],
            'data' => [
                'user_name' => [
                    'value' => $info['user_name'],
                    'color' => '#173177',
                ],
                'greeting' => [
                    'value' => $info['greeting'],
                    'color' => '#173177',
                ],
                'jd_sign_count' => [
                    'value' => $info['jd_sign_count'],
                    'color' => '#173177',
                ],
                'bean_award_day' => [
                    'value' => $info['bean_award_day'],
                    'color' => '#173177',
                ],
                'nutrients_day' => [
                    'value' => $info['nutrients_day'],
                    'color' => '#173177',
                ],
                'bean_award_total' => [
                    'value' => $info['bean_award_total'],
                    'color' => '#173177',
                ],
                'nutrients_total' => [
                    'value' => $info['nutrients_total'],
                    'color' => '#173177',
                ],
            ],
        ]);

        DI()->logger->debug('微信推送结果', $result);

        return $result;
    }


}

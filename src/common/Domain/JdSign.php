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
use Library\DateHelper;
use Library\Exception\BadRequestException;
use Library\Exception\Exception;
use Library\Exception\InternalServerErrorException;
use PhalApi\Model\NotORMModel;
use function Common\DI;
use function Common\multi_array_merge;
use function PhalApi\T;

/**
 * 京东签到 领域层
 * JdSign
 * @package Common\Domain
 * @author  LYi-Ho 2020-01-14 15:53:11
 */
class JdSign
{
    use Common;

    private $sign_key;
    private $offset = 0;
    private $limit = 50;
    private $user_cookie = [];
    private $roundId = '';
    private $lotteryCode = '';
    private $day_begin = 0;
    private $jd_sign_field = 'ly_jd_sign.id,ly_jd_sign.return_data,jd_user.pt_key,jd_user.pt_pin,jd_user.pt_token';
    private $canCollectUserNutr = true;

    public function __construct()
    {
        $this->day_begin = strtotime(date('Y-m-d'));
    }

    /**
     * 京豆 签到状态
     * @param bool $status
     * @return array|mixed
     */
    public static function beanSignStatusName($status = false)
    {
        $names = [
            1 => '已签到',
            2 => '未签到',
            3 => '未登录',
        ];
        if ($names !== false) {
            return $names[$status];
        }
        return $names;
    }

    /**
     * 测试 钩子
     */
    public function test()
    {
        die;
    }

    /**
     * 设置京东签到项目
     * @param int   $jd_user_id
     * @param array $open_signs
     * @throws BadRequestException
     * @throws Exception
     */
    public function doInfo(int $jd_user_id, array $open_signs)
    {
        DI()->response->setMsg(T('设置成功'));

        $user = User::getCurrentUser(true);

        $jd_user_id = intval($jd_user_id);
        $user_id = intval($user['id']);

        $modelJdSign = $this->Model_JdSign();
        $modelJdUser = $this->Model_JdUser();
        $jd_user_info = $modelJdUser->getInfo([
            'id' => $jd_user_id,
            'user_id' => $user_id,
        ]);
        if (!$jd_user_info) {
            throw new Exception(T('找不到该京东用户'));
        }
        $update_all_where = [
            'user_id' => $user_id,
            'jd_user_id' => $jd_user_info['id'],
            'status' => 1,
        ];
        $update_all_data = [
            'status' => 0,
            'edit_time' => NOW_TIME,
        ];
        if (!empty($open_signs)) {
            foreach ($open_signs as $sign_key) {
                $sign_info = $modelJdSign->getInfo([
                    'user_id' => $user_id,
                    'jd_user_id' => $jd_user_info['id'],
                    'sign_key' => $sign_key,
                ]);
                // 不存在该签到条目
                if (!$sign_info) {
                    $insert_res = $modelJdSign->insert([
                        'user_id' => $user_id,
                        'jd_user_id' => $jd_user_info['id'],
                        'sign_key' => $sign_key,
                        'status' => 1,
                        'add_time' => NOW_TIME,
                        'edit_time' => NOW_TIME,
                    ]);
                    if ($insert_res === false) throw new Exception(T('系统异常'));
                } else {
                    if ($sign_info['status'] != 1) {
                        $update_res = $modelJdSign->update($sign_info['id'], ['status' => 1, 'edit_time' => NOW_TIME]);
                        if ($update_res === false) throw new Exception(T('系统异常'));
                    }
                }
            }
            $update_all_where['NOT sign_key'] = $open_signs;
        }
        $update_res = $modelJdSign->updateByWhere($update_all_where, $update_all_data);
        if ($update_res === false) throw new Exception(T('系统异常'));
    }

    /**
     * 京东会员 逻辑层
     * @return JdUser
     */
    protected function Domain_JdUser()
    {
        return self::getDomain('JdUser');
    }

    /**
     * 京东签到项 数据层
     * @return \Common\Model\JdSign|\Common\Model\Common|NotORMModel
     */
    protected function Model_JdSign()
    {
        return self::getModel('JdSign');
    }

    /**
     * 京东会员 数据层
     * @return \Common\Model\JdUser|\Common\Model\Common|NotORMModel
     */
    protected function Model_JdUser()
    {
        return self::getModel('JdUser');
    }

    /**
     * 设置签到项key值
     * @param string $sign_key
     * @return $this
     */
    public function setSignKey(string $sign_key)
    {
        $this->sign_key = $sign_key;
        return $this;
    }

    /**
     * 判断时间戳是不是今天
     * @param $timestamp
     * @return bool
     */
    private function is_today($timestamp)
    {
        return $timestamp >= $this->day_begin;
    }

    /**
     * 执行某项签到 - 所有
     * @throws BadRequestException
     */
    public function doItemSignAll()
    {
        if (empty($this->sign_key)) throw new BadRequestException(T('请先设置签到项'));
        while (true) {
            $jd_sign_list = $this->Model_JdSign()->getListLimitByWhere($this->limit, $this->offset, [
                // 类型为签到
                'ly_jd_sign.sign_key' => $this->sign_key,
                // 任务状态为正常
                'ly_jd_sign.status' => 1,
                // 登录状态为正常
                'jd_user.status' => 1,
            ], 'ly_jd_sign.id asc', $this->jd_sign_field);
            if (empty($jd_sign_list)) break;
            foreach ($jd_sign_list as $jd_sign_info) {
                try {
                    $this->doItemSign(0, $jd_sign_info);
                } catch (\Exception $e) {
                    DI()->logger->error("执行签到项|{$this->sign_key}|异常|{$e->getMessage()}", $jd_sign_info);
                }
            }
            $this->offset += $this->limit;
        }
    }

    /**
     * 执行某项签到
     * @param int   $jd_sign_id
     * @param array $jd_sign_info
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    public function doItemSign(int $jd_sign_id = 0, array $jd_sign_info = [])
    {
        if (empty($this->sign_key)) throw new BadRequestException(T('请先设置签到项'));
        $jd_sign_info = $this->getJDSignInfo($jd_sign_id, $jd_sign_info);
        switch ($this->sign_key) {
            case 'bean':
                // 签到领京豆
                $this->doBeanSign($jd_sign_info);
                break;
            case 'plant':
                // 种豆得豆
                $this->doPlantBean($jd_sign_info);
                break;
            case 'vvipclub':
                // 京享值领京豆
                $this->doVVipClub($jd_sign_info);
                break;
            case 'wheelSurf':
                // 福利转盘
                $this->doWheelSurf($jd_sign_info);
                break;
            case 'jrSign':
                // 京东金融APP签到
                $this->doJRSign($jd_sign_info);
                break;
            case 'doubleSign':
                // 领取双签礼包
                $this->doDoubleSign($jd_sign_info);
                break;
            case 'jrRiseLimit':
                // 提升白条额度
                $this->doJRRiseLimit($jd_sign_info);
                break;
            case 'jrFlopReward':
                // 翻牌赢钢镚
                $this->doJRFlopReward($jd_sign_info);
                break;
            case 'jrLottery':
                // 金币抽奖
                $this->doJRLottery($jd_sign_info);
                break;
            case 'jrSignRecords':
                // 每日赚京豆签到
                $this->doJRSignRecords($jd_sign_info);
                break;
            default:
                throw new BadRequestException(T('不存在该签到项'));
                break;
        }
    }

    /**
     * 获取京东签到项数据
     * @param int   $jd_sign_id
     * @param array $jd_sign_info
     * @return array|mixed
     * @throws BadRequestException
     * @throws Exception
     */
    private function getJDSignInfo(int $jd_sign_id, array $jd_sign_info = [])
    {
        if (empty($jd_sign_info)) {
            if (empty($jd_sign_id)) {
                throw new BadRequestException(T('非法请求'));
            }
            $jd_sign_info = $this->Model_JdSign()->getInfo([
                'ly_jd_sign.id' => intval($jd_sign_id),
                // 类型为签到
                'ly_jd_sign.sign_key' => $this->sign_key,
                // 任务状态为正常
                'ly_jd_sign.status' => 1,
                // 登录状态为正常
                'jd_user.status' => 1,
            ], $this->jd_sign_field);
        }
        if (empty($jd_sign_info)) throw new Exception(T("不存在该签到或用户未开启该签到|jd_sign_id|{$jd_sign_id}|sign_key|{$this->sign_key}"));
        return $this->parseSignInfo($jd_sign_info);
    }

    /**
     * 格式化京东签到项数据
     * @param array $data
     * @return array
     */
    private function parseSignInfo($data = [])
    {
        $data['return_data'] = isset($data['return_data']) ? unserialize($data['return_data']) : [];
        $data['return_data'] = multi_array_merge([
            // 签到状态
            'status' => 0,
            // 今天 获得京豆数量
            'bean_award_day' => 0,
            // 总共 获得京豆数量
            'bean_award_total' => 0,
            // 今天 获得营养液数量
            'nutrients_day' => 0,
            // 总共 获得营养液数量
            'nutrients_total' => 0,
            // 签到时间
            'sign_time' => 0,
            // 下次运行时间
            'next_time' => 0,
        ], $data['return_data']);

        // 设置请求所需的cookie
        $this->user_cookie = [
            'pt_key' => $data['pt_key'],
            'pt_pin' => $data['pt_pin'],
            'pt_token' => $data['pt_token'],
        ];
        return $data;
    }

    /**
     * 初始化签到更新数据
     * @param array $data
     * @return array
     */
    private function initUpdateSignData($data = [])
    {
        return \Common\multi_array_merge([
            // 上次运行时间
            'last_time' => time(),
            'return_data' => [
                // 签到状态
                'status' => 0,
                // 今天 获得京豆数量
                'bean_award_day' => 0,
                // 总共 获得京豆数量
                'bean_award_total' => 0,
                // 今天 获得营养液数量
                'nutrients_day' => 0,
                // 总共 获得营养液数量
                'nutrients_total' => 0,
                // 签到时间
                'sign_time' => time(),
                // 下次运行时间
                'next_time' => $this->day_begin + 86400,
            ],
        ], $data);
    }

    /**
     * 获取签到状态
     * @param bool|array $jd_user
     * @return array
     * @throws BadRequestException
     */
    public function getSignStatus($jd_user = false)
    {
        if (empty($jd_user)) throw new BadRequestException(T('非法参数'));
        $sign_list = $this->Model_JdSign()->getListByWhere(['jd_user_id' => $jd_user['id'], 'status' => 1], 'sign_key,return_data');
        if (empty($sign_list)) {
            throw new BadRequestException(T('该用户不存在开启中的签到'));
        }

        $jd_sign_count = count($sign_list);
        $bean_award_day = 0;
        $bean_award_total = 0;
        $nutrients_day = 0;
        $nutrients_total = 0;
        foreach ($sign_list as $sign_info) {
            $return_data = unserialize($sign_info['return_data']);
            $bean_award_day += $return_data['bean_award_day'] ?? 0;
            $bean_award_total += $return_data['bean_award_total'] ?? 0;
            if ($sign_info['sign_key'] != 'doubleSign') {
                $nutrients_day += $return_data['nutrients_day'] ?? 0;
                $nutrients_total += $return_data['nutrients_total'] ?? 0;
            }
        }
        unset($sign_info, $sign_list);

        $h = date('G', NOW_TIME);
        if ($h < 11) {
            $greeting = '早上好！！！';
        } else if ($h < 13) {
            $greeting = '中午好！！！';
        } else if ($h < 17) {
            $greeting = '下午好！！！';
        } else {
            $greeting = '晚上好！！！';
        }
        $result = [];
        $result['user_name'] = $jd_user['user_name'];
        $result['greeting'] = $greeting;
        $result['jd_sign_count'] = $jd_sign_count;
        $result['bean_award_day'] = $bean_award_day;
        $result['nutrients_day'] = $nutrients_day;
        $result['bean_award_total'] = $bean_award_total;
        $result['nutrients_total'] = $nutrients_total;
        return $result;
    }

    /**
     * 构建请求链接
     * @param string       $url    请求地址
     * @param array|string $params 请求参数
     * @return string
     * @throws Exception
     */
    private function buildURL($url, $params = [])
    {
        $url_info = parse_url($url);
        if (!$url_info) throw new Exception('非法请求地址');
        // URL携带的参数转成数组
        parse_str($url_info['query'] ?? '', $query);
        // 有传入请求参数
        if (!empty($params)) {
            if (is_string($params)) {
                // 如果开头是 ? 把 ? 去掉
                if (strpos($params, '?') === 0) {
                    $params = substr($params, 1);
                }
                // 参数转成数组
                parse_str($params, $params);
            }
        } else {
            $params = [];
        }
        // 传入参数替换原有参数
        $params = array_merge($query, $params);
        // 参数数组构建为请求字符串
        $params = http_build_query($params);

        $scheme = isset($url_info['scheme']) ? $url_info['scheme'] . ':' : '';
        $pass = isset($url_info['pass']) ? ':' . $url_info['pass'] : '';
        $user = isset($url_info['user']) ? $url_info['user'] . $pass . '@' : '';
        $host = $url_info['host'] ?? '';
        $port = isset($url_info['port']) ? ':' . $url_info['port'] : '';
        $path = $url_info['path'] ?? '';
        $query = empty($params) ? '' : '?' . $params;
        $fragment = isset($url_info['fragment']) ? '#' . $url_info['fragment'] : '';
        // scheme:[//[user[:password]@]host[:port]][/path][?query][#fragment]

        return $scheme . '//' . $user . $host . $port . $path . $query . $fragment;
    }

    /**
     * 执行签到领京豆
     * @param array $jd_sign_info
     * @throws BadRequestException
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     * @throws InternalServerErrorException
     */
    private function doBeanSign(array $jd_sign_info = [])
    {
        // 今天的开始时间戳
        if ($this->is_today($jd_sign_info['return_data']['sign_time'])) {
            return;
            // throw new \Library\Exception\Exception(\PhalApi\T('今天已签到'));
        }

        $sign_info = $this->beanSignInfo();
        switch ($sign_info['status']) {
            case 1:
                throw new Exception(T('已签到'));
                break;
            case 2:
                // 进行签到
                $sign_res = $this->beanSign();
                $award = [];
                if ($sign_res['awardType'] == 1) {
                    // 普通签到奖励
                    $award = $sign_res['dailyAward'];
                } else if ($sign_res['awardType'] == 2) {
                    // 连续签到奖励
                    $award = $sign_res['continuityAward'];
                } else {
                    DI()->logger->error('京东APP签到|未知签到奖励类型', $sign_res);
                }

                $this->Model_JdSign()->updateByWhere([
                    'id' => intval($jd_sign_info['id']),
                    'sign_key' => $this->sign_key,
                    'status' => 1,
                ], $this->initUpdateSignData([
                    'return_data' => [
                        'status' => $sign_res['status'],
                        'bean_award_day' => $award['beanAward']['beanCount'],
                        'bean_award_total' => ($jd_sign_info['return_data']['bean_award_total'] + $award['beanAward']['beanCount']),
                    ],
                ]));
                break;
            case 3:
                $this->Domain_JdUser()::loginStatusExpired($this->user_cookie);
                throw new Exception(T('请更新登录状态cookie'));
                break;
            default:
                DI()->logger->error('未知的京东签到状态码', $sign_info);
                break;
        }
    }

    /**
     * 京东APP 京豆 签到信息
     * @return array
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function beanSignInfo()
    {
        $url = $this->buildURL('https://api.m.jd.com/client.action', [
            'functionId' => 'findBeanIndex',
            'body' => json_encode([
                'source' => '',
                'monitor_refer' => '',
                'rnVersion' => '3.9',
                'rnClient' => '1',
                'monitor_source' => 'bean_m_bean_index',
            ]),
            'appid' => 'ld',
            'client' => 'android',
            'clientVersion' => ' nexus 5 build/mra58n) applewebkit/537.36 (khtml, like gecko) chrome/79.0.3945.117 mobile safari/537.36',
            'networkType' => '',
            'osVersion' => '',
            'uuid' => '',
        ]);

        $data = $this->jdRequest($url);
        // 签到状态 根据测试, 1 表示已签到, 2 表示未签到, 3 表示未登录
        $sign_status = $data['status'] ?? 0;
        // if ($sign_status == 1) {
        //     throw new \Library\Exception\Exception(\PhalApi\T('已签到'));
        // } else if ($sign_status == 3) {
        //     throw new \Library\Exception\Exception(\PhalApi\T('请更新登录状态cookie'));
        // }
        // 签到天数
        $sign_days = $data['continuousDays'] ?? 0;
        // 我持有的京豆数量
        $beans_count = $data['totalUserBean'] ?? 0;
        return [
            'status' => $sign_status,
            'days' => $sign_days,
            'count' => $beans_count,
        ];
    }

    /**
     * 请求操作
     * @param string     $url
     * @param bool|array $post_data
     * @param int        $retryTimes 重新请求次数
     * @return array|bool
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function jdRequest($url, $post_data = false, $retryTimes = 5)
    {
        $res = $post_data === false ? DI()->curl->setCookie($this->user_cookie)->json_get($url) : DI()->curl->setCookie($this->user_cookie)->json_post($url, $post_data);
        if (isset($res['success'])) {
            // $data = [];
            if ($res['success']) {
                $data = $res['data'];
            } else {
                DI()->logger->error('请求失败', $res);
                throw new Exception($res['message']);
            }
        } else {
            $code = $res['code'] ?? false;
            if ($code === false) {
                DI()->logger->error('接口异常', $res);
                throw new Exception(T('接口异常'));
            } else if ($code == 3) {
                $this->Domain_JdUser()::loginStatusExpired($this->user_cookie);
                throw new Exception(T('请更新登录状态cookie'));
            } else if ($code != 0) {
                // 除去正常code的其他返回
                DI()->logger->error('京东返回未知状态|jdRequest', $res);
            }
            $data = $res['data'] ?? $res;
            $errorCode = $res['errorCode'] ?? false;
            $errorMessage = $res['errorMessage'] ?? false;
            if (!empty($errorMessage)) {
                DI()->logger->error("请求返回错误|URL|{$url}", $res);
                // {"code":"0","errorCode":"PB001","errorMessage":"抱歉，活动太火爆了"}
                // 尝试重新请求
                if ($retryTimes > 0) {
                    if (in_array($errorCode, ['PB001', 'T201'])) {
                        DI()->logger->debug("尝试重新请求，剩余重试次数{$retryTimes}次|errorCode|{$errorCode}|errorMessage|{$errorMessage}");
                        return $this->jdRequest($url, $post_data, $retryTimes - 1);
                    } else {
                        DI()->logger->debug("未知错误|errorCode|{$errorCode}|errorMessage|{$errorMessage}");
                    }
                }
                throw new Exception($errorMessage);
            }
        }
        // sleep(2);
        return $data;
    }

    /**
     * 京东APP 京豆 签到
     * @return array|bool
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function beanSign()
    {
        $url = $this->buildURL('https://api.m.jd.com/client.action', [
            'functionId' => 'signBeanIndex',
            'body' => json_encode([
                // 'source' => '',
                'monitor_refer' => '',
                'rnVersion' => '3.9',
                'monitor_source' => 'bean_m_bean_index',
            ]),
            'appid' => 'ld',
            'client' => 'android',
            'clientVersion' => ' nexus 5 build/mra58n) applewebkit/537.36 (khtml, like gecko) chrome/79.0.3945.117 mobile safari/537.36',
            'networkType' => '',
            'osVersion' => '',
            'uuid' => '',
        ]);

        $data = $this->jdRequest($url);
        DI()->logger->debug('京东APP签到', $data);
        return $data;
    }

    /**
     * 执行种豆得豆
     * @param array $jd_sign_info
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function doPlantBean(array $jd_sign_info = [])
    {
        // if ($jd_sign_info['return_data']['next_time'] > time()) {
        //     return;
        //     // throw new \Library\Exception\Exception(\PhalApi\T('未到下次收取时间'));
        // }

        // 如果允许扫描时间大于当前时间，重定义是否允许收取好友营养液的变量
        if (isset($jd_sign_info['return_data']['canCollectUserNutr']) && $jd_sign_info['return_data']['canCollectUserNutr'] > time()) {
            $this->canCollectUserNutr = false;
        }

        // 种豆得豆相关信息
        $plant_info = $this->plantBeanInfo();
        // 现持有营养液数量
        $nutrients = $plant_info['nutrients'] ?? 0;

        // 1、种豆得豆任务
        foreach ($plant_info['awardList'] as $award) {
            // limitFlag为2代表任务已完成
            if (isset($award['childAwardList'])) {
                foreach ($award['childAwardList'] as $childAward) {
                    $nutrients += $this->doPlantBeanAward($childAward);
                }
                unset($childAward);
            } else {
                $nutrients += $this->doPlantBeanAward($award);
            }
        }

        // 2、可收取营养液数量大于0
        if ($plant_info['nutrCount'] > 0) {
            // 持有数量
            $nutrients += $this->receiveNutrients();
        }
        // 3、帮好友收取、收取得到的奖励累积到本次该收取的数量
        if ($this->canCollectUserNutr) {
            $nutrients += $this->receivePlantFriend();
        }
        // 持有营养液
        if ($nutrients > 0) {
            // 使用营养液 培养京豆
            $this->cultureBean();
        }

        // 获得京豆数量
        $bean_award = 0;
        // awardState： 1：培养中 5：待领取 6：已领取
        // beanState： 2：发芽 4：成豆
        $last_round_info = $plant_info['last_round'];
        // 上轮京豆未收取
        if (isset($last_round_info['roundId']) && $last_round_info['awardState'] == 5) {
            // 收取京豆
            $bean_award = $this->receivedBean($last_round_info['roundId']);
        }

        if (empty($nutrients) && empty($bean_award)) return;

        DI()->logger->debug("本次累计获得营养液|{$nutrients}|瓶|京豆|{$bean_award}|颗");

        $this->Model_JdSign()->updateByWhere([
            'id' => intval($jd_sign_info['id']),
            'sign_key' => $this->sign_key,
            'status' => 1,
        ], $this->initUpdateSignData([
            'return_data' => [
                // 今天 获得京豆数量
                'bean_award_day' => !$this->is_today($jd_sign_info['return_data']['sign_time']) ? $bean_award : ($jd_sign_info['return_data']['bean_award_day'] + $bean_award),
                // 总共 获得京豆数量
                'bean_award_total' => ($jd_sign_info['return_data']['bean_award_total'] + $bean_award),
                // 今天 获得营养液数量 每天清空
                'nutrients_day' => !$this->is_today($jd_sign_info['return_data']['sign_time']) ? $nutrients : ($jd_sign_info['return_data']['nutrients_day'] + $nutrients),
                // 总共 获得营养液数量
                'nutrients_total' => ($jd_sign_info['return_data']['nutrients_total'] + $nutrients),
                // 下次运行时间、状态2：7点再来领取
                // 'next_time' => $plant_info['timeNutrientsResState'] == 2 ? strtotime(date('Y-m-d 7:00:00') . '+ 1 day') : $plant_info['nextReceiveTime'],
                // 下次运行时间 不做限制
                'next_time' => 0,
                // 如果不能收取好友的营养液，设置为第二天9点开始重新扫描收取
                'canCollectUserNutr' => $this->canCollectUserNutr ? time() : strtotime(date('Y-m-d 9:00:00') . '+ 1 day'),
            ],
        ]));

    }

    /**
     * 种豆得豆 信息
     * @return array
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function plantBeanInfo()
    {
        $url = $this->buildURL('https://api.m.jd.com/client.action', [
            'functionId' => 'plantBeanIndex',
            'body' => json_encode([
                'monitor_refer' => '',
                'wxHeadImgUrl' => '',
                'shareUuid' => '',
                'followType' => '1',
                'monitor_source' => 'plant_m_plant_index',
                'version' => '8.4.0.0',
            ]),
            'appid' => 'ld',
            'client' => 'android',
            'clientVersion' => ' nexus 5 build/mra58n) applewebkit/537.36 (khtml, like gecko) chrome/79.0.3945.117 mobile safari/537.36',
            'networkType' => '',
            'osVersion' => '',
            'uuid' => '',
        ]);

        $data = $this->jdRequest($url);
        // DI()->logger->debug('种豆得豆 信息', $data);

        // $entryId = $data['entryId'];
        // 每轮信息数组
        $roundList = $data['roundList'];
        // DI()->logger->debug('roundList', $roundList);
        // 上轮信息
        $last_round_info = $roundList[0] ?? [];
        // 本轮信息
        $this_round_info = $roundList[1] ?? [];
        // 本轮ID
        // $this_round_id = $this_round_info['roundId'];
        $this->roundId = $this_round_info['roundId'];
        // 本轮现持有营养液数量
        $this_round_nutrients = $this_round_info['nutrients'] ?? 0;
        // 可获取营养液信息
        $timeNutrientsRes = $data['timeNutrientsRes'];
        // 可领取状态 1：可领取 2：7点再来领取（promptText）3：等待生成
        // $timeNutrientsResState = $timeNutrientsRes['state'];
        // 下次可领取时间 时间戳
        // $nextReceiveTime = substr($timeNutrientsRes['nextReceiveTime'] ?? '0', 0, 10);
        // 本次可领取数量
        $nutrCount = $timeNutrientsRes['nutrCount'] ?? 0;
        // 任务列表
        $awardList = $data['awardList'];

        return [
            // 'entryId' => $entryId,
            'last_round' => $last_round_info,
            // 'roundId' => $this_round_id,
            'nutrients' => $this_round_nutrients,
            // 'timeNutrientsRes' => $timeNutrientsRes,
            // 'timeNutrientsResState' => $timeNutrientsResState,
            // 'nextReceiveTime' => $nextReceiveTime,
            'nutrCount' => $nutrCount,
            'awardList' => $awardList,
        ];
    }

    /**
     * 完成种豆得豆任务
     * @param bool $info
     * @return bool
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function doPlantBeanAward($info = false)
    {
        // limitFlag为2代表任务已完成
        if ($info === false || $info['limitFlag'] == 2) return 0;

        // var_dump($info['awardName'] . ' ---- ' . $info['awardType']);
        // 每日签到 ---- 1
        // 邀请好友 ---- 2
        // 浏览店铺 ---- 3
        // 医药会场 ---- 4
        // 关注商品 ---- 5
        // 金融双签 ---- 7
        switch (intval($info['awardType'])) {
            case 3:
                return $this->shopTaskList();
                break;
            case 4:
                return $this->purchaseRewardTask();
                break;
            case 5:
                return $this->productTaskList();
                break;
            default:
                break;
        }
        return 0;
    }

    /**
     * 种豆得豆任务 - 关注店铺 - 列表
     * @return array|bool
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function shopTaskList()
    {
        $url = $this->buildURL('https://api.m.jd.com/client.action', [
            'functionId' => 'shopTaskList',
        ]);

        $data = $this->jdRequest($url, [
            'body' => json_encode([
                'monitor_refer' => 'plant_shopList',
                'monitor_source' => 'plant_app_plant_index',
                'version' => '8.4.0.0',
            ]),
            'appid' => 'ld',
            'client' => 'android',
            'clientVersion' => ' nexus 5 build/mra58n) applewebkit/537.36 (khtml, like gecko) chrome/79.0.3945.117 mobile safari/537.36',
            'networkType' => '',
            'osVersion' => '',
            'uuid' => '',
        ]);

        // DI()->logger->debug("种豆得豆任务 - 关注店铺 - 列表", $data);

        // 奖励的营养液数量
        $nutrients = 0;
        $goodShopList = $data['goodShopList'] ?? [];
        $moreShopList = $data['moreShopList'] ?? [];
        foreach ($goodShopList as $item) {
            // 任务状态为2表示还能领取营养液
            if ($item['taskState'] == 2) {
                $nutrients += $this->shopNutrientsTask($item['shopId'], $item['shopTaskId']);
            }
        }
        unset($goodShopList, $item);
        foreach ($moreShopList as $item) {
            // 任务状态为2表示还能领取营养液
            if ($item['taskState'] == 2) {
                $nutrients += $this->shopNutrientsTask($item['shopId'], $item['shopTaskId']);
            }
        }
        unset($moreShopList, $item);

        return $nutrients;
    }

    /**
     * 种豆得豆任务 - 关注店铺
     * @param bool $shopId
     * @param bool $shopTaskId
     * @return array|bool
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function shopNutrientsTask($shopId = false, $shopTaskId = false)
    {
        if (!$shopId || !$shopTaskId) return 0;
        $url = $this->buildURL('https://api.m.jd.com/client.action', [
            'functionId' => 'shopNutrientsTask',
        ]);

        $data = $this->jdRequest($url, [
            'body' => json_encode([
                'monitor_refer' => 'plant_shopNutrientsTask',
                'monitor_source' => 'plant_app_plant_index',
                'shopId' => (string)$shopId,
                'shopTaskId' => (string)$shopTaskId,
                'version' => '8.4.0.0',
            ]),
            'appid' => 'ld',
            'client' => 'android',
            'clientVersion' => ' nexus 5 build/mra58n) applewebkit/537.36 (khtml, like gecko) chrome/79.0.3945.117 mobile safari/537.36',
            'networkType' => '',
            'osVersion' => '',
            'uuid' => '',
        ]);
        DI()->logger->debug("种豆得豆任务 - 关注店铺", $data);
        // 取消关注店铺
        $this->JDFollowShop($shopId);

        return $data['nutrCount'] ?? 0;
    }

    /**
     * 关注店铺、取消关注店铺
     * @param bool $shopId
     * @param bool $follow
     * @return array|bool
     * @throws Exception
     */
    private function JDFollowShop($shopId = false, $follow = false)
    {
        if (!$shopId) return false;

        $follow = !$follow ? 'DelShopFav' : 'AddShopFav';
        $url = $this->buildURL("https://wq.jd.com/fav/shop/{$follow}", [
            'shopId' => (string)$shopId,
            'venderId' => (string)$shopId,
            'sceneval' => '2',
            'g_login_type' => '1',
            // 'callback' => 'jsonpCBKO',
            'g_ty' => 'ls',
        ]);
        $data = DI()->curl->setCookie($this->user_cookie)->setHeader([
            'Referer' => "https://shop.m.jd.com/?shopId={$shopId}",
        ])->json_get($url);
        // DI()->curl->unsetHeader('Referer');

        if ($data['iRet'] != 0) throw new Exception($data['errMsg']);

        DI()->logger->debug('关注店铺、取消关注店铺', $data);

        return true;
    }

    /**
     * 种豆得豆任务 - 逛逛会场
     * @return array|bool
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function purchaseRewardTask()
    {
        $url = $this->buildURL('https://api.m.jd.com/client.action', [
            'functionId' => 'purchaseRewardTask',
        ]);

        $data = $this->jdRequest($url, [
            'body' => json_encode([
                'monitor_refer' => 'plant_purchaseRewardTask',
                'monitor_source' => 'plant_app_plant_index',
                'roundId' => $this->roundId,
                'version' => '8.4.0.0',
            ]),
            'appid' => 'ld',
            'client' => 'android',
            'clientVersion' => ' nexus 5 build/mra58n) applewebkit/537.36 (khtml, like gecko) chrome/79.0.3945.117 mobile safari/537.36',
            'networkType' => '',
            'osVersion' => '',
            'uuid' => '',
        ]);

        DI()->logger->debug("完成奖励营养液的任务 - 逛逛会场", $data);

        return $data['nutrState'] == 1 ? 1 : 0;
    }

    /**
     * 种豆得豆任务 - 关注商品 - 列表
     * @return array|bool
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function productTaskList()
    {
        $url = $this->buildURL('https://api.m.jd.com/client.action', [
            'functionId' => 'productTaskList',
        ]);

        $data = $this->jdRequest($url, [
            'body' => json_encode([
                'monitor_refer' => 'plant_productTaskList',
                'monitor_source' => 'plant_app_plant_index',
                'version' => '8.4.0.0',
            ]),
            'appid' => 'ld',
            'client' => 'android',
            'clientVersion' => ' nexus 5 build/mra58n) applewebkit/537.36 (khtml, like gecko) chrome/79.0.3945.117 mobile safari/537.36',
            'networkType' => '',
            'osVersion' => '',
            'uuid' => '',
        ]);

        // DI()->logger->debug("种豆得豆任务 - 关注商品 - 列表", $data);

        // 奖励的营养液数量
        $nutrients = 0;
        $productInfoList = $data['productInfoList'] ?? [];
        foreach ($productInfoList as $item) {
            foreach ($item as $info) {
                // 任务状态为2表示还能领取营养液
                if ($info['taskState'] == 2) {
                    $nutrients += $this->productNutrientsTask($info['skuId'], $info['productTaskId']);
                }
            }
        }
        unset($productInfoList, $item);

        return $nutrients;
    }

    /**
     * 种豆得豆任务 - 关注商品
     * @param bool $skuId
     * @param bool $productTaskId
     * @return array|bool
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function productNutrientsTask($skuId = false, $productTaskId = false)
    {
        if (!$skuId || !$productTaskId) return 0;

        $url = $this->buildURL('https://api.m.jd.com/client.action', [
            'functionId' => 'productNutrientsTask',
        ]);

        $data = $this->jdRequest($url, [
            'body' => json_encode([
                'monitor_refer' => 'plant_productNutrientsTask',
                'monitor_source' => 'plant_app_plant_index',
                'productTaskId' => (string)$productTaskId,
                'skuId' => (string)$skuId,
                'version' => '8.4.0.0',
            ]),
            'appid' => 'ld',
            'client' => 'android',
            'clientVersion' => ' nexus 5 build/mra58n) applewebkit/537.36 (khtml, like gecko) chrome/79.0.3945.117 mobile safari/537.36',
            'networkType' => '',
            'osVersion' => '',
            'uuid' => '',
        ]);
        DI()->logger->debug("种豆得豆任务 - 关注商品", $data);
        // 取消收藏商品
        $this->JDFavoriteGood($skuId);

        return $data['nutrCount'] ?? 0;
    }

    /**
     * 收藏商品、取消收藏商品
     * @param bool $skuId
     * @param bool $favorite
     * @return array|bool
     * @throws Exception
     */
    private function JDFavoriteGood($skuId = false, $favorite = false)
    {
        if (!$skuId) return false;

        $favorite = !$favorite ? 'FavCommDel' : 'FavCommAdd';
        $url = $this->buildURL("https://wq.jd.com/fav/comm/{$favorite}", [
            // 'shopId' => (string)'',
            'commId' => (string)$skuId,
            'sceneval' => '2',
        ]);

        $data = DI()->curl->setCookie($this->user_cookie)->setHeader([
            'Referer' => "https://shop.m.jd.com/?shopId={$skuId}",
        ])->json_get($url);
        // DI()->curl->unsetHeader('Referer');

        if ($data['iRet'] != 0) throw new Exception($data['errMsg']);

        DI()->logger->debug('收藏商品、取消收藏商品', $data);

        return true;
    }

    /**
     * 收取营养液
     * @return integer
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function receiveNutrients()
    {
        if (!$this->roundId) return 0;

        $url = $this->buildURL('https://api.m.jd.com/client.action', [
            'functionId' => 'receiveNutrients',
            'body' => json_encode([
                'roundId' => $this->roundId,
                'monitor_source' => 'plant_m_plant_index',
                'monitor_refer' => 'plant_receiveNutrients',
                'version' => '8.4.0.0',
            ]),
            'appid' => 'ld',
            'client' => 'android',
            'clientVersion' => ' nexus 5 build/mra58n) applewebkit/537.36 (khtml, like gecko) chrome/79.0.3945.117 mobile safari/537.36',
            'networkType' => '',
            'osVersion' => '',
            'uuid' => '',
        ]);

        $data = $this->jdRequest($url);
        // 本次收取数量
        // $nutrients = $data['nutrients'];
        // 下次收取时间 时间戳
        // $nextReceiveTime = substr($data['nextReceiveTime'] ?? '0', 0, 10);

        DI()->logger->debug('收取营养液', $data);

        // 只返回 本次收取数量
        return $data['nutrients'];
    }

    /**
     * 种豆得豆好友收集营养液
     * @return integer
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function receivePlantFriend()
    {
        // 可收集好友列表页码
        $pageNum = 1;
        // 获取奖励的总数量
        $nutrients = 0;
        // 循环可收集好友列表
        while (!empty($list = $this->plantFriendList($pageNum)) && $this->canCollectUserNutr) {
            // 页码+1 - 下一页
            $pageNum += 1;
            // 取出可收集数量字段作判断
            $nutrCountList = array_column($list, 'nutrCount');
            if (empty($nutrCountList)) continue;
            // 只收取可收集数量3瓶的 数量少的不收取，因为数量少的肯定不会有奖励
            while (($key = array_search(3, $nutrCountList)) !== false && $this->canCollectUserNutr) {
                unset($nutrCountList[$key]);
                $item = $list[$key];
                // 累积总奖励
                $nutrients += $this->collectUserNutr($item['paradiseUuid']);
            }
        }
        return $nutrients;
    }

    /**
     * 种豆得豆好友列表
     * @param int $pageNum
     * @return array|mixed
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function plantFriendList($pageNum = 1)
    {
        if (!$this->canCollectUserNutr) return [];

        $url = $this->buildURL('https://api.m.jd.com/client.action', [
            'functionId' => 'plantFriendList',
            'body' => json_encode([
                'pageNum' => (string)$pageNum,
                'monitor_source' => 'plant_m_plant_index',
                'monitor_refer' => 'plantFriendList',
                'version' => '8.4.0.0',
            ]),
            'appid' => 'ld',
            'client' => 'android',
            'clientVersion' => ' nexus 5 build/mra58n) applewebkit/537.36 (khtml, like gecko) chrome/79.0.3945.117 mobile safari/537.36',
            'networkType' => '',
            'osVersion' => '',
            'uuid' => '',
        ]);

        $data = $this->jdRequest($url);

        // 有tips提示就是表示 今日收取次数已达上限
        if (isset($data['tips'])) {
            $this->canCollectUserNutr = false;
            return [];
        }

        // DI()->logger->debug('种豆得豆好友列表', $data);

        return $data['friendInfoList'] ?? [];
    }

    /**
     * 收取用户的营养液
     * @param bool|integer $paradiseUuid
     * @return integer
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function collectUserNutr($paradiseUuid = false)
    {
        if (!$paradiseUuid || !$this->canCollectUserNutr) return 0;
        $url = $this->buildURL('https://api.m.jd.com/client.action', [
            'functionId' => 'collectUserNutr',
            'body' => json_encode([
                'paradiseUuid' => $paradiseUuid,
                'roundId' => $this->roundId,
                'monitor_source' => 'plant_m_plant_index',
                'monitor_refer' => 'collectUserNutr',
                'version' => '8.4.0.0',
            ]),
            'appid' => 'ld',
            'client' => 'android',
            'clientVersion' => ' nexus 5 build/mra58n) applewebkit/537.36 (khtml, like gecko) chrome/79.0.3945.117 mobile safari/537.36',
            'networkType' => '',
            'osVersion' => '',
            'uuid' => '',
        ]);

        $data = $this->jdRequest($url);

        DI()->logger->debug('收取用户的营养液', $data);

        // 收取结果 1 收取成功 2 没有可收取的营养液 3 今日收取次数已达上限
        $collectResult = $data['collectResult'] ?? 0;
        // 标识 今日收取次数已达上限 ，不能继续收集
        if ($collectResult == 3) {
            $this->canCollectUserNutr = false;
        }

        // 帮忙收取获得的奖励 营养液 数量
        // $collectNutrRewards = $data['collectNutrRewards'] ?? 0;

        return $data['collectNutrRewards'] ?? 0;
    }

    /**
     * 培养京豆
     * @return array|bool
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function cultureBean()
    {
        if (!$this->roundId) return false;

        $url = $this->buildURL('https://api.m.jd.com/client.action', [
            'functionId' => 'cultureBean',
            'body' => json_encode([
                'roundId' => $this->roundId,
                'monitor_source' => 'plant_m_plant_index',
                'monitor_refer' => 'plant_index',
                'version' => '8.4.0.0',
            ]),
            'appid' => 'ld',
            'client' => 'android',
            'clientVersion' => ' nexus 5 build/mra58n) applewebkit/537.36 (khtml, like gecko) chrome/79.0.3945.117 mobile safari/537.36',
            'networkType' => '',
            'osVersion' => '',
            'uuid' => '',
        ]);

        $data = $this->jdRequest($url);

        DI()->logger->debug('培养京豆', $data);

        return $data;
    }

    /**
     * 收取京豆
     * @param bool $roundId
     * @return array|bool
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function receivedBean($roundId = false)
    {
        if (!$roundId) return 0;
        $url = $this->buildURL('https://api.m.jd.com/client.action', [
            'functionId' => 'receivedBean',
            'body' => json_encode([
                'monitor_refer' => 'plant_index',
                'monitor_source' => 'plant_m_plant_index',
                'roundId' => $roundId,
                'version' => '8.4.0.0',
            ]),
            'appid' => 'ld',
            'client' => 'android',
            'clientVersion' => ' nexus 5 build/mra58n) applewebkit/537.36 (khtml, like gecko) chrome/79.0.3945.117 mobile safari/537.36',
            'networkType' => '',
            'osVersion' => '',
            'uuid' => '',
        ]);

        $data = $this->jdRequest($url);

        DI()->logger->debug('收取京豆', $data);

        return $data['awardBean'] ?? 0;
    }

    /**
     * 执行京享值领京豆
     * @param array $jd_sign_info
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function doVVipClub(array $jd_sign_info = [])
    {
        if ($this->is_today($jd_sign_info['return_data']['sign_time'])) {
            return;
            // throw new \Library\Exception\Exception(\PhalApi\T('今天已签到'));
        }

        // 完成所有任务，获取免费次数
        $this->vvipclub_doTaskAll();

        // 京享值领京豆相关信息
        $luckyBox_info = $this->vvipclub_luckyBox();
        if ($luckyBox_info['freeTimes'] <= 0) {
            return;
        }

        // 获得京豆数量
        $bean_award = 0;
        for ($i = 0; $i < $luckyBox_info['freeTimes']; $i++) {
            $bean_award += $this->vvipclub_shaking();
        }

        $this->Model_JdSign()->updateByWhere([
            'id' => intval($jd_sign_info['id']),
            'sign_key' => $this->sign_key,
            'status' => 1,
        ], $this->initUpdateSignData([
            'return_data' => [
                // 今天 获得京豆数量
                'bean_award_day' => !$this->is_today($jd_sign_info['return_data']['sign_time']) ? $bean_award : ($jd_sign_info['return_data']['bean_award_day'] + $bean_award),
                // 总共 获得京豆数量
                'bean_award_total' => ($jd_sign_info['return_data']['bean_award_total'] + $bean_award),
            ],
        ]));

    }

    /**
     * 京享值领京豆 完成所有任务
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function vvipclub_doTaskAll()
    {
        $list = $this->vvipclub_lotteryTaskList();
        foreach ($list as $item) {
            $taskName = $item['taskName'];
            $item = $this->vvipclub_lotteryTaskInfo($taskName);
            // 不存在该任务 信息
            if (empty($item)) {
                continue;
            }
            $item = $item[0] ?? [];
            // 该任务已完成
            if ($item['currentFinishTimes'] >= $item['totalPrizeTimes']) {
                continue;
            }
            // 任务列表
            $taskItems = $item['taskItems'];
            for ($i = $item['currentFinishTimes']; $i < $item['totalPrizeTimes']; $i++) {
                $taskItemId = $taskItems[$i]['id'];
                $this->vvipclub_doTask($taskName, $taskItemId);
            }
        }
    }

    /**
     * 京享值领京豆 任务列表
     * @return mixed
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function vvipclub_lotteryTaskList()
    {
        $url = $this->buildURL('https://api.m.jd.com/client.action', [
            'appid' => 'vip_h5',
            'functionId' => 'vvipclub_lotteryTask',
            // 'body' => json_encode([
            //     'info' => 'shareTask,browseTask,attentionTask',
            //     'withItem' => false,
            // ]),
        ]);

        $data = $this->jdRequest($url);

        // DI()->logger->debug('京享值领京豆 任务列表', $data);

        return $data;
    }

    /**
     * 京享值领京豆 任务详情
     * @param $taskName
     * @return array|bool
     * @throws Exception
     */
    private function vvipclub_lotteryTaskInfo($taskName)
    {
        $url = $this->buildURL('https://api.m.jd.com/client.action', [
            'appid' => 'vip_h5',
            'functionId' => 'vvipclub_lotteryTask',
            'body' => json_encode([
                'info' => $taskName,
                'withItem' => true,
            ]),
        ]);

        try {
            $data = $this->jdRequest($url);
            // DI()->logger->debug('京享值领京豆 任务详情', $data);
            return $data;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 京享值领京豆 完成任务
     * @param $taskName
     * @param $taskItemId
     * @return bool|mixed
     * @throws Exception
     */
    private function vvipclub_doTask($taskName, $taskItemId)
    {
        $url = $this->buildURL('https://api.m.jd.com/client.action', [
            'appid' => 'vip_h5',
            'functionId' => 'vvipclub_doTask',
            'body' => json_encode([
                'taskName' => $taskName,
                'taskItemId' => $taskItemId,
            ]),
        ]);

        try {
            $data = $this->jdRequest($url);
            // DI()->logger->debug('京享值领京豆 完成任务', $data);
            return $data;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 京享值领京豆 信息
     * @return mixed
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function vvipclub_luckyBox()
    {
        $url = $this->buildURL('https://api.m.jd.com/client.action', [
            'functionId' => 'vvipclub_luckyBox',
            'body' => json_encode([
                'info' => 'freeTimes,title,beanNum,useBeanNum,imgUrl',
            ]),
            'appid' => 'vip_h5',
        ]);

        $data = $this->jdRequest($url);

        DI()->logger->debug('京享值领京豆 信息', $data);

        return $data;
    }

    /**
     * 京享值领京豆 摇一摇
     * @return mixed
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function vvipclub_shaking()
    {
        $url = $this->buildURL('https://api.m.jd.com/client.action', [
            'functionId' => 'vvipclub_shaking',
            'body' => json_encode([
                'type' => '0',
                'riskInfo' => [
                    'platform' => 3,
                    'pageClickKey' => 'MJDVip_Shake',
                ],
            ]),
            'appid' => 'vip_h5',
        ]);

        $data = $this->jdRequest($url);

        DI()->logger->debug('京享值领京豆 摇一摇', $data);

        return $data['prizeBean']['count'] ?? 0;
    }

    /**
     * 执行 福利转盘
     * @param array $jd_sign_info
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function doWheelSurf(array $jd_sign_info = [])
    {
        if ($this->is_today($jd_sign_info['return_data']['sign_time'])) {
            return;
            // throw new \Library\Exception\Exception(\PhalApi\T('今天已签到'));
        }

        // 京享值领京豆相关信息
        $info = $this->wheelSurfIndex();

        if ($info['lotteryCount'] <= 0) {
            return;
        }

        // 获得京豆数量
        $bean_award = 0;

        for ($i = 0; $i < $info['lotteryCount']; $i++) {
            $bean_award += $this->lotteryDraw();
        }

        $this->Model_JdSign()->updateByWhere([
            'id' => intval($jd_sign_info['id']),
            'sign_key' => $this->sign_key,
            'status' => 1,
        ], $this->initUpdateSignData([
            'return_data' => [
                // 今天 获得京豆数量
                'bean_award_day' => !$this->is_today($jd_sign_info['return_data']['sign_time']) ? $bean_award : ($jd_sign_info['return_data']['bean_award_day'] + $bean_award),
                // 总共 获得京豆数量
                'bean_award_total' => ($jd_sign_info['return_data']['bean_award_total'] + $bean_award),
            ],
        ]));

    }

    /**
     * 福利转盘 详情
     * @return mixed
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function wheelSurfIndex()
    {
        $url = $this->buildURL('https://api.m.jd.com/client.action', [
            'functionId' => 'wheelSurfIndex',
            'body' => json_encode([
                'actId' => 'jgpqtzjhvaoym',
                'appSource' => 'jdhome',
            ]),
            'appid' => 'ld',
            'client' => 'android',
            'clientVersion' => ' nexus 5 build/mra58n) applewebkit/537.36 (khtml, like gecko) chrome/79.0.3945.117 mobile safari/537.36',
            'networkType' => '',
            'osVersion' => '',
            'uuid' => '',
        ]);

        $data = $this->jdRequest($url);
        $this->lotteryCode = $data['lotteryCode'] ?? '';
        // DI()->logger->debug('福利转盘 详情', $data);
        return $data;
    }

    /**
     * 福利转盘 抽奖
     * @return mixed
     * @throws Exception
     */
    private function lotteryDraw()
    {
        $url = $this->buildURL('https://api.m.jd.com/client.action', [
            'functionId' => 'lotteryDraw',
            'body' => json_encode([
                'actId' => 'jgpqtzjhvaoym',
                'appSource' => 'jdhome',
                'lotteryCode' => $this->lotteryCode,
            ]),
            'appid' => 'ld',
            'client' => 'android',
            'clientVersion' => ' nexus 5 build/mra58n) applewebkit/537.36 (khtml, like gecko) chrome/79.0.3945.117 mobile safari/537.36',
            'networkType' => '',
            'osVersion' => '',
            'uuid' => '',
        ]);

        try {
            $data = $this->jdRequest($url);
            DI()->logger->debug('福利转盘 抽奖', $data);
            //{"isWinner":"0","chances":"1","prizeType":"5","prizeId":"910582","prizeName":"1个京豆","tips":"京豆1个","prizeSendNumber":"1"}
            $prizeType = $data['prizeType'] ?? false;
            // $prizeId = $data['prizeId'] ?? false;
            if ($prizeType == 5) {
                return $data['prizeSendNumber'] ?? 0;
            }
            return 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * 执行 京东金融APP签到
     * @param array $jd_sign_info
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function doJRSign(array $jd_sign_info = [])
    {
        if ($this->is_today($jd_sign_info['return_data']['sign_time'])) {
            return;
            // throw new \Library\Exception\Exception(\PhalApi\T('今天已签到'));
        }

        // 京享值领京豆相关信息
        $isSign = $this->JRSignInfo();

        if ($isSign) {
            return;
        }

        $this->JRSign();

        $this->Model_JdSign()->updateByWhere([
            'id' => intval($jd_sign_info['id']),
            'sign_key' => $this->sign_key,
            'status' => 1,
        ], $this->initUpdateSignData([
        ]));

    }

    /**
     * 京东金融APP签到 信息
     * @return bool|mixed
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function JRSignInfo()
    {
        $url = $this->buildURL('https://ms.jr.jd.com/gw/generic/gry/h5/m/querySignHistory');
        $form_data = [
            'reqData' => json_encode([
                'channelSource' => 'JRAPP',
                'riskDeviceParam' => json_encode([
                ]),
            ]),
        ];
        $res = $this->jrRequest($url, $form_data);
        // DI()->logger->debug('京东金融APP签到 信息', $res);
        if ($res['resBusiCode'] != 0) {
            throw new Exception($res['resBusiMsg']);
        }
        // 内容
        $data = $res['resBusiData'] ?? [];
        // 今天是否已签到 - 默认值为true，防止获取失败一直重复尝试签到
        $isSign = $data['isSign'] ?? true;
        return $isSign;
    }

    /**
     * 京东金融 APP 请求操作
     * @param      $url
     * @param bool $data
     * @return bool
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function jrRequest($url, $data = false)
    {
        $res = empty($data) ? DI()->curl->setCookie($this->user_cookie)->json_get($url) : DI()->curl->setCookie($this->user_cookie)->json_post($url, $data);
        $resultCode = $res['resultCode'] ?? 0;

        if ($resultCode == 3) {
            $this->Domain_JdUser()::loginStatusExpired($this->user_cookie);
            throw new Exception(T('请更新登录状态cookie'));
        } else if ($resultCode == 0) {
            $data = $res['resultData'];
        } else {
            DI()->logger->debug('京东金融返回未知状态', $res);
            throw new Exception(T('请求失败'));
        }

        // resBusiCode
        // 15 已经领取过
        // 24 当天未签到

        // sleep(2);
        return $data;
    }

    /**
     * 京东金融APP签到
     * @return array|mixed
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function JRSign()
    {
        $url = $this->buildURL('https://ms.jr.jd.com/gw/generic/gry/h5/m/signIn');

        $res = $this->jrRequest($url, [
            'reqData' => json_encode([
                'channelSource' => 'JRAPP',
                'riskDeviceParam' => json_encode([
                ]),
            ]),
        ]);
        DI()->logger->debug('京东金融APP签到', $res);
        if ($res['resBusiCode'] != 0) {
            throw new Exception($res['resBusiMsg']);
        }
        $data = $res['resBusiData'] ?? [];
        return $data;
    }

    /**
     * 执行 领取双签礼包
     * @param array $jd_sign_info
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function doDoubleSign(array $jd_sign_info = [])
    {
        if ($this->is_today($jd_sign_info['return_data']['sign_time'])) {
            return;
            // throw new \Library\Exception\Exception(\PhalApi\T('今天已签到'));
        }

        $bean_award = $this->doubleSign();
        // 领取双签送的营养液
        $nutrients = $this->receiveNutrientsTask('7');

        $this->Model_JdSign()->updateByWhere([
            'id' => intval($jd_sign_info['id']),
            'sign_key' => $this->sign_key,
            'status' => 1,
        ], $this->initUpdateSignData([
            'return_data' => [
                // 今天 获得京豆数量
                'bean_award_day' => !$this->is_today($jd_sign_info['return_data']['sign_time']) ? $bean_award : ($jd_sign_info['return_data']['bean_award_day'] + $bean_award),
                // 总共 获得京豆数量
                'bean_award_total' => ($jd_sign_info['return_data']['bean_award_total'] + $bean_award),
                // 今天 获得营养液数量
                'nutrients_day' => !$this->is_today($jd_sign_info['return_data']['sign_time']) ? $nutrients : ($jd_sign_info['return_data']['nutrients_day'] + $nutrients),
                // 总共 获得营养液数量
                'nutrients_total' => ($jd_sign_info['return_data']['nutrients_day'] + $nutrients),
            ],
        ]));

    }

    /**
     * 京东APP、京东金融APP 领取双签礼包
     * @return array|mixed
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function doubleSign()
    {
        $url = $this->buildURL('https://nu.jr.jd.com/gw/generic/jrm/h5/m/process');
        $form_data = [
            'reqData' => json_encode([
                'actCode' => 'FBBFEC496C',
                // 双签相关请求类型：9 获取双签相关信息 3 领取双签礼包 12 双签领取结果
                'type' => 3,
                'frontParam' => [
                    'channel' => 'JR',
                ],
                'riskDeviceParam' => json_encode([
                ]),
            ]),
        ];

        $res = $this->jrRequest($url, $form_data);
        DI()->logger->debug('领取双签礼包', $res);

        if ($res['code'] != 200) {
            DI()->logger->error("领取双签礼包|{$res['msg']}", $res);
            throw new Exception($res['msg']);
        }
        $res = $res['data'];
        if ($res['businessCode'] != '000sq') {
            DI()->logger->error("领取双签礼包|{$res['businessMsg']}", $res);
            throw new Exception($res['businessMsg']);
        }
        $data = $res['businessData'] ?? [];
        if ($data['businessCode'] != '000sq') {
            DI()->logger->error("领取双签礼包|{$data['businessMsg']}", $data);
            throw new Exception($data['businessMsg']);
        }
        return $data['businessData']['awardListVo'][0]['count'];
    }

    /**
     * 种豆得豆任务
     * @param bool $awardType 7签到
     * @return integer
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function receiveNutrientsTask($awardType = false)
    {
        if ($awardType === false) return 0;
        $url = $this->buildURL('https://api.m.jd.com/client.action', [
            'functionId' => 'receiveNutrientsTask',
        ]);

        $data = $this->jdRequest($url, [
            'body' => json_encode([
                'monitor_refer' => 'plant_receiveNutrientsTask',
                'monitor_source' => 'plant_app_plant_index',
                'awardType' => (string)$awardType,
                'version' => '8.4.0.0',
            ]),
            'appid' => 'ld',
            'client' => 'android',
            'clientVersion' => ' nexus 5 build/mra58n) applewebkit/537.36 (khtml, like gecko) chrome/79.0.3945.117 mobile safari/537.36',
            'networkType' => '',
            'osVersion' => '',
            'uuid' => '',
        ]);

        DI()->logger->debug("完成奖励营养液的任务|awardType|{$awardType}", $data);

        return $data['nutrNum'] ?? 0;
    }

    /**
     * 执行 京东金融APP - 提升白条额度
     * @param array $jd_sign_info
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function doJRRiseLimit(array $jd_sign_info = [])
    {
        if ($this->is_today($jd_sign_info['return_data']['sign_time'])) {
            return;
            // throw new \Library\Exception\Exception(\PhalApi\T('今天已签到'));
        }

        $info = $this->JRRiseLimitInfo();
        $this->JRRiseLimit($info);

        $this->Model_JdSign()->updateByWhere([
            'id' => intval($jd_sign_info['id']),
            'sign_key' => $this->sign_key,
            'status' => 1,
        ], $this->initUpdateSignData([
        ]));

    }

    /**
     * 京东金融APP 提升白条额度信息
     * @return array|mixed
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function JRRiseLimitInfo()
    {
        $url = $this->buildURL('https://ms.jr.jd.com/gw/generic/bt/h5/m/getRiseLimitItems');

        $form_data = [
            'reqData' => json_encode([
                "riskDeviceInfo" => [
                    "appId" => "com.jd.jinrong",
                ],
            ]),
        ];

        $res = $this->jrRequest($url, $form_data);
        if ($res['code'] != '0000') {
            DI()->logger->error("提升白条额度信息|{$res['error_msg']}", $res);
            throw new Exception($res['error_msg']);
        }
        $raiseItemList = $res['raiseItemList'] ?? [];
        $raiseItem = $raiseItemList[0] ?? [];
        // 编号 $raiseItem['uniqueCode']
        // 状态 $raiseItem['itemStatus'] 0 可领取 4 不可领取
        // 额度提升数量 $raiseItem['changeLimit']

        if (empty($raiseItem) || $raiseItem['itemStatus'] != 0) {
            return [];
        }

        return $raiseItem;
    }

    /**
     * 京东金融APP 提升白条额度
     * @param array $raiseItem
     * @return array|mixed|void
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function JRRiseLimit($raiseItem = [])
    {
        if (empty($raiseItem)) {
            return;
        }

        $url = $this->buildURL('https://ms.jr.jd.com/gw/generic/bt/h5/m/receiveDailyQuotaPackage');
        $form_data = [
            'reqData' => json_encode([
                "packageId" => $raiseItem['uniqueCode'],
            ]),
        ];

        $res = $this->jrRequest($url, $form_data);

        // DI()->logger->debug('提升白条额度', $res);

        if (empty($res['result'])) {
            DI()->logger->error("提升白条额度", $res);
            throw new Exception('返回数据结构异常');
        }
        $data = $res['result'];
        if ($data['code'] != '0' || $data['issuccess'] != 1) {
            DI()->logger->error("提升白条额度|{$data['error_msg']}", $data);
            throw new Exception($data['error_msg']);
        }
        return $res;
    }

    /**
     * 执行 京东金融APP - 翻牌赢钢镚
     * @param array $jd_sign_info
     * @throws Exception
     */
    private function doJRFlopReward(array $jd_sign_info = [])
    {
        if ($this->is_today($jd_sign_info['return_data']['sign_time'])) {
            return;
            // throw new \Library\Exception\Exception(\PhalApi\T('今天已签到'));
        }

        // 查询是否能翻牌
        $can_flop = $this->JRFlopRewardInfo();
        if (!$can_flop) {
            return;
        }

        $this->JRFlopReward();

        $this->Model_JdSign()->updateByWhere([
            'id' => intval($jd_sign_info['id']),
            'sign_key' => $this->sign_key,
            'status' => 1,
        ], $this->initUpdateSignData([
        ]));

    }

    /**
     * 京东金融APP 翻牌赢钢镚 信息
     * @return int
     * @throws Exception
     */
    private function JRFlopRewardInfo()
    {
        $url = $this->buildURL('https://gps.jd.com/activity/signin/reward/home', [
            'uaType' => 2,
            'platCode' => 3,
        ]);

        $res = DI()->curl->setCookie($this->user_cookie)->json_get($url);

        if ($res['code'] != 1) {
            DI()->logger->error("翻牌赢钢镚 信息|{$res['msg']}", $res);
            throw new Exception($res['msg']);
        }

        $data = $res['data'];
        if ($data['result'] != 0) {
            DI()->logger->error("翻牌赢钢镚 信息|返回数据异常", $data);
            throw new Exception('返回数据异常');
        }
        $isAllowSignin = 0;
        if (isset($data['isAllowSignin'])) {
            $isAllowSignin = $data['isAllowSignin'];
        } else if (isset($data['total']) && isset($data['used'])) {
            $isAllowSignin = $data['total'] > $data['used'] ? 1 : 0;
        } else {
            DI()->logger->error("翻牌赢钢镚 信息|返回数据发生变化", $data);
        }

        return $isAllowSignin;
    }

    /**
     * 京东金融APP 翻牌赢钢镚
     * @return mixed
     * @throws Exception
     */
    private function JRFlopReward()
    {
        $url = $this->buildURL('https://gps.jd.com/activity/signin/reward/choice', [
            'uaType' => 2,
            'position' => 1,
            'platCode' => 3,
        ]);

        $res = DI()->curl->setCookie($this->user_cookie)->json_get($url);

        if ($res['code'] != 1) {
            DI()->logger->error("翻牌赢钢镚|{$res['msg']}", $res);
            throw new Exception($res['msg']);
        }

        $data = $res['data'];
        if ($data['result'] != 0) {
            DI()->logger->error("翻牌赢钢镚|返回数据异常", $data);
            throw new Exception('返回数据异常');
        }

        // DI()->logger->debug("翻牌赢钢镚", $data);
        return $data;
    }

    /**
     * 执行 京东金融APP - 金币抽奖
     * @param array $jd_sign_info
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function doJRLottery(array $jd_sign_info = [])
    {
        if ($this->is_today($jd_sign_info['return_data']['sign_time'])) {
            return;
            // throw new \Library\Exception\Exception(\PhalApi\T('今天已签到'));
        }

        // 查询是否能翻牌
        $info = $this->JRLotteryInfo();
        if ($info === false || $info > 0) {
            return;
        }

        $this->JRLottery();

        $this->Model_JdSign()->updateByWhere([
            'id' => intval($jd_sign_info['id']),
            'sign_key' => $this->sign_key,
            'status' => 1,
        ], $this->initUpdateSignData([
        ]));

    }

    /**
     * 京东金融APP 金币抽奖 信息
     * @return bool|mixed
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function JRLotteryInfo()
    {
        $url = $this->buildURL('https://ms.jr.jd.com/gw/generic/hy/h5/m/lotteryInfo', [
            'reqData' => json_encode([
                'actKey' => 'AbeQry',
            ]),
        ]);

        $res = $this->jrRequest($url);

        if ($res['code'] != '0000') {
            DI()->logger->error("金币抽奖 信息|{$res['msg']}", $res);
            throw new Exception($res['msg']);
        }
        DI()->logger->debug("金币抽奖 信息", $res);

        $data = $res['data'];
        // 设定，消耗0金币为免费抽奖，返回数组错误时返回false
        $lotteryCoins = $data['lotteryCoins'] ?? false;

        return $lotteryCoins;
    }

    /**
     * 京东金融APP 金币抽奖
     * @return bool|mixed
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function JRLottery()
    {
        $url = $this->buildURL('https://ms.jr.jd.com/gw/generic/hy/h5/m/lottery', [
            'reqData' => json_encode([
                'actKey' => 'AbeQry',
            ]),
        ]);

        DI()->curl->setHeader(['Referer' => 'https://m.jr.jd.com/member/coinlottery/index.html?channel=01-qd-190306']);
        $res = $this->jrRequest($url);
        // DI()->curl->unsetHeader('Referer');
        DI()->logger->debug("金币抽奖", $res);

        // if ($res['code'] != '1000') {
        //     DI()->logger->error("金币抽奖|{$res['msg']}", $res);
        //     throw new \Library\Exception\Exception($res['msg']);
        // }
        //
        // $data = $res['data'];

        return true;
    }

    /**
     * 执行 京东金融APP - 每日赚京豆签到
     * @param array $jd_sign_info
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function doJRSignRecords(array $jd_sign_info = [])
    {
        if ($this->is_today($jd_sign_info['return_data']['sign_time'])) {
            return;
            // throw new \Library\Exception\Exception(\PhalApi\T('今天已签到'));
        }

        // 获得京豆数量
        $bean_award = $this->JRSignRecords();

        $this->Model_JdSign()->updateByWhere([
            'id' => $jd_sign_info['id'],
            'sign_key' => $this->sign_key,
            'status' => 1,
        ], $this->initUpdateSignData([
            'return_data' => [
                // 今天 获得京豆数量
                'bean_award_day' => !$this->is_today($jd_sign_info['return_data']['sign_time']) ? $bean_award : ($jd_sign_info['return_data']['bean_award_day'] + $bean_award),
                // 总共 获得京豆数量
                'bean_award_total' => ($jd_sign_info['return_data']['bean_award_total'] + $bean_award),
            ],
        ]));

    }

    /**
     * 京东金融APP 每日赚京豆 - 连续签到信息
     * @return int
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function JRSignRecords()
    {
        $url = $this->buildURL('https://ms.jr.jd.com/gw/generic/zc/h5/m/signRecords');

        DI()->curl->setHeader(['Referer' => 'https://jddx.jd.com/m/jddnew/money/index.html?from=dlqfl']);
        $res = $this->jrRequest($url, [
            'reqData' => json_encode([
                'bizLine' => 2,
                'deviceInfo' => [
                    // 'openUUID' => '',
                    'optType' => 'https://jddx.jd.com/m/jddnew/money/index.html?from=dlqfl',
                ],
                'clientType' => 'sms',
                'clientVersion' => '11.0',
            ]),
        ]);
        // DI()->curl->unsetHeader('Referer');

        if ($res['resultCode'] != '00000') {
            DI()->logger->error("每日赚京豆 - 连续签到信息|{$res['resultMsg']}", $res);
            throw new Exception($res['resultMsg']);
        }

        $data = $res['data'];
        if (empty($data['signRecords'])) {
            DI()->logger->debug("每日赚京豆 - 连续签到信息", $data);
            return 0;
        }

        // 获得京豆数量
        $bean_award = 0;
        foreach ($data['signRecords'] as $signRecord) {
            if ($signRecord['signDate'] == date('Ymd')) {
                if ($signRecord['signStatus'] == 2) {
                    $bean_award += $this->JRSignRewardGift();
                }
                break;
            }
        }

        return $bean_award;
    }

    /**
     * 京东金融APP 每日赚京豆 - 签到
     * @return int
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function JRSignRewardGift()
    {
        $url = $this->buildURL('https://ms.jr.jd.com/gw/generic/zc/h5/m/signRewardGift');

        DI()->curl->setHeader(['Referer' => 'https://jddx.jd.com/m/jddnew/money/index.html?from=dlqfl']);
        $res = $this->jrRequest($url, [
            'reqData' => json_encode([
                'bizLine' => 2,
                'signDate' => date('Ymd'),
                'deviceInfo' => [
                ],
                'clientType' => 'sms',
                'clientVersion' => '11.0',
            ]),
        ]);
        // DI()->curl->unsetHeader('Referer');

        if ($res['resultCode'] != '00000') {
            DI()->logger->error("每日赚京豆 - 签到|{$res['resultMsg']}", $res);
            throw new Exception($res['resultMsg']);
        }

        $data = $res['data'];
        DI()->logger->debug("每日赚京豆 - 签到", $data);

        return $data['rewardAmount'] ?? 0;
    }

    /**
     * 京东金融APP签到 今天签到结果
     * @return array|mixed
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalServerErrorException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private function JRTodaySignResult()
    {
        $url = $this->buildURL('https://ms.jr.jd.com/gw/generic/gry/h5/m/queryTodaySignResult');
        $form_data = [
            'reqData' => json_encode([
                'channelSource' => 'JRAPP',
                'riskDeviceParam' => json_encode([
                ]),
            ]),
        ];

        $res = $this->jrRequest($url, $form_data);
        // DI()->logger->debug('京东金融APP签到', $res);
        if ($res['resBusiCode'] != 0) {
            throw new Exception($res['resBusiMsg']);
        }
        $data = $res['resBusiData'] ?? [];
        return $data;
    }

    /**
     * 获取京东用户信息
     * @param array $data
     * @return array
     * @throws Exception
     */
    public function getJDUserInfo(array $data)
    {
        $url = $this->buildURL('https://wq.jd.com/user_new/info/GetJDUserInfoUnion', [
            'orgFlag' => 'JD_PinGou_New',
            'callSource' => 'mainorder',
            'channel' => 4,
            'isHomewhite' => 0,
            'sceneval' => 2,
            'g_login_type' => 1,
            // 'callback'=>'GetJDUserInfoUnion',
            'g_ty' => 'ls',
        ]);
        $result = DI()->curl->setCookie([
            'pt_key' => $data['pt_key'],
            'pt_pin' => $data['pt_pin'],
            'pt_token' => $data['pt_token'],
        ])->setHeader([
            'Referer' => 'https://home.m.jd.com/myJd/newhome.action',
        ])->json_get($url);
        // DI()->curl->unsetHeader('Referer');
        if ($result['retcode'] != 0 || empty($result['data'])) {
            DI()->logger->error('获取京东会员信息错误', $result);
            throw new Exception(T('未知错误'));
        }
        $data = $result['data'] ?? [];
        $user_name = $data['userInfo']['baseInfo']['curPin'];
        $nick_name = $data['userInfo']['baseInfo']['nickname'];
        $level_name = $data['userInfo']['baseInfo']['levelName'];
        return [
            'user_name' => $user_name,
            'nick_name' => $nick_name,
            'level_name' => $level_name,
        ];

    }

}

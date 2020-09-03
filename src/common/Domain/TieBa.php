<?php
/**
 * Created by PhpStorm.
 * User: LYi-Ho
 * Date: 2018-11-26
 * Time: 11:14:57
 */

namespace Common\Domain;


use Library\DateHelper;
use Library\Exception\BadRequestException;
use Library\Exception\InternalServerErrorException;
use PhalApi\Model\NotORMModel;
use function Common\DI;
use function PhalApi\T;
use function TieBa\easy_match;
use function TieBa\sqlAdds;
use function TieBa\textMiddle;

/**
 * 贴吧 领域层
 * TieBa
 * @package Common\Domain
 * @author  LYi-Ho 2018-11-26 11:14:57
 */
class TieBa
{
    use Common;

    protected $useragent = 'Mozilla/5.0 (Linux; Android 4.4.2; H650 Build/KOT49H) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/30.0.0.0 Mobile Safari/537.36';
    protected $header = [
        'Accept:application/json',
        'Accept-Encoding:gzip,deflate,sdch',
        'Accept-Language:zh-CN,zh;q=0.8',
        'Connection:close',
    ];
    protected $cookie = [];
    protected $curlopt_header = false;
    protected $curlopt_nobody = false;

    public function __construct()
    {
    }

    /**
     * 贴吧 数据层
     * @return \Common\Model\TieBa|\Common\Model\Common|NotORMModel
     */
    protected function Model_TieBa()
    {
        return self::getModel('TieBa');
    }

    /**
     * 百度ID 数据层
     * @return \Common\Model\BaiDuId|\Common\Model\Common|NotORMModel
     */
    protected function Model_BaiDuId()
    {
        return self::getModel('BaiDuId');
    }

    /**
     * 用户 数据层
     * @return \Common\Model\User|\Common\Model\Common|NotORMModel
     */
    protected function Model_User()
    {
        return self::getModel('User');
    }

    /**
     * 百度ID 领域层
     * @return BaiDuId
     */
    protected function Domain_BaiDuId()
    {
        return self::getDomain('BaiDuId');
    }

    /**
     * CURL整合--返回数组
     * @param string     $url
     * @param bool|array $post
     * @return mixed
     * @throws InternalServerErrorException
     * @throws \PhalApi\Exception\InternalServerErrorException
     */
    protected function curl($url, $post = false)
    {
        $option = [
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_ENCODING => 'gzip',
            CURLOPT_REFERER => 'https://wappass.baidu.com/',
            CURLOPT_USERAGENT => $this->useragent,
            CURLOPT_HEADER => boolval($this->curlopt_header),
            CURLOPT_NOBODY => boolval($this->curlopt_nobody),
        ];
        $ret = $post ?
            DI()->curl
                ->setHeader($this->header)
                ->setOption($option)
                ->setCookie($this->cookie)
                ->post($url, $post)
            :
            DI()->curl
                ->setHeader($this->header)
                ->setOption($option)
                ->setCookie($this->cookie)
                ->get($url);

        $this->curlopt_header = $this->curlopt_nobody = false;
        $this->cookie = [];
        if (empty($ret)) throw new InternalServerErrorException(T('连接到百度服务器失败'));
        return $ret;
    }

    /**
     * 忽略签到
     * @param $tieba_id
     * @param $no
     * @return bool
     * @throws InternalServerErrorException
     */
    public function noSignTieba($tieba_id, $no)
    {
        $result = $this->Model_TieBa()->update($tieba_id, ['no' => intval($no)]);
        if ($result === false) throw new InternalServerErrorException(T('操作失败'));
        return true;
    }

    /**
     * 添加BDUSS
     * @param $bduss
     * @throws BadRequestException
     * @throws InternalServerErrorException
     * @throws \PhalApi\Exception\InternalServerErrorException
     */
    public function addBduss($bduss)
    {
        // 去除双引号和bduss=
        $bduss = str_replace('"', '', $bduss);
        $bduss = str_ireplace('BDUSS=', '', $bduss);
        $bduss = str_replace(' ', '', $bduss);
        $bduss = sqlAdds($bduss);
        $baidu_name = sqlAdds($this->getBaiduId($bduss));
        if (empty($baidu_name)) throw new BadRequestException(T('您的 BDUSS Cookie 信息有误，请核验后重新绑定'));
        $this->Domain_BaiDuId()::add($baidu_name, $bduss);
    }

    /**
     * 获取一个bduss对应的百度用户名
     * @param string $bduss BDUSS
     * @return string|bool 百度用户名，失败返回FALSE
     * @throws \PhalApi\Exception\InternalServerErrorException
     * @throws InternalServerErrorException
     */
    public function getBaiduId($bduss)
    {
        $this->cookie = ['BDUSS' => $bduss, 'BAIDUID' => strtoupper(md5(NOW_TIME))];
        $data = $this->curl('http://wapp.baidu.com/');
        return urldecode(textMiddle($data, 'i?un=', '">'));
    }

    /**
     * 扫描指定用户的所有贴吧并储存--用于一键刷新
     * @param string $user_id UserID，如果留空，表示当前用户的UID
     * @throws BadRequestException
     * @throws InternalServerErrorException
     * @throws \PhalApi\Exception\InternalServerErrorException
     */
    public function scanTiebaByUser($user_id = '')
    {
        set_time_limit(0);
        if (empty($user_id)) {
            $user = User::getCurrentUser(true);// 获取登录状态
            $user_id = $user['id'];
        }
        $bx = $this->Model_BaiDuId()->getListByWhere(['user_id' => $user_id]);
        foreach ($bx as $by) {
            // $upid = $by['id'];
            // $bduss[$upid] = $by['bduss'];
            $this->scanTiebaByPid($by['id']);
        }
    }

    /**
     * 扫描指定PID的所有贴吧
     * @param int $pid PID
     * @throws InternalServerErrorException
     * @throws \PhalApi\Exception\InternalServerErrorException
     */
    public function scanTiebaByPid($pid)
    {
        set_time_limit(0); // 不超时
        $baiduid_model = $this->Model_BaiDuId();
        $cma = $baiduid_model->get($pid);
        $tieba_model = $this->Model_TieBa();
        $user_id = $cma['user_id'];
        $bduss = $cma['bduss'];
        $bname = $cma['name'];
        $pid = $cma['id'];
        unset($cma);
        $bid = $this->getUserid($bname);
        $pn = 1;
        $a = 0;
        while (true) {
            if (empty($bid)) {
                break;
            }
            $rc = $this->getTieba($bid, $bduss, $pn);
            $ngf = isset($rc["forum_list"]["non-gconforum"]) ? $rc["forum_list"]["non-gconforum"] : [];
            if (!empty($rc['forum_list']['gconforum'])) {
                foreach ($rc['forum_list']['gconforum'] as $v) {
                    $ngf[] = $v;
                }
            }
            if (!empty($ngf) && is_array($ngf)) {
                $refresh_time = $rc['time'];
                foreach ($ngf as $v) {
                    $vn = addslashes(htmlspecialchars($v['name']));
                    $ist = $tieba_model->getCount(['baidu_id' => $pid, 'tieba' => $vn]);
                    if ($ist == 0) {
                        $a++;
                        $tieba_model->insert(['baidu_id' => $pid, 'fid' => $v['id'], 'user_id' => $user_id, 'tieba' => $vn, 'refresh_time' => $refresh_time]);
                    }
                }
                if ($a > 0) {
                    $baiduid_model->update($pid, ['refresh_time' => $refresh_time]);
                }
            }
            if ((count($ngf) < 1)) {
                break;
            }
            $pn++;
        }
    }

    /**
     * 获取贴吧用户id
     * 获取指定pid用户userid--根据贴吧用户名找
     * @throws InternalServerErrorException
     */
    private function getUserid($name)
    {
        $res = DI()->curl->json_get("http://tieba.baidu.com/home/get/panel?ie=utf-8&un={$name}");
        return $res['data']['id'] ?? false;
    }

    /**
     * 获取指定pid
     * @throws \PhalApi\Exception\InternalServerErrorException
     */
    public function getTieba($userid, $bduss, $pn)
    {
        $head = [
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: Mozilla/5.0 (SymbianOS/9.3; Series60/3.2 NokiaE72-1/021.021; Profile/MIDP-2.1 Configuration/CLDC-1.1 ) AppleWebKit/525 (KHTML, like Gecko) Version/3.0 BrowserNG/7.1.16352',
        ];
        $url = 'http://c.tieba.baidu.com/c/f/forum/like';
        $data = [
            '_client_id' => 'wappc_' . NOW_TIME . '_' . '258',
            '_client_type' => 2,
            '_client_version' => '6.5.8',
            '_phone_imei' => '357143042411618',
            'from' => 'baidu_appstore',
            'is_guest' => 1,
            'model' => 'H60-L01',
            'page_no' => $pn,
            'page_size' => 200,
            'timestamp' => NOW_TIME . '903',
            'uid' => $userid,
        ];
        $sign_str = '';
        foreach ($data as $k => $v) {
            $sign_str .= $k . '=' . $v;
        }
        $sign = strtoupper(md5($sign_str . 'tiebaclient!!!'));
        $data['sign'] = $sign;
        $res = DI()->curl
            ->setHeader($head)
            ->setCookie(['BDUSS' => $bduss])
            ->setOption([CURLOPT_SSL_VERIFYPEER => false, CURLOPT_FOLLOWLOCATION => true])
            ->post($url, $data);
        $res = json_decode($res, true);
        return $res;
    }

    /**
     * 得到贴吧 FID
     * @param string $kw 贴吧名
     * @return string FID
     * @throws \PhalApi\Exception\InternalServerErrorException
     */
    public function getFid($kw)
    {
        $url = 'http://tieba.baidu.com/mo/m?kw=' . urlencode($kw);
        $s = DI()->curl
            ->setHeader(['User-Agent: fuck phone', 'Referer: http://wapp.baidu.com/', 'Content-Type: application/x-www-form-urlencoded', 'Cookie:BAIDUID=' . strtoupper(md5(NOW_TIME))])
            ->get($url);
        $x = easy_match('<input type="hidden" name="fid" value="*"/>', $s);
        return $x[1] ?? false;
    }

    /**
     * 执行全部贴吧用户的签到任务
     * @throws \PhalApi\Exception\InternalServerErrorException
     */
    public function doRetryAll()
    {
        set_time_limit(0);
        //处理所有签到出错的贴吧
        $tieba_model = $this->Model_TieBa();
        $where = [
            // 不忽略签到
            'no = ?' => 0,
            // 签到状态不为0==签到出错
            'status != ?' => 0,
        ];
        $total_sign_tieba = $tieba_model->getCount($where); // 该条件下所有贴吧数量
        $limit = 100; // 100条100条循环拿
        $count = ceil($total_sign_tieba / $limit); // 循环100条的次数
        for ($i = 1; $i <= $count; $i++) {
            $tieba_list = $tieba_model->queryRows("
            SELECT
                `t`.`tieba`,
                `t`.`id`,
                `t`.`baidu_id`,
                `t`.`fid`,
                `bid`.`bduss` 
            FROM
                `ly_tieba` AS `t`
                LEFT JOIN `ly_baiduid` AS `bid` ON `t`.`baidu_id` = `bid`.`id` 
            WHERE
                `t`.`no` = ? 
                AND `t`.`status` != ? 
            ORDER BY
                `t`.`id` 
                LIMIT ?,?
            ", [
                0,// 不忽略签到
                0, // 签到状态不为0==签到出错
                0,// 偏移量
                $limit,// 数量
            ]);
            foreach ($tieba_list as $item) {
                $this->doSign($item['tieba'], $item['id'], $item['bduss'], $item['fid']);
            }
        }
    }

    /**
     * 获取签到状态
     * @param array $user
     * @return array
     * @throws BadRequestException
     */
    public function getSignStatus($user = [])
    {
        if (empty($user)) throw new BadRequestException(T('非法参数'));
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
        $day_time = DateHelper::getDayTime();
        $tieba_model = $this->Model_TieBa();
        $total = $tieba_model->getCount(['user_id=?' => $user['user_id']]);
        if ($total <= 0) throw new BadRequestException(T('该用户没有贴吧账号'));
        $success_count = $tieba_model->getCount(['user_id=?' => $user['user_id'], 'no=?' => 0, 'status=?' => 0, 'latest>=?' => $day_time['begin'], 'latest<=?' => $day_time['end']]);//签到成功
        $fail_count = $tieba_model->getCount(['user_id=?' => $user['user_id'], 'no=?' => 0, 'status>?' => 0, 'latest>=?' => $day_time['begin'], 'latest<=?' => $day_time['end']]);//签到失败
        $no_count = $tieba_model->getCount(['user_id=?' => $user['user_id'], 'no>?' => 0]);//忽略签到
        $result = [
            'user_name' => $user['user_name'],
            'greeting' => $greeting,
            'tieba_count' => $total,
            'success_count' => $success_count,
            'fail_count' => $fail_count,
            'ignore_count' => $no_count,
        ];

        return $result;
    }

    /**
     * 执行全部贴吧用户的签到任务
     * @throws \PhalApi\Exception\InternalServerErrorException
     */
    public function doSignAll()
    {
        set_time_limit(0);
        $day_time = DateHelper::getDayTime(); // 今天开始的时间和结束的时间的时间戳
        //处理所有未签到的贴吧
        while (true) {
            $tieba_list = $this->Model_TieBa()->queryRows("
            SELECT
                `t`.`tieba`,
                `t`.`id`,
                `t`.`baidu_id`,
                `t`.`fid`,
                `bid`.`bduss` 
            FROM
                `ly_tieba` AS `t`
                LEFT JOIN `ly_baiduid` AS `bid` ON `t`.`baidu_id` = `bid`.`id` 
            WHERE
                `t`.`no` = ? 
                AND `t`.`latest` < ? 
            ORDER BY
                `t`.`id` 
                LIMIT ?,?
            ", [
                0,// 不忽略签到
                $day_time['begin'], // 今天没有签到
                0,// 偏移量
                100,// 数量 - 100条100条循环拿
            ]);
            if (empty($tieba_list)) break;
            foreach ($tieba_list as $item) {
                $this->doSign($item['tieba'], $item['id'], $item['bduss'], $item['fid']);
            }
        }
    }

    /**
     * 执行一个贴吧用户的签到
     * @param $baidu_id
     * @throws \PhalApi\Exception\InternalServerErrorException
     */
    public function doSignByBaiDuId($baidu_id)
    {
        set_time_limit(0);
        $day_time = DateHelper::getDayTime(); // 今天开始的时间和结束的时间的时间戳
        //处理所有未签到的贴吧
        while (true) {
            $tieba_list = $this->Model_TieBa()->queryRows("
            SELECT
                `t`.`id`,
                `t`.`tieba`,
                `t`.`fid`,
                `bid`.`bduss`  
            FROM
                `ly_tieba` AS `t`
                LEFT JOIN `ly_baiduid` AS `bid` ON `t`.`baidu_id` = `bid`.`id` 
            WHERE
                `bid`.`id` = ? 
                AND `t`.`no` = ? 
                AND `t`.`latest` < ?
            ORDER BY `t`.`id` 
                LIMIT ?,?
            ",
                [
                    $baidu_id,// 该贴吧用户
                    0,// 不忽略签到
                    $day_time['begin'],// 今天没有签到
                    0,// 从0开始取
                    100,// 100条100条循环拿
                ]
            );
            if (empty($tieba_list)) break;
            foreach ($tieba_list as $item) {
                $this->doSign($item['tieba'], $item['id'], $item['bduss'], $item['fid']);
            }
        }
    }

    /**
     * 执行一个会员的签到
     * @param $user_id
     * @throws \PhalApi\Exception\InternalServerErrorException
     */
    public function doSignByUserId($user_id)
    {
        set_time_limit(0);
        $day_time = DateHelper::getDayTime(); // 今天开始的时间和结束的时间的时间戳
        //处理所有未签到的贴吧
        while (true) {
            $tieba_list = $this->Model_TieBa()->queryRows("
            SELECT
                `t`.`id`,
                `t`.`tieba`,
                `t`.`fid`,
                `bid`.`bduss`  
            FROM
                `ly_tieba` AS `t`
                LEFT JOIN `ly_baiduid` AS `bid` ON `t`.`baidu_id` = `bid`.`id` 
            WHERE
                `bid`.`user_id` = ? 
                AND `t`.`no` = ? 
                AND `t`.`latest` < ?
            ORDER BY `t`.`id` 
                LIMIT ?,?
            ",
                [
                    $user_id,// 该会员
                    0,// 不忽略签到
                    $day_time['begin'],// 今天没有签到
                    0,// 从0开始取
                    100,// 100条100条循环拿
                ]
            );
            if (empty($tieba_list)) break;
            foreach ($tieba_list as $item) {
                $this->doSign($item['tieba'], $item['id'], $item['bduss'], $item['fid']);
            }
        }
    }

    /**
     * 执行一个贴吧的签到
     * @param $tieba_id
     * @return array|mixed
     * @throws BadRequestException
     * @throws \PhalApi\Exception\InternalServerErrorException
     */
    public function doSignByTieBaId($tieba_id)
    {
        // $x = self::Model_TieBa()->get($tieba_id);
        $tieba_info = $this->Model_TieBa()->queryRows("
        SELECT
            `t`.`tieba`,
            `t`.`fid`,
            `bid`.`bduss` 
        FROM
            `ly_tieba` AS `t`
            LEFT JOIN `ly_baiduid` AS `bid` ON `t`.`baidu_id` = `bid`.`id` 
        WHERE
            `t`.`id` =?
        ",
            [
                $tieba_id,
            ]
        );
        $tieba_info = $tieba_info[0] ?? [];
        if (empty($tieba_info)) throw new BadRequestException(T('您没有该贴吧'));
        $this->doSign($tieba_info['tieba'], $tieba_id, $tieba_info['bduss'], $tieba_info['fid']);
        return self::getInfo($tieba_id);
    }

    /**
     * 对一个贴吧执行完整的签到任务
     * @param $kw
     * @param $id
     * @param $bduss
     * @param $fid
     * @return array|mixed
     * @throws \PhalApi\Exception\InternalServerErrorException
     */
    public function doSign($kw, $id, $bduss, $fid)
    {
        $again_error_id = 160002; //重复签到错误代码
        // $again_error_id_2 = 1101; //特殊的重复签到错误代码！！！签到过快=已签到
        // $again_error_id_3 = 1102; //特殊的重复签到错误代码！！！签到过快=已签到
        $status_succ = false;
        $ck = $bduss;
        $kw = addslashes($kw);

        $update_data = [];

        if (empty($fid)) {
            $fid = $this->getFid($kw);//贴吧唯一ID
            // $update_data = array_merge($update_data, ['fid' => $fid]);
            $update_data['fid'] = $fid;
        }

        $time = time();
        $error_code = 0;
        $error_msg = '';
        //三种签到方式依次尝试
        $tbs = $this->getTbs($ck);
        //客户端
        if ($status_succ === false) {
            $r = $this->DoSign_Client($kw, $fid, $ck, $tbs);
            $v = json_decode($r, true);
            if ($v != $r && $v != null) {//decode失败时会直接返回原文或NULL
                $time = $v['time'];
                if (!empty($v['error_code']) && $v['error_code'] != $again_error_id) {
                    $error_code = $v['error_code'];
                    $error_msg = $v['error_msg'];
                }
            }
        }

        /*//手机网页
        if ($status_succ === false) {
            $r = self::DoSign_Mobile($kw, $fid, $ck, $tbs);
            $v = json_decode($r, true);
            if ($v != $r && $v != NULL) {//decode失败时会直接返回原文或NULL
                if (empty($v['no']) || $v['no'] == $again_error_id_2 || $v['no'] == $again_error_id_3) {
                    $status_succ = true;
                } else {
                    $error_code = $v['no'];
                    $error_msg = $v['error'];
                }
            }
        }

        //网页---尽量不用
        if ($status_succ === false) {
            if (self::DoSign_Default($kw, $fid, $ck) === true) {
                $status_succ = true;
            }
        }*/

        $update_data['latest'] = $time;
        $update_data['status'] = $error_code;
        $update_data['last_error'] = $error_msg;

        $this->Model_TieBa()->update($id, $update_data);
        // return self::getInfo($id);
        return true;
    }

    /**
     * 得到BDUSS
     * @param int|string $baidu_id 贴吧用户PID
     */
    /*public static function getCookie($baidu_id)
    {
        if (empty($baidu_id)) {
            return false;
        }
        $baiduid_model = new Model_BaiduId();
        $temp = $baiduid_model->get($baidu_id);
        return $temp['bduss'];
    }*/

    /**
     * 得到TBS
     * @param $bduss
     * @return
     */
    public function getTbs($bduss)
    {
        $url = 'http://tieba.baidu.com/dc/common/tbs';
        DI()->curl->setHeader(['User-Agent: fuck phone', 'Referer: http://tieba.baidu.com/', 'X-Forwarded-For: 115.28.1.' . mt_rand(1, 255)]);
        DI()->curl->setCookie(["BDUSS" => $bduss]);
        $res = DI()->curl->json_get($url);
        return $res['tbs'];
    }

    /**
     * 客户端签到
     * @param $kw
     * @param $fid
     * @param $ck
     * @param $tbs
     * @return string
     * @throws \PhalApi\Exception\InternalServerErrorException
     */
    public function DoSign_Client($kw, $fid, $ck, $tbs)
    {
        $temp = [
            'BDUSS' => $ck,
            '_client_id' => '03-00-DA-59-05-00-72-96-06-00-01-00-04-00-4C-43-01-00-34-F4-02-00-BC-25-09-00-4E-36',
            '_client_type' => '4',
            '_client_version' => '1.2.1.17',
            '_phone_imei' => '540b43b59d21b7a4824e1fd31b08e9a6',
            'fid' => $fid,
            'kw' => $kw,
            'net_type' => '3',
            'tbs' => $tbs,
        ];
        $x = '';
        foreach ($temp as $k => $v) {
            $x .= $k . '=' . $v;
        }
        $temp['sign'] = strtoupper(md5($x . 'tiebaclient!!!'));
        $url = 'http://c.tieba.baidu.com/c/c/forum/sign';
        DI()->curl->setHeader(['Content-Type: application/x-www-form-urlencoded', 'User-Agent: Fucking iPhone/1.0 BadApple/99.1']);
        DI()->curl->setCookie(["BDUSS" => $ck]);
        return DI()->curl->post($url, $temp);
    }

    /**
     * 手机网页签到
     * @param $kw
     * @param $fid
     * @param $ck
     * @param $tbs
     * @return string
     * @throws \PhalApi\Exception\InternalServerErrorException
     */
    public function DoSign_Mobile($kw, $fid, $ck, $tbs)
    {
        $url = 'http://tieba.baidu.com/mo/q/sign?tbs=' . $tbs . '&kw=' . urlencode($kw) . '&is_like=1&fid=' . $fid;
        DI()->curl->setHeader(['User-Agent: fuck phone', 'Referer: http://tieba.baidu.com/f?kw=' . $kw, 'Host: tieba.baidu.com', 'X-Forwarded-For: 115.28.1.' . mt_rand(1, 255), 'Origin: http://tieba.baidu.com', 'Connection: Keep-Alive']);
        DI()->curl->setCookie(['BDUSS' => $ck]);
        return DI()->curl->get($url);
    }

    /**
     * 网页签到
     * @param $kw
     * @param $fid
     * @param $ck
     * @return bool
     * @throws \PhalApi\Exception\InternalServerErrorException
     */
    public function DoSign_Default($kw, $fid, $ck)
    {
        $url = 'http://tieba.baidu.com/mo/m?kw=' . urlencode($kw) . '&fid=' . $fid;
        $s = DI()->curl
            ->setHeader(['User-Agent: fuck phone', 'Referer: http://wapp.baidu.com/', 'Content-Type: application/x-www-form-urlencoded'])
            ->setCookie(['BDUSS' => $ck])
            ->get($url);
        preg_match('/\<td style=\"text-align:right;\"\>\<a href=\"(.*)\"\>签到\<\/a\>\<\/td\>\<\/tr\>/', $s, $s);
        if (isset($s[1])) {
            $url = 'http://tieba.baidu.com' . $s[1];
            DI()->curl
                ->setHeader(['Accept: text/html, application/xhtml+xml, */*', 'Accept-Language: zh-Hans-CN,zh-Hans;q=0.8,en-US;q=0.5,en;q=0.3', 'User-Agent: Fucking Phone'])
                ->setCookie(['BDUSS' => $ck])
                ->get($url);
            //临时判断解决方案
            $url = 'http://tieba.baidu.com/mo/m?kw=' . urlencode($kw) . '&fid=' . $fid;
            $s = DI()->curl
                ->setHeader(['User-Agent: fuck phone', 'Referer: http://wapp.baidu.com/', 'Content-Type: application/x-www-form-urlencoded'])
                ->setCookie(['BDUSS' => $ck])
                ->get($url);
            //如果找不到这段html则表示没有签到则stripos()返回false，同时is_bool()返回true，最终返回false
            return !is_bool(stripos($s, '<td style="text-align:right;"><span >已签到</span></td>'));
        } else {
            return true;
        }
    }

    /**
     * 获取ServerTime
     * @return array
     * @throws InternalServerErrorException
     * @throws \PhalApi\Exception\InternalServerErrorException
     */
    public function serverTime()
    {
        $url = 'https://wappass.baidu.com/wp/api/security/antireplaytoken?tpl=tb&v=' . NOW_TIME . '0000';
        $data = $this->curl($url);
        $arr = json_decode($data, true);

        return $arr['errno'] == 110000 ? ['code' => 0, 'time' => $arr['time']] : ['code' => -1, 'msg' => $arr['errmsg']];
    }

    /**
     * 获取验证码图片
     * @param $vCodeStr
     * @return mixed
     * @throws InternalServerErrorException
     * @throws \PhalApi\Exception\InternalServerErrorException
     */
    public function getVCPic($vCodeStr)
    {
        $url = 'https://wappass.baidu.com/cgi-bin/genimage?' . $vCodeStr . '&v=' . NOW_TIME . '0000';
        return $this->curl($url);
    }

    /**
     * 普通登录操作
     * @param string $time
     * @param string $user
     * @param string $pwd
     * @param string $p
     * @param string $vcode
     * @param string $vcodestr
     * @return array
     * @throws InternalServerErrorException
     * @throws \PhalApi\Exception\InternalServerErrorException
     */
    public function login(string $time, string $user, string $pwd, string $p, string $vcode = '', string $vcodestr = '')
    {
        DI()->response->setMsg(T('成功'));
        $url = 'https://wappass.baidu.com/wp/api/login?v=' . NOW_TIME . '0000';
        $post = 'username=' . $user . '&code=&password=' . $p . '&verifycode=' . $vcode . '&clientfrom=native&tpl=tb&login_share_strategy=choice&client=android&adapter=3&t=' . NOW_TIME . '0000&act=bind_mobile&loginLink=0&smsLoginLink=1&lPFastRegLink=0&fastRegLink=1&lPlayout=0&loginInitType=0&lang=zh-cn&regLink=1&action=login&loginmerge=1&isphone=0&dialogVerifyCode=&dialogVcodestr=&dialogVcodesign=&gid=660BDF6-30E5-4A83-8EAC-F0B4752E1C4B&vcodestr=' . $vcodestr . '&countrycode=&servertime=' . $time . '&logLoginType=sdk_login&passAppHash=&passAppVersion=';
        $data = $this->curl($url, $post);
        $arr = json_decode($data, true);
        if (array_key_exists('errInfo', $arr) && $arr['errInfo']['no'] == '0') {
            if (!empty($arr['data']['loginProxy'])) {
                $data = $this->curl($arr['data']['loginProxy']);
                $arr = json_decode($data, true);
            }
            $data = $arr['data']['xml'];
            preg_match('!<uname>(.*?)</uname>!i', $data, $user);
            preg_match('!<uid>(.*?)</uid>!i', $data, $uid);
            preg_match('!<portrait>(.*?)</portrait>!i', $data, $face);
            preg_match('!<displayname>(.*?)</displayname>!i', $data, $displayname);
            preg_match('!<bduss>(.*?)</bduss>!i', $data, $bduss);
            preg_match('!<ptoken>(.*?)</ptoken>!i', $data, $ptoken);
            preg_match('!<stoken>(.*?)</stoken>!i', $data, $stoken);
            return ['code' => 0, 'uid' => $uid[1], 'user' => $user[1], 'displayname' => $displayname[1], 'face' => $face[1], 'bduss' => $bduss[1], 'ptoken' => $ptoken[1], 'stoken' => $stoken[1]];
        } else if ($arr['errInfo']['no'] == '310006' || $arr['errInfo']['no'] == '500001' || $arr['errInfo']['no'] == '500002') {
            return ['code' => $arr['errInfo']['no'], 'msg' => $arr['errInfo']['msg'], 'vcodestr' => $arr['data']['codeString']];
        } else if ($arr['errInfo']['no'] == '400023') {
            return ['code' => $arr['errInfo']['no'], 'msg' => $arr['errInfo']['msg'], 'type' => $arr['data']['showType'], 'phone' => $arr['data']['phone'], 'email' => $arr['data']['email'], 'lstr' => $arr['data']['lstr'], 'ltoken' => $arr['data']['ltoken']];
        } else if (array_key_exists('errInfo', $arr)) {
            return ['code' => $arr['errInfo']['no'], 'msg' => $arr['errInfo']['msg']];
        } else {
            throw new InternalServerErrorException(T('登录失败，原因未知'));
        }
    }

    /**
     * 登录异常时发送手机/邮件验证码
     * @param $type
     * @param $lstr
     * @param $ltoken
     * @return array
     * @throws InternalServerErrorException
     * @throws \PhalApi\Exception\InternalServerErrorException
     */
    public function sendCode($type, $lstr, $ltoken)
    {
        $url = 'https://wappass.baidu.com/wp/login/sec?ajax=1&v=' . NOW_TIME . '0000&vcode=&clientfrom=native&tpl=tb&login_share_strategy=choice&client=android&adapter=3&t=' . NOW_TIME . '0000&act=bind_mobile&loginLink=0&smsLoginLink=1&lPFastRegLink=0&fastRegLink=1&lPlayout=0&loginInitType=0&lang=zh-cn&regLink=1&action=login&loginmerge=1&isphone=0&dialogVerifyCode=&dialogVcodestr=&dialogVcodesign=&gid=660BDF6-30E5-4A83-8EAC-F0B4752E1C4B&showtype=' . $type . '&lstr=' . rawurlencode($lstr) . '&ltoken=' . $ltoken;
        $data = $this->curl($url);
        $arr = json_decode($data, true);
        if (array_key_exists('errInfo', $arr) && $arr['errInfo']['no'] == '0') {
            return ['code' => 0];
        } else if (array_key_exists('errInfo', $arr)) {
            return ['code' => $arr['errInfo']['no'], 'msg' => $arr['errInfo']['msg']];
        } else {
            throw new InternalServerErrorException(T('发生验证码失败，原因未知'));
        }
    }

    /**
     * 登录异常时登录操作
     * @param string $type
     * @param string $lstr
     * @param string $ltoken
     * @param string $vcode
     * @return array
     * @throws BadRequestException
     * @throws InternalServerErrorException
     * @throws \PhalApi\Exception\InternalServerErrorException
     */
    public function login2(string $type, string $lstr, string $ltoken, string $vcode)
    {
        DI()->response->setMsg(T('成功'));
        $url = 'https://wappass.baidu.com/wp/login/sec?type=2&v=' . NOW_TIME . '0000';
        $post = [
            'vcode' => $vcode,
            'clientfrom' => 'native',
            'tpl' => 'tb',
            'login_share_strategy' => 'choice',
            'client' => 'android',
            'adapter' => '3',
            't' => NOW_TIME . '0000',
            'act' => 'bind_mobile',
            'loginLink' => '0',
            'smsLoginLink' => '1',
            'lPFastRegLink' => '0',
            'fastRegLink' => '1',
            'lPlayout' => '0',
            'loginInitType' => '0',
            'lang' => 'zh-cn',
            'regLink' => '1',
            'action' => 'login',
            'loginmerge' => '1',
            'isphone' => '0',
            'dialogVerifyCode' => '',
            'dialogVcodestr' => '',
            'dialogVcodesign' => '',
            'gid' => '660BDF6-30E5-4A83-8EAC-F0B4752E1C4B',
            'showtype' => $type,
            'lstr' => rawurlencode($lstr),
            'ltoken' => $ltoken,
        ];
        // $post = 'vcode=' . $vcode . '&clientfrom=native&tpl=tb&login_share_strategy=choice&client=android&adapter=3&t=' . NOW_TIME . '0000&act=bind_mobile&loginLink=0&smsLoginLink=1&lPFastRegLink=0&fastRegLink=1&lPlayout=0&loginInitType=0&lang=zh-cn&regLink=1&action=login&loginmerge=1&isphone=0&dialogVerifyCode=&dialogVcodestr=&dialogVcodesign=&gid=660BDF6-30E5-4A83-8EAC-F0B4752E1C4B&showtype=' . $type . '&lstr=' . rawurlencode($lstr) . '&ltoken=' . $ltoken;
        $data = $this->curl($url, $post);
        $arr = json_decode($data, true);
        if (array_key_exists('errInfo', $arr) && $arr['errInfo']['no'] == '0') {
            if (!empty($arr['data']['loginProxy'])) {
                $data = $this->curl($arr['data']['loginProxy']);
                $arr = json_decode($data, true);
            }
            $data = $arr['data']['xml'];
            // preg_match('!<uname>(.*?)</uname>!i', $data, $user);
            // preg_match('!<uid>(.*?)</uid>!i', $data, $uid);
            // preg_match('!<portrait>(.*?)</portrait>!i', $data, $face);
            preg_match('!<displayname>(.*?)</displayname>!i', $data, $displayname);
            preg_match('!<bduss>(.*?)</bduss>!i', $data, $bduss);
            // preg_match('!<ptoken>(.*?)</ptoken>!i', $data, $ptoken);
            // preg_match('!<stoken>(.*?)</stoken>!i', $data, $stoken);
            // return ['code' => 0, 'uid' => $uid[1], 'user' => $user[1], 'displayname' => $displayname[1], 'face' => $face[1], 'bduss' => $bduss[1], 'ptoken' => $ptoken[1], 'stoken' => $stoken[1]];
            $this->Domain_BaiDuId()::add($displayname[1], $bduss[1]);
        } else if (array_key_exists('errInfo', $arr)) {
            return ['code' => $arr['errInfo']['no'], 'msg' => $arr['errInfo']['msg']];
        } else {
            throw new InternalServerErrorException(T('登录失败，原因未知'));
        }
    }

    /**
     * 检测是否需要验证码
     * @param $user
     * @return array
     * @throws BadRequestException
     * @throws InternalServerErrorException
     * @throws \PhalApi\Exception\InternalServerErrorException
     */
    public function checkVC($user)
    {
        if (empty($user)) {
            throw new BadRequestException(T('请先输入用户名'));
        }
        $url = 'https://wappass.baidu.com/wp/api/login/check?tt=' . NOW_TIME . '9117&username=' . $user . '&countrycode=&clientfrom=wap&sub_source=leadsetpwd&tpl=tb';
        $data = $this->curl($url);
        $arr = json_decode($data, true);
        if ($arr['errInfo'] && $arr['errInfo']['no'] == '0' && empty($arr['data']['codeString'])) {
            return ['code' => 0];
        } else if ($arr['errInfo'] && $arr['errInfo']['no'] == '0') {
            return ['code' => 1, 'vcodestr' => $arr['data']['codeString']];
        } else {
            return ['code' => $arr['errInfo']['no'], 'msg' => $arr['errInfo']['msg']];
        }
    }

    /**
     * 手机验证码登录，获取手机号是否存在
     * @param $phone
     * @return array
     * @throws BadRequestException
     * @throws InternalServerErrorException
     * @throws \PhalApi\Exception\InternalServerErrorException
     */
    public function getPhone($phone)
    {
        if (empty($phone)) {
            throw new BadRequestException(T('请先输入手机号'));
        }
        if (strlen($phone) != 11) {
            throw new BadRequestException(T('请输入正确的手机号'));
        }
        $phone2 = '';
        for ($i = 0; $i < 11; $i++) {
            $phone2 .= $phone[$i];
            if ($i == 2 || $i == 6) $phone2 .= '+';
        }
        $url = 'https://wappass.baidu.com/wp/api/security/getphonestatus?v=' . NOW_TIME . '0000';
        $post = [
            'mobilenum' => $phone2,
            'clientfrom' => 'native',
            'tpl' => 'tb',
            'login_share_strategy' => 'choice',
            'client' => 'android',
            'adapter' => 3,
            't' => NOW_TIME . '0000',
            'act' => 'bind_mobile',
            'loginLink' => 0,
            'smsLoginLink' => 1,
            'lPFastRegLink' => 0,
            'fastRegLink' => 1,
            'lPlayout' => 0,
            'lang' => 'zh-cn',
            'regLink' => 1,
            'action' => 'login',
            'loginmerge' => 1,
            'isphone' => 0,
            'dialogVerifyCode' => '',
            'dialogVcodestr' => '',
            'dialogVcodesign' => '',
            'gid' => 'E528690-4ADF-47A5-BA87-1FD76D2583EA',
            'agreement' => 1,
            'vcodesign' => '',
            'vcodestr' => '',
            'sms' => 1,
            'username' => $phone,
            'countrycode' => '',
        ];
        // $post = 'mobilenum=' . $phone2 . '&clientfrom=native&tpl=tb&login_share_strategy=choice&client=android&adapter=3&t=' . SERVER_TIME . '0000&act=bind_mobile&loginLink=0&smsLoginLink=1&lPFastRegLink=0&fastRegLink=1&lPlayout=0&lang=zh-cn&regLink=1&action=login&loginmerge=1&isphone=0&dialogVerifyCode=&dialogVcodestr=&dialogVcodesign=&gid=E528690-4ADF-47A5-BA87-1FD76D2583EA&agreement=1&vcodesign=&vcodestr=&sms=1&username=' . $phone . '&countrycode=';
        $data = $this->curl($url, $post);
        $arr = json_decode($data, true);
        if ($arr['errInfo'] && $arr['errInfo']['no'] == '0') {
            return ['code' => 0, 'msg' => $arr['errInfo']['msg']];
        } else {
            return ['code' => $arr['errInfo']['no'], 'msg' => $arr['errInfo']['msg']];
        }
    }

    /**
     * 手机验证码登录，发送验证码
     * @param        $phone
     * @param string $vcode
     * @param string $vcodestr
     * @param string $vcodesign
     * @return array
     * @throws BadRequestException
     * @throws InternalServerErrorException
     * @throws \PhalApi\Exception\InternalServerErrorException
     */
    public function sendSms($phone, $vcode = '', $vcodestr = '', $vcodesign = '')
    {
        if (empty($phone)) throw new BadRequestException(T('请先输入手机号'));
        if (strlen($phone) != 11) throw new BadRequestException(T('请输入正确的手机号'));
        $url = 'https://wappass.baidu.com/wp/api/login/sms?v=' . NOW_TIME . '0000';
        $post = [
            'username' => $phone,
            'tpl' => 'tb',
            'clientfrom' => 'native',
            'countrycode' => '',
            'gid' => 'E528690-4ADF-47A5-BA87-1FD76D2583EA',
            'dialogVerifyCode' => $vcode,
            'vcodesign' => $vcodesign,
            'vcodestr' => $vcodestr,
        ];
        // $post = 'username=' . $phone . '&tpl=tb&clientfrom=native&countrycode=&gid=E528690-4ADF-47A5-BA87-1FD76D2583EA&dialogVerifyCode=' . $vcode . '&vcodesign=' . $vcodesign . '&vcodestr=' . $vcodestr;
        $data = $this->curl($url, $post);
        $arr = json_decode($data, true);
        if ($arr['errInfo'] && $arr['errInfo']['no'] == '0') {
            return ['code' => 0, 'msg' => $arr['errInfo']['msg']];
        } else if ($arr['errInfo']['no'] == '50020') {
            return ['code' => $arr['errInfo']['no'], 'msg' => $arr['errInfo']['msg'], 'vcodestr' => $arr['data']['vcodestr'], 'vcodesign' => $arr['data']['vcodesign']];
        } else {
            return ['code' => $arr['errInfo']['no'], 'msg' => $arr['errInfo']['msg']];
        }
    }

    /**
     * 手机验证码登录操作
     * @param $phone
     * @param $smsvc
     * @return array
     * @throws BadRequestException
     * @throws InternalServerErrorException
     * @throws \PhalApi\Exception\InternalServerErrorException
     */
    public function login3($phone, $smsvc)
    {
        if (empty($phone)) throw new BadRequestException(T('手机号不能为空'));
        if (strlen($phone) != 11) throw new BadRequestException(T('请输入正确的手机号'));
        if (empty($smsvc)) throw new BadRequestException(T('验证码不能为空'));

        $url = 'https://wappass.baidu.com/wp/api/login?v=' . NOW_TIME . '0000';
        $post = [
            'smsvc' => $smsvc,
            'clientfrom' => 'native',
            'tpl' => 'tb',
            'login_share_strategy' => 'choice',
            'client' => 'android',
            'adapter' => '3',
            't' => NOW_TIME . '0000',
            'act' => 'bind_mobile',
            'loginLink' => '0',
            'smsLoginLink' => '1',
            'lPFastRegLink' => '0',
            'fastRegLink' => '1',
            'lPlayout' => '0',
            'lang' => 'zh-cn',
            'regLink' => '1',
            'action' => 'login',
            'loginmerge' => '',
            'isphone' => '0',
            'dialogVerifyCode' => '',
            'dialogVcodestr' => '',
            'dialogVcodesign' => '',
            'gid' => 'E528690-4ADF-47A5-BA87-1FD76D2583EA',
            'agreement' => '1',
            'vcodesign' => '',
            'vcodestr' => '',
            'smsverify' => '1',
            'sms' => '1',
            'mobilenum' => $phone,
            'username' => $phone,
            'countrycode' => '',
            'passAppHash' => '',
            'passAppVersion' => '',
        ];

        // $post = 'smsvc=' . $smsvc . '&clientfrom=native&tpl=tb&login_share_strategy=choice&client=android&adapter=3&t=' . SERVER_TIME . '0000&act=bind_mobile&loginLink=0&smsLoginLink=1&lPFastRegLink=0&fastRegLink=1&lPlayout=0&lang=zh-cn&regLink=1&action=login&loginmerge=&isphone=0&dialogVerifyCode=&dialogVcodestr=&dialogVcodesign=&gid=E528690-4ADF-47A5-BA87-1FD76D2583EA&agreement=1&vcodesign=&vcodestr=&smsverify=1&sms=1&mobilenum=' . $phone . '&username=' . $phone . '&countrycode=&passAppHash=&passAppVersion=';

        $data = $this->curl($url, $post);
        $arr = json_decode($data, true);
        if (array_key_exists('errInfo', $arr) && $arr['errInfo']['no'] == '0') {
            if (!empty($arr['data']['loginProxy'])) {
                $data = $this->curl($arr['data']['loginProxy']);
                $arr = json_decode($data, true);
            }
            $data = $arr['data']['xml'];
            // preg_match('!<uname>(.*?)</uname>!i', $data, $user);
            // preg_match('!<uid>(.*?)</uid>!i', $data, $uid);
            // preg_match('!<portrait>(.*?)</portrait>!i', $data, $face);
            preg_match('!<displayname>(.*?)</displayname>!i', $data, $displayname);
            preg_match('!<bduss>(.*?)</bduss>!i', $data, $bduss);
            // preg_match('!<ptoken>(.*?)</ptoken>!i', $data, $ptoken);
            // preg_match('!<stoken>(.*?)</stoken>!i', $data, $stoken);
            // return ['code' => 0, 'uid' => $uid[1], 'user' => $user[1], 'displayname' => $displayname[1], 'face' => $face[1], 'bduss' => $bduss[1], 'ptoken' => $ptoken[1], 'stoken' => $stoken[1]];
            $this->Domain_BaiDuId()::add($displayname[1], $bduss[1]);
        } else if (array_key_exists('errInfo', $arr)) {
            return ['code' => $arr['errInfo']['no'], 'msg' => $arr['errInfo']['msg']];
        } else {
            throw new InternalServerErrorException(T('登录失败，原因未知'));
        }
    }

    /**
     * 获取扫码登录二维码
     * @return array
     * @throws InternalServerErrorException
     * @throws \PhalApi\Exception\InternalServerErrorException
     */
    public function getQRCode()
    {
        $url = 'https://passport.baidu.com/v2/api/getqrcode?lp=pc&gid=07D9D20-91EB-43D8-8553-16A98A0B24AA&apiver=v3&tt=' . NOW_TIME . '0000&callback=callback';
        // $data = $this->curl($url, FALSE, 'https://passport.baidu.com/v2/?login');
        $data = $this->curl($url);
        preg_match('/callback\((.*?)\)/', $data, $match);
        $arr = json_decode($match[1], true);
        if (array_key_exists('errno', $arr) && $arr['errno'] == 0) {
            return ['code' => 0, 'imgurl' => $arr['imgurl'], 'sign' => $arr['sign'], 'link' => 'https://wappass.baidu.com/wp/?qrlogin&t=' . NOW_TIME . '&error=0&sign=' . $arr['sign'] . '&cmd=login&lp=pc&tpl=&uaonly='];
        } else {
            return ['code' => $arr['errno'], 'msg' => '获取二维码失败'];
        }
    }

    /**
     * 扫码登录操作
     * @param $sign
     * @throws BadRequestException
     * @throws InternalServerErrorException
     * @throws \PhalApi\Exception\InternalServerErrorException
     */
    public function qrLogin($sign)
    {
        $url = 'https://passport.baidu.com/channel/unicast?channel_id=' . $sign . '&tpl=pp&gid=07D9D20-91EB-43D8-8553-16A98A0B24AA&apiver=v3&tt=' . NOW_TIME . '0000&callback=callback';
        $data = $this->curl($url);
        preg_match('/callback\((.*?)\)/', $data, $match);
        $arr = json_decode($match[1], true);
        if (array_key_exists('errno', $arr) && $arr['errno'] == 0) {
            $arr = json_decode($arr['channel_v'], true);
            $this->curlopt_header = true;
            $data = $this->curl('https://passport.baidu.com/v2/api/bdusslogin?bduss=' . $arr['v'] . '&u=https%3A%2F%2Fpassport.baidu.com%2F&qrcode=1&tpl=pp&apiver=v3&tt=' . NOW_TIME . '0000&callback=callback');
            preg_match('/callback\((.*?)\)/', $data, $match);
            $arr = json_decode($match[1], true);
            if (array_key_exists('errInfo', $arr) && $arr['errInfo']['no'] == '0') {
                $data = str_replace('=deleted', '', $data);
                preg_match('!BDUSS=(.*?);!i', $data, $bduss);

                $this->Domain_BaiDuId()::add($arr['data']['displayname'], $bduss[1]);

                // preg_match('!PTOKEN=(.*?);!i', $data, $ptoken);
                // preg_match('!STOKEN=(.*?);!i', $data, $stoken);
                // $userid = self::getUserid($arr['data']['userName']);
                // return ['code' => 0, 'uid' => $userid, 'user' => $arr['data']['userName'], 'displayname' => $arr['data']['displayname'], 'mail' => $arr['data']['mail'], 'phone' => $arr['data']['phoneNumber'], 'bduss' => $bduss[1], 'ptoken' => $ptoken[1], 'stoken' => $stoken[1]];
            } else if (array_key_exists('errInfo', $arr)) {
                throw new InternalServerErrorException(T($arr['errInfo']['msg']));
                // return ['code' => $arr['errInfo']['no'], 'msg' => $arr['errInfo']['msg']];
            } else {
                throw new InternalServerErrorException(T('登录失败，原因未知'));
                // return ['code' => '-1', 'msg' => '登录失败，原因未知'];
            }
        } else if (array_key_exists('errno', $arr)) {
            throw new InternalServerErrorException(T('未检测到登录状态'));
            // return ['code' => $arr['errno']];
        } else {
            throw new InternalServerErrorException(T('登录失败，原因未知'));
            // return ['code' => '-1', 'msg' => '登录失败，原因未知'];
        }
    }

}

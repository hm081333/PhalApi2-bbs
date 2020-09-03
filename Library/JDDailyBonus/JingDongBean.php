<?php

namespace Library\JDDailyBonus;

use Library\Exception\InternalServerErrorException;
use function Common\DI;
use function PhalApi\T;

/**
 * 京东京豆
 * Class JingDongBean
 * @package Library\JDDailyBonus
 */
class JingDongBean
{
    private $JDBUrl;

    private $KEY;
    private $LogDetails = false; //是否开启响应日志, true则开启

    public function __construct($stop = 0)
    {
        sleep($stop);
        $this->JDBUrl = [
            'url' => 'https://api.m.jd.com/client.action?functionId=signBeanIndex&appid=ld',
            'headers' => [
                'Cookie' => $this->KEY,
            ],
        ];
        $nobyda = new nobyda();
        $nobyda->get($this->JDBUrl, function ($error, $response, $data) use ($nobyda) {
            try {
                if ($error) {
                    throw new InternalServerErrorException(T($error));
                } else {
                    $cc = json_decode($data);
                    $Details = $this->LogDetails ? "response:\n" . $data : '';
                    if ($cc['code'] == 3) {
                        DI()->logger->info("京东商城-京豆Cookie失效 " . $Details);
                        $merge['JDBean']['notify'] = "京东商城-京豆: 失败, 原因: Cookie失效‼️";
                        $merge['JDBean']['fail'] = 1;
                    } else if (preg_match('/跳转至拼图/', $data)) {
                        $merge['JDBean']['notify'] = "京东商城-京豆: 失败, 原因: 需要拼图验证 ⚠️";
                        $merge['JDBean']['fail'] = 1;
                    } else if (preg_match('/\"status\":\"?1\"?/', $data)) {
                        DI()->logger->info("京东商城-京豆签到成功 " . $Details);
                        if (preg_match('/dailyAward/', $data)) {
                            $merge['JDBean']['notify'] = "京东商城-京豆: 成功, 明细: " . $cc['data']['dailyAward']['beanAward']['beanCount'] . "京豆 🐶";
                            $merge['JDBean']['bean'] = $cc['data']['dailyAward']['beanAward']['beanCount'];
                        } else if (preg_match('/continuityAward/', $data)) {
                            $merge['JDBean']['notify'] = "京东商城-京豆: 成功, 明细: " . $cc['data']['continuityAward']['beanAward']['beanCount'] . "京豆 🐶";
                            $merge['JDBean']['bean'] = $cc['data']['continuityAward']['beanAward']['beanCount'];
                        } else if (preg_match('/新人签到/', $data)) {
                            $quantity = preg_match('/beanCount\":\"(\d+)\".+今天/', $data);
                            $merge['JDBean']['bean'] = $quantity ? $quantity[1] : 0;
                            $merge['JDBean']['notify'] = "京东商城-京豆: 成功, 明细: " . ($quantity ? $quantity[1] : "无") . "京豆 🐶";
                        } else {
                            $merge['JDBean']['notify'] = "京东商城-京豆: 成功, 明细: 无京豆 🐶";
                        }
                        $merge['JDBean']['success'] = 1;
                    } else {
                        $merge['JDBean']['fail'] = 1;
                        DI()->logger->info("京东商城-京豆签到失败 " . $Details);
                        if (preg_match('/(已签到|新人签到)/', $data)) {
                            $merge['JDBean']['notify'] = "京东商城-京豆: 失败, 原因: 已签过 ⚠️";
                        } else if (preg_match('/人数较多|S101/', $data)) {
                            $merge['JDBean']['notify'] = "京东商城-京豆: 失败, 签到人数较多 ⚠️";
                        } else {
                            $merge['JDBean']['notify'] = "京东商城-京豆: 失败, 原因: 未知 ⚠️";
                        }
                    }
                }
            } catch (\Exception $eor) {
                $nobyda->AnError('京东商城-京豆', 'JDBean', $eor);
            } finally {
                return;
            }

        });
    }

}

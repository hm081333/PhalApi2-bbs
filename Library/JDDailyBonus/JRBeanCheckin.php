<?php

namespace Library\JDDailyBonus;

use Library\Exception\InternalServerErrorException;
use function Common\DI;
use function PhalApi\T;

/**
 * 金融京豆 签到
 * Class JRBeanCheckin
 * @package Library\JDDailyBonus
 */
class JRBeanCheckin
{
    private $JRBUrl;

    private $KEY;
    private $LogDetails = false; //是否开启响应日志, true则开启

    public function __construct($stop = 0)
    {
        sleep($stop);
        $this->JRBUrl = [
            'url' => 'https://ms.jr.jd.com/gw/generic/zc/h5/m/signRewardGift',
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Cookie' => $this->KEY,
                'Referer' => 'https://jddx.jd.com/m/jddnew/money/index.html',
            ],
            'body' => 'reqData=%7B%22bizLine%22%3A2%2C%22signDate%22%3A%221%22%2C%22deviceInfo%22%3A%7B%22os%22%3A%22iOS%22%7D%2C%22clientType%22%3A%22sms%22%2C%22clientVersion%22%3A%2211.0%22%7D',
        ];
        $nobyda = new nobyda();
        $nobyda->post($this->JRBUrl, function ($error, $response, $data) use ($nobyda) {
            try {
                if ($error) {
                    throw new InternalServerErrorException(T($error));
                } else {
                    $c = json_decode($data);
                    $Details = $this->LogDetails ? "response:\n" . $data : '';
                    if (preg_match('/\"resultCode\":\"00000\"/', $data)) {
                        DI()->logger->info("京东金融-金贴签到成功 " . $Details);
                        if ($c['resultData']['data']['rewardAmount'] != "0") {
                            $merge['JRBean']['notify'] = "京东金融-金贴: 成功, 明细: " . $c['resultData']['data']['rewardAmount'] . "金贴 💰";
                            $merge['JRBean']['success'] = 1;
                            //$merge['JRBean']['bean'] = $c['resultData']['data']['rewardAmount'];
                        } else {
                            $merge['JRBean']['notify'] = "京东金融-金贴: 成功, 明细: 无奖励 🐶";
                            $merge['JRBean']['success'] = 1;
                        }
                    } else {
                        DI()->logger->info("京东金融-金贴签到失败 " . $Details);
                        if (preg_match('/发放失败|70111|10000/', $data)) {
                            $merge['JRBean']['notify'] = "京东金融-金贴: 失败, 原因: 已签过 ⚠️";
                            $merge['JRBean']['fail'] = 1;
                        } else {
                            if (preg_match('/(\"resultCode\":3|请先登录)/', $data)) {
                                $merge['JRBean']['notify'] = "京东金融-金贴: 失败, 原因: Cookie失效‼️";
                                $merge['JRBean']['fail'] = 1;
                            } else {
                                $merge['JRBean']['notify'] = "京东金融-金贴: 失败, 原因: 未知 ⚠️";
                                $merge['JRBean']['fail'] = 1;
                            }
                        }
                    }
                }
            } catch (\Exception $eor) {
                $nobyda->AnError('京东金融-金贴', 'JRBean', $eor);
            } finally {
                return;
            }

        });
    }

}

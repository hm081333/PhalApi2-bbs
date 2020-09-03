<?php

namespace Library\JDDailyBonus;

use Library\Exception\InternalServerErrorException;
use function Common\DI;
use function PhalApi\T;

/**
 * 金融抓娃娃
 * Class JingRongDoll
 * @package Library\JDDailyBonus
 */
class JingRongDoll
{
    private $DollUrl;

    private $KEY;
    private $LogDetails = false; //是否开启响应日志, true则开启

    public function __construct($stop = 0, $type = false, $num = false)
    {
        sleep($stop);
        $this->DollUrl = [
            'url' => 'https://nu.jr.jd.com/gw/generic/jrm/h5/m/process',
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Cookie' => $this->KEY,
            ],
            'body' => 'reqData=%7B%22actCode%22%3A%22890418F764%22%2C%22type%22%3A' . ($type ? $type : '3') . '%7D',
        ];
        $nobyda = new nobyda();
        $nobyda->post($this->DollUrl, function ($error, $response, $data) use ($nobyda, $stop, $num) {
            try {
                if ($error) {
                    throw new InternalServerErrorException(T($error));
                } else {
                    $cc = json_decode($data);
                    $Details = $this->LogDetails ? "response:\n" . $data : '';
                    if ($cc['resultCode'] == 0) {
                        if ($cc['resultData']['data']['businessData'] != null) {
                            DI()->logger->info("京东金融-娃娃登录成功 " . $Details);
                            if ($cc['resultData']['data']['businessData']['pickStatus'] == 2) {
                                if (preg_match('/\"rewardPrice\":\"?(\d+)/', $data, $matches)) {
                                    $JRDoll_bean = $matches[1];
                                    new JingRongDoll($stop, "4", $JRDoll_bean);
                                } else {
                                    $merge['JRDoll']['success'] = 1;
                                    $merge['JRDoll']['notify'] = "京东金融-娃娃: 成功, 明细: 无京豆 🐶";
                                }
                            } else {
                                DI()->logger->info("京东金融-娃娃签到失败 " . $Details);
                                $merge['JRDoll']['notify'] = "京东金融-娃娃: 失败, 原因: 已签过 ⚠️";
                                $merge['JRDoll']['fail'] = 1;
                            }
                        } else if ($cc['resultData']['data']['businessCode'] == 200) {
                            DI()->logger->info("京东金融-娃娃签到成功 " . $Details);
                            $merge['JRDoll']['bean'] = $num ? $num : 0;
                            $merge['JRDoll']['success'] = $num ? 1 : 0;
                            $merge['JRDoll']['notify'] = "京东金融-娃娃: 成功, 明细: " . ($num ? $num . "京豆 🐶" : "无京豆 🐶");
                        } else {
                            DI()->logger->info("京东金融-娃娃签到异常 " . $Details);
                            $merge['JRDoll']['fail'] = 1;
                            $merge['JRDoll']['notify'] = "京东金融-娃娃: 失败, 原因: 领取异常 ⚠️";
                        }
                    } else if ($cc['resultCode'] == 3) {
                        DI()->logger->info("京东金融-娃娃签到失败 " . $Details);
                        $merge['JRDoll']['notify'] = "京东金融-娃娃: 失败, 原因: Cookie失效‼️";
                        $merge['JRDoll']['fail'] = 1;
                    } else {
                        DI()->logger->info("京东金融-娃娃判断失败 " . $Details);
                        $merge['JRDoll']['notify'] = "京东金融-娃娃: 失败, 原因: 未知 ⚠️";
                        $merge['JRDoll']['fail'] = 1;
                    }
                }
            } catch (\Exception $eor) {
                $nobyda->AnError('京东金融-娃娃', 'JRDoll', $eor);
            } finally {
                return;
            }

        });
    }

}

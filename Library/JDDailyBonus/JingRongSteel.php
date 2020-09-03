<?php

namespace Library\JDDailyBonus;

use Library\Exception\InternalServerErrorException;
use function Common\DI;
use function PhalApi\T;

/**
 * 金融钢镚
 * Class JingRongSteel
 * @package Library\JDDailyBonus
 */
class JingRongSteel
{
    private $JRSUrl;

    private $KEY;
    private $LogDetails = false; //是否开启响应日志, true则开启

    public function __construct($stop = 0)
    {
        sleep($stop);
        $this->JRSUrl = [
            'url' => 'https://ms.jr.jd.com/gw/generic/gry/h5/m/signIn',
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Cookie' => $this->KEY,
            ],
            'body' => 'reqData=%7B%22channelSource%22%3A%22JRAPP%22%2C%22riskDeviceParam%22%3A%22%7B%7D%22%7D',
        ];
        $nobyda = new nobyda();
        $nobyda->post($this->JRSUrl, function ($error, $response, $data) use ($nobyda) {
            try {
                if ($error) {
                    throw new InternalServerErrorException(T($error));
                } else {
                    $cc = json_decode($data);
                    $Details = $this->LogDetails ? "response:\n" . $data : '';
                    if (preg_match('/\"resBusiCode\":0/', $data)) {
                        DI()->logger->info("京东金融-钢镚签到成功 " . $Details);
                        $leng = $cc['resultData']['resBusiData']['actualTotalRewardsValue'];
                        if (strlen($leng) == 1) {
                            $merge['JRSteel']['notify'] = "京东金融-钢镚: 成功, 明细: " . "0.0" . $cc['resultData']['resBusiData']['actualTotalRewardsValue'] . "钢镚 💰";
                            $merge['JRSteel']['success'] = 1;
                            $merge['JRSteel']['steel'] = "0.0" . $cc['resultData']['resBusiData']['actualTotalRewardsValue'];
                        } else {
                            $merge['JRSteel']['notify'] = "京东金融-钢镚: 成功, 明细: " . "0." . $cc['resultData']['resBusiData']['actualTotalRewardsValue'] . "钢镚 💰";
                            $merge['JRSteel']['success'] = 1;
                            $merge['JRSteel']['steel'] = "0." . $cc['resultData']['resBusiData']['actualTotalRewardsValue'];
                        }
                    } else {
                        DI()->logger->info("\n" . "京东金融-钢镚签到失败 " . $Details);
                        if (preg_match('/(已经领取|\"resBusiCode\":15)/', $data)) {
                            $merge['JRSteel']['notify'] = "京东金融-钢镚: 失败, 原因: 已签过 ⚠️";
                            $merge['JRSteel']['fail'] = 1;
                        } else {
                            if (preg_match('/未实名/', $data)) {
                                $merge['JRSteel']['notify'] = "京东金融-钢镚: 失败, 原因: 账号未实名 ⚠️";
                                $merge['JRSteel']['fail'] = 1;
                            } else {
                                if (preg_match('/(\"resultCode\":3|请先登录)/', $data)) {
                                    $merge['JRSteel']['notify'] = "京东金融-钢镚: 失败, 原因: Cookie失效‼️";
                                    $merge['JRSteel']['fail'] = 1;
                                } else {
                                    $merge['JRSteel']['notify'] = "京东金融-钢镚: 失败, 原因: 未知 ⚠️";
                                    $merge['JRSteel']['fail'] = 1;
                                }
                            }
                        }
                    }
                }
            } catch (\Exception $eor) {
                $nobyda->AnError('京东金融-钢镚', 'JRSteel', $eor);
            } finally {
                return;
            }

        });
    }

}

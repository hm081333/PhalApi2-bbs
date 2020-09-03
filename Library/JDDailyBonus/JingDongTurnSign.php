<?php

namespace Library\JDDailyBonus;

use Library\Exception\InternalServerErrorException;
use function Common\DI;
use function PhalApi\T;

/**
 * 京东转盘 签到
 * Class JingDongTurnSign
 * @package Library\JDDailyBonus
 */
class JingDongTurnSign
{
    private $JDTUrl;

    private $KEY;
    private $LogDetails = false; //是否开启响应日志, true则开启

    public function __construct($stop = 0, $code = false)
    {
        sleep($stop);
        $this->JDTUrl = [
            'url' => "https://api.m.jd.com/client.action?functionId=lotteryDraw&body=%7B%22actId%22%3A%22jgpqtzjhvaoym%22%2C%22appSource%22%3A%22jdhome%22%2C%22lotteryCode%22%3A%22{$code}%22%7D&appid=ld",
            'headers' => [
                'Cookie' => $this->KEY,
            ],
        ];
        $nobyda = new nobyda();
        $nobyda->get($this->JDTUrl, function ($error, $response, $data) use ($nobyda, $stop, $code) {
            try {
                if ($error) {
                    throw new InternalServerErrorException(T($error));
                } else {
                    $cc = json_decode($data);
                    $Details = $this->LogDetails ? "response:\n" . $data : '';

                    if ($cc['code'] == 3) {
                        DI()->logger->info("京东转盘Cookie失效 " . $Details);
                        $merge['JDTurn']['notify'] = "京东商城-转盘: 失败, 原因: Cookie失效‼️";
                        $merge['JDTurn']['fail'] = 1;
                    } else {
                        if (preg_match('/(\"T216\"|活动结束)/', $data)) {
                            $merge['JDTurn']['notify'] = "京东商城-转盘: 失败, 原因: 活动结束 ⚠️";
                            $merge['JDTurn']['fail'] = 1;
                        } else {
                            if (preg_match('/(京豆|\"910582\")/', $data)) {
                                DI()->logger->info("京东商城-转盘签到成功 " . $Details);
                                $merge['JDTurn']['notify'] .= $merge['JDTurn']['notify'] ? "\n京东商城-转盘: 成功, 明细: " . $cc['data']['prizeSendNumber'] . "京豆 🐶 (多次)" : "京东商城-转盘: 成功, 明细: " . $cc['data']['prizeSendNumber'] . "京豆 🐶";
                                $merge['JDTurn']['success'] += 1;
                                $merge['JDTurn']['bean'] += $cc['data']['prizeSendNumber'];
                                if ($cc['data']['chances'] != "0") {
                                    new JingDongTurnSign(2000, $code);
                                }
                            } else {
                                DI()->logger->info("京东商城-转盘签到失败 " . $Details);
                                if (preg_match('/未中奖/', $data)) {
                                    $merge['JDTurn']['notify'] .= $merge['JDTurn']['notify'] ? "\n京东商城-转盘: 成功, 状态: 未中奖 🐶 (多次)" : "京东商城-转盘: 成功, 状态: 未中奖 🐶";
                                    $merge['JDTurn']['success'] += 1;
                                    if ($cc['data']['chances'] != "0") {
                                        new JingDongTurnSign(2000, $code);
                                    }
                                } else if (preg_match('/(T215|次数为0)/', $data)) {
                                    $merge['JDTurn']['notify'] = "京东商城-转盘: 失败, 原因: 已转过 ⚠️";
                                    $merge['JDTurn']['fail'] = 1;
                                } else if (preg_match('/(T210|密码)/', $data)) {
                                    $merge['JDTurn']['notify'] = "京东商城-转盘: 失败, 原因: 无支付密码 ⚠️";
                                    $merge['JDTurn']['fail'] = 1;
                                } else {
                                    $merge['JDTurn']['notify'] .= $merge['JDTurn']['notify'] ? "\n京东商城-转盘: 失败, 原因: 未知 ⚠️ (多次)" : "京东商城-转盘: 失败, 原因: 未知 ⚠️";
                                    $merge['JDTurn']['fail'] += 1;
                                }
                            }
                        }
                    }
                }
            } catch (\Exception $eor) {
                $nobyda->AnError('京东商城-转盘', 'JDTurn', $eor);
            } finally {
                return;
            }

        });
    }

}

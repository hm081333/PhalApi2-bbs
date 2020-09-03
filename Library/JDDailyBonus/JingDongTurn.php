<?php

namespace Library\JDDailyBonus;

use Library\Exception\InternalServerErrorException;
use function Common\DI;
use function PhalApi\T;

/**
 * 京东转盘
 * Class JingDongTurn
 * @package Library\JDDailyBonus
 */
class JingDongTurn
{
    private $JDTUrl;

    private $KEY;
    private $LogDetails = false; //是否开启响应日志, true则开启

    public function __construct($stop = 0)
    {
        sleep($stop);
        $this->JDTUrl = [
            'url' => 'https://api.m.jd.com/client.action?functionId=wheelSurfIndex&body=%7B%22actId%22%3A%22jgpqtzjhvaoym%22%2C%22appSource%22%3A%22jdhome%22%7D&appid=ld',
            'headers' => [
                'Cookie' => $this->KEY,
            ],
        ];
        $nobyda = new nobyda();
        $nobyda->get($this->JDTUrl, function ($error, $response, $data) use ($nobyda, $stop) {
            try {
                if ($error) {
                    throw new InternalServerErrorException(T($error));
                } else {
                    $cc = json_decode($data)['data']['lotteryCode'];
                    $Details = $this->LogDetails ? "response:\n" . $data : '';
                    if ($cc) {
                        DI()->logger->info("京东商城-转盘查询成功 " . $Details);
                        return new JingDongTurnSign($stop, $cc);
                    } else {
                        $merge['JDTurn']['notify'] = "京东商城-转盘: 失败, 原因: 查询错误 ⚠️";
                        $merge['JDTurn']['fail'] = 1;
                        DI()->logger->info("京东商城-转盘查询失败 " . $Details);
                    }
                }
            } catch (\Exception $eor) {
                $nobyda->AnError('京东转盘-查询', 'JDTurn', $eor);
            } finally {
                return;
            }

        });
    }

}

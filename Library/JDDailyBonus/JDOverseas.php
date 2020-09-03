<?php

namespace Library\JDDailyBonus;

use Library\Exception\InternalServerErrorException;
use function Common\DI;
use function PhalApi\T;

/**
 * 京东国际
 * Class JDOverseas
 * @package Library\JDDailyBonus
 */
class JDOverseas
{
    private $OverseasUrl;

    private $KEY;
    private $LogDetails = false; //是否开启响应日志, true则开启

    public function __construct($stop = 0)
    {
        sleep($stop);
        $this->OverseasUrl = [
            'url' => 'https://api.m.jd.com/client.action?functionId=checkin',
            'headers' => [
                "Content-Type" => "application/x-www-form-urlencoded",
                'Cookie' => $this->KEY,
            ],
            'body' => 'body=%7B%7D&build=167237&client=apple&clientVersion=9.0.0&openudid=1fce88cd05c42fe2b054e846f11bdf33f016d676&partner=apple&scope=11&sign=e27f8b904040a0e3c99b87fc27e09c87&st=1591730990449&sv=101',
        ];
        $nobyda = new nobyda();
        $nobyda->post($this->OverseasUrl, function ($error, $response, $data) use ($nobyda, $stop) {
            try {
                if ($error) {
                    throw new InternalServerErrorException(T($error));
                } else {
                    $Details = $this->LogDetails ? "response:\n" . $data : '';
                    if (preg_match('/\"type\":\d+?,/', $data)) {
                        DI()->logger->info("京东商城-国际签到成功 " . $Details);
                        $merge['Overseas']['success'] = 1;
                        if (preg_match('/\"jdBeanAmount\":[1-9]+/', $data)) {
                            preg_match('/\"jdBeanAmount\":(\d+)/', $data, $matches);
                            $merge['Overseas']['bean'] = $matches[1];
                            $merge['Overseas']['notify'] = "京东商城-国际: 成功, 明细: " . $merge['Overseas']['bean'] . "京豆 🐶";
                        } else {
                            $merge['Overseas']['notify'] = "京东商城-国际: 成功, 明细: 无京豆 🐶";
                        }
                    } else {
                        DI()->logger->info("京东商城-国际签到失败 " . $Details);
                        $merge['Overseas']['fail'] = 1;
                        if (preg_match('/(\"code\":\"13\"|重复签到)/', $data)) {
                            $merge['Overseas']['notify'] = "京东商城-国际: 失败, 原因: 已签过 ⚠️";
                        } else if (preg_match('/\"code\":\"-1\"/', $data)) {
                            $merge['Overseas']['notify'] = "京东商城-国际: 失败, 原因: Cookie失效‼️";
                        } else {
                            $merge['Overseas']['notify'] = "京东商城-国际: 失败, 原因: 未知 ⚠️";
                        }
                    }
                }
            } catch (\Exception $eor) {
                $nobyda->AnError('京东商城-国际', 'Overseas', $eor);
            } finally {
                return;
            }

        });
    }

}

<?php

namespace Library\JDDailyBonus;

use Library\Exception\InternalServerErrorException;
use function Common\DI;
use function PhalApi\T;

/**
 * 京东闪购
 * Class JDFlashSale
 * @package Library\JDDailyBonus
 */
class JDFlashSale
{
    private $JDPETUrl;

    private $KEY;
    private $LogDetails = false; //是否开启响应日志, true则开启

    public function __construct($stop = 0)
    {
        sleep($stop);
        $this->JDPETUrl = [
            'url' => 'https://api.m.jd.com/client.action?functionId=partitionJdSgin',
            'headers' => [
                "Content-Type" => "application/x-www-form-urlencoded",
                'Cookie' => $this->KEY,
            ],
            'body' => 'body=%7B%22version%22%3A%22v2%22%7D&client=apple&clientVersion=9.0.8&openudid=1fce88cd05c42fe2b054e846f11bdf33f016d676&sign=6768e2cf625427615dd89649dd367d41&st=1597248593305&sv=121',
        ];
        $nobyda = new nobyda();
        $nobyda->post($this->JDPETUrl, function ($error, $response, $data) use ($nobyda, $stop) {
            try {
                if ($error) {
                    throw new InternalServerErrorException(T($error));
                } else {
                    $cc = json_decode($data);
                    $Details = $this->LogDetails ? "response:\n" . $data : '';
                    if ($cc['result']['code'] == 0) {
                        DI()->logger->info("京东商城-闪购签到成功 " . $Details);
                        $merge['JDFSale']['bean'] = $cc['result']['jdBeanNum'] || 0;
                        $merge['JDFSale']['notify'] = "京东商城-闪购: 成功, 明细: " . ($merge['JDFSale']['bean'] || "无") . "京豆 🐶";
                        $merge['JDFSale']['success'] = 1;
                    } else {
                        $merge['JDFSale']['fail'] = 1;
                        DI()->logger->info("京东商城-闪购签到失败 " . $Details);
                        if (preg_match('/(已签到|已领取|\"2005\")/', $data)) {
                            $merge['JDFSale']['notify'] = "京东商城-闪购: 失败, 原因: 已签过 ⚠️";
                        } else if (preg_match('/不存在|已结束|\"2008\"|\"3001\"/', $data)) {
                            //$merge['JDFSale']['notify'] = "京东商城-闪购: 失败, 原因: 需瓜分 ⚠️";
                            new FlashSaleDivide($stop);
                        } else if (preg_match('/(\"code\":\"3\"|\"1003\")/', $data)) {
                            $merge['JDFSale']['notify'] = "京东商城-闪购: 失败, 原因: Cookie失效‼️";
                        } else {
                            $merge['JDFSale']['notify'] = "京东商城-闪购: 失败, 原因: 未知 ⚠️";
                        }
                    }
                }
            } catch (\Exception $eor) {
                $nobyda->AnError('京东商城-闪购', 'JDFSale', $eor);
            } finally {
                return;
            }

        });
    }

}

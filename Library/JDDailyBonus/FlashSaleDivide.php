<?php

namespace Library\JDDailyBonus;

use Library\Exception\InternalServerErrorException;
use function Common\DI;
use function PhalApi\T;

/**
 * 京东闪购 瓜分签到
 * Class FlashSaleDivide
 * @package Library\JDDailyBonus
 */
class FlashSaleDivide
{
    private $Url;

    private $KEY;
    private $LogDetails = false; //是否开启响应日志, true则开启

    public function __construct($stop = 0)
    {
        sleep($stop);
        $this->Url = [
            'url' => 'https://api.m.jd.com/client.action?functionId=partitionJdShare',
            'headers' => [
                "Content-Type" => "application/x-www-form-urlencoded",
                'Cookie' => $this->KEY,
            ],
            'body' => 'body=%7B%22version%22%3A%22v2%22%7D&client=apple&clientVersion=9.0.8&openudid=1fce88cd05c42fe2b054e846f11bdf33f016d676&sign=49baa3b3899b02bbf06cdf41fe191986&st=1597682588351&sv=111',
        ];
        $nobyda = new nobyda();
        $nobyda->post($this->Url, function ($error, $response, $data) use ($nobyda, $stop) {
            try {
                if ($error) {
                    throw new InternalServerErrorException(T($error));
                } else {
                    $Details = $this->LogDetails ? "response:\n" . $data : '';
                    $cc = json_decode($data);
                    if ($cc['result']['code'] == 0) {
                        $merge['JDFSale']['success'] = 1;
                        $merge['JDFSale']['bean'] = $cc['result']['jdBeanNum'] ?: 0;
                        $merge['JDFSale']['notify'] = "京东闪购-瓜分: 成功, 明细: " . ($merge['JDFSale']['bean'] || "无") . "京豆 🐶";
                        DI()->logger->info("京东闪购-瓜分签到成功 " . $Details);
                    } else {
                        $merge['JDFSale']['fail'] = 1;
                        DI()->logger->info("京东闪购-瓜分签到失败 " . $Details);
                        if (preg_match('/已参与|已领取|\"2006\"/', $data)) {
                            $merge['JDFSale']['notify'] = "京东闪购-瓜分: 失败, 原因: 已瓜分 ⚠️";
                        } else if (preg_match('/不存在|已结束|未开始|\"2008\"|\"2012\"/', $data)) {
                            $merge['JDFSale']['notify'] = "京东闪购-瓜分: 失败, 原因: 活动已结束 ⚠️";
                        } else if (preg_match('/\"code\":\"1003\"|未获取/', $data)) {
                            $merge['JDFSale']['notify'] = "京东闪购-瓜分: 失败, 原因: Cookie失效‼️";
                        } else {
                            $merge['JDFSale']['notify'] = "京东闪购-瓜分: 失败, 原因: 未知 ⚠️";
                        }
                    }
                }
            } catch (\Exception $eor) {
                $nobyda->AnError('京东闪购-瓜分', 'JDFSale', $eor);
            } finally {
                return;
            }

        });
    }

}

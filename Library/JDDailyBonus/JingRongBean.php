<?php

namespace Library\JDDailyBonus;

use Library\Exception\InternalServerErrorException;
use function Common\DI;
use function PhalApi\T;

/**
 * 金融京豆
 * Class JingRongBean
 * @package Library\JDDailyBonus
 */
class JingRongBean
{
    private $login;

    private $KEY;
    private $LogDetails = false; //是否开启响应日志, true则开启

    public function __construct($stop = 0)
    {
        sleep($stop);
        $this->login = [
            'url' => 'https://ms.jr.jd.com/gw/generic/zc/h5/m/signRecords',
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Cookie' => $this->KEY,
                'Referer' => 'https://jddx.jd.com/m/money/index.html?from=sign',
            ],
            'body' => 'reqData=%7B%22bizLine%22%3A2%7D',
        ];
        $nobyda = new nobyda();
        $nobyda->post($this->login, function ($error, $response, $data) use ($nobyda) {
            try {
                if ($error) {
                    throw new InternalServerErrorException(T($error));
                } else {
                    $Details = $this->LogDetails ? "response:\n" . $data : '';
                    if (preg_match('/\"login\":true/', $data)) {
                        DI()->logger->info("京东金融-金贴登录成功 " . $Details);
                        new JRBeanCheckin(200);
                    } else {
                        DI()->logger->info("京东金融-金贴登录失败 " . $Details);
                        if (preg_match('/\"login\":false/', $data)) {
                            $merge['JRBean']['notify'] = "京东金融-金贴: 失败, 原因: Cookie失效‼️";
                            $merge['JRBean']['fail'] = 1;
                        } else {
                            $merge['JRBean']['notify'] = "京东金融-金贴: 登录接口需修正 ‼️‼️";
                            $merge['JRBean']['fail'] = 1;
                        }
                    }
                }
            } catch (\Exception $eor) {
                $nobyda->AnError('金融金贴-登录', 'JRBean', $eor);
            } finally {
                return;
            }

        });
    }

}

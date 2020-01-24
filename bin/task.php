<?php
defined('API_ROOT') || define('API_ROOT', dirname(dirname(__FILE__)));
require_once API_ROOT . '/public/init.php';// 引入核心文件

//只允许CLI命令调用，不能通过网址调用
$param_arr = getopt('a:n:u:t:');
$argv = [
    $param_arr['a'] ?? false,// action
    $param_arr['t'] ?? false,// type
    $param_arr['n'] ?? false,// 备用
    $param_arr['u'] ?? false,// 备用
];

$argv[0] || die('Access Invalid!');

set_time_limit(0);
ignore_user_abort(true);
try {
    switch ($argv[0]) {
        case 'tieba':
            switch ($argv[1]) {
                case 'sign':// 签到
                    $di->logger->info('执行定时:贴吧签到');
                    \Common\Domain\TieBa::doSignAll();// 签到所有贴吧
                    break;
                case 'retry':// 重试
                    $di->logger->info('执行定时:贴吧重试签到');
                    \Common\Domain\TieBa::doRetryAll();// 重试所有出错贴吧
                    break;
                case 'send_info':
                    $di->logger->info('推送签到详情信息');
                    $wechat_domain = new \Common\Domain\WeChatPublicPlatform();
                    $wechat_domain->sendTieBaSignDetailByCron();
                    break;
                default:
                    return;
                    break;
            }
            break;
        case 'jd':
            switch ($argv[1]) {
                case 'bean':// 签到领京豆
                    $di->logger->info('执行定时:签到领京豆');
                    \Common\Domain\JdSign::doBeanSignAll();// 签到领京豆
                    break;
                case 'plant':// 种豆得豆
                    $di->logger->info('执行定时:种豆得豆');
                    \Common\Domain\JdSign::doPlantBeanAll();// 种豆得豆
                    break;
                case 'vvipclub':// 京享值领京豆
                    $di->logger->info('执行定时:京享值领京豆');
                    \Common\Domain\JdSign::doVVipClubAll();// 京享值领京豆
                    break;
                case 'wheelSurf':// 京享值领京豆
                    $di->logger->info('执行定时:福利转盘');
                    \Common\Domain\JdSign::doWheelSurfAll();// 福利转盘
                    break;
                case 'test':
                    \Common\Domain\JdSign::test();
                    break;
                default:
                    return;
                    break;
            }
            break;
        default:
            break;
    }

} catch (Exception $e) {
    $di->logger->error($e->getMessage());
    echo $e->getMessage();
}


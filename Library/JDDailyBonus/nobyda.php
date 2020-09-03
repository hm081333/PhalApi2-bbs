<?php

namespace Library\JDDailyBonus;

use function Common\DI;

class nobyda
{
    public $start;

    public function __construct()
    {
        $this->start = time();
    }

    public function notify($title, $subtitle, $message)
    {
        log($title . $subtitle . $message);
    }

    public function node()
    {
        return;
    }

    public function write($value, $key)
    {
        return;
    }

    public function read($key)
    {
        return;
    }

    public function adapterStatus($response)
    {
        if ($response) {
            if ($response['status']) {
                $response['statusCode'] = $response['status'];
            } else if ($response['statusCode']) {
                $response["status"] = $response['statusCode'];
            }
        }
        return $response;
    }

    public function get($options, $callback)
    {
        $options['headers']['User-Agent'] = 'JD4iPhone/167169 (iPhone; iOS 13.4.1; Scale/3.00)';
        $error = false;
        $response = false;
        $body = DI()->curl->setHeader($options['headers'] ?? [])->get($options['url']);
        $callback($error, $this->adapterStatus($response), $body);
    }

    public function post($options, $callback)
    {
        $options['headers']['User-Agent'] = 'JD4iPhone/167169 (iPhone; iOS 13.4.1; Scale/3.00)';
        $error = false;
        $response = false;
        $body = DI()->curl->setHeader($options['headers'] ?? [])->post($options['url'], $options['body']);
        $callback($error, $this->adapterStatus($response), $body);
    }

    public function log($message)
    {
        DI()->logger->info($message);
    }

    public function AnError($name, $key, $er)
    {
        $merge=[];
        if (!$merge[$key]['notify']) {
            $merge[$key]['notify'] = `${name}: 异常, 已输出日志 ‼️`;
        } else {
            $merge[$key]['notify'] += `\n${name}: 异常, 已输出日志 ‼️ (2)`;
        }
        $merge[$key]['error'] = 1;
        $er_str = json_encode($er);
        $line = preg_match('/\"line\"/', $er_str);
        return DI()->logger->info(`‼️${name}发生错误\n‼️名称: ${$er['name']}\n‼️描述: ${$er['message']}${$line ? `\n‼️行列: ${$er_str}` : ``}`);
    }

    public function time()
    {
        $end = sprintf("%.2f", ((time() - $this->start) / 1000));
        return '\n签到用时: ' . $end . ' 秒';
    }

    public function done($value = [])
    {
        return;
    }

}

<?php

namespace Common\Api;

/**
 * 京东签到项 接口服务类
 * JdSignItem
 * @author LYi-Ho 2018-11-24 16:06:44
 */
class JdSignItem extends Base
{
    use Common;

    public function getRules()
    {
        $rules = parent::getRules();
        return $rules;
    }


}

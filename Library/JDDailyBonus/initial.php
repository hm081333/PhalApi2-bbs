<?php


namespace Library\JDDailyBonus;


class initial
{
    public $acData = [
        // 京东商城-夺宝
        'JDTreasure' => '29xMZdGiiGYmMZ5CQcGmb7iPhN7n',
        // 京东商城-母婴
        'JDBaby' => '3BbAVGQPDd6vTyHYjmAutXrKAos6',
        // 京东商城-数码
        'JD3C' => '4SWjnZSCTHPYjE5T7j35rxxuMTb6',
        // 京东晚市-补贴
        'JDSubsidy' => 'xK148m4kWj5hBcTPuJUNNXH3AkJ',
        // 京东商城-钟表
        'JDClocks' => '2BcJPCVVzMEtMUynXkPscCSsx68W',
        // 京东商城-医药
        'JDDrug' => '3tqTG5sF1xCUyC6vgEF5CLCxGn7w',
        // 京东商城-超市
        'JDGStore' => 'aNCM6yrzD6qp1Vvh5YTzeJtk7cM',
        // 京东商城-宠物
        'JDPet' => '37ta5sh5ocrMZF3Fz5UMJbTsL42',
        // 京东商城-图书
        'JDBook' => '3SC6rw5iBg66qrXPGmZMqFDwcyXi',
        // 京东拍拍-二手
        'JDShand' => '3S28janPLYmtFxypu37AYAGgivfp',
        // 京东商城-美妆
        'JDMakeup' => '2smCxzLNuam5L14zNJHYu43ovbAP',
        // 京东商城-清洁
        'JDClean' => '2Tjm6ay1ZbZ3v7UbriTj6kHy9dn6',
        // 京东商城-女装
        'JDWomen' => 'DpSh7ma8JV7QAxSE2gJNro8Q2h9',
        // 京东商城-个护
        'JDCare' => 'NJ1kd1PJWhwvhtim73VPsD1HwY3',
        // 京东商城-美食
        'JDFood' => '4PzvVmLSBq5K63oq4oxKcDtFtzJo',
        // 京东商城-珠宝
        'JDJewels' => 'zHUHpTHNTaztSRfNBFNVZscyFZU',
        // 京东商城-菜场
        'JDVege' => 'Wcu2LVCFMkBP3HraRvb7pgSpt64',
    ];

    public $merge = [
        'SpeedUp' => [],
        'JDBean' => [],
        'JDTurn' => [],
        'JRDoll' => [],
        'JRDSign' => [],
        'Overseas' => [],
        'JDFSale' => [],
        'JDPet' => [],
        'JD3C' => [],
        'JDTreasure' => [],
        'JDBaby' => [],
        'JDSubsidy' => [],
        'JDDrug' => [],
        'JDClocks' => [],
        'JDBook' => [],
        'JDGStore' => [],
        'JDShand' => [],
        'JDMakeup' => [],
        'JDWomen' => [],
        'JDCare' => [],
        'JDFood' => [],
        'JDClean' => [],
        'JDVege' => [],
        'JDJewels' => [],
        'JDCube' => [],
        'JDPrize' => [],
        'JRSteel' => [],
        'JRBean' => [],
        'subsidy' => [],
        'JDCash' => [],
        'JDGetCash' => [],
        'JDShake' => [],
    ];

    public function __construct()
    {
        foreach ($this->merge as &$merge) {
            $merge = [
                'success' => 0,
                'fail' => 0,
                'error' => 0,
                'bean' => 0,
                'steel' => 0,
                'notify' => '',
                'key' => 0,
                'TSteel' => 0,
                'Cash' => 0,
                'TCash' => 0,
                'Qbear' => 0,
                'nickname' => '',
            ];
        }
    }


}

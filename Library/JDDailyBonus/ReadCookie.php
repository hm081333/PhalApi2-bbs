<?php

namespace Library\JDDailyBonus;

class ReadCookie
{
    private $DualAccount;

    private $DualKey;

    private $Key;

    private $out = 0; //接口超时退出, 用于可能发生的网络不稳定, 0则关闭. 如QX日志出现大量"JS Context timeout"后脚本中断时, 建议填写6000

    private $stop = 0;

    private $boxdis = true; //是否开启自动禁用, false则关闭. 脚本运行崩溃时(如VPN断连), 下次运行时将自动禁用相关崩溃接口(仅部分接口启用), 崩溃时可能会误禁用正常接口. (该选项仅适用于QX,Surge,Loon)

    private $ReDis = false; //是否移除所有禁用列表, true则开启. 适用于触发自动禁用后, 需要再次启用接口的情况. (该选项仅适用于QX,Surge,Loon)


    public function __construct()
    {
        new initial();
        $nobyda = new nobyda();
        $this->DualAccount = true;
        $this->DualKey = ''; //如需双账号签到,此处单引号内填写抓取的"账号2"Cookie, 否则请勿填写

        $this->Key = 'shshshfpb=tVBqHpN7OyXYgPxIVBqY9vg%3D%3D; shshshfpa=b57728e0-9c76-3ba0-cad4-b6a185c849a4-1567159746; __jdu=15826026155661206111329; pinId=HxzY8uAQww21ffsfn98I-w; TrackID=1CZJLZnkFgP74KaePuYH_h6uoDyfF5fB_UfP7LDNQVY2xG8_1l3kq4i6Rg38IPCLd4GJb5vdpF933XyprToIpTJR9zNKwAUhKlZrMuVoRCZI; cn=0; autoOpenApp_downCloseDate_auto=1597230765177_21600000; warehistory="100013505108,"; __jdv=122270672%7Candroidapp%7Ct_335139774%7Cappshare%7CCopyURL%7C1597895033476; areaId=19; PCSYCityID=CN_440000_441900_0; 3AB9D23F7A4B3C9B=ZCI77DLATPU56KOWQY7SIADNBLZ4YGQBXXZJLUQJXU4O2STCU5TYMMXQZQW6AOO64BVOROS26TU2WPW5S6Z7QXQXFQ; ipLoc-djd=19-1655-4886-0; __jda=122270672.15826026155661206111329.1582602615.1598672620.1598923871.84; __jdc=122270672; mba_muid=15826026155661206111329; shshshfp=bb121ebd1fe42c9aaa8cdb7b3e415964; jcap_dvzw_fp=2b8f1e15e06d403b441948b1a6b3cba4$841880209415; TrackerID=MaQQwFVqUM7XACCPzdAJCSOJU-5d1RiBqwaS2Qp9SmrjRwEZsDYuaB0hDh71bf7oybXH6xxz7K1IIzKeo6YTcPcpt2GFZO6zg9deymeJwUt5d-CMySNOzs-CN_K04LmH; pt_key=AAJfTaT7ADBoLFy101N1EOD5RTmSDHS56toP3TFjhpRUYjQgiUvcpf1yH0zgug5LBZVLA_9T_uI; pt_pin=hm081333; pt_token=2o7pzw4c; pwdt_id=hm081333; mobilev=html5; __jdb=122270672.8.15826026155661206111329|84.1598923871; mba_sid=15989238716474259343362560277.8; __jd_ref_cls=Mnpm_ComponentApplied;';
        if ($this->Key) {
            $add = $this->DualKey ? true : false;
            $KEY = $this->Key;
            $out = $this->out;
            $stop = $this->stop;
            $boxdis = $this->boxdis;
            $LogDetails = $this->LogDetails;
            $ReDis = $this->ReDis ? '' : '';
            // all();
        } else {
            $nobyda->notify("京东签到", "", "脚本终止, 未获取Cookie ‼️");
            $nobyda->done();
        }
    }

}

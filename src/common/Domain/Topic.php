<?php
/**
 * Created by PhpStorm.
 * User: LYi-Ho
 * Date: 2018-11-26
 * Time: 11:14:57
 */

namespace Common\Domain;

/**
 * 文章 领域层
 * Class Subject
 * @package Common\Domain
 * @author  LYi-Ho 2018-11-26 11:14:57
 */
class Topic
{
    use Common;

    public static function create($data)
    {
        // if (empty($data['title'])) {
        //     throw new PhalApi_Exception_Error(T('请输入文章标题'), 1);// 抛出普通错误 T标签翻译
        // } else if (empty($data['content'])) {
        //     throw new PhalApi_Exception_Error(T('请输入正文内容'), 1);// 抛出普通错误 T标签翻译
        // } else if (empty($data['subject_id'])) {
        //     throw new PhalApi_Exception_Error(T('请选择课程'), 1);// 抛出普通错误 T标签翻译
        // }
        $user = self::getDomain('User')::getCurrentUser(TRUE);// 当前登录的会员
        $topic_model = self::getModel();
        $insert_data = [];
        $insert_data['class_id'] = $data['subject_id'];
        $insert_data['title'] = $data['title'];
        $insert_data['detail'] = $data['content'];
        $insert_data['user_id'] = $user['id'];
        $insert_data['name'] = $user['user_name'];
        $insert_data['email'] = $user['email'];
        $insert_data['add_time'] = NOW_TIME;
        /*if ($this->sticky == 'on') {
            $insert_data['sticky'] = 1;
        }*/
        $topic_id = $topic_model->insert($insert_data);
        if ($topic_id) {
            self::DI()->response->setMsg(\PhalApi\T('发布成功'));
            return ['topic_id' => $topic_id];
        } else {
            throw new \Library\Exception\InternalServerErrorException(\PhalApi\T('发布失败'), 2);// 抛出服务端错误
        }
    }
}

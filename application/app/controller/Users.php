<?php


namespace app\app\controller;


use app\model\Book;
use app\model\Comments;
use app\model\Message;
use app\model\User;
use app\model\UserBook;
use app\model\UserFinance;
use app\service\FinanceService;
use app\service\PromotionService;
use app\service\UserService;
use think\facade\App;
use think\facade\Env;
use think\facade\Validate;

class Users extends BaseAuth
{
    protected $userService;
    protected $financeService;
    protected $promotionService;

    protected function initialize()
    {
        parent::initialize(); // TODO: Change the autogenerated stub
        $this->userService = new UserService();
        $this->financeService = new FinanceService();
        $this->promotionService = new PromotionService();
    }

    public function bookshelf()
    {

        $favors = UserBook::where('user_id', '=', $this->uid)->select();

        foreach ($favors as &$favor) {
            $book = Book::get($favor->book_id);
            if (empty($book['cover_url'])) {
                $book['cover_url'] = $this->imgUrl . '/static/upload/book/' . $favor->book_id . '/cover.jpg';
            }
            $favor['book'] = $book;
        }
        $result = [
            'success' => 1,
            'favors' => $favors
        ];
        return json($result);
    }

    public function delfavors()
    {
        $ids = explode(',', input('ids')); //书籍id;
        $this->userService->delFavors($this->uid, $ids);
        return json(['success' => 1, 'msg' => '删除收藏']);
    }

    public function switchfavor()
    {
        $redis = new_redis();
        if ($redis->exists('favor_lock:' . $this->uid)) { //如果存在锁
            return json(['success' => 0, 'msg' => '操作太频繁']);
        } else {
            $redis->set('favor_lock:' . $this->uid, 1, 3); //写入锁

            $isfavor = input('isfavor');
            $book_id = input('book_id');
            $user = User::get($this->uid);
            if ($isfavor == 0) { //未收藏
                $books = $user->books;
                if (count($books) >= 20) {
                    return json(['success' => 0, 'msg' => '您已经收藏太多了']); //isfavor为0表示未收藏
                }
                $book = Book::get($book_id);
                $user->books()->save($book);
                return json(['success' => 1, 'isfavor' => 1]); //isfavor表示已收藏
            } else {
                $user->books()->detach(['book_id' => $book_id]);
                return json(['success' => 1, 'isfavor' => 0]); //isfavor为0表示未收藏
            }
        }
    }

    public function history()
    {
        $redis = new_redis();
        $vals = $redis->hVals($this->redis_prefix . ':history:' . $this->uid);
        $books = array();
        foreach ($vals as $val) {
            $books[] = json_decode($val, true);
        }
        $result = [
            'success' => 1,
            'books' => $books
        ];
        return json($result);
    }

//    public function delhistory()
//    {
//        $keys = explode(',', input('ids'));
//        $this->userService->delHistory($this->uid, $keys);
//        return ['success' => 1, 'msg' => '删除阅读历史'];
//    }

    public function getVipExpireTime()
    {
        $user = User::get($this->uid);
        $time = $user->vip_expire_time - time();
        $result = [
            'success' => 1,
            'time' => $time
        ];
        return json($result);
    }

    public function update()
    {
        $nick_name = input('nickname');
        $user = new User();
        $user->nick_name = $nick_name;
        $res = $user->isUpdate(true)->save(['id' => $this->uid]);
        if ($res) {
            session('xwx_nick_name', $nick_name);
            return json(['success' => 1, 'msg' => '修改成功']);
        } else {
            return json(['success' => 0, 'msg' => '修改失败']);
        }
    }

    public function bindphone()
    {
        $user = User::get($this->uid);
        $code = trim(input('phonecode'));
        $phone = trim(input('phone'));
        if (verifycode($code, $phone) == 0) {
            return json(['success' => 0, 'msg' => '验证码错误']);
        }
        if (User::where('mobile', '=', $phone)->find()) {
            return json(['success' => 0, 'msg' => '该手机号码已经存在']);
        }
        $user->mobile = $phone;
        $user->isUpdate(true)->save();
        session('xwx_user_mobile', $phone);
        return json(['success' => 1, 'msg' => '绑定成功']);
    }

    public function verifycode()
    {
        $phone = input('phone');
        $code = input('phonecode');
        if (is_null(session('xwx_sms_code')) || $code != session('xwx_sms_code')) {
            return json(['success' => 0, 'msg' => '验证码错误']);
        }
        if (is_null(session('xwx_cms_phone')) || $phone != session('xwx_cms_phone')) {
            return json(['success' => 0, 'msg' => '验证码错误']);
        }
        return json(['success' => 1, 'msg' => '验证码正确']);
    }

    public function sendcms()
    {
        $code = generateRandomString();
        $phone = trim(input('phone'));
        $validate = Validate::make([
            'phone' => 'mobile'
        ]);
        $data = [
            'phone' => $phone
        ];
        if (!$validate->check($data)) {
            return json(['success' => 0, 'msg' => '手机格式不正确']);
        }
        $sms = new \Util\Common();
        $result = $sms->sendcode($this->uid, $phone, $code);
        if ($result['status'] == 0) { //如果发送成功
            session('xwx_sms_code', $code); //写入session
            session('xwx_cms_phone', $phone);
            $redis = new_redis();
            $redis->set($this->redis_prefix . ':xwx_mobile_unlock:' . $this->uid, 1, 300); //设置解锁缓存，让用户可以更改手机
        }
        return json(['success' => 0, 'msg' => $result['msg']]);
    }

    public function resetpwd()
    {
        $pwd = input('password');
        $validate = new \think\Validate;
        $validate->rule('password', 'require|min:6|max:21');

        $data = [
            'password' => $pwd,
        ];
        if (!$validate->check($data)) {
            return json(['msg' => '密码在6到21位之间', 'success' => 0]);
        }
        $user = User::get($this->uid);
        $user->password = $pwd;
        $user->isUpdate(true)->save();
        return json(['msg' => '修改成功', 'success' => 1]);
    }

    public function subComment()
    {
        $content = strip_tags(input('comment'));
        $book_id = input('book_id');

        $redis = new_redis();
        if ($redis->exists('comment_lock:' . $this->uid)) {
            return json(['msg' => '每10秒只能评论一次', 'success' => 0, 'isLogin' => 1]);
        } else {
            $comment = new Comments();
            $comment->user_id = $this->uid;
            $comment->book_id = $book_id;
            $comment->content = $content;
            $result = $comment->save();
            if ($result) {
                $redis->set('comment_lock:' . $this->uid, 1, 10);
                cache('comments:' . $book_id, null); //清除评论缓存
                return json(['msg' => '评论成功', 'success' => 1, 'isLogin' => 1]);
            } else {
                return json(['msg' => '评论失败', 'success' => 0, 'isLogin' => 1]);
            }
        }
    }

    public function leavemsg()
    {
        $msg = new Message();
        $msg->type = 0;//类型为用户留言
        $msg->msg_key = $this->uid; //这里的key为留言用户的id
        $res = $msg->save();
        if ($res) {
            $content = strip_tags(input('content'));//过滤掉用户输入的HTML标签
            //保存用户留言的文件路径
            $dir = Env::get('root_path') . '/public/static/upload/message/' . $msg->id . '/';
            if (!file_exists($dir)) {
                mkdir($dir, 0777);
            }
            $savename = $dir . 'msg.txt';
            file_put_contents($savename, $content);
            return json(['success' => 1, 'msg' => '留言成功']);
        } else {
            return json(['success' => 0, 'msg' => '留言失败']);
        }
    }

    public function message()
    {
        $startItem = input('startItem');
        $pageSize = input('pageSize');
        $map[] = ['msg_key', '=', $this->uid];
        $map[] = ['type', '=', 0]; //类型为用户留言
        $msgs = Message::where($map)->limit($startItem, $pageSize)->select()
            ->each(function ($item, $key) {
                $dir = Env::get('root_path') . '/public/static/upload/message/' . $item['id'] . '/';
                $item['content'] = file_get_contents($dir . 'msg.txt'); //获取用户留言内容

                //利用本条留言的ID查出本条留言的所有回复留言
                $map2[] = ['msg_key', '=', $item['id']];
                $map2[] = ['type', '=', 1]; //类型为回复
                $replys = Message::where($map2)->select();
                $item['replys'] = $replys;
                foreach ($replys as &$reply) {
                    $reply['content'] = file_get_contents($dir . $reply->id . '.txt');
                }
            });

        return json(['success' => 1, 'msg' => $msgs, 'startItem' => $startItem, 'pageSize' => $pageSize]);
    }

    public function getRewards()
    {
        $startItem = input('startItem');
        $pageSize = input('pageSize');
        $map = array();
        $map[] = ['user_id', '=', $this->uid];
        $map[] = ['usage', '=', 4]; //4为奖励记录
        $rewards = UserFinance::where($map)->limit($startItem, $pageSize)->select();
        return json([
            'success' => 1,
            'rewards' => $rewards,
            'startItem' => $startItem,
            'pageSize' => $pageSize
        ]);
    }

    public function isfavor()
    {
        $book_id = input('book_id');
        $isfavor = 0;
        $where[] = ['user_id', '=', $this->uid];
        $where[] = ['book_id', '=', $book_id];
        $userfavor = UserBook::where($where)->find();
        if (!is_null($userfavor) || !empty($userfavor)) { //收藏本漫画
            $isfavor = 1;
        }
        $result = [
            'success' => 1,
            'isfavor' => $isfavor
        ];
        return json($result);
    }

}
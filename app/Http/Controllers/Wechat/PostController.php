<?php
/**
 * Created by PhpStorm.
 * User: xuxiaodao
 * Date: 2017/11/13
 * Time: 下午6:16
 */

namespace App\Http\Wechat;


use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Service\PaginateService;
use App\Http\Service\PostService;
use App\Http\Service\SendMessageService;
use App\Models\MessageSession;
use App\Models\Post;
use App\Models\User;
use App\Models\WechatApp;
use League\Flysystem\Exception;

class PostController extends Controller
{
    protected $postLogic;
    protected $paginateLogic;

    public function __construct(PostService $postLogic, PaginateService $paginateLogic)
    {
        $this->postLogic = $postLogic;
        $this->paginateLogic = $paginateLogic;
    }

    /**
     * 发表贴子
     *
     * @author yezi
     *
     * @return array
     * @throws ApiException
     */
    public function store()
    {
        $user = request()->input('user');
        $content = request()->input('content');
        $imageUrls = request()->input('attachments');
        $location = request()->input('location');
        $private = request()->input('private');
        $topic = request()->input('username');
        $mobile = request()->input('mobile');

        try {
            \DB::beginTransaction();

            if (empty($content) && empty($imageUrls)) {
                throw new ApiException('内容不能为空', 6000);
            }

            $result = $this->postLogic->save($user, $content, $imageUrls, $location, $private, $topic);

            if($mobile){
                $checkMobile = validMobile($mobile);
                if($checkMobile){
                    $sendMessageService = app(SendMessageService::class);
                    if($sendMessageService->countTodayPostSecretMessage($user->id) > 2){
                        throw new ApiException('超过发送数量！',500);
                    }
                    //发送短信
                    $number = rand(10000,100000);
                    $content = "【恋言网】您的消息编号：$number,信息：hi，有同学跟你表白了，登录微信小程序【{$user->{User::REL_APP}->{WechatApp::FIELD_NAME}}】，在表白墙搜索你的手机号码即可查看！";
                    sendMessage($user->{User::FIELD_MOBILE},$mobile,$content);
                    //建立一个短信会话
                    $session = $sendMessageService->createMessageSession($user->id,null,$mobile,$result->id,MessageSession::ENUM_OBJ_TYPE_POST);
                    $sendMessageService->saveSecretMessage($user->id,null,$session->id,$content,$imageUrls,$number);
                }else{
                    throw new ApiException('号码格式错误！', 6000);
                }
            }

            \DB::commit();
        } catch (Exception $e) {
            \DB::rollBack();
            throw new ApiException($e, 60001);
        }

        return collect($result)->toArray();
    }

    /**
     * 获取帖子列表,type=1全部,=2关注,=3最新,4=最热
     *
     * @author yezi
     *
     * @return array
     */
    public function postList()
    {
        $user = request()->input('user');
        $pageSize = request()->input('page_size', 10);
        $pageNumber = request()->input('page_number', 1);
        $just = request()->input('just');
        $type = request()->input('type');
        $orderBy = request()->input('order_by', 'created_at');
        $sortBy = request()->input('sort_by', 'desc');
        $filter = request()->input('filter');

        $pageParams = ['page_size' => $pageSize, 'page_number' => $pageNumber];

        $query = $this->postLogic->builder($user,$type,$just)->filter($filter)->sort($orderBy, $sortBy)->done();

        $posts = $this->paginateLogic->paginate($query, $pageParams, '*', function ($post) use ($user) {

            return $this->postLogic->formatSinglePost($post, $user);

        });

        return collect($posts)->toArray();
    }

    /**
     * 表白墙详情
     *
     * @author yezi
     *
     * @param $id
     * @return mixed
     */
    public function detail($id)
    {
        $user = request()->input('user');

        $post = Post::query()->with(['poster', 'praises', 'comments'])->find($id);

        $result = $this->postLogic->formatSinglePost($post, $user);

        return $result;
    }

    /**
     * 获取最新的贴子
     *
     * @author yezi
     *
     * @return static
     * @throws ApiException
     */
    public function getMostNewPost()
    {
        $user = request()->input('user');
        $time = request()->input('date_time');

        if (empty($time)) {
            throw new ApiException('参数错误', 60001);
        }

        $posts = $this->postLogic->getPostList($user, $time);

        $posts = collect($posts)->map(function ($post) use ($user) {

            return $this->postLogic->formatSinglePost($post, $user);

        });

        return $posts;
    }

    /**
     * 删除帖子
     *
     * @author yezi
     *
     * @param $id
     * @return mixed
     * @throws ApiException
     */
    public function delete($id)
    {
        $user = request()->input('user');

        if (empty($id)) {
            throw new ApiException('404', null, '60001');
        }

        $result = Post::where(Post::FIELD_ID, $id)->delete();

        return $result;
    }

}
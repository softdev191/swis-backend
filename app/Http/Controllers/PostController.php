<?php
namespace App\Http\Controllers;

use App\Activity;
use App\Follow;
use App\PostClick;
use App\PostShare;
use App\PostView;
use FFMpeg\Coordinate\FrameRate;
use FFMpeg\FFMpeg;
use FFMpeg\Filters\Video\VideoFilters;
use FFMpeg\Filters\Video\WatermarkFilter;
use File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;
use Auth;
use App\Post;
use App\Like;
use App\User;
use App\Favourite;
use App\Post_meta;
class PostController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
    }
    
    /** Reply API */
    public function reply(Request $request){
        $this->validate($request,[
            'post_id' => 'required|Integer',
            'comment' => 'required',
        ]);
        $mainPostId = $request->main_post_id;
        $postId = $request->post_id;
        $commented_post_id = $request->commented_post_id;
        $comment = $request->comment;
        $currentUser = Auth::User();
        $userId = $currentUser->id;

        $post = Post::find($postId);
        if(!empty($commented_post_id) && strlen($commented_post_id) > 0){
            $commentedPost = Post::find($commented_post_id);
        }else{
            $commentedPost = null;
        }

        if(!$post){
            $code = Response::HTTP_NOT_FOUND;
            $message = "Post id not found";
        }else{

            $receivers = array();
            $newComment = Auth::User()->posts()->create([
                'parent_post_id' => $postId,
                'comment' => $comment,
            ]);

            $type = 6;

            if($userId != $post->user_id){
                if(!empty($post->parent_post_id)){
                    array_push($receivers, array("user_id" => $post->user_id, "message" => $currentUser->name." replied to your comment."));

                    $type = 7;
                    Activity::create(["sender_id" => $currentUser->id,"receiver_id" => $post->user_id,"type" =>$type,"post_id" => $mainPostId,"comment_id" => $newComment->id]);

                }else {
                    array_push($receivers, array("user_id" => $post->user_id, "message" => $currentUser->name." commented on your Search."));
                    Activity::create(["sender_id" => $currentUser->id,"receiver_id" => $post->user_id,"type" =>$type,"post_id" => $mainPostId,"comment_id" => $newComment->id]);

                }
            }
            $commentParts = explode(" ",$comment);
            foreach ($commentParts as $commentPart) {
                if($this->startsWith($commentPart, "@")){
                    $tempUser = User::where('username',ltrim($commentPart, '@'))->first();
                    if($tempUser!=null && $userId != $tempUser->id && !$this->isExist($receivers,$tempUser->id)){
                        if($commentedPost != null){
                            array_push($receivers, array("user_id" => $tempUser->id, "message" => $currentUser->name." replied to your comment."));
                        }else{
                            array_push($receivers, array("user_id" => $tempUser->id, "message" => $currentUser->name." mentioned you in a comment."));
                            $type = 8;
                            Activity::create(["sender_id" => $currentUser->id,"receiver_id" => $tempUser->id,"type" =>$type,"post_id" => $mainPostId,"comment_id" => $newComment->id]);

                        }
                    }
                }
            }

            foreach ($receivers as $receiver) {
                (new SendPushNotification)->commentNotification($receiver['user_id'], $postId, !empty($post->parent_post_id)?$post->parent_post_id:$post->id,$receiver['message'],!empty($post->parent_post_id));
            }

            /* Response code and message */
            $code = Response::HTTP_OK;
            $message = "Post reply added";
        }
        try{

            $realPost = $this->getFullPostDetailsById($mainPostId, 0);
            return response([
                'responseCode' => $code,
                'responseMessage' => $message,
                'post' => $realPost
            ],$code);
        }catch (\Exception $e){
            return response([
                'responseCode' => Response::HTTP_BAD_REQUEST,
                'responseMessage' => $e->getMessage()
            ],Response::HTTP_OK);
        }

    }

    function startsWith ($string, $startString)
    {
        $len = strlen($startString);
        return (substr($string, 0, $len) === $startString);
    }


    /* Toggle Like API */
    public function like(Request $request){
        $this->validate($request,[
            'post_id' => 'required|Integer'
        ]);
        $postId = $request->post_id;
        $post = Post::find($postId);
        $userId = Auth::user()->id;
        if(!$post){
            $code = Response::HTTP_NOT_FOUND;
            $message = "Post id not found";
        }else{
            $like = Like::where('post_id',$postId)->where('user_id',$userId);
            $check = $like->count();
            if($check){
                $like->first()->delete();
                $message = "Unliked Successfully";
            }else{
                $like = Like::create([
                    'post_id' => $postId,
                    'user_id' => $userId,
                ]);
                if($userId!=$post->user_id){
                    if(isset($post->parent_post_id) && strlen($post->parent_post_id)>0){
                        $parentPost = Post::where('id',$post->parent_post_id)->first();
                        (new SendPushNotification)->likeCommentNotification($post->user_id,$postId,!empty($parentPost->parent_post_id)?$parentPost->parent_post_id:$parentPost->id);
                    }else {
                        (new SendPushNotification)->likeNotification($post->user_id,$postId);
                    }
                }
                $message = "Liked Successfully";
            }
            $code = Response::HTTP_OK;
        }
        return response([   
            'responseCode' => $code,
            'responseMessage' => $message,
        ],$code);
    }

    /**
     * Fetch Posts API
     */
    public function fetchPosts(Request $request){

        try{
            $userId = isset($request->user_id)?$request->user_id:'';
            $limit = isset($request->page_limit) ? $request->page_limit : 8;
            $page = isset($request->page) ? $request->page : 0;
            $currentUser = Auth::user();
            /** Fetch Posts for given user else login user */
            if($userId==''){
                $users = Follow::where('follower_id',$currentUser->id)->where('status','approved')->pluck('following_id')->all();
                array_push($users,$currentUser->id);
                if(isset($request->local) && $request->local==1){
                    $latitude = $currentUser->latitude;
                    $longitude = $currentUser->longitude;

                    //for KM 6371 and for miles 3959

                    $posts = Post::with(['user','comments'])->withCount('bookmarks')
                        ->where('parent_post_id',null)
                        ->where('delete',0)
                        ->where(db::Raw("(3959 * acos( cos( radians('$latitude') ) * cos( radians(latitude) ) * cos( radians(longitude) - radians('$longitude') ) + sin( radians('$latitude') ) * sin( radians(latitude))))"), '<', 50)
                        ->orderBy('created_at', 'DESC');

                }else {
                    $posts = Post::with(['user','comments'])->withCount('bookmarks')
                        ->whereIn('user_id',$users)
                        ->where('parent_post_id',null)
                        ->where('delete',0)
                        ->orderBy('created_at', 'DESC');
                }

            }else{

                if($userId != $currentUser->id && !(Follow::where("follower_id",$currentUser->id)->where("following_id",$userId)->where('status','approved')->count() > 0 || !User::where('id',$userId)->first()->hide_searched)){
                    return response([
                        'responseCode' => Response::HTTP_OK,
                        'responseMessage' => "Success",
                        'next_page' => 1,
                        'nextPage' => 1,
                        'total_page' => 1,
                        "posts" => array()
                    ],Response::HTTP_OK);
                }

                $posts = Post::with(['user','comments'])->withCount('bookmarks')
                    ->where('user_id',$userId)
                    ->where('parent_post_id',null)
                    ->where('delete',0)
                    ->orderBy('created_at', 'DESC');
            }

            $totalPage = floor((int)$posts->count()/$limit) +($posts->count()%$limit>0?1:0);

            $posts = $posts->skip($page * $limit)->take($limit)->get();
            $finalPosts = array();
            if(isset($posts)){
                foreach($posts as $post){
                    $postId = $post->id;
                    $checkFavourite = Favourite::where('post_id',$postId)->where('user_id',Auth::User()->id)->count();
                    if($checkFavourite == 0){
                        $favourite = false;
                    }else{
                        $favourite = true;
                    }

                    $likes = Like::where('post_id',$postId)->count();
                    $comment = Post::where('parent_post_id',$postId)->count() + Post::where('parent_post_id',$postId)->withCount("replies")->get()->sum("replies_count");
                    $postMeta  =  null;
                    if(isset($request->local) && $request->local==1){
                        $postMeta  = Post_meta::where('post_id',$postId)->where('public_search','1')->orderBy('created_at','ASC')->get();
                    }else{
                        $postMeta  = Post_meta::where('post_id',$postId)->orderBy('created_at','ASC')->get();
                    }

                    $post->like_count = $likes;
                    $post->comment_count = $comment;
                    $post->favourite = $favourite;
                    $post->websites = $postMeta;
                    $post->clicks_count = PostClick::where('post_id', $postId)->sum('count');
                    $post->views_count = PostView::where('post_id', $postId)->sum('count');
                    $post->shares_count = PostShare::where('post_id', $postId)->sum('count');

                    //$post->setRelation("comments", $post->comments->slice(-2, 2));

                    if(sizeof($postMeta)>0){
                        array_push($finalPosts,$post);
                    }
                }
            }

            return response([
                'responseCode' => Response::HTTP_OK,
                'responseMessage' => "Success",
                'next_page' => $page + 1,
                'nextPage' => $page + 1,
                'total_page' => $totalPage,
                "posts" => $finalPosts,
            ],Response::HTTP_OK);
        }catch (\Exception $e){
            return response([
                'responseCode' => Response::HTTP_BAD_REQUEST,
                'responseMessage' => $e->getMessage()
            ],Response::HTTP_OK);
        }

    } /**
     * Fetch Posts API
     */
    public function fetchPostById(Request $request,String $id){

        try{
            $local = $request->local;
            $currentUser = Auth::user();
            $post = $this->getFullPostDetailsById($id, $local);

            return response([
                'responseCode' => Response::HTTP_OK,
                'responseMessage' => "Success",
                "post" => $post
            ],Response::HTTP_OK);
        }catch (\Exception $e){
            return response([
                'responseCode' => Response::HTTP_BAD_REQUEST,
                'responseMessage' => $e->getMessage()
            ],Response::HTTP_OK);
        }

    }

    /**
     * Fetch Favourites API
     */
    public function fetchFavourites(Request $request){
        $loggedUser = Auth::user();
        $userId = isset($request->user_id)?$request->user_id:$loggedUser->id;
        $limit = isset($request->page_limit) ? $request->page_limit : 8;
        $page = isset($request->page) ? $request->page : 0;


        if($userId != $loggedUser->id && Follow::where("follower_id",$loggedUser->id)->where("following_id",$userId)->where('status','approved')->count() == 0){
            return response([
                'responseCode' => Response::HTTP_OK,
                'responseMessage' => "Total Favourite Posts",
                "favourites" => array()
            ],Response::HTTP_OK);
        }

        $favs = Post::with('user','comments')->Join('favourites','posts.id','=','favourites.post_id')
            ->where('favourites.user_id',$userId)
            ->select('posts.*')
            ->withCount('bookmarks')
            ->where('posts.delete',0)
            ->orderBy('favourites.created_at', 'DESC')
            ->skip($page * $limit)->take($limit);

        $totalPage = floor((int)$favs->count()/$limit) +($favs->count()%$limit>0?1:0);

        $favs = $favs->skip($page * $limit)->take($limit)->get();

        foreach($favs as $fav){
            $favId = $fav->id;
            $checkFavourite = Favourite::where('post_id',$favId)->where('user_id',Auth::User()->id)->count();
            if($checkFavourite == 0){
                $favourite = false;
            }else{
                $favourite = true;
            }

            $likes = db::table('likes')->where('post_id',$favId)->count();
            $comment = Post::where('parent_post_id',$favId)->count() + Post::where('parent_post_id',$favId)->withCount("replies")->get()->sum("replies_count");

            $postMeta  = db::table('post_metas')->where('post_id',$favId)->orderBy('created_at','ASC')->get();

            $fav->like_count = $likes;
            $fav->comment_count = $comment;
            $fav->favourite = $favourite;
            $fav->websites = $postMeta;
            $fav->clicks_count = PostClick::where('post_id', $favId)->sum('count');
            $fav->views_count = PostView::where('post_id', $favId)->sum('count');
            $fav->shares_count = PostShare::where('post_id', $favId)->sum('count');
            //$fav->setRelation("comments", $fav->comments->slice(-2, 2));

        }

        return response([
            'responseCode' => Response::HTTP_OK,
            'responseMessage' => "Total Favourite Posts",
            'nextPage' => $page+1,
            'total_page' => $totalPage,
            "favourites" => $favs,
            "ss" => "ss"
        ],Response::HTTP_OK);
    }

    /** Fetch Reply API */
    public function fetchReply(Request $request){
        $this->validate($request,[
            'post_id' => 'required|Integer',
            'page_limit' => 'Integer',
            'page' => 'Integer',
        ]);
        $parent_post_id = $request->post_id;
        $post = Post::find($parent_post_id);
        if(!$post){
            return response([
                'responseCode' => Response::HTTP_NOT_FOUND,
                'responseMessage' => "Post id not found",
            ],Response::HTTP_OK);
        }
        
        $limit = isset($request->page_limit) ? $request->page_limit : 10;
        $page = isset($request->page) ? $request->page : 0;

        if(isset($request->comment_id) && strlen($request->comment_id)>0 && $request->comment_id != $request->post_id){
            if($page == 0){
                $limit = $limit - 1;
            }
            $comment = Post::where('id',$request->comment_id)->with('user')->withCount('likes','replies')->first();
            $parentComment = Post::where('id', $comment->parent_post_id)->where('parent_post_id',"<>",0)->first();
            if($parentComment != null){
                $finalComment = Post::where('id',$parentComment->id)->with('user')->withCount('likes','replies')->first();
                $finalComment->comments = Post::where('parent_post_id',$finalComment->id)->where('id','<>',$comment->id)->with('user')->withCount('likes','replies')->get();
                $finalComment->comments->push($comment);
            }else {
                $finalComment = Post::where('id',$comment->id)->with('comments','user')->withCount('likes','replies')->first();
            }

            $replies = Post::with('comments','user')->withCount('likes','replies')
                ->where('posts.parent_post_id',$parent_post_id)
                ->where('id','<>',$finalComment->id)
                ->orderBy('posts.created_at', 'DESC')
                ->skip($page * $limit)->take($limit)->get();
            if($page == 0)
                $replies->prepend($finalComment);
        }else {
            $replies = Post::with('comments','user')->withCount('likes','replies')
                ->where('posts.parent_post_id',$parent_post_id)
                ->orderBy('posts.created_at', 'DESC')
                ->skip($page * $limit)->take($limit)->get();
        }

        return response([
            'responseCode' => Response::HTTP_OK,
            'responseMessage' => "Success",
            'next_page' => $page + 1,
            'nextPage' => $page + 1,
            'comments' => $replies
        ],Response::HTTP_OK);
    }

    /**
     * Fetch total Users who liked the Post API
     */
    public function fetchLikedUsers(Request $request){
        $this->validate($request,[
            'post_id' => 'required|Integer'
        ]);
        $postId = $request->post_id;
        $post = Post::find($postId);
        if(!$post){
            return response([
                'responseCode' => Response::HTTP_NOT_FOUND,
                'responseMessage' => "Post id not found",
            ],Response::HTTP_OK);
        }else{
            $limit = isset($request->page_limit) ? $request->page_limit : 20;
            $page = isset($request->page) ? $request->page : 0;

            $likedUsers = User::join('likes','likes.user_id','=','users.id')->where('likes.post_id',$postId)->select('users.*');
            $totalLikedUsers = $likedUsers->count();
            $likedUsers = $likedUsers->skip($page * $limit)->take($limit)->get();

            return response([
                'responseCode' => Response::HTTP_OK,
                'responseMessage' => "Liked Users",
                'nextPage' => $page + 1,
                'likes_count' => $totalLikedUsers,
                "users" => $likedUsers
            ],Response::HTTP_OK);
        }
    }

    /**
     * Toggle-Favourite API
     */
    public function favourite(Request $request){
        $this->validate($request,[
            'post_id' => 'required'
        ]);
        $postId = $request->post_id;
        $post = Post::find($postId);
        if(!$post){
            return response([
                'responseCode' => Response::HTTP_NOT_FOUND,
                'responseMessage' => "Post not found",
            ],Response::HTTP_OK);
        }
        /* Favourite the posts if count zero else unfavourite the posts */
        $getfavs = Auth::User()->favourites()->where('post_id',$postId);
        $check = $getfavs->count();
        if($check == 0){
            Auth::User()->favourites()->create([
                'post_id' => $postId,
            ]);
            $message = "Post Bookmarked Successfully";
        }else{
            $getfavs->delete();
            $message = "Post Unbookmarked Successfully";
        }
        return response([
            'responseCode' => Response::HTTP_OK,
            'responseMessage' => $message,
        ],Response::HTTP_OK);
    }

    /** Delete Post API */
    public function delPost(Request $request){
        try{
            $this->validate($request,[
                'postId' => 'required|Integer',
            ]);
            $postId = $request->get('postId');
            $post = Auth::User()->posts()->find($postId);
            if(!$post){
                $code = Response::HTTP_NOT_FOUND;
                $message = "Post not found";
            }else{

                if(!$post->delete()){

                    return response([
                        'responseCode' => Response::HTTP_BAD_REQUEST,
                        'responseMessage' => "Error in deleting post."
                    ]);
                }

                $postMetas = Post_meta::where('post_id',$postId)->get();
                try{
                    foreach ($postMetas as $postMeta){
                        $this->safeDeletePostWebsiteMedias($postMeta);
                        $postMeta->delete();
                    }
                } catch (\Exception $e){
                    return response([
                        'responseCode' => Response::HTTP_BAD_REQUEST,
                        'responseMessage' => "Server error,please try again."
                    ],Response::HTTP_OK);
                }

                $code = Response::HTTP_OK;
                $message = "Post deleted successfully";
            }
            return response([
                'responseCode' => $code,
                'responseMessage' => $message,
            ],$code);
        }catch (\Exception $e){
            return response([
                'responseCode' => Response::HTTP_BAD_REQUEST,
                'responseMessage' => "Server error,please try again."
            ],Response::HTTP_OK);
        }
    }

    /** Delete Post API */
    public function delWebsite(Request $request){
        try{
            $this->validate($request,[
                'postId' => 'required|Integer',
                'websiteId' => 'required|Integer',
            ]);
            $postId = $request->get('postId');
            $websiteId = $request->get('websiteId');
            $post = Post::where('id',$postId)->first();
            if(!$post){
                $code = Response::HTTP_NOT_FOUND;
                $message = "Post not found";
            }else{
                if(Post_meta::where('post_id',$postId)->count()>1){

                    $postMeta = Post_meta::where('post_id', $postId)->where('id', $websiteId)->first();
                    $this->safeDeletePostWebsiteMedias($postMeta);
                    $postMeta->delete();

                    $code = Response::HTTP_OK;
                    $message = "Post website deleted successfully";
                }else {
                    return response([
                        'responseCode' => Response::HTTP_BAD_REQUEST,
                        'responseMessage' => "Server error, Please try to delete full posts."
                    ],Response::HTTP_OK);
                }
            }
            return response([
                'responseCode' => $code,
                'responseMessage' => $message,
            ],$code);
        }catch (\Exception $e){
            return response([
                'responseCode' => Response::HTTP_BAD_REQUEST,
                'responseMessage' => "Server error,please try again."
            ],Response::HTTP_OK);
        }
    }


    /** Delete Comment API */
    public function delComment(Request $request){
        try{
            $this->validate($request,[
                'comment_id' => 'required|Integer',
            ]);
            $mainPostId = $request->main_post_id;
            $commentId = $request->get('comment_id');
            $post = Auth::User()->posts()->find($commentId);
            if(!$post){
                $code = Response::HTTP_NOT_FOUND;
                $message = "Comment not found";
            }else{

                if(!$post->delete()){

                    return response([
                        'responseCode' => Response::HTTP_BAD_REQUEST,
                        'responseMessage' => "Error in deleting comment."
                    ]);
                }

                $code = Response::HTTP_OK;
                $message = "Comment deleted successfully";
            }
            $realPost = null;

            try{
                if($mainPostId !=null)
                    $realPost = $this->getFullPostDetailsById($mainPostId, 0);
            }catch (\Exception $e){

            }

            return response([
                'responseCode' => $code,
                'responseMessage' => $message,
                'post' => $realPost
            ],$code);
        }catch (\Exception $e){
            return response([
                'responseCode' => Response::HTTP_BAD_REQUEST,
                'responseMessage' => "Server error,please try again.",
                'error_log' => $e->getMessage()
            ],Response::HTTP_OK);
        }
    }


    /**
     * Fetch Followers whose status is pending
     */
    public function bookmarkedUsers(Request $request, $post_id){
        $user = Auth::User();
        $limit = isset($request->page_limit) ? $request->page_limit : 20;
        $page = isset($request->page) ? $request->page : 0;
        $query = isset($request->query)?$request->get('query'):'';



        $bookmarks = User::leftjoin('favourites as fav','users.id','=','fav.user_id')
            ->where('fav.post_id',$post_id)
            ->select('users.*')
            ->orderBy('fav.created_at', 'DESC');

        $totalPage = floor((int)$bookmarks->count()/$limit) +($bookmarks->count()%$limit>0?1:0);

        $bookmarks = $bookmarks->skip($page * $limit)->take($limit)->get();

        return response([
            'responseCode' => Response::HTTP_OK,
            'responseMessage' => "",
            'nextPage' => $page+1,
            'total_page' => $totalPage,
            'users' => $bookmarks
        ],Response::HTTP_OK);
    }

    private function isExist($receivers, $id)
    {
        foreach ($receivers as $receiver) {
            if($receiver['user_id'] == $id) {
                return true;
            }
        }
        return false;
    }


    public function split(Request $request, $post_id){
        $user = Auth::User();
        $position = $request->position;
        if(!isset($position) || $position < 1){
            return response([
                'responseCode' => Response::HTTP_BAD_REQUEST,
                'responseMessage' => "Please enter valid split position."
            ],Response::HTTP_OK);
        }
        try{
            $position = (int)$position;
            $post = Auth::User()->posts()->find($post_id);
            if($post != null){
                $postMetas = Post_meta::where("post_id",$post_id)->orderBy('created_at','ASC')->get()->toArray();
                $secondPart = array_slice($postMetas, $position);

                $newPost = new POST;
                $newPost->user_id = Auth::User()->id;
                $newPost->search_term = ""; // No need , we can remove later
                $newPost->journey_id = time();
                $newPost->latitude = $post->latitude;
                $newPost->longitude = $post->longitude;
                $newPost->created_at = $secondPart[0]["created_at"];
                $newPost->updated_at = $secondPart[0]["created_at"];
                $newPost->save();
                foreach ($secondPart as $postMeta){
                    $postmeta = new Post_meta;
                    $postmeta->post_id = $newPost->id;
                    $postmeta->website = $postMeta['website'];
                    $postmeta->title = $postMeta['title'];
                    $postmeta->description = $postMeta['description'];
                    $postmeta->search_term = $postMeta['search_term'];
                    $postmeta->bing_id = "bing_id".$postMeta['id'];
                    $postmeta->type = $postMeta['type'];
                    $postmeta->public_search = $postMeta['public_search'];
                    $postmeta->image = $postMeta['image'];
                    $postmeta->content = $postMeta['content'];

                    $postmeta->save();
                    Post_meta::where('id',$postMeta['id'])->where('post_id',$post->id)->delete();
                }

                return response([
                    'responseCode' => Response::HTTP_OK,
                    'responseMessage' => "Split Successful.",
                    'posts' => [$this->getFullPostDetailsById($newPost->id, null),$this->getFullPostDetailsById($post->id, null)]
                ],Response::HTTP_OK);

            }else{
                return response([
                    'responseCode' => Response::HTTP_BAD_REQUEST,
                    'responseMessage' => "Post not found"
                ],Response::HTTP_OK);
            }
        }catch (\Exception $e){
            return response([
                'responseCode' => Response::HTTP_BAD_REQUEST,
                'responseMessage' => $e->getMessage()
            ],Response::HTTP_OK);
        }

    }

    public function saveMedia(Request $request){
        $user = Auth::User();
        $supported_exts = array(
            'jpg',
            'jpeg',
            'png',
            'mp4'
        );
        try{
            $caption = $request->get('caption');
            $type = $request->get('type');
            $journeyId = $request->get('journey_id');
            $url = $request->get('url');
            $video = $request->file('video');
            $android = $request->get('android');
            $image = $request->file('image');
            if(isset($android) && $android == 1){
                $video->move(app()->basePath('public/posts/videos'),"temp_1.mp4");
                $video_url = "posts/videos/temp_1.mp4";

                $image->move(app()->basePath('public/posts/images'),"temp_1.jpg");
                $image_url = "posts/images/temp_1.jpg";
                $watermarkPath = app()->basePath('public/posts/images/temp_1.jpg');
                $ffmpeg = FFMpeg::create([
                    'ffmpeg.binaries'  => '/usr/bin/ffmpeg', // the path to the FFMpeg binary
                    'ffprobe.binaries' => '/usr/bin/ffprobe', // the path to the FFProbe binary
                    'timeout'          => 3600, // the timeout for the underlying process
                    'ffmpeg.threads'   => 12,   // the number of threads that FFMpeg should use
                ]);

                $frameRate = new FrameRate(15);
                $video = $ffmpeg->open(app()->basePath('public/posts/videos/temp_1.mp4'));
                $videoFilter = new WatermarkFilter($watermarkPath, [
                    'position' => 'absolute',
                    'bottom' => 0,
                    'right' => 0,
                    'top' => 0,
                    'left' => 0,
                ]);

                $video->addFilter($videoFilter);
                $video->filters()->crop(new \FFMpeg\Coordinate\Point("t*100", 0, true), new \FFMpeg\Coordinate\Dimension(300, 250));

                $video
                    ->filters()
                    ->resize(new \FFMpeg\Coordinate\Dimension(320, 240))
                    ->synchronize();
                $video->filters()->framerate($frameRate, 15);
                $format = new \FFMpeg\Format\Video\X264('libmp3lame', 'libx264');
                $commands = $video->save($format,app()->basePath('public/posts/images/export-x264.mp4'));

                return response([
                    'responseCode' => Response::HTTP_BAD_REQUEST,
                    'responseMessage' => "completed",
                    "commands" => json_encode($commands)
                ],Response::HTTP_OK);
            }
            if(!isset($caption) || strlen($caption) == 0){
                return response([
                    'responseCode' => Response::HTTP_BAD_REQUEST,
                    'responseMessage' => "Caption can not be empty"
                ],Response::HTTP_OK);
            }

            if(!isset($type) || strlen($type) == 0){
                return response([
                    'responseCode' => Response::HTTP_BAD_REQUEST,
                    'responseMessage' => "Type can not be empty"
                ],Response::HTTP_OK);
            }

            if(!isset($journeyId) || strlen($journeyId) == 0){
                return response([
                    'responseCode' => Response::HTTP_BAD_REQUEST,
                    'responseMessage' => "Journey Id can not be empty"
                ],Response::HTTP_OK);
            }

            if($video == null && $type == "raw_video"){
                return response([
                    'responseCode' => Response::HTTP_BAD_REQUEST,
                    'responseMessage' => "Video can not be empty"
                ],Response::HTTP_OK);
            }

            if($image != null){
                $ext = $image->getClientOriginalExtension();
                if($ext != null && strlen($ext) > 0 && in_array($ext, $supported_exts)){
                    $video_url = "";
                    if($type == "raw_video"){
                        $extVid = $video->getClientOriginalExtension();
                        if($extVid != null && strlen($extVid) > 0 && in_array($extVid, $supported_exts)){
                            $newVName = "video_".time().rand(0,9999999).'.'.$extVid;
                            /** Save image in the given location */

                            $video->move(app()->basePath('public/posts/videos'),$newVName);
                            $video_url = "posts/videos/".$newVName;
                        }else{
                            return response([
                                'responseCode' => Response::HTTP_BAD_REQUEST,
                                'responseMessage' => "Please upload valid video file."
                            ],Response::HTTP_OK);
                        }
                    }
                    /** generate random name of uploaded image */
                    $newName = "image_".time().rand(0,9999999).'.'.$ext;
                    /** Save image in the given location */

                    $image->move(app()->basePath('public/posts/images'),$newName);
                    $image_url = "posts/images/".$newName;

                    $getPostDetails = db::table('posts')->where('journey_id',$journeyId)->where('user_id',$user->id);
                    $postCount = $getPostDetails->count();
                    if($postCount == 0){
                        $post = new POST;
                        $post->user_id = Auth::User()->id;
                        $post->search_term = "";
                        $post->journey_id = $journeyId;
                        $post->latitude = $user->latitude;
                        $post->longitude = $user->longitude;
                        $post->save();
                        $postId = $post->id;
                    }else{
                        $getPostDetails = $getPostDetails->first();
                        $postId = $getPostDetails->id;
                    }



                    $postmeta = new Post_meta;
                    $postmeta->post_id = $postId;
                    if(isset($url) && strlen($url) > 0){
                        $postmeta->website = $url;
                    }

                    $postmeta->title = $caption;
                    $postmeta->description = "";
                    $postmeta->search_term = $caption;
                    $postmeta->type = isset($type)?$type:null;
                    $postmeta->public_search = $user->share_local_search;
                    $postmeta->image = $image_url;
                    if($type == "raw_video"){
                        $postmeta->media = $video_url;
                    }

                    $postmeta->save();
                    return response([
                        'responseCode' => Response::HTTP_OK,
                        'responseMessage' => "Success",
                        "postMeta" => $postmeta
                    ],Response::HTTP_OK);
                }else{
                    return response([
                        'responseCode' => Response::HTTP_BAD_REQUEST,
                        'responseMessage' => "Please upload valid image file."
                    ],Response::HTTP_OK);
                }

            }else{
                return response([
                    'responseCode' => Response::HTTP_BAD_REQUEST,
                    'responseMessage' => "Image is empty"
                ],Response::HTTP_OK);
            }

        }catch (\Exception $e){
            return response([
                'responseCode' => Response::HTTP_BAD_REQUEST,
                'responseMessage' => $e->getMessage()
            ],Response::HTTP_OK);
        }
    }

    /**
     * @param String $id
     * @param $local
     * @return mixed
     */
    public function getFullPostDetailsById(String $id, $local)
    {
        $post = Post::with('user', 'comments')->withCount('bookmarks')
            ->where('id', $id)
            ->where('delete', 0)
            ->first();

        $postId = $post->id;
        $checkFavourite = Favourite::where('post_id', $postId)->where('user_id', Auth::User()->id)->count();
        if ($checkFavourite == 0) {
            $favourite = false;
        } else {
            $favourite = true;
        }

        $likes = Like::where('post_id', $postId)->count();
        $comment = Post::where('parent_post_id', $postId)->count() + Post::where('parent_post_id', $postId)->withCount("replies")->get()->sum("replies_count");
        $postMeta = null;
        if (isset($local) && $local == 1) {
            $postMeta = Post_meta::where('post_id', $postId)->where('public_search', '1')->orderBy('created_at', 'ASC')->get();
        } else {
            $postMeta = Post_meta::where('post_id', $postId)->orderBy('created_at', 'ASC')->get();
        }

        $post->like_count = $likes;
        $post->comment_count = $comment;
        $post->favourite = $favourite;
        $post->websites = $postMeta;
        $post->clicks_count = PostClick::where('post_id', $postId)->sum('count');
        $post->views_count = PostView::where('post_id', $postId)->sum('count');
        $post->shares_count = PostShare::where('post_id', $postId)->sum('count');
        //$post->setRelation("comments", $post->comments->slice(-2, 2));



        return $post;
    }

    /**
     * @param $postMeta
     */
    private function safeDeletePostWebsiteMedias($postMeta): void
    {
        try{
            if ($postMeta->type == "thought" || $postMeta->type == "raw_image" || $postMeta->type == "raw_video") {
                $pathinfo = pathinfo($postMeta->image);
                $path = app()->basePath('public/posts/images/') . $pathinfo['filename'] . '.' . $pathinfo['extension'];
                unlink($path);
            }
            if ($postMeta->type == "raw_video") {
                $pathinfo = pathinfo($postMeta->media);
                $path = app()->basePath('public/posts/videos/') . $pathinfo['filename'] . '.' . $pathinfo['extension'];
                unlink($path);
            }
        }catch (\Exception $e){

        }

    }


    public function test(Request $request, $postId){
        $like = Like::where('post_id',$postId)->where('user_id',Auth::user()->id)->with('activities')->first();

        return response([
            'responseCode' => Response::HTTP_BAD_REQUEST,
            'responseMessage' => "",
            'likes' => $like,
            'aa' => $like->delete()
        ],Response::HTTP_OK);
    }
}
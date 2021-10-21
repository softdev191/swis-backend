<?php
namespace App\Http\Controllers;

use App\Follow;
use App\Post;
use App\PostClick;
use App\PostShare;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Auth;
use DB;
use App\User;
use App\Non_recommended;

class PostClickController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth:api');
    }


    /**
     * Fetch Followers whose status is pending
     */
    public function clickedUsers(Request $request, $post_id){
        $user = Auth::User();
        $limit = isset($request->page_limit) ? $request->page_limit : 20;
        $page = isset($request->page) ? $request->page : 0;
        $query = isset($request->query)?$request->get('query'):'';    



        $clicks = User::join('post_clicks as pc','users.id','=','pc.user_id')
            ->where('pc.post_id',$post_id)
            ->groupBy('users.id')
            ->select('users.*')
            ->orderBy('pc.created_at', 'DESC');

        $totalPage = floor((int)$clicks->count()/$limit) +($clicks->count()%$limit>0?1:0);

        $clicks = $clicks->skip($page * $limit)->take($limit)->get();

        return response([
            'responseCode' => Response::HTTP_OK,
            'responseMessage' => "",
            'nextPage' => $page+1,
            'total_page' => $totalPage,
            'users' => $clicks
        ],Response::HTTP_OK);
    }

    public function addClick(Request $request, $post_id){
        $user = Auth::User();
        $count = isset($request->count) ? $request->count : 1;
        if(Post::where("id",$post_id)->count() > 0){
            $click = PostClick::firstOrNew(["user_id" => $user->id,"post_id" => (int)$post_id]);
            $click->count = $click->count + $count;
            $click->save();
            return response([
                'responseCode' => Response::HTTP_OK,
                'responseMessage' => "added successfully",
                'click' => $click
            ]);
        }else{
            return response([
                'responseCode' => Response::HTTP_BAD_REQUEST,
                'responseMessage' => "post does not exist"
                ]);
        }
    }


}
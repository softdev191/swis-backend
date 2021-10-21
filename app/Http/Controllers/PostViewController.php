<?php
namespace App\Http\Controllers;

use App\Follow;
use App\Post;
use App\PostShare;
use App\PostView;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Auth;
use DB;
use App\User;
use App\Non_recommended;

class PostViewController extends Controller
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
    public function viewedUsers(Request $request, $post_id){
        $user = Auth::User();
        $limit = isset($request->page_limit) ? $request->page_limit : 20;
        $page = isset($request->page) ? $request->page : 0;
        $query = isset($request->query)?$request->get('query'):'';    



        $shares = User::join('post_views as pv','users.id','=','pv.user_id')
            ->where('pv.post_id',$post_id)
            ->select('users.*')
            ->groupBy('users.id')
            ->orderBy('pv.created_at', 'DESC');

        $totalPage = floor((int)$shares->count()/$limit) +($shares->count()%$limit>0?1:0);

        $shares = $shares->skip($page * $limit)->take($limit)->get();

        return response([
            'responseCode' => Response::HTTP_OK,
            'responseMessage' => "",
            'nextPage' => $page+1,
            'total_page' => $totalPage,
            'users' => $shares
        ],Response::HTTP_OK);
    }

    public function addView(Request $request, $post_id){
        $user = Auth::User();
        $count = isset($request->count) ? $request->count : 1;
        if(Post::where("id",$post_id)->count() > 0){
            $view = PostView::firstOrNew(["user_id" => $user->id,"post_id" => (int)$post_id]);
            $view->count = $view->count + $count;
            $view->save();
            return response([
                'responseCode' => Response::HTTP_OK,
                'responseMessage' => "added successfully",
                'view' => $view
            ]);
        }else{
            return response([
                'responseCode' => Response::HTTP_BAD_REQUEST,
                'responseMessage' => "post does not exist"
                ]);
        }
    }


}
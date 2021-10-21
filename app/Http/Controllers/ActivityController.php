<?php
namespace App\Http\Controllers;

use App\Activity;
use App\Follow;
use App\Post;
use App\PostShare;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Auth;
use DB;
use App\User;
use App\Non_recommended;

class ActivityController extends Controller
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


    public function yourActivities(Request $request){
        $user = Auth::User();
        $limit = isset($request->page_limit) ? $request->page_limit : 20;
        $page = isset($request->page) ? $request->page : 0;



        $activities = Activity::where('receiver_id',$user->id)->with('sender','post','comment')
            ->orderBy('created_at', 'DESC');

        $totalPage = floor((int)$activities->count()/$limit) +($activities->count()%$limit>0?1:0);

        $activities = $activities->skip($page * $limit)->take($limit)->get();

        return response([
            'responseCode' => Response::HTTP_OK,
            'responseMessage' => "",
            'nextPage' => $page+1,
            'total_page' => $totalPage,
            'activities' => $activities
        ],Response::HTTP_OK);
    }



    public function followerActivities(Request $request){
        $user = Auth::User();
        $limit = isset($request->page_limit) ? $request->page_limit : 20;
        $page = isset($request->page) ? $request->page : 0;

        $followingIds = Follow::where('follower_id',$user->id)->where('status','approved')->pluck('following_id')->all();

        $activities = Activity::whereIn('sender_id',$followingIds)->with('sender','receiver','post','comment')
            ->orderBy('created_at', 'DESC');

        $totalPage = floor((int)$activities->count()/$limit) +($activities->count()%$limit>0?1:0);

        $activities = $activities->skip($page * $limit)->take($limit)->get();

        return response([
            'responseCode' => Response::HTTP_OK,
            'responseMessage' => "",
            'nextPage' => $page+1,
            'total_page' => $totalPage,
            'activities' => $activities
        ],Response::HTTP_OK);
    }
}
<?php
namespace App\Http\Controllers;

use App\Follow;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Auth;
use DB;
use App\User;
use App\Non_recommended;

class FollowController extends Controller
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
     * Follow Request API
     */
    public function followRequest(Request $request){
        $userId = Auth::User()->id;
        $followingId = $request->following_id;

        /** Check the following_id exists or not */
        $check = User::find($followingId);
        if($check == ''){
            return response([
                'responseCode' => Response::HTTP_BAD_REQUEST,
                'responseMessage' => "This user not exists",
            ],Response::HTTP_OK);
        }
        /** Check the user have already send follow request or not */
        $followCheck = Follow::where('follower_id',$userId)
            ->where('following_id',$followingId)
            ->select('follower_id','following_id','status');
        $check = $followCheck->get();
        if(count($check) != 0){
            return response([
                'responseCode' => Response::HTTP_BAD_REQUEST,
                'responseMessage' => "Follow Request already Sent",
                'details' => json_decode($check)
            ],Response::HTTP_OK);
        }
        /** Check the status of auto follow of following user */
        $status = User::where('id',$followingId)->first()->auto_select;
        /** Send the follow request */
        $follow = new Follow;
        $follow->follower_id = $userId;
        $follow->following_id = $followingId;
        $follow->status = ($status == 'on')?'approved':'pending';
        $follow->save();

        // Get User details
        if($status == 'on'){
            (new SendPushNotification)->followNotification($followingId);
        }else{
            (new SendPushNotification)->followRequestedNotification($followingId);

        }

        return response([
            'responseCode' => Response::HTTP_OK,
            'responseMessage' => ($status == 'on')?"You have successfully followed.":"Follow request sent successfully.",
            'details' => json_decode($followCheck->get())
        ],Response::HTTP_OK);
    }

    /**
     * Fetch Followers whose status is pending
     */
    public function fetchPendingFollowers(Request $request){
        $userId = Auth::User()->id;
        $limit = isset($request->page_limit) ? $request->page_limit : 20;
        $page = isset($request->page) ? $request->page : 0;
        $query = isset($request->query)?$request->get('query'):'';    

        /** Fetch The followers whom status is pending */

        $follower = User::leftJoin('follows as f','users.id','=','f.follower_id')
            ->where('f.following_id',$userId)
            ->where('f.status','pending')
            ->orderBy('f.created_at', 'DESC')
            ->select('f.follower_id as id','f.following_id','f.status','users.*')
            ->where('users.name','like',"%".$request->get('query')."%")
            ->orderBy('f.created_at', 'DESC');

        $totalPage = floor((int)$follower->count()/$limit) +($follower->count()%$limit>0?1:0);

        $follower = $follower->skip($page * $limit)->take($limit)->get();

        return response([
            'responseCode' => Response::HTTP_OK,
            'responseMessage' => "FOLLOW REQUESTS",
            'nextPage' => $page+1,
            'total_page' => $totalPage,
            'followers' => $follower
        ],Response::HTTP_OK);
    }

    /**
     * Approve Request API
     */
    public function ApproveRequest(Request $request){
        $userId = Auth::User()->id;
        $reqUserId = $request->user_id;
        $approve = DB::table('follows')
            ->where('following_id',$userId)
            ->where('follower_id',$reqUserId)
            ->where('status','pending');
        $check = $approve->get();
        if(count($check) == 0){
            return response([
                'responseCode' => Response::HTTP_BAD_REQUEST,
                'responseMessage' => "Something Wrong",
            ],Response::HTTP_OK);
        }
        $approve->update(['status' => 'approved']);

        (new SendPushNotification)->followAcceptNotification($reqUserId);

        return response([
            'responseCode' => Response::HTTP_OK,
            'responseMessage' => "Follow Request Approved Successfully",
        ],Response::HTTP_OK);
    }

    /**
     * Decline Request API
     */
    public function DeclineRequest(Request $request){
        $userId = Auth::User()->id;
        $reqUserId = $request->user_id;
        $approve = Follow::where('following_id',$userId)->where('follower_id',$reqUserId)->where('status','pending');
        $check = $approve->count();
        if($check == 0){
            return response([
                'responseCode' => Response::HTTP_BAD_REQUEST,
                'responseMessage' => "Something Wrong",
            ],Response::HTTP_OK);
        }
        $approve->delete();
        return response([
            'responseCode' => Response::HTTP_OK,
            'responseMessage' => "Follow Request Decline Successfully",
        ],Response::HTTP_OK);
    }

    /**
     * Followers API (Means users that is following me )
     */
    public function fetchApprovedFollowers(Request $request){
        $userId = isset($request->user_id)?$request->user_id:Auth::User()->id;
        $limit = isset($request->page_limit) ? $request->page_limit : 20;
        $page = isset($request->page) ? $request->page : 0;
        $searchStr = $request->get('query');

        /** Check the following_id exists or not */
        $check = User::where('id',$userId)->count();
        if($check == 0){
            return response([
                'responseCode' => Response::HTTP_BAD_REQUEST,
                'responseMessage' => "This user not exists",
            ],Response::HTTP_OK);
        }

        /** Fetch The followers who status is approved */
        $follower = User::leftJoin('follows as f','users.id','=','f.follower_id')->where('f.following_id',$userId)
            ->where('f.status','approved')
            ->where(function ($query) use ($searchStr){
                $query->where('users.name','like',"%".$searchStr."%")->orWhere('users.username','like',"%".$searchStr."%");
            })
            ->select('users.*')
            ->orderBy('f.created_at', 'DESC');

        $totalPage = floor((int)$follower->count()/$limit) +($follower->count()%$limit>0?1:0);

        $follower = $follower->skip($page * $limit)->take($limit)->get();
        return response([
            'responseCode' => Response::HTTP_OK,
            'responseMessage' => "FOLLOWERS",
            'nextPage' => $page+1,
            'total_page' => $totalPage,
            'followers' => $follower
        ],Response::HTTP_OK);
    }

    /**
     * Followings API (Means users that I follow )
     */
    public function fetchFollowings(Request $request){
        $userId = isset($request->user_id)?$request->user_id:Auth::User()->id;
        $limit = isset($request->page_limit) ? $request->page_limit : 20;
        $page = isset($request->page) ? $request->page : 0;
        $searchStr = $request->get('query');
        /** Check the following_id exists or not */
        $check = User::where('id',$userId)->get();
        if(count($check) == 0){
            return response([
                'responseCode' => Response::HTTP_BAD_REQUEST,
                'responseMessage' => "This user not exists",
            ],Response::HTTP_OK);
        }
        /** Fetch The followings whom status is approved */
        $following = User::join('follows as f','f.following_id','users.id')->where('f.follower_id',$userId)->select('users.*')
            ->where('f.status','approved')
            ->where(function ($query) use ($searchStr){
                $query->where('users.name','like',"%".$searchStr."%")->orWhere('users.username','like',"%".$searchStr."%");
            })
            ->select('users.*')
            ->orderBy('f.created_at', 'DESC');

        $totalPage = floor((int)$following->count()/$limit) +($following->count()%$limit>0?1:0);

        $following = $following->skip($page * $limit)->take($limit)->get();

        return response([
            'responseCode' => Response::HTTP_OK,
            'responseMessage' => "FOLLOWINGS",
            'nextPage' => $page+1,
            'total_page' => $totalPage,
            'followings' => $following
        ],Response::HTTP_OK);
    }


    /**
     */
    public function fetchFollowingsFeed(Request $request){
        try{
            $userId = isset($request->user_id)?$request->user_id:Auth::User()->id;
            $limit = isset($request->page_limit) ? $request->page_limit : 20;
            $page = isset($request->page) ? $request->page : 0;
            /** Check the following_id exists or not */
            $check = User::where('id',$userId)->get();
            if(count($check) == 0){
                return response([
                    'responseCode' => Response::HTTP_BAD_REQUEST,
                    'responseMessage' => "This user not exists",
                ],Response::HTTP_OK);
            }
            /** Fetch The followings whom status is approved */
            $following = User::join('follows as f','f.following_id','users.id')->where('f.follower_id',$userId)
                ->select('users.*')
                ->addSelect(DB::raw('MAX(p.created_at) as last_post_time'))
                ->leftjoin('posts as p', function($join) {
                    $join->on('users.id', '=', 'p.user_id');

                })
                ->where('f.status','approved')
                ->where('p.parent_post_id',null)
                ->orderBy('last_post_time','desc')
                ->groupBy('users.id')
                ->skip($page * $limit)->take($limit)
                ->get();

            return response([
                'responseCode' => Response::HTTP_OK,
                'responseMessage' => "FOLLOWINGS",
                'nextPage' => $page+1,
                'followings' => $following
            ],Response::HTTP_OK);
        }catch (\Exception $e){
            return response([
                'responseCode' => Response::HTTP_BAD_REQUEST,
                'responseMessage' => $e->getMessage(),
                'followings' => $following
            ],Response::HTTP_OK);
        }

    }


    /**
     * UnFollow API
     */
    public function unFollow(Request $request){
        $this->validate($request, [
            'user_id'    => 'required|integer',
        ]);
        $userId = $request->user_id;
        $check = User::find($userId);
        if(!$check){
            $code = Response::HTTP_NOT_FOUND;
            $message = "This user not exists";
        }else{
            $checkfollow = Follow::where('follower_id',Auth::User()->id)->where('following_id',$userId);
            $follow = $checkfollow->count();
            if($follow == 0){
                $code = Response::HTTP_OK;
                $message = "Something Wrong";
            }else{
                $checkfollow->first()->delete();
                $code = Response::HTTP_OK;
                $message = "Unfollowed Successfully";
            }
        }
        return response([
            'responseCode' => $code,
            'responseMessage' => $message,
        ],$code);
    }

    /** Recommended User API with pagination */
    public function recommendedUser(Request $request){
        $userId = Auth::User()->id;
        $limit = isset($request->page_limit) ? $request->page_limit : 20;
        $page = isset($request->page) ? $request->page : 0;
        $searchStr = isset($request->query)?$request->get('query'):"";

        /** Getting list of recommended user with pagination */
        $removedUser = Non_recommended::where('user_id',$userId)->pluck('opponent_id')->all();
        $followedUser = Follow::where('follower_id',$userId)->pluck('following_id')->all();

        array_push($removedUser,$userId);
        $removedUser = array_merge($removedUser,$followedUser);

        $recommendedUser = User::whereNotIn('id',$removedUser)
            ->orderBy('created_at', 'ASC')
            ->where(function ($query) use ($searchStr){
                $query->where('users.name','like',"%".$searchStr."%")->orWhere('users.username','like',"%".$searchStr."%");
            })
            ->skip($page * $limit)->take($limit)
            ->get();

        /** Return Response */
        return response([
            'responseCode' => Response::HTTP_OK,
            'responseMessage' => "Recommended User",
            'nextPage' => $page+1,
            'recommendedUser' => $recommendedUser
        ],Response::HTTP_OK);
    }

    /** Remove Follower */
    public function removeFollower(Request $request){
        $this->validate($request,[
            'user_id' => 'required|Integer',
        ]);
        $followerId = $request->get('user_id');
        $followingId = Auth::User()->id;

        $check = Follow::where('follower_id',$followerId)->where('following_id',$followingId)->where('status','approved')->delete();
        if($check == 1){
            $message = "Follower Removed Successfully";
        }else{
            $message = "Something wrong";
        }
        return response([
            'responseCode' => Response::HTTP_OK,    
            'responseMessage' => $message
        ],Response::HTTP_OK);
    }

    /** Change Auto Follow API */
    public function autoFollow(){
        $status = Auth::User()->auto_select;
        if($status == 'on'){
            Auth::User()->update(['auto_select' => 'off']);
            $message = "Auto Accept Follows Deactivate Successfully";
        }else{
            Auth::User()->update(['auto_select' => 'on']);
            $message = "Auto Accept Follows Active Successfully";
        }
        return response([
            'responseCode' => Response::HTTP_OK,
            'responseMessage' => $message
        ],Response::HTTP_OK);
    }

    /** Delete Recommended API */
    public function delRecommended(Request $request){
        $this->validate($request, [
            'opponentId'    => 'required|integer',
        ]);
        $opponentId = $request->get('opponentId');
        /* Check oppenent exists or not */
        $check = Auth::User()->find($opponentId);
        if($check == ''){
            return response([
                'responseCode' => Response::HTTP_OK,
                'responseMessage' => "Recommened user not found"
            ],Response::HTTP_OK);
        }
        /* check recommended opponent is already deleted or not */
        $check = Non_recommended::where('user_id',Auth::User()->id)->where('opponent_id',$opponentId)->count();
        if($check == 0){
            Non_recommended::create([
                'user_id' => Auth::User()->id,
                'opponent_id' => $opponentId,
            ]);
            return response([
                'responseCode' => Response::HTTP_OK,
                'responseMessage' => "Success"
            ],Response::HTTP_OK);
        }
        return response([
            'responseCode' => Response::HTTP_OK,
            'responseMessage' => "Recommended User Already Deleted"
        ],Response::HTTP_OK);
    }
}
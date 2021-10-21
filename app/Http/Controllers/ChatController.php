<?php

namespace App\Http\Controllers;

use App\ChatMessage;
use App\ChatOccupant;
use App\ChatRoom;
use App\Post;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Twilio\Exceptions\RestException;
use Twilio\Rest\Client;
use Tymon\JWTAuth\JWTAuth;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use validator;
use Illuminate\Support\Facades\Auth;
use DB;
use App\User;
use App\Follow;
use Illuminate\Support\Facades\Mail;
use Illuminate\Notifications\Notification;
use Carbon\Carbon;

class ChatController extends Controller
{
    /**
     * @var \Tymon\JWTAuth\JWTAuth
     */
    public function __construct(JWTAuth $jwt, Mail $mail){
        $this->middleware('auth:api');
    }

    /** User Register API */
    public function createChatRoom(Request $request){
        $user = Auth::user();
        try{
            $this->validate($request,[
                'occupants' => 'required',
            ]);
            $request->occupants = $user->id.",".$request->occupants;
            $occupantsArr = explode(",",$request->occupants);
            $occupantsArr = array_values(array_unique($occupantsArr));

            if(User::whereIn('id',$occupantsArr)->count() != sizeof($occupantsArr)){
                return response([
                    'responseCode' => Response::HTTP_BAD_REQUEST,
                    'responseMessage' => "Some user ids are not valid."
                ],Response::HTTP_OK);
            }
            $type = "PRIVATE";


            $channelName = "";

            if(sizeof($occupantsArr) > 2){
                $type = "GROUP";
            }

            if($type == "PRIVATE"){
                $rooms = ChatRoom::join('chat_occupants as co','co.room','chat_rooms.id')->where('chat_rooms.type','PRIVATE')
                    ->where('co.user_id',$occupantsArr[0])->orWhere('co.user_id',$occupantsArr[1])->groupBy('co.room')->select('co.room', DB::raw('count(*) as total'))->pluck('co.room','total')->all();

                if(array_key_exists("2",$rooms)){
                    $room = ChatRoom::with("other_occupants")->where('id',$rooms["2"])->first();
                    ChatRoom::where('id',$room->id)->update(['deleted_by' => '']);
                    return response([
                        'responseCode' => Response::HTTP_OK,
                        'responseMessage' => "Chat room exist.",
                        'chat_room' => $room,
                    ],Response::HTTP_OK);
                }
            }

            $room = ChatRoom::create([
                "creator" => $user->id,
                "occupants" => "",
                "name" => $request->name,
                "channel" => $channelName,
                "type" => $type
            ]);
            foreach ($occupantsArr as $occupant) {
                ChatOccupant::create([
                    "room" => $room->id,
                    "user_id" => $occupant,
                ]);
            }

            $room->update(["channel" => "presence-chat.".$room->id]);

            return response([
                'responseCode' => Response::HTTP_OK,
                'responseMessage' => "Chat room created successfully.",
                'chat_room' => ChatRoom::with("other_occupants")->where('id',$room->id)->first(),
            ],Response::HTTP_OK);
        }catch (\Exception $e){
            return response([
                'responseCode' => Response::HTTP_BAD_REQUEST,
                'responseMessage' => $e->getMessage()
            ],Response::HTTP_OK);
        }

    }

    public function getChatRooms(Request $request){
        $user = Auth::user();
        $limit = isset($request->page_limit) ? $request->page_limit : 20;
        $page = isset($request->page) ? $request->page : 0;
        $searchStr = $request->get('query');
        try{

            $chatRoomIds = ChatOccupant::where('user_id',$user->id)->pluck('room')->all();
            $rooms = ChatRoom::whereIn('chat_rooms.id',$chatRoomIds)->with("other_occupants","last_message")->join('chat_occupants as co','chat_rooms.id','co.room')
                ->leftjoin('users as u','co.user_id','u.id')
                ->where(function ($query) use ($searchStr){
                    $query->where('u.name','like',"%".$searchStr."%")
                            ->orWhere('chat_rooms.name','like',"%".$searchStr."%");
                })
                ->whereRaw("(find_in_set('$user->id',deleted_by) is null OR find_in_set('$user->id',deleted_by) = 0)")
                ->groupBy('chat_rooms.id')
                ->select('chat_rooms.*')
                ->orderBy('chat_rooms.updated_at','DESC');

            $totalPage = floor((int)$rooms->count()/$limit) +($rooms->count()%$limit>0?1:0);
            $rooms = $rooms->skip($page * $limit)->take($limit)->get();

            return response([
                'responseCode' => Response::HTTP_OK,
                'responseMessage' => "",
                'nextPage' => $page+1,
                'total_page' => $totalPage,
                'chat_rooms' => $rooms,
            ],Response::HTTP_OK);
        }catch (\Exception $e){
            return response([
                'responseCode' => Response::HTTP_BAD_REQUEST,
                'responseMessage' => $e->getMessage()
            ],Response::HTTP_OK);
        }
    }


    public function getAllConversations(Request $request){
        $user = Auth::user();
        $limit = isset($request->page_limit) ? $request->page_limit : 20;
        $page = isset($request->page) ? $request->page : 0;
        $searchStr = $request->get('query');
        $rooms = array();
        try{
            if($page == 0){
                $chatRoomIds = ChatOccupant::where('user_id',$user->id)->pluck('room')->all();
                $rooms = ChatRoom::whereIn('chat_rooms.id',$chatRoomIds)->with("other_occupants","last_message")->join('chat_occupants as co','chat_rooms.id','co.room')
                    ->leftjoin('users as u','co.user_id','u.id')
                    ->where(function ($query) use ($searchStr){
                        $query->where('u.name','like',"%".$searchStr."%")
                            ->orWhere('chat_rooms.name','like',"%".$searchStr."%");
                    })
                    ->whereRaw("(find_in_set('$user->id',deleted_by) is null OR find_in_set('$user->id',deleted_by) = 0)")
                    ->groupBy('chat_rooms.id')
                    ->select('chat_rooms.*')
                    ->orderBy('chat_rooms.updated_at','DESC');

                $rooms = $rooms->get();
            }


            //Friends
            $privatchatRoomIds = ChatRoom::where('type','PRIVATE')->join('chat_occupants', 'chat_occupants.room', 'chat_rooms.id')->where('chat_occupants.user_id',$user->id)->pluck('chat_rooms.id')->all();
            //$privatchatRoomIds = ChatOccupant::where('user_id',$user->id)->pluck('room')->all();
            $removeFriends = ChatOccupant::whereIn('room',$privatchatRoomIds)->where('user_id','<>',$user->id)->pluck('user_id')->all();
            $followingIds = Follow::whereRaw("follower_id = $user->id")->where('status','approved')->pluck('following_id')->all();
            $followerIds = Follow::whereRaw("following_id = $user->id")->where('status','approved')->pluck('follower_id')->all();
            $friendsIds = array_diff(array_values(array_unique(array_merge($followingIds,$followerIds))), $removeFriends);
            $friends = User::whereIn('id',$friendsIds)
                ->where(function ($query) use ($searchStr){
                    $query->where('name','like',"%".$searchStr."%")->orWhere('username','like',"%".$searchStr."%");
                })
                ->select('id', 'name', 'username','bio', 'avatar','updated_at')
                ->orderBy('name', 'ASC');

            $totalPageF = floor((int)$friends->count()/$limit) +($friends->count()%$limit>0?1:0);
            $friends = $friends->skip($page * $limit)->take($limit)->get()->makeHidden(['auto_accept','follow_request_count','searches_count','followers_count','followings_count','followed']);



            return response([
                'responseCode' => Response::HTTP_OK,
                'responseMessage' => "",
                'nextPage' => $page+1,
                'total_page' => $totalPageF,
                'chat_rooms' => $rooms,
                'friends' => $friends,
            ],Response::HTTP_OK);
        }catch (\Exception $e){
            return response([
                'responseCode' => Response::HTTP_BAD_REQUEST,
                'responseMessage' => $e->getMessage()
            ],Response::HTTP_OK);
        }
    }


    public function chatRoomDetails(Request $request, $id){
        $user = Auth::user();
        try{

            $room = ChatRoom::with('other_occupants','last_message')->whereId($id)->first();
            if($room != null){
                return response([
                    'responseCode' => Response::HTTP_OK,
                    'responseMessage' => "",
                    'chat_room' => $room,
                ],Response::HTTP_OK);

            }else{
                return response([
                    'responseCode' => Response::HTTP_BAD_REQUEST,
                    'responseMessage' => "Chat room not found or not belongs to you."
                ],Response::HTTP_OK);
            }


        }catch (\Exception $e){
            return response([
                'responseCode' => Response::HTTP_BAD_REQUEST,
                'responseMessage' => $e->getMessage()
            ],Response::HTTP_OK);
        }
    }


    public function delChatRoom(Request $request, $id){
        $user = Auth::user();
        $isRoomExist = ChatOccupant::where('room',$id)->where('user_id',$user->id)->count() > 0;
        if($isRoomExist){
            $room = ChatRoom::whereId($id)->first();
            $room->deleted_by = ($room->deleted_by!=null && strlen($room->deleted_by)>0)?$room->deleted_by.",".$user->id:$user->id;
            $room->save();
            return response([
                'responseCode' => Response::HTTP_OK,
                'responseMessage' => "Chat room has been deleted for you."
            ],Response::HTTP_OK);
        }else{
            return response([
                'responseCode' => Response::HTTP_BAD_REQUEST,
                'responseMessage' => "Chat room not found or not belongs to you."
            ],Response::HTTP_OK);
        }
    }

    public function updateGroupName(Request $request,$id){
        $user = Auth::user();
        try{
            if(!(isset($request->name) && strlen($request->name)>0)){
                return response([
                    'responseCode' => Response::HTTP_BAD_REQUEST,
                    'responseMessage' => "Please enter valid group name."
                ],Response::HTTP_OK);
            }

            $isRoomExist = ChatOccupant::where('room',$id)->where('user_id',$user->id)->count() > 0;
            if($isRoomExist){
                $room = ChatRoom::whereId($id)->first();
                if($room->type == "GROUP"){
                    $room->update(["name" => $request->name]);
                    return response([
                        'responseCode' => Response::HTTP_OK,
                        'responseMessage' => "Group Name has been updated.",
                        'chat_room' => ChatRoom::with('other_occupants','last_message')->whereId($id)->first(),
                    ],Response::HTTP_OK);
                }else{
                    return response([
                        'responseCode' => Response::HTTP_BAD_REQUEST,
                        'responseMessage' => "Private Chat can not have name."
                    ],Response::HTTP_OK);
                }

            }else{
                return response([
                    'responseCode' => Response::HTTP_BAD_REQUEST,
                    'responseMessage' => "Chat room not found or not belongs to you."
                ],Response::HTTP_OK);
            }
        }catch (\Exception $e){
            return response([
                'responseCode' => Response::HTTP_BAD_REQUEST,
                'responseMessage' => $e->getMessage()
            ],Response::HTTP_OK);
        }
    }


}
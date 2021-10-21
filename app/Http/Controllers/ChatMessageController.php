<?php

namespace App\Http\Controllers;

use App\ChatMessage;
use App\ChatOccupant;
use App\ChatRoom;
use App\Post;
use App\Post_meta;
use Illuminate\Broadcasting\PrivateChannel;
use phpDocumentor\Reflection\Types\Boolean;
use Pusher\Pusher;
use Pusher\PusherException;
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

class ChatMessageController extends Controller
{

    public function __construct(JWTAuth $jwt, Mail $mail){
        $this->middleware('auth:api');
    }

    public function getMessageByRoom(Request $request, $roomId){
        $user = Auth::user();
        $limit = isset($request->page_limit) ? $request->page_limit : 20;
        $page = isset($request->page) ? $request->page : 0;
        try{

            $chatMessages = ChatMessage::with(["sender","post"])->where('room',$roomId)->orderBy('created_at', 'DESC');
            $totalPage = floor((int)$chatMessages->count()/$limit) +($chatMessages->count()%$limit>0?1:0);
            $chatMessages = $chatMessages->skip($page * $limit)->take($limit)->get();

            return response([
                'responseCode' => Response::HTTP_OK,
                'responseMessage' => "",
                'nextPage' => $page + 1,
                'total_page' => $totalPage,
                'chat_messages' => $chatMessages,
            ],Response::HTTP_OK);
        }catch (\Exception $e){
            return response([
                'responseCode' => Response::HTTP_BAD_REQUEST,
                'responseMessage' => $e->getMessage()
            ],Response::HTTP_OK);
        }
    }

    public function sharePost(Request $request){
        $user = Auth::user();
        $userId  = $user->id;
        $postId = $request->post_id;
        try{
            if(isset($postId) && strlen($postId) > 0){
                $post = Post::where('id',$postId)->first();
                if($post != null){
                    if(strlen(trim($request->room_id)) == 0 && strlen(trim(($request->friend_id))) == 0){
                        return response([
                            'responseCode' => Response::HTTP_BAD_REQUEST,
                            'responseMessage' => "Please select at least one user."
                        ],Response::HTTP_OK);
                    }
                    $roomIds = explode(",",$request->room_id);
                    $friendIds = explode(",",$request->friend_id);



                    foreach ($roomIds as $roomId) {
                        if(ChatOccupant::where('room',$roomId)->where('user_id',$user->id)->count() > 0){
                            $room = ChatRoom::with("other_occupants")->where('id',$roomId)->first();
                            if($room != null)
                                $this->sharedPostToRoom($room, $user, $post);
                        }
                    }

                    foreach ($friendIds as $friend_id) {
                        if($friend_id != null && strlen(trim($friend_id)) > 0 && User::where('id',$friend_id)->count() > 0){
                            $room = $this->findOrCreateRoom($userId, $friend_id);
                            if($room != null)
                                $this->sharedPostToRoom($room, $user, $post);
                        }
                    }

                    return response([
                        'responseCode' => Response::HTTP_OK,
                        'responseMessage' => "Search shared successfully."
                    ],Response::HTTP_OK);


                }else{
                    return response([
                        'responseCode' => Response::HTTP_BAD_REQUEST,
                        'responseMessage' => "Post is not valid."
                    ],Response::HTTP_OK);
                }
            }else{
                return response([
                    'responseCode' => Response::HTTP_BAD_REQUEST,
                    'responseMessage' => "Post is not valid"
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
     * @param $userId
     * @param $friend_id
     * @return ChatRoom|\Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Model|null|object
     */
    public function findOrCreateRoom($userId, $friend_id)
    {
        $rooms = ChatRoom::join('chat_occupants as co', 'co.room', 'chat_rooms.id')->where('chat_rooms.type', 'PRIVATE')
            ->where('co.user_id', $userId)->orWhere('co.user_id', $friend_id)->groupBy('co.room')->select('co.room', DB::raw('count(*) as total'))->pluck('co.room', 'total')->all();
        $room = null;
        if (array_key_exists("2", $rooms)) {
            $room = ChatRoom::with("other_occupants")->where('id', $rooms["2"])->first();
        }

        if($room == null){
            $room = ChatRoom::create([
                "creator" => $userId,
                "occupants" => "",
                "name" => "",
                "channel" => "",
                "type" => "PRIVATE"
            ]);

            ChatOccupant::create(["room" => $room->id, "user_id" => $userId,]);
            ChatOccupant::create(["room" => $room->id, "user_id" => $friend_id,]);

            $room->update(["channel" => "presence-chat.".$room->id]);
        }
        return $room;
    }

    /**
     * @param $room
     * @param $user
     * @param $post
     * @return \Exception
     */
    public function sharedPostToRoom($room, $user, $post): bool
    {
        $id = $room->id;
        $type = 'POST';

        ChatRoom::where('id', $id)->update(['deleted_by' => ""]);
        //Save message to DB
        ChatMessage::create([
            "room" => $id,
            "sender" => $user->id,
            "message" => "",
            "media" => "",
            "post" => $post->id,
            "type" => $type
        ]);


        //send message in socket
        $app_id = env('PUSHER_APP_ID');
        $app_key = env('PUSHER_APP_KEY');
        $app_secret = env('PUSHER_APP_SECRET');
        $app_cluster = env('PUSHER_APP_CLUSTER');

        try {
            $pusher = new Pusher($app_key, $app_secret, $app_id, array('cluster' => $app_cluster, 'encrypted' => true, 'debug' => true), 'https://devapi.swis.app', '6001');

            $postMeta = Post_meta::where('post_id', $post->id)->first();
            $postUser = User::where('id', $post->user_id)->first();
            $array = array();
            $array['message'] = '';
            $array['post'] = array("id" => $post->id, "search_term" => $postMeta->search_term, "image" => $postMeta->image, 'description' => $postMeta->description, "user_name" => $postUser->name, 'user_avatar' => $postUser->avatar);
            $array['type'] = $type;
            $array['user_id'] = $user->id;

            $result = $pusher->trigger($room->channel, 'client-message', $array);


        } catch (\Exception $e) {

        }

        //Send notifications to users
        $occupantsArr = ChatOccupant::where('room', $id)->pluck('user_id')->all();
        $occupantsArr = array_diff($occupantsArr, array($user->id));
        foreach ($occupantsArr as $receiver) {
            (new SendPushNotification)->messageNotification($receiver, $id, $user->id, $type, "");
        }
        return true;
    }
}
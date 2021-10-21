<?php

namespace App\Http\Controllers;

use App\Activity;
use App\User;
use FCM;
use Illuminate\Support\Facades\Auth;
use LaravelFCM\Message\OptionsBuilder;
use LaravelFCM\Message\PayloadDataBuilder;
use LaravelFCM\Message\PayloadNotificationBuilder;
use Log;
use Setting;


class SendPushNotification extends Controller
{

    /**
     * @param $user_id
     * @param string $title
     * @param $id
     * @return mixed
     */
    public function likeNotification($user_id, $id){
        try{
            $recepient = User::findOrFail($user_id);
            if(!$recepient->notification_like){
                return "";
            }
            $type = 1;
            $title="SWIS";
            $currentUser = Auth::user();
            $description = $currentUser->name." has liked your search.";
            Activity::create(["sender_id" => $currentUser->id,"receiver_id" => $recepient->id,"type" => $type,"post_id" => $id]);
            return $this->sendPushToUser($user_id, $title, $type,$description, $id);
        }catch (\Exception $e){}

    }

    /**
     * @param $user_id
     * @param string $title
     * @param $id
     * @return mixed
     */
    public function likeCommentNotification($user_id, $id,$parent_post_id){
        try{
            $recepient = User::findOrFail($user_id);
            if(!$recepient->notification_like){
                return "";
            }
            $type = 2;
            $title="SWIS";
            $currentUser = Auth::user();
            $description = $currentUser->name." just liked your comment.";
            Activity::create(["sender_id" => $currentUser->id,"receiver_id" => $recepient->id,"type" => $type,"post_id" => $parent_post_id,"comment_id" => $id]);
            return $this->sendPushToUser($user_id, $title, $type,$description, $id,$parent_post_id);
        }catch (\Exception $e){}

    }

    /**
     * @param $user_id
     * @param string $title
     * @param $id
     * @return mixed
     */
    public function commentNotification($user_id,$id,$parent_post_id, $description = "", $isComment = false){
        try{
            $recepient = User::findOrFail($user_id);
            if(!$recepient->notification_comment){
                return "";
            }
            $type = 2; //TODO need to change to 6
            $title="SWIS";
            $currentUser = Auth::user();

            return $this->sendPushToUser($user_id, $title, $type,$description, $id,$parent_post_id);
        }catch (\Exception $e){}

    }

    /**
     * @param $user_id
     * @param string $title
     * @return mixed
     */
    public function followRequestedNotification($user_id){
        try{
            $recepient = User::findOrFail($user_id);
            if(!$recepient->notification_follow){
                return "";
            }
            $type = 3;
            $title="SWIS";
            $currentUser = Auth::user();
            $description = $currentUser->name." requested to follow you.";
            Activity::create(["sender_id" => $currentUser->id,"receiver_id" => $recepient->id,"type" => $type]);
            return $this->sendPushToUser($user_id, $title,$type,$description);
        }catch (\Exception $e){}

    }

    /**
     * @param $user_id
     * @param string $title
     * @return mixed
     */
    public function followNotification($user_id){
        try{
            $recepient = User::findOrFail($user_id);
            /*if(!$recepient->notification_follow){
                return "";
            }*/
            $type = "4";
            $title="SWIS";
            $currentUser = Auth::user();
            $description = $currentUser->name." followed you.";
            Activity::create(["sender_id" => $currentUser->id,"receiver_id" => $recepient->id,"type" => $type]);
            return $this->sendPushToUser($user_id, $title,$type,$description);
        }catch (\Exception $e){
            return $e->getMessage();
        }

    }

    /**
     * @param $user_id
     * @param string $title
     * @return mixed
     */
    public function followAcceptNotification($user_id){

        try{
            $recepient = User::findOrFail($user_id);
            if(!$recepient->notification_follow){
                return "";
            }
            $type = 5;
            $title="SWIS";
            $currentUser = Auth::user();
            $description = $currentUser->name." accepted your follow request.";
            return $this->sendPushToUser($user_id, $title,$type,$description, $currentUser->id);
        }catch (\Exception $e){}

    }


    /**
     *
     */
    public function messageNotification($recepient_id, $room_id, $user_id, $message_type, $message=""){
        try{
            $recepient = User::findOrFail($recepient_id);

            $type = 9;
            $currentUser = User::findOrFail($user_id);
            $title = $currentUser->username;
            if($message_type == "TEXT"){
                $description = $currentUser->username.": ".$message;
            }else{
                $description = $currentUser->username." sent you a message";
            }

            return $this->sendPushToUser($recepient->id, $title, $type,$description, $room_id);
        }catch (\Exception $e){}

    }



    /**
     * @param $user_id
     * @param $title
     * @param $type
     * @param $description
     * @param string $id
     * @return mixed
     */
    public function sendPushToUser($user_id, $title,$type, $description, $id="",$main_post_id=""){

        $user = User::findOrFail($user_id);
        if($user->device_type == "android"){

            $fields = array('to' =>$user->device_token,"priority" => "high",'data' => array('id' =>$id,'main_post_id' =>$main_post_id,'body' =>$description,'title' =>$title,
                'type' => $type
            ));
            $headers = array(
                'Authorization: key=' . env('FCM_SERVER_KEY', 'Your FCM server key'),
                'Content-Type: application/json'
            );

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://fcm.googleapis.com/fcm/send');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
            $result = curl_exec($ch);
            curl_close($ch);
            return json_encode($fields);
        }else {
            $fields = array('to' =>$user->device_token,'notification' => array('id' =>$id,'main_post_id' =>$main_post_id,'body' =>$description,'title' =>$title,
                'icon' => 'myicon', 'sound' => 'mySound','type' => $type
            ));
            $headers = array(
                'Authorization: key=' . env('FCM_SERVER_KEY', 'Your FCM server key'),
                'Content-Type: application/json'
            );

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://fcm.googleapis.com/fcm/send');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
            $result = curl_exec($ch);
            curl_close($ch);
            return json_encode($fields);
        }
    }
}

<?php

namespace App;

use DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ChatRoom extends Model
{
    protected $fillable = [
        'creator','occupants','name','channel','type'
    ];

    protected $hidden = [
        'deleted_by','updated_at','creator','occupants'
    ];

    public function last_message(){
        return $this->hasOne('App\ChatMessage','room')->orderBy('created_at','DESC');
    }

    public function other_occupants(){
        $user = Auth::user();
        $url = url('').'/';
        if($user != null){

            return $this->hasMany('App\ChatOccupant','room')->join('users as u','u.id','chat_occupants.user_id')
                ->where('chat_occupants.user_id','<>',$user->id)
                ->select('u.id', 'u.name', 'u.username', DB::raw("CONCAT('$url',u.avatar) AS avatar"),'u.updated_at','chat_occupants.room');
        }else{
            return null;
        }
    }

}

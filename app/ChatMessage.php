<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ChatMessage extends Model
{
    protected $fillable = [
        'room','sender','message','type','post','media'
    ];

    protected $hidden = [
        'deleted_by','updated_at'
    ];


    public function getMessageAttribute(){
        $user = Auth::user();
        $message = $this->attributes['message'];
        switch ($this->attributes['type']){
            case "IMAGE":
                $message = ($user->id == $this->attributes['sender'])?"You sent an image":"Sent an image";
                break;
            case "AUDIO":
                $message = ($user->id == $this->attributes['sender'])?"You sent an audio":"Sent an audio";
                break;
            case "VIDEO":
                $message = ($user->id == $this->attributes['sender'])?"You sent a video":"Sent a video";
                break;
            case "POST":
                $message = ($user->id == $this->attributes['sender'])?"You sent a post":"Sent a post";
                break;
        }

        return $message;
    }

    public function sender(){
        return $this->belongsTo(User::class,'sender')->select('id','avatar','username','name','updated_at','auto_select');
    }

    public function post(){
        return $this->belongsTo('App\Post','post')->select('id','user_id')->with("first_website","user");
    }


}

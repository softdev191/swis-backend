<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Activity extends Model
{
    protected $fillable = [
        'post_id','sender_id','receiver_id','type','comment_id'
    ];

    protected $hidden = [
        'sender_id','updated_at','receiver_id','post_id'
    ];

    public function post(){
        return $this->belongsTo('App\Post','post_id')->select('id')->with("first_website");
    }

    public function sender(){
        return $this->belongsTo('App\User','sender_id')->select('id', 'name', 'username', 'avatar','updated_at');
    }

    public function receiver(){
        return $this->belongsTo('App\User','receiver_id')->select('id', 'name', 'username', 'avatar','updated_at');
    }

    public function comment(){
        return $this->belongsTo('App\Post','comment_id')->where('journey_id', null)->select('id','journey_id','comment');
    }

}

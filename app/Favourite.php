<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Favourite extends Model
{
    protected $appends = ['like'];
    protected $fillable = [
        'post_id','user_id',
    ];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [
        'ownLike'
    ];

    public function getLikeAttribute()//use model mutators
    {
        if ( ! array_key_exists('ownLike', $this->relations))
            $this->load('ownLike');
        $ownLike = $this->getRelation('ownLike');
        return count($ownLike) > 0;
    }


    public function user(){
        return $this->belongsTo('App\User','user_id');
    }
    public function comments(){
        return $this->hasMany('App\Post','parent_post_id','post_id')->withCount('Likes','replies')->with('user');
    }


    public function ownLike(){
        $user = Auth::user();
        if($user!=null){
            return $this->hasMany('App\Like','post_id')->whereUserId(Auth::user()->id);
        }
        return  array();
    }
}

<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Follow extends Model
{

    protected $fillable = [
        'follower_id','following_id',
    ];
    public function following_user(){
        return $this->belongsTo('App\User','following_id');
    }

    public function follower_user(){
        return $this->belongsTo('App\User','follower_id');
    }


    public function activities(){
        return $this->hasMany('App\Activity','sender_id','follower_id')->where('type',4);
    }


    public function requestactivities(){
        return $this->hasMany('App\Activity','sender_id','follower_id')->where('type',3);
    }

    public function delete()
    {
        $this->deleteRelatedData();
        return parent::delete();
    }

    /**
     * will delete related activities also
     */
    public function deleteRelatedData(){
        $this->activities()->delete();
        $this->requestactivities()->delete();
    }
}

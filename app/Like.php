<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Auth;

class Like extends Model
{
    // use Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'post_id','user_id',
    ];
    public function user(){
        return $this->belongsTo('App\User','user_id');
    }


    public function activities(){
        return $this->hasMany('App\Activity','post_id','post_id')->where('type',1);
    }

    public function commentactivities(){
        return $this->hasMany('App\Activity','comment_id','post_id')->where('type',2);
    }


    public function delete()
    {
        $this->deleteRelatedData();
        return parent::delete();
    }



    /**
     * will delete related activities also
     * */
    public function deleteRelatedData(){
        $this->activities()->delete();
        $this->commentactivities()->delete();
    }

}

<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Post extends Model
{

    protected $appends = ['like'];
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [ 
        'user_id','comment','parent_post_id','delete',
    ];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [
        'ownLike','updated_at','search_term','latitude','longitude'
    ];

    public function getLikeAttribute()//use model mutators
    {
        if ( ! array_key_exists('ownLike', $this->relations))
            $this->load('ownLike');
        $ownLike = $this->getRelation('ownLike');
        return count($ownLike) > 0;
    }

    public function brands(){
        return $this->hasMany(Like::class,'post_id');
    }

    public function user(){
        return $this->belongsTo(User::class,'user_id')->select('id','avatar','username','name','updated_at','auto_select');
    }


    public function comments(){
        return $this->hasMany('App\Post','parent_post_id')->withCount('likes','replies')->with('user');
    }

    public function likes(){
        return $this->hasMany('App\Like','post_id');
    }

    public function bookmarks(){
        return $this->hasMany(Favourite::class,'post_id');
    }

    public function clicks(){
        return $this->hasMany(PostClick::class,'post_id');
    }

    public function views(){
        return $this->hasMany(PostView::class,'post_id');
    }

    public function shares(){
        return $this->hasMany(PostShare::class,'post_id');
    }

    public function replies(){
        return $this->hasMany(Post::class,'parent_post_id');
    }

    public function websites(){
        return $this->hasMany('App\Post_meta','post_id');
    }

    public function first_website(){
        return $this->hasOne('App\Post_meta','post_id');
    }

    public function ownLike(){
        return $this->hasMany('App\Like')->whereUserId(Auth::user()->id);
    }


    public function postactivities(){
        return $this->hasMany('App\Activity','post_id');
    }

    public function commentactivities(){
        return $this->hasMany('App\Activity','comment_id');
    }


    public function delete()
    {
        $this->deleteRelatedData();
        return parent::delete();
    }



    /**
     * This will delete all posts related data like
     * Bookmarks
     * Likes
     * Comments
     * Activities
     * */
    public function deleteRelatedData(){
        $this->bookmarks()->delete();
        $this->likes()->delete();
        $this->postactivities()->delete();
        $this->commentactivities()->delete();
        $this->replies->each->delete();
    }

}

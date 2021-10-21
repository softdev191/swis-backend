<?php

namespace App;

use Illuminate\Notifications\Notifiable;
use Illuminate\Auth\Authenticatable;
use Laravel\Lumen\Auth\Authorizable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Tymon\JWTAuth\Contracts\JWTSubject;

use Illuminate\Support\Facades\Auth;
use DB;

class User extends Model implements JWTSubject, AuthenticatableContract, AuthorizableContract
{
    // use Authenticatable, Authorizable,Notifiable;
    use Authenticatable, Authorizable;
    protected $appends = ['auto_accept','followed','followers_count','followings_count','searches_count','follow_request_count'];

    protected $attributes = [
        'background_url' => '',
    ];
    
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name','username','email','password','dob','gender','token','phone','avatar','bio',
        'device_type','device_token','device_id','otp','background_url',
        'auto_select','hide_searched','hide_searches','hide_favourite','notification_like','city','country','pin',
        'notification_comment','notification_follow','share_location','text_color','share_local_search'
    ];

    public function getFollowersCountAttribute(){
        $id = $this->attributes['id'];
        $follower = DB::table('follows as f')->join('users as u','f.follower_id','=','u.id')->where('f.following_id',$id)->where('f.status','approved')->count();

        return $follower;
    }


    public function getFollowingsCountAttribute(){
        $id = $this->attributes['id'];
        $following = DB::table('follows as f')->join('users as u','f.following_id','u.id')->where('f.follower_id',$id)->where('f.status','approved')->count();
        return $following;
    }


    public function getSearchesCountAttribute(){
        $id = $this->attributes['id'];
        $search = DB::table('posts')->where('user_id',$id)->where('parent_post_id',null)->count();
        return $search;
    }


    public function getFollowRequestCountAttribute(){
        $id = $this->attributes['id'];
        $follow_request = Follow::where('following_id',$id)->where('status','pending')->count();
        return $follow_request;
    }

    public function getFollowedAttribute()//use model mutators
    {
        if ( ! array_key_exists('follow', $this->relations))
            $this->load('follow');
        $ownLike = $this->getRelation('follow');
        return count($ownLike) > 0;
    }


    public function getAvatarAttribute(){
        if($this->attributes['avatar'] != null && strlen($this->attributes['avatar'])>0){
            if(strpos($this->attributes['avatar'], 'https://api.swis.app/') !== false){//temporary
                return url(str_replace('https://api.swis.app/','',$this->attributes['avatar']))."?updated_at=".Carbon::parse($this->attributes['updated_at'])->timestamp;
            }
            if (strpos($this->attributes['avatar'], 'http://157.230.52.87:8000/') !== false) {//temporary
                return url(str_replace('http://157.230.52.87:8000/','',$this->attributes['avatar']))."?updated_at=".Carbon::parse($this->attributes['updated_at'])->timestamp;
            }
            return url($this->attributes['avatar'])."?updated_at=".Carbon::parse($this->attributes['updated_at'])->timestamp;
        }else{
            return url('avatar/default_profile.png');
        }
    }

    public function getBackgroundUrlAttribute(){
        if($this->attributes['background_url'] != null){
            if(strpos($this->attributes['background_url'], 'https://api.swis.app/') !== false){//temporary
                return url(str_replace('https://api.swis.app/','',$this->attributes['background_url']));
            }
            return url($this->attributes['background_url']);
        }else{
            return url('background/default_background_final.jpg');
        }
    }
    public function getHideSearchedAttribute(){
        return $this->attributes['hide_searched'] == "on";
    }

    public function getHideSearchesAttribute(){
        return $this->attributes['hide_searches'] == "on";
    }

    public function getShareLocalSearchAttribute(){
        return $this->attributes['share_local_search'] == 1;
    }
    public function getAutoAcceptAttribute(){
        if(array_key_exists("auto_select",$this->attributes)){
            return $this->attributes['auto_select'] == "on";
        }
        return null;
    }
    public function getNotificationLikeAttribute(){
        return $this->attributes['notification_like'] == "on";
    }
    public function getHideFavouriteAttribute(){
        return $this->attributes['hide_favourite'] == "on";
    }
    public function getNotificationCommentAttribute(){
        return $this->attributes['notification_comment'] == "on";
    }
    public function getNotificationFollowAttribute(){
        return $this->attributes['notification_follow'] == "on";
    }
    public function getShareLocationAttribute(){
        return $this->attributes['share_location'] == "on";
    }
    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [
        'password','token','device_id','device_token','updated_at','follow'
    ];

    /** Function to generate OTP */
    public static function generateNumericOTP($n){
        $generator = "1357902468";
        $result = ""; 
        for ($i = 1; $i <= $n; $i++) 
            $result .= substr($generator, (rand()%(strlen($generator))), 1); 
        return $result;
    }


    public function posts(){
        return $this->hasMany(Post::class,'user_id');
    }
    public function likes(){
        return $this->hasMany(Like::class,['user_id','post_id']);
    }

    public function favourites(){
        return $this->hasMany(Favourite::class,'user_id');
    }

    public function followings(){
        return $this->hasMany(Follow::class,'follower_id')->with('following_user');
    }

    public function channels(){
        return $this->hasMany(ChatRoom::class,'user_id');
    }


    public function getJWTIdentifier(){
        return $this->getKey();
    }

    public function getJWTCustomClaims(){
        return [];
    }

    public function follow(){
        $user = Auth::user();
        if($user == null){
            $user = $this;
        }
        return $this->hasMany('App\Follow','following_id')->where('follower_id',$user->id)->where('status','approved');
    }
}
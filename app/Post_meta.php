<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Post_meta extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        //
    ];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [
        'updated_at','bing_id'
    ];

    public function getDescriptionAttribute(){
        if($this->attributes['image'] != null && strlen($this->attributes['image'])>0){
            return "";
        }else{
            return $this->attributes['description'];
        }
    }

    public function getImageAttribute(){
        if($this->attributes['type'] == "raw_image" || $this->attributes['type'] == "raw_video" || $this->attributes['type'] == "thought"){
            return url($this->attributes['image']);
        }else{
            return $this->attributes['image'];
        }
    }

    public function getMediaAttribute(){
        if(isset($this->attributes['media']) && strlen($this->attributes['media']) > 0){
            return url($this->attributes['media']);
        }else{
            return $this->attributes['media'];
        }
    }

}

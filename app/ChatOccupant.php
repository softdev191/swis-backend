<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ChatOccupant extends Model
{
    protected $fillable = [
        'room','user_id','deleted'
    ];

    protected $hidden = [
        'created_at','updated_at'
    ];

    public function user(){
        return $this->belongsTo('App\User','user_id')->select('id', 'name', 'username', 'avatar','updated_at');
    }
}

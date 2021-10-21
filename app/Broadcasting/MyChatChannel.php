<?php

namespace App\Broadcasting;

use App\ChatOccupant;
use App\ChatRoom;
use App\User;
use BeyondCode\LaravelWebSockets\WebSockets\Channels\PresenceChannel;

class MyChatChannel
{
    /**
     * Create a new channel instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Authenticate the user's access to the channel.
     *
     * @param  \App\User  $user
     * @return array|bool
     */
    public function join(User $user, $roomId)
    {
        $isRoomExist = ChatOccupant::where('room',$roomId)->where('user_id',$user->id)->count() > 0;

        if($isRoomExist){
            return ['id' => $user->id, 'name' => $user->name];
        }

        return false;
    }
}

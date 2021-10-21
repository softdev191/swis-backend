<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('chat.{id}', \App\Broadcasting\MyChatChannel::class);

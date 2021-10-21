<?php

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class MyBroadcastServiceProvider extends ServiceProvider
{
    public function boot()
    {
        require base_path('routes/channels.php');
        Broadcast::channel('App.User.*', function ($user, $userId) {
            return true;
        });
    }
}

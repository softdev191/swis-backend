<?php

use BeyondCode\LaravelWebSockets\Facades\WebSocketsRouter;
use Illuminate\Broadcasting\BroadcastController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It is a breeze. Simply tell Lumen the URIs it should respond to
| and give it the Closure to call when that URI is requested.
|
*/

$router->get('/', function () use ($router) {
    return $router->app->version();});

$router->group(['prefix' => 'user'], function ($router) {

    $router->post('register', 'UserController@register');
    $router->post('login', 'UserController@login');
    $router->post('logout', 'UserController@logout');
    $router->post('otp/send','UserController@sendotp');
    $router->post('otp/verify','UserController@verifyotp');
    $router->post('password/reset','UserController@resetPassword');
    $router->get('users/search','UserController@searchUsers');

    $router->group(['prefix' => 'posts'], function ($router) {
        $router->post('media','PostController@saveMedia');

        $router->post('reply','PostController@reply');
        $router->post('toggle-like','PostController@like');
        $router->get('fetch-reply','PostController@fetchReply');
        $router->get('liked-users','PostController@fetchLikedUsers');
        $router->post('favourites','PostController@favourite');
        $router->get('fetch-favourites','PostController@fetchFavourites');
        $router->delete('delete_post','PostController@delPost');
        $router->delete('delete_website','PostController@delWebsite');
        $router->delete('delete_comment','PostController@delComment');
        $router->get('/{id}','PostController@fetchPostById');
        $router->get('{id}/bookmarks','PostController@bookmarkedUsers');


        $router->get('{id}/test','PostController@test');

        $router->group(['prefix' => '{id}/shares'], function ($router) {
            $router->get('/','PostShareController@sharedUsers');
            $router->post('add','PostShareController@addShare');
        });


        $router->group(['prefix' => '{id}/clicks'], function ($router) {
            $router->get('/','PostClickController@clickedUsers');
            $router->post('add','PostClickController@addClick');
        });


        $router->group(['prefix' => '{id}/views'], function ($router) {
            $router->get('/','PostViewController@viewedUsers');
            $router->post('add','PostViewController@addView');
        });

        $router->post('{id}/split','PostController@split');
    });

    $router->group(['prefix' => 'activities'], function ($router) {
        $router->get('/your','ActivityController@yourActivities');
        $router->get('/followers','ActivityController@followerActivities');
    });

    $router->get('fetch-posts','PostController@fetchPosts');

    /** User API */
    $router->get('username/{username}','UserController@fetchUserDetailsByUsername');
    $router->get('details','UserController@fetchUserDetails');
    $router->put('update-location','UserController@updateUserLocation');
    $router->post('verify/email','UserController@verifyEmail');
    $router->post('verify/username','UserController@verifyUsername');
    $router->post('verify/phone','UserController@verifyPhone');
    $router->post('update/avatar','UserController@updateAvatar');
    $router->post('update/profile','UserController@updateProfile');
    $router->post('update/background','UserController@updateBackground');

    /** Follow APIs */
    $router->post('follow-request','FollowController@followRequest');
    $router->get('pending-request-list','FollowController@fetchPendingFollowers');
    $router->put('approve-request','FollowController@ApproveRequest');
    $router->delete('decline-request','FollowController@DeclineRequest');
    $router->get('followers','FollowController@fetchApprovedFollowers');
    $router->get('followings','FollowController@fetchFollowings');
    $router->get('followings/feed','FollowController@fetchFollowingsFeed');
    $router->delete('unfollow','FollowController@unFollow');
    $router->get('recommended','FollowController@recommendedUser');
    $router->post('delete-recommended','FollowController@delRecommended');
    $router->post('remove_follower','FollowController@removeFollower');
    $router->put('auto_follow','FollowController@autoFollow');

    /** Bing Search APIs */
    $router->post('search','SearchController@webSearch');
    $router->post('save-search','SearchController@saveSearch');
    $router->post('search/trending','SearchController@trendingAPI');
    $router->get('search','SearchController@search_data');
    $router->get('search/businesses','SearchController@fetchLocalBusinesses');
    $router->get('scrap/image','SearchController@getImage');


    /* test API */
    $router->get('delete','UserController@delUser');
    $router->get('notification/test','UserController@testPush');

    $router->get('settings/{type}','StaticDataController@setting');
    $router->get('settings','StaticDataController@settings');
    $router->post('support','UserController@reportIssue');

    $router->get('friends','UserController@friends');



    $router->group(['prefix' => 'chats'], function ($router) {
        $router->post('/','ChatController@createChatRoom');
        $router->get('/','ChatController@getChatRooms');
        $router->get('/conversations','ChatController@getAllConversations');
        $router->delete('/{id}','ChatController@delChatRoom');
        $router->put('/{id}','ChatController@updateGroupName');
        $router->get('/{id}','ChatController@chatRoomDetails');
        $router->post('/sharepost','ChatMessageController@sharePost');
        $router->get('/{id}/messages','ChatMessageController@getMessageByRoom');
    });


    $router->group(['prefix' => 'files'], function ($router) {
        $router->post('/','FileController@addFile');
    });

    app('websockets.router')->webSocket('app/{appKey}', \App\Http\Controllers\MyCustomWebSocketHandler::class);


    $router->group(['middleware' => 'auth:api'], function () use ($router) {
        $router->post('broadcasting/auth', 'MyBroadcastController@authenticate');
        $router->get('broadcasting/auth', 'MyBroadcastController@authenticate');
    });

    $router->get('test','SearchController@test');
});
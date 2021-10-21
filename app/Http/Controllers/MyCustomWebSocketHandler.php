<?php

namespace App\Http\Controllers;

use App\ChatMessage;
use App\ChatOccupant;
use App\ChatRoom;
use Tymon\JWTAuth\JWTAuth;
use App\Events\NewMessage;
use BeyondCode\LaravelWebSockets\Apps\App;
use BeyondCode\LaravelWebSockets\QueryParameters;
use BeyondCode\LaravelWebSockets\WebSockets\Channels\ChannelManager;
use BeyondCode\LaravelWebSockets\WebSockets\Exceptions\UnknownAppKey;
use BeyondCode\LaravelWebSockets\WebSockets\Messages\PusherMessageFactory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Ratchet\ConnectionInterface;
use Ratchet\RFC6455\Messaging\MessageInterface;
use Ratchet\WebSocket\MessageComponentInterface;

class MyCustomWebSocketHandler implements MessageComponentInterface
{
    protected $channelManager;

    public function __construct(ChannelManager $channelManager)
    {
        $this->channelManager = $channelManager;
    }
    public function onOpen(ConnectionInterface $connection)
    {
        $this->verifyAppKey($connection)
            ->generateSocketId($connection)
            ->establishConnection($connection);


    }

    public function onClose(ConnectionInterface $connection)
    {
        $this->channelManager->removeFromAllChannels($connection);
    }

    public function onError(ConnectionInterface $connection, \Exception $exception)
    {
        if ($exception instanceof WebSocketException) {
            $connection->send(json_encode(
                $exception->getPayload()
            ));
        }

    }

    public function onMessage(ConnectionInterface $connection, MessageInterface $msg)
    {

        $message = PusherMessageFactory::createForMessage($msg, $connection, $this->channelManager);

        $message->respond();



        $this->saveMessage($connection,$msg);
    }


    protected function generateSocketId(ConnectionInterface $connection)
    {
        $socketId = sprintf('%d.%d', random_int(1, 1000000000), random_int(1, 1000000000));

        $connection->socketId = $socketId;

        return $this;
    }

    protected function establishConnection(ConnectionInterface $connection)
    {
        $connection->send(json_encode([
            'event' => 'pusher:connection_established',
            'data' => json_encode([
                'socket_id' => $connection->socketId,
                'activity_timeout' => 30,
            ]),
        ]));

        return $this;
    }

    protected function verifyAppKey(ConnectionInterface $connection)
    {
        $appKey = QueryParameters::create($connection->httpRequest)->get('appKey');

        if (! $app = App::findByKey($appKey)) {
            throw new UnknownAppKey($appKey);
        }

        $connection->app = $app;

        return $this;
    }

    private function saveMessage($connection,$message)
    {

        $payload = json_decode($message->getPayload());
        if($payload != null && $payload->event == 'client-message') {
            $channel = $payload->channel;
            $roomId = (int)explode(".", $channel)[1];
            $room = ChatRoom::where('id',$roomId)->first();
            if ($room != null) {
                ChatRoom::where('id',$roomId)->update(['deleted_by' => ""]);
                //$room->update(['deleted_by' => ""]);
                $data = json_decode($payload->data);
                if (isset($data->user_id)) {
                    //$occupantsArr = explode(",",$room->occupants);
                    $occupantsArr = ChatOccupant::where('room',$room->id)->pluck('user_id')->all();
                    $occupantsArr = array_diff($occupantsArr, array($data->user_id));
                    foreach ($occupantsArr as $receiver) {
                        (new SendPushNotification)->messageNotification($receiver, $roomId, $data->user_id ,$data->type ,isset($data->message) ? $data->message : "");
                    }
                    ChatMessage::create([
                        "room" => $roomId,
                        "sender" => $data->user_id,
                        "message" => isset($data->message) ? $data->message : "",
                        "media" => isset($data->media) ? $data->media : "",
                        "type" => isset($data->type) ? $data->type : "TEXT"
                    ]);
                }
            }
        }
    }
}
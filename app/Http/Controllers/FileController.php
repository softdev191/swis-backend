<?php

namespace App\Http\Controllers;

use App\ChatOccupant;
use App\ChatRoom;
use Illuminate\Broadcasting\PrivateChannel;
use Twilio\Exceptions\RestException;
use Twilio\Rest\Client;
use Tymon\JWTAuth\JWTAuth;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use validator;
use Illuminate\Support\Facades\Auth;
use DB;
use App\User;
use App\Follow;
use Illuminate\Support\Facades\Mail;
use Illuminate\Notifications\Notification;
use Carbon\Carbon;

class FileController extends Controller
{
    /**
     * @var \Tymon\JWTAuth\JWTAuth
     */
    public function __construct(JWTAuth $jwt, Mail $mail){
        $this->middleware('auth:api');
    }

    public function addFile(Request $request){
        $user = Auth::User();
        $supported_exts = array(// Will add more extension when required
            'jpg',
            'jpeg',
            'png',
            'mp4'
        );
        try{
            $category = $request->get('category'); //chats,posts,avatar,background
            if(!isset($category) || strlen($category) == 0){
                $category = "chats";
            }
            $type = $request->get('type'); //video or image
            $file = $request->file('file');

            if($file != null){
                $ext = $file->getClientOriginalExtension();
                if($ext != null && strlen($ext) > 0 && in_array($ext, $supported_exts)){

                    $newName = time().rand(0,9999999).'.'.$ext;
                    /** Save image in the given location */

                    $file->move(app()->basePath('public/'.$category.'/'.$type.'s'),$newName);
                    $image_url = url($category."/".$type."s/".$newName);

                    return response([
                        'responseCode' => Response::HTTP_OK,
                        'responseMessage' => "Success",
                        "url" => $image_url
                    ],Response::HTTP_OK);
                }else{
                    return response([
                        'responseCode' => Response::HTTP_BAD_REQUEST,
                        'responseMessage' => "Please upload valid image/video file."
                    ],Response::HTTP_OK);
                }

            }else{
                return response([
                    'responseCode' => Response::HTTP_BAD_REQUEST,
                    'responseMessage' => "file is empty"
                ],Response::HTTP_OK);
            }

        }catch (\Exception $e){
            return response([
                'responseCode' => Response::HTTP_BAD_REQUEST,
                'responseMessage' => $e->getMessage()
            ],Response::HTTP_OK);
        }
    }
}
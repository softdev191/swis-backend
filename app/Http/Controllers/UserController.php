<?php

namespace App\Http\Controllers;

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

class UserController extends Controller
{
    /**
     * @var \Tymon\JWTAuth\JWTAuth
     */
    protected $jwt;

    public function __construct(JWTAuth $jwt, Mail $mail){
        $this->jwt = $jwt;
        $this->middleware('auth:api',['except' => ['testPush','resetPassword','delUser','register', 'login','verifyEmail','verifyUsername','verifyPhone','sendotp','verifyotp']]);
        $this->mail = $mail;
    }

    /** User Register API */
    public function register(Request $request){

        try{
            $this->validate($request,[
                'name' => 'required|string|max:255',
                'username' => 'required|unique:users',
                'email' => 'required|email|max:255|unique:users',
                'password' => 'required|string|min:6',
                'device_type' => 'required',
                'device_token' => 'required',
                'device_id' => 'required'
            ]);
            $user = User::create([
                'name' => $request->name,
                'username' => $request->username,
                'email' => $request->email,
                'password' => Hash::make($request->password),
            ]);
            $credentials = $request->only(['email', 'password']);
            if (!$token = $this->jwt->attempt($credentials)) {
                return response()->json($arrayName = array('responseCode' => 401, 'responseMessage'=>"Invalid Credentials"));
            }
            $user->token = $token;
            $user->device_type =  $request->get('device_type');
            $user->device_token =  $request->get('device_token');
            $user->device_id =  $request->get('device_id');
            $user->text_color =  "#000000";
            $user->save();

            /** Send mail to user */
            try{
                $data =array('name'=>$request->name,'username' => $request->username,'email' => $request->email);
                Mail::send('emails.welcome',$data,function($message) use ($data)
                {
                    $message->from('support@swis.app', "SWIS");
                    $message->subject("Welcome");
                    $message->to($data['email']);
                });
            }catch (\Exception $e){

            }


            $user = User::find(Auth::User()->id);
            $user->api_token = $token;



            return response([
                'responseCode' => Response::HTTP_OK,
                'responseMessage' => "User successfully registered",
                'token_type' => 'Bearer',
                'user'=>$user,
                'chat_settings' => ['authorizer' => 'https://devapi.swis.app/user/broadcasting/auth','host' => 'devapi.swis.app', 'port' => '6001', 'apiKey' => '9f854238ece5e2811f1c3296ac8b5c7a']
            ],Response::HTTP_OK);
        }catch (\Exception $e){
            return response([
                'responseCode' => Response::HTTP_INTERNAL_SERVER_ERROR,
                'responseMessage' => $e->getMessage()
            ],Response::HTTP_OK);
        }

    }

    /** User Login API */
    public function login(Request $request){

        try{
            $this->validate($request, [
                'email'    => 'required|email|max:255',
                'password' => 'required',
            ]);
            $credentials = $request->only('email', 'password');
            if (Auth::attempt($credentials)) {
                $token = $this->jwt->attempt($credentials);
                $user = Auth::User();
                $user->device_type = $request->get('device_type');
                $user->device_token = $request->get('device_token');
                $user->device_id = $request->get('device_id');
                $user->save();

                $user = User::find(Auth::User()->id);
                $user->api_token = $token;

                return response([
                    'responseCode' => Response::HTTP_OK,
                    'responseMessage' => "Login Successfully",
                    'user' => $user,
                    'chat_settings' => ['authorizer' => 'https://devapi.swis.app/user/broadcasting/auth','host' => 'devapi.swis.app', 'port' => '6001', 'apiKey' => '9f854238ece5e2811f1c3296ac8b5c7a']
                ],Response::HTTP_OK);
            } else {
                return response([
                    'responseCode' => Response::HTTP_NOT_FOUND,
                    'responseMessage' => "Login credentials doesn't exist"
                ],Response::HTTP_OK);
            }
        }catch (\Exception $e){
            return response([
                'responseCode' => Response::HTTP_BAD_REQUEST,
                'responseMessage' => $e->getMessage()
            ],Response::HTTP_OK);
        }

    }

    /** Fetch User Details API */
    public function fetchUserDetails(Request $request){
        $userId = isset($request->user_id)?$request->user_id:Auth::User()->id;
        $checkFollow = isset($request->user_id)?true:false;

        /** Check the following_id exists or not */
        $check = User::find($userId);
        if(!$check){
            return response([
                'responseCode' => Response::HTTP_BAD_REQUEST,
                'responseMessage' => "This user not exists",
            ],Response::HTTP_OK);
        }

        /** Getting the details of the User */
        $details = User::where('id',$userId)->first();


        if($checkFollow){
            $isFollowed = Follow::where('follower_id',Auth::User()->id)->where('following_id',$userId)->count();
            $details->followed = $isFollowed?true:false;
        }
        $details->can_chat = Follow::where('follower_id',Auth::User()->id)->where('following_id',$userId)->count() > 0 || Follow::where('following_id',Auth::User()->id)->where('follower_id',$userId)->count() > 0;

        return response([
            'responseCode' => Response::HTTP_OK,
            'responseMessage' => "User Details",
            "user" => $details,
            'chat_settings' => ['authorizer' => 'https://devapi.swis.app/user/broadcasting/auth','host' => 'devapi.swis.app', 'port' => '6001', 'apiKey' => '9f854238ece5e2811f1c3296ac8b5c7a']
        ],Response::HTTP_OK);
    }


    /** Fetch User Details By Username API */
    public function fetchUserDetailsByUsername(Request $request, $username){
        $checkFollow = isset($username)?true:false;

        /** Check the following_id exists or not */
        $user = User::where('username',$username)->first();
        if(!$user){
            return response([
                'responseCode' => Response::HTTP_BAD_REQUEST,
                'responseMessage' => "This user does not exist",
            ],Response::HTTP_OK);
        }


        if($checkFollow){
            $isFollowed = Follow::where('follower_id',Auth::User()->id)->where('following_id',$user->id)->count();
            $user->followed = $isFollowed?true:false;
        }


        return response([
            'responseCode' => Response::HTTP_OK,
            'responseMessage' => "User Details",
            "user" => $user,
        ],Response::HTTP_OK);
    }

    /** Update User Location */
    public function updateUserLocation(Request $request){
        $user = Auth::User();

        $user->address = isset($request->address)?$request->address:$user->address;
        $user->zip = isset($request->zip)?$request->zip:$user->zip;
        $user->latitude = isset($request->latitude)?$request->latitude:$user->latitude;
        $user->longitude = isset($request->longitude)?$request->longitude:$user->longitude;
        $user->save();

        return response([
            'responseCode' => Response::HTTP_OK,
            'responseMessage' => "User Location Updated",
            'user' => $user,
            'chat_settings' => ['authorizer' => 'https://devapi.swis.app/user/broadcasting/auth','host' => 'devapi.swis.app', 'port' => '6001', 'apiKey' => '9f854238ece5e2811f1c3296ac8b5c7a']

        ],Response::HTTP_OK);
    }

    /** Verify email api - without auth */
    public function verifyEmail(Request $request){
        $this->validate($request, [
            'email' => 'required|email',
        ]);
        $email = $request->email;
        $checkEmail = User::where('email',$email)->count();
        if($checkEmail != 0){
            $code = Response::HTTP_FOUND;
            $message = "Email already exists";
        }else{
            $code = Response::HTTP_OK;
            $message = "Success";
        }
        return response([
            'responseCode' => $code,
            'responseMessage' => $message
        ],$code);
    }

    /** Verify username api - without auth */
    public function verifyUsername(Request $request){
        $this->validate($request, [
            'username' => 'required',
        ]);
        $username = $request->username;
        $checkUsername = User::where('username',$username)->count();
        if($checkUsername != 0){
            $code = Response::HTTP_FOUND;
            $message = "Username already exists";
        }else{
            $code = Response::HTTP_OK;
            $message = "Success";
        }
        return response([
            'responseCode' => $code,
            'responseMessage' => $message
        ],$code);
    }

    /** Verify Phone API - without auth */
    public function verifyPhone(Request $request){
        $this->validate($request, [
            'phone' => 'required|min:10',
        ]);
        $phone = $request->phone;
        $checkPhone = User::where('phone',$phone)->count();
        if($checkPhone != 0){
            $code = Response::HTTP_FOUND;
            $message = "Phone Number Already Exists";
        }else{
            $code = Response::HTTP_OK;
            $message = "Success";
        }
        return response([
            'responseCode' => $code,
            'responseMessage' => $message
        ],Response::HTTP_OK);
    }

    /** Update Profile API */
    public function updateProfile(Request $request){
        try{
            $user = Auth::user();

            if(isset($request->email)){
                if(User::where('email',$request->email)->where('id', '<>', $user->id)->count()>0){
                    return response([
                        'responseCode' => Response::HTTP_BAD_REQUEST,
                        'responseMessage' => "This email is already in use."
                    ],Response::HTTP_OK);
                }
            }

            if(isset($request->auto_accept))
                $request->merge(['auto_select' => $request->auto_accept]);
            if(isset($request->hide_searches) && strlen($request->hide_searches)>0 && $request->hide_searches == "on"){
                $request->merge(['share_local_search' => "0"]);
            }
            Auth::User()->update($request->all());

            $user = User::where('id',$user->id)->first();

            return response([
                'responseCode' => Response::HTTP_OK,
                'responseMessage' => "Updated successfully",
                'user' => $user,
                'chat_settings' => ['authorizer' => 'https://devapi.swis.app/user/broadcasting/auth','host' => 'devapi.swis.app', 'port' => '6001', 'apiKey' => '9f854238ece5e2811f1c3296ac8b5c7a']

            ],Response::HTTP_OK);
        }catch (\Exception $e){
            return response([
                'responseCode' => Response::HTTP_BAD_REQUEST,
                'responseMessage' => $e->getMessage()
            ],Response::HTTP_OK);
        }

    }

    /** Update Avatar API */
    public function updateAvatar(Request $request){
        $user = Auth::User();
        try{


            $avatar = $request->file('avatar');
            if($avatar != null){
                /** generate random name of uploaded image */
                $newName = "avatar_".$user->id.'.'.$avatar->getClientOriginalExtension();
                /** Save image in the given location */
                $avatar->move(app()->basePath('public/avatar/'),$newName);
                $user->avatar = "avatar/".$newName;
            }
            $user->updated_at = Carbon::now();
            $user->save();
            return response([
                'responseCode' => Response::HTTP_OK,
                'responseMessage' => "Profile picture updated successfully",
                'user' => User::where('id',Auth::user()->id)->first(),
                'chat_settings' => ['authorizer' => 'https://devapi.swis.app/user/broadcasting/auth','host' => 'devapi.swis.app', 'port' => '6001', 'apiKey' => '9f854238ece5e2811f1c3296ac8b5c7a']

            ],Response::HTTP_OK);
        }catch (\Exception $e){
            return response([
                'responseCode' => Response::HTTP_BAD_REQUEST,
                'responseMessage' => $e->getMessage()
            ],Response::HTTP_OK);
        }
    }

    /** Update Background API */
    public function updateBackground(Request $request){
        $user = Auth::User();
        $this->validate($request, [
            'background' => 'image|mimes:jpeg,png,jpg,gif|max:10240'
        ]);
        $background = $request->file('background');
        if($background != null){
            /** generate random name of uploaded image */
            $newName = "background_".$user->id.'.'.$background->getClientOriginalExtension();
            /** Save image in the given location */
            $background->move(app()->basePath('public/background/'),$newName);
            $user->background_url = "background/".$newName;
        }
        $user->save();
        return response([
            'responseCode' => Response::HTTP_OK,
            'responseMessage' => "Background changed successfully",
            'user' => User::where('id',Auth::user()->id)->first(),
            'chat_settings' => ['authorizer' => 'https://devapi.swis.app/user/broadcasting/auth','host' => 'devapi.swis.app', 'port' => '6001', 'apiKey' => '9f854238ece5e2811f1c3296ac8b5c7a']

        ],Response::HTTP_OK);
    }

    /** Function to send OTP */
    public function sendotp(Request $request){
        if(env('APP_ENV', '') == 'dev'){
            $otp = 1234;
        }else {
            $otp = User::generateNumericOTP(4);
        }
        if(isset($request->old_user) && $request->old_user == 1){
            $user = User::where('phone', $request->phone)->first();
        }else {
            $user = Auth::user();
        }
        try{
            $phoneUser = User::where('phone', $request->phone)->get();
            if(sizeof($phoneUser) == 0){
                if(isset($request->old_user) && $request->old_user == 1){
                    return response([
                        'responseCode' => Response::HTTP_BAD_REQUEST,
                        'responseMessage' => "Number not registered."
                    ],Response::HTTP_OK);
                }
                User::where('id', $user->id)->update(['otp'=>$otp]);
                $smsResponse = $this->sendSMS("+".$request->phone,"SWIS Code is ".$otp);
                if(!is_numeric($smsResponse)){
                    return response([
                        'responseCode' => Response::HTTP_BAD_REQUEST,
                        'responseMessage' => $smsResponse
                    ],Response::HTTP_OK);
                }
                return response([
                    'responseCode' => Response::HTTP_OK,
                    'responseMessage' => "Your OTP code is ".$otp,
                    'otp' => $otp
                ],Response::HTTP_OK);
            }else{
                if(isset($request->old_user) && $request->old_user == 1){
                    User::where('id', $user->id)->update(['otp'=>$otp]);
                    $smsResponse = $this->sendSMS("+".$request->phone,"SWIS Code is ".$otp);
                    if(!is_numeric($smsResponse)){
                        return response([
                            'responseCode' => Response::HTTP_BAD_REQUEST,
                            'responseMessage' => $smsResponse,
                            'otp' => $otp
                        ],Response::HTTP_OK);
                    }
                    return response([
                        'responseCode' => Response::HTTP_OK,
                        'responseMessage' => "Your OTP code is ".$otp,
                        'otp' => $otp
                    ],Response::HTTP_OK);
                }
                return response([
                    'responseCode' => Response::HTTP_BAD_REQUEST,
                    'responseMessage' => "Phone already exits"
                ],Response::HTTP_OK);
            }
        }catch (\Exception $e){
            return response([
                'responseCode' => Response::HTTP_BAD_REQUEST,
                'responseMessage' => $e->getMessage()
            ],Response::HTTP_OK);
        }

    }

    private function sendSMS($phone, $sms){
        try{
            $client = new Client(env("TWILIO_SID","AC597fbfaad137771931fbe97ff4cb1fb6"),env("TWILIO_TOKEN","e702a0a922388666d27be5db1cbfbdbf"));

            try{
                $client->messages->create(
                    $phone,
                    array(
                        'from' => env("TWILIO_NUMBER","+15005550006"),
                        'body' => $sms
                    )
                );
            }catch (RestException $exception){
                return "Your phone number is not valid.";
            }

            return "200";
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    /** Function of OTP verification */
    public function verifyotp(Request $request){
        $user = User::where('phone', $request->phone)->first();
        if($user == null){
            $user = Auth::user();
        }
        if($user->otp == $request->otp){
            $user->phone = $request->phone;
            $user->save();
            $user->api_token = $user->token;
            return response([
                'responseCode' => Response::HTTP_OK,
                'responseMessage' => "OTP verified Successfully",
                'user' => $user,

            ],Response::HTTP_OK);
        }else{
            return response([
                'responseCode' => Response::HTTP_BAD_REQUEST,
                'responseMessage' => "OTP is invalid"
            ],Response::HTTP_OK);
        }
    }

    /** Logout Function */
    public function logout(Request $request){
        try{

            return response([
                'responseCode' => Response::HTTP_OK,
                'responseMessage' => "Logout Successfully",
            ],Response::HTTP_OK);
        }catch (\Exception $e){
            return response([
                'responseCode' => Response::HTTP_BAD_REQUEST,
                'responseMessage' => $e->getMessage(),
            ],Response::HTTP_OK);
        }

    }

    /** Reset Password */
    public function resetPassword(Request $request){
        $this->validate($request, [
            'phone' => 'required|min:10',
            'password'   => 'required',
        ]);
        $phone = $request->get('phone');
        $password = Hash::make($request->password);
        User::where('phone',$phone)->update(['password' => $password]);
        return response([
            'responseCode' => Response::HTTP_OK,
            'responseMessage' => "Password Reset Successfully",
        ],Response::HTTP_OK);
    }

    /** Delete User */
    public function delUser(Request $request){
        $phone = $request->get('phone');
        $email = $request->get('email');
        $check = User::where('phone', $phone)->orWhere('email', $email)->delete();
        if($check == 1){
            return response([
                'responseCode' => Response::HTTP_OK,
                'responseMessage' => "User Deleted"
            ],Response::HTTP_OK);
        }else{
            return response([
                'responseCode' => Response::HTTP_NOT_FOUND,
                'responseMessage' => "User Not Found"
            ],Response::HTTP_OK);
        }
    }

    public function testPush(Request $request){
        try{
            $response = (new SendPushNotification)->followNotification($request->user_id);
            return response([
                'responseCode' => Response::HTTP_OK,
                'responseMessage' => "",
                'data'=>json_decode($response,true)
            ],Response::HTTP_OK);
        }catch (\Exception $e){
            return response([
                'responseCode' => Response::HTTP_OK,
                'responseMessage' => $e->getMessage()
            ],Response::HTTP_OK);
        }

    }

    public function reportIssue(Request $request){
        /** Send mail to user */
        try{
            $user = Auth::user();

            $data =array('name'=>$user->name,'id' => $user->id,'email' => $user->email,'message_part' => $request->message);
            Mail::send('emails.support',$data,function($message) use ($data)
            {
                $message->from($data['email'], $data['name']);
                $message->subject("SWIS - Reported by ".$data['name']);
                $message->to('support@swis.app');
            });
            return response([
                'responseCode' => Response::HTTP_OK,
                'responseMessage' => "Your query has been successfully submited."
            ],Response::HTTP_OK);

        }catch (\Exception $e){
            return response([
                'responseCode' => Response::HTTP_BAD_REQUEST,
                'responseMessage' => $e->getMessage()
            ],Response::HTTP_OK);
        }

    }


    public function searchUsers(Request $request){
        $user = Auth::User();
        $limit = isset($request->page_limit) ? $request->page_limit : 10;
        $page = isset($request->page) ? $request->page : 0;
        $query = isset($request->query)?$request->get('query'):'';



        $users = User::select('id', 'name', 'username', 'avatar','updated_at')->where('name', 'LIKE', '%'.$query.'%')
            ->orWhere('username', 'LIKE', '%'.$query.'%');

        $totalPage = floor((int)$users->count()/$limit) +($users->count()%$limit>0?1:0);

        $users = $users->skip($page * $limit)->take($limit)->get()->makeHidden('auto_accept');

        return response([
            'responseCode' => Response::HTTP_OK,
            'responseMessage' => "",
            'nextPage' => $page+1,
            'total_page' => $totalPage,
            'users' => $users
        ],Response::HTTP_OK);
    }

    public function friends(Request $request){
        $user = Auth::User();
        $limit = isset($request->page_limit) ? $request->page_limit : 50;
        $page = isset($request->page) ? $request->page : 0;
        $searchStr = $request->get('query');
        $topUserId = isset($request->top_user_id)?$request->top_user_id:"";

        /** Fetch The followers who status is approved */
        $followingIds = Follow::whereRaw("follower_id = $user->id")->where('status','approved')->pluck('following_id')->all();
        $followerIds = Follow::whereRaw("following_id = $user->id")->where('status','approved')->pluck('follower_id')->all();
        $friendsIds = array_values(array_unique(array_merge($followingIds,$followerIds)));
        $friends = User::whereIn('id',$friendsIds)
            ->where(function ($query) use ($searchStr){
                $query->where('name','like',"%".$searchStr."%")->orWhere('username','like',"%".$searchStr."%");
            })
            ->where('id','<>',$topUserId)
            ->select('id', 'name', 'username','bio', 'avatar','updated_at')
            ->orderBy('name', 'ASC');

        $totalPage = floor((int)$friends->count()/$limit) +($friends->count()%$limit>0?1:0);
        $friends = $friends->skip($page * $limit)->take($limit)->get()->makeHidden(['auto_accept','follow_request_count','searches_count','followers_count','followings_count','followed']);

        if($page == 0){
            $topUser = User::where('id',$topUserId)->where(function ($query) use ($searchStr){
                $query->where('name','like',"%".$searchStr."%")->orWhere('username','like',"%".$searchStr."%");
            })
                ->select('id', 'name', 'username','bio', 'avatar','updated_at')->first();
            if($topUser != null){
                $topUser =  $topUser->makeHidden(['auto_accept','follow_request_count','searches_count','followers_count','followings_count','followed']);
                $friends->prepend($topUser);
            }
        }

        return response([
            'responseCode' => Response::HTTP_OK,
            'responseMessage' => "",
            'nextPage' => $page+1,
            'total_page' => $totalPage,
            'friends' => $friends
        ],Response::HTTP_OK);
    }
}
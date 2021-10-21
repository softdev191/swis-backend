<?php
namespace App\Http\Controllers;

use App\Post;
use App\User;
use Carbon\Carbon;
use DateInterval;
use DateTime;
use DOMDocument;
use DOMXPath;
use Exception;
use function GuzzleHttp\Psr7\str;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Validator;
use Auth;
use DB;
use App\Post_meta;
use Sunra\PhpSimple\HtmlDomParser;

class SearchController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth',['except' => ['getImage']]);
    }
    /**
     * Search API Function
     */
    public function webSearch(Request $request){
        /* Check that the data comming are right or wrong */

        try{
            $validator = Validator::make($request->all(), [
                'type' => 'required',
                'count' => 'int',
                'offset' => 'int',
            ]);
            if ($validator->fails()){return $validator->messages();}

            /* Getting request values in variables */
            $qry = $request->get('query',"");
            $getType = $request->type;
            $count = 10;
            $offset = (isset($request->offset)?$request->offset:0)*10;
            $userId = Auth::User()->id;



            switch ($getType){
                case 'text': $type = '';break;
                case 'news': $type = 'news/';break;
                case 'image': $type = 'images/';break;
                case 'video': $type = 'videos/';break;
                case 'map': return "Map API not exists";break;
                default: $type = '';break;
            }

            $accessKey = '133b113e6a4549a9bce79a6233259536';
            $endpoint = 'https://api.cognitive.microsoft.com/bing/v7.0/'.$type."search";
            $term  = $qry;

            function BingWebSearch ($url, $key, $query, $count, $offset) {
                $headers = "Ocp-Apim-Subscription-Key: $key\r\nAccept-Language: en\r\n";
                $options = array ('http' => array (
                    'header' => $headers,
                    'method' => 'GET'));
                $context = stream_context_create($options);
                $finalUrl =  $url . "?q=" . urlencode($query)."&count=".$count."&offset=".$offset;
                if((!isset($query) || strlen($query)==0)){
                    $finalUrl = $finalUrl."&mkt=en-us";
                }
                $result = file_get_contents($finalUrl, false, $context);
                $headers = array();
                foreach ($http_response_header as $k => $v) {
                    $h = explode(":", $v, 2);
                    if (isset($h[1]))
                        if (preg_match("/^BingAPIs-/", $h[0]) || preg_match("/^X-MSEdge-/", $h[0]))
                            $headers[trim($h[0])] = trim($h[1]);
                }
                return array($headers, $result);
            }

            if (strlen($accessKey) >0) {
                list($headers, $json) = BingWebSearch($endpoint, $accessKey, $term, $count, $offset);

                $data = json_decode($json);
                $elementsArray = array();

                if($data == null){
                    return response([
                        'responseCode' => Response::HTTP_BAD_REQUEST,
                        'responseMessage' => "No data found",
                    ],Response::HTTP_OK);
                }
                switch ($getType){
                    case 'text':
                        $values = $data->webPages->value;

                        $totalPages = floor((int)$data->webPages->totalEstimatedMatches/$count) +($data->webPages->totalEstimatedMatches%$count>0?1:0);

                        foreach ($values as $key => $value){
                            $description = $value->snippet;
                            $idDesc = "";
                            try{
                                $idDesc = substr($description , 0, 20);
                                if(json_encode($idDesc) == false){
                                    $idDesc = substr(preg_replace("/[^a-zA-Z0-9]+/", "", $description) , 0, 20);
                                }
                            }catch (\Exception $e){
                                $idDesc = substr(preg_replace("/[^a-zA-Z0-9]+/", "", $description) , 0, 20);
                            }
                            $id = $value->name.$idDesc;
                            $array = array('id' => $id,'title' => $value->name, 'website'=>$value->url,'description'=>$description);
                            $elementsArray[$key] = $array;
                        }

                        $videos = array();
                        if(isset($data->videos)){
                            $values = $data->videos->value;
                            foreach ($values as $key => $value){
                                $linkExtracts = explode("&mid=", $value->webSearchUrl);
                                $videoId = $linkExtracts[1];
                                $array = array(
                                    "id" => $videoId,
                                    'title' => $value->name,
                                    'website'=>$value->contentUrl,
                                    'description'=>$value->description,
                                    'thumbnailUrl' => $value->thumbnailUrl,
                                    'datetime' => $this->durationFull(isset($value->datePublished)?$value->datePublished:null),
                                    'provider'=>($value->publisher != null && sizeof($value->publisher)>0)?$value->publisher[0]->name:"",
                                    'video_duration'=>isset($value->duration)?$this->videoDuration($value->duration):"",
                                );
                                if(sizeof($videos)<6)
                                    array_push($videos, $array);
                            }
                        }

                        // NEWS-------
                        $news = array();
                        if(isset($data->news) && isset($data->news->value) && sizeof($data->news->value)>0){
                            $values = $data->news->value;
                            foreach ($values as $key => $value){
                                $description = $value->description;
                                $idDesc = "";
                                try{
                                    $idDesc = substr($description , 0, 20);
                                    if(json_encode($idDesc) == false){
                                        $idDesc = substr(preg_replace("/[^a-zA-Z0-9]+/", "", $description) , 0, 20);
                                    }
                                }catch (\Exception $e){
                                    $idDesc = substr(preg_replace("/[^a-zA-Z0-9]+/", "", $description) , 0, 20);
                                }
                                $id = $value->name.$idDesc;
                                $array = array(
                                    'id' => utf8_encode($id),
                                    'title' => utf8_encode($value->name),
                                    'datetime' => $this->duration(isset($value->datePublished)?$value->datePublished:null),
                                    'website'=>$value->url,
                                    'provider'=>($value->provider != null && sizeof($value->provider)>0)?$value->provider[0]->name:"",
                                    'provider_icon'=>($value->provider != null && sizeof($value->provider)>0 && isset($value->provider[0]->image))?$value->provider[0]->image->thumbnail->contentUrl:"",
                                    'description'=>utf8_encode($value->description),
                                    'image' => $this->generateBiggerImage(isset($value->image)?$value->image->thumbnail:null)
                                );
                                array_push($news, $array);
                            }
                        }


                        return response([
                            'responseCode' => Response::HTTP_OK,
                            'responseMessage' => "Search Results",
                            "searchQuery" => $data->queryContext->originalQuery,
                            "searchType" => 'text',
                            "total_page" => $totalPages,
                            "nextOffset" => $request->offset+1,
                            "count" => $count,
                            "searchResults" => $elementsArray,
                            "videos" => $videos,
                            "news" => $news
                        ],Response::HTTP_OK);
                    case 'news':
                        $values = $data->value;
                        $totalPages = 10;
                        if(isset($data->totalEstimatedMatches)){
                            $totalPages = floor((int)$data->totalEstimatedMatches/$count) +($data->totalEstimatedMatches%$count>0?1:0);

                        }
                        foreach ($values as $key => $value){
                            $description = $value->description;
                            $idDesc = "";
                            try{
                                $idDesc = substr($description , 0, 20);
                                if(json_encode($idDesc) == false){
                                    $idDesc = substr(preg_replace("/[^a-zA-Z0-9]+/", "", $description) , 0, 20);
                                }
                            }catch (\Exception $e){
                                $idDesc = substr(preg_replace("/[^a-zA-Z0-9]+/", "", $description) , 0, 20);
                            }
                            $id = $value->name.$idDesc;
                            $array = array(
                                'id' => utf8_encode($id),
                                'title' => utf8_encode($value->name),
                                'type' => $getType,
                                'datetime' => $this->duration(isset($value->datePublished)?$value->datePublished:null),
                                'website'=>$value->url,
                                'provider'=>($value->provider != null && sizeof($value->provider)>0)?$value->provider[0]->name:"",
                                'provider_icon'=>($value->provider != null && sizeof($value->provider)>0 && isset($value->provider[0]->image))?$value->provider[0]->image->thumbnail->contentUrl:"",
                                'description'=>utf8_encode($value->description),
                                'image' => $this->generateBiggerImage(isset($value->image)?$value->image->thumbnail:null)
                            );
                            $elementsArray[$key] = $array;
                        }

                        return response([
                            'responseCode' => Response::HTTP_OK,
                            'responseMessage' => "Search Results",
                            "searchQuery" => isset($data->queryContext)?$data->queryContext->originalQuery:"",
                            "searchType" => 'news',
                            "total_page" => $totalPages,
                            "nextOffset" => (isset($term) && strlen($term)>0)?$request->offset+1:$request->offset,
                            "count" => $count,
                            "searchResults" => $elementsArray
                        ],Response::HTTP_OK);
                    case 'image':

                        $values = $data->value;
                        $totalPages = floor((int)$data->totalEstimatedMatches/$count) +($data->totalEstimatedMatches%$count>0?1:0);

                        foreach ($values as $key => $value){
                            $array = array(
                                'id' => $value->imageId,
                                'title' => $value->name,
                                'website'=>$value->webSearchUrl,
                                'image' => $value->contentUrl,
                            );
                            $elementsArray[$key] = $array;
                        }
                        return response([
                            'responseCode' => Response::HTTP_OK,
                            'responseMessage' => "Search Results",
                            "searchQuery" => $data->queryContext->originalQuery,
                            "searchType" => 'image',
                            "total_page" => $totalPages,
                            "nextOffset" => $request->offset+1,
                            "count" => $count,
                            "searchResults" => $elementsArray
                        ],Response::HTTP_OK);
                    case 'video':
                        $values = $data->value;
                        $totalPages = floor((int)$data->totalEstimatedMatches/$count) +($data->totalEstimatedMatches%$count>0?1:0);
                        foreach ($values as $key => $value){
                            $array = array(
                                "id" => $value->videoId,
                                'title' => $value->name,
                                'website'=>$value->contentUrl,
                                'description'=>$value->description,
                                'thumbnailUrl' => $value->thumbnailUrl,
                            );
                            $elementsArray[$key] = $array;
                        }
                        return response([
                            'responseCode' => Response::HTTP_OK,
                            'responseMessage' => "Search Results",
                            "searchQuery" =>isset($data->queryContext)?$data->queryContext->originalQuery:"",
                            "searchType" => 'video',
                            "total_page" => $totalPages,
                            "nextOffset" => $request->offset+1,
                            "count" => $count,
                            "searchResults" => $elementsArray
                        ],Response::HTTP_OK);
                    case 'map':
                        $type = 'maps/';
                        break;
                    default:
                        $type = '/';
                        break;
                }
            } else {
                return response([
                    'responseCode' => Response::HTTP_BAD_REQUEST,
                    'responseMessage' => "Server error",
                ],Response::HTTP_OK);
            }
        }catch (\Exception $e){
            return response([
                'responseCode' => Response::HTTP_BAD_REQUEST,
                'responseMessage' => "No data found",
            ],Response::HTTP_OK);
        }
    }

    /**
     * Save Search API Function
     */
    public function saveSearch(Request $request){

        $user = Auth::user();
        if($user->hide_searches){
            return response([
                'responseCode' => Response::HTTP_OK,
                'responseMessage' => "Success"
            ],Response::HTTP_OK);
        }
        try{
            $validator = Validator::make($request->all(), [
                'type' => 'required',
                'website' => 'required',
                'journey_id' => 'required',
            ]);
            if ($validator->fails()) {
                return $validator->messages();
            }

            $url = $request->get('website');
            if(strpos($url,"youtube") !== false){
                if(!((strpos($url,"search_query=") !== false && strlen(explode("?search_query=",$url)[1]) > 0) || strpos($request->get('website'),"watch?v") !== false || strpos($request->get('website'),"youtube.com/user") !== false)){
                    return response([
                        'responseCode' => Response::HTTP_OK,
                        'responseMessage' => "Wrong url",
                    ], Response::HTTP_OK);
                }
            }
            if(strpos($url,"amazon.com") !== false){
                $amazonExtract = explode("www.amazon.com",$url);
                if(strlen($amazonExtract[1]) == 0 || $amazonExtract[1] == "/" ||  $amazonExtract[1] == "/s?k=" ){
                    return response([
                        'responseCode' => Response::HTTP_OK,
                        'responseMessage' => "Wrong url",
                    ], Response::HTTP_OK);
                }
            }


            $metaData = $this->getSiteMetaData($url);

            $journeyId = $request->get('journey_id');
            $title = strlen($request->get('title')) > 0?$request->get('title'):(array_key_exists("title",$metaData)?$metaData['title']:"");
            $image = strlen($request->get('image')) > 0?$request->get('image'):(array_key_exists("image",$metaData)?$metaData['image']:"");
            $description = strlen($request->get('description')) > 0 ?$request->get('description'):(array_key_exists("description",$metaData)?$metaData['description']:"");
            if(strpos($request->get('website'),"watch?v") !== false){
                $request->type = "video";
            }

            if(strlen($title) == 0 && strlen($description) == 0 && strlen($image) == 0){
                return response([
                    'responseCode' => Response::HTTP_OK,
                    'responseMessage' => "Wrong url",
                ], Response::HTTP_OK);
            }

            if(strpos($title,"Something went wrong") !== false){
                $title = $this->getTitleFromDescription($description);
            }

            $searchTerm = strlen($request->get('query'))>0?$request->get('query'):$title;
            $user = Auth::User();
            $userId = $user->id;

            $getPostDetails = db::table('posts')->where('journey_id',$journeyId)->where('user_id',$userId);
            $postCount = $getPostDetails->count();
            if($postCount == 0){
                $post = new POST;
                $post->user_id = Auth::User()->id;
                $post->search_term = $searchTerm; // No need , we can remove later
                $post->journey_id = $journeyId;
                $post->latitude = $user->latitude;
                $post->longitude = $user->longitude;
                $post->save();
                $postId = $post->id;
            }else{
                $getPostDetails = $getPostDetails->first();
                $postId = $getPostDetails->id;
            }
            $bingId = strlen($request->get('bing_id'))>0?$request->get('bing_id'):md5($request->get('website'));


            $getPostMetaDetails = db::table('post_metas')->where('post_id',$postId)->where('bing_id',$bingId);
            $countPostMeta = $getPostMetaDetails->count();
            $getPostMetaDetails = $getPostMetaDetails->get();
            if($countPostMeta == 0){

                $postmeta = new Post_meta;
                $postmeta->post_id = $postId;
                $postmeta->website = $request->get('website');
                $postmeta->title = $title;
                $postmeta->description = $description;
                $postmeta->search_term = $searchTerm;
                $postmeta->bing_id = $bingId;
                $postmeta->type = isset($request->type)?$request->type:null;
                $postmeta->public_search = $user->share_local_search;
                if(strlen($image) > 0){
                    $postmeta->image = $image;
                }else {
                    $postmeta->image = strlen($description) > 0?"":url('background/default_search.png');
                    $postmeta->content = $description;
                }

                $postmeta->save();
                return response([
                    'responseCode' => Response::HTTP_OK,
                    'responseMessage' => "Success",
                    "postMeta" => $postmeta
                ],Response::HTTP_OK);
            }
            return response([
                'responseCode' => Response::HTTP_OK,
                'responseMessage' => "Success",
                "postMeta" => $getPostMetaDetails
            ],Response::HTTP_OK);
        }catch (\Exception $e) {
            return response([
                'responseCode' => Response::HTTP_OK,
                'responseMessage' => $e->getMessage(),
            ], Response::HTTP_OK);
        }
    }

    /**
     * Trending API function
     */
    public function trendingAPI(Request $request){

        try{
            $this->validate($request,[
                'type' => 'required',
                'count' => 'int',
                'offset' => 'int',
                'country_code' => 'string'
            ]);
            /* Setting request values in variables */
            $getType = $request->get('type');
            $countryCode = $request->get('country_code','en-us');
            $count = 10;

            switch ($getType){
                case 'news':
                    //bypassing using yahoo news RSS
                    return $this->yahooRSS("https://news.yahoo.com/rss","news",$request->get('offset',0));
                    //$request->offset = $request->offset+1;
                    //return $this->webSearch($request);
                    break;
                case 'all':
                    //bypassing using yahoo news RSS
                    return $this->yahooRSS("https://news.yahoo.com/rss/mostviewed","all",$request->get('offset',0));
                    break;
                case 'image': $type = 'images/';break;
                case 'video': $type = 'videos/';break;
                default: $type = 'news/';break;
            }
            $offset = $request->get('offset',0)*$count;

            $accessKey = '133b113e6a4549a9bce79a6233259536';
            $endpoint = 'https://api.cognitive.microsoft.com/bing/v7.0/'.$type.'trending?mkt='.$countryCode;
            if($getType == 'all'){
                $endpoint = 'https://api.cognitive.microsoft.com/bing/v7.0/'.$type.'?mkt='.$countryCode;
            }
            function BingWebSearch2 ($url, $key, $count, $offset) {
                $headers = "Ocp-Apim-Subscription-Key: $key\r\n";
                $options = array ('http' => array (
                    'header' => $headers,
                    'method' => 'GET'));
                $context = stream_context_create($options);
                $result = file_get_contents($url."&count=".$count."&offset=".$offset, false, $context);
                $headers = array();
                foreach ($http_response_header as $k => $v) {
                    $h = explode(":", $v, 2);
                    if (isset($h[1]))
                        if (preg_match("/^BingAPIs-/", $h[0]) || preg_match("/^X-MSEdge-/", $h[0]))
                            $headers[trim($h[0])] = trim($h[1]);
                }
                return array($headers, $result);
            }

            list($headers, $json) = BingWebSearch2($endpoint, $accessKey, $count, $offset);
            $data = json_decode($json);

            switch ($getType){

                case 'all':
                    $elementsArray = array();
                    $values = $data->value;
                    $totalPage = 1;
                    foreach ($values as $key => $value){
                        $description = $value->description;
                        $id = $value->name.(strlen(json_encode(substr($description , 0, 20)))> 8 ?substr($description , 0, 20):"");
                        $array = array(
                            'id' => $id,
                            'title' => $value->name,
                            'website'=>$value->url,
                            'description'=>$description,
                            'image' => str_replace("&pid=News","",(isset($value->image) && $value->image!=null)?$value->image->thumbnail->contentUrl:""),
                            'type' => $getType,
                            'provider' => (isset($value->provider) && sizeof($value->provider)>0)?$value->provider[0]->name:""
                        );
                        array_push($elementsArray,$array);
                    }
                    return response([
                        'responseCode' => Response::HTTP_OK,
                        'responseMessage' => "",
                        "searchType" => 'all',
                        "total_page" => $totalPage,
                        "nextOffset" => 1,
                        "count" => $count,
                        "searchResults" => $elementsArray
                    ],Response::HTTP_OK);


                case 'image':
                    $elementsArray = array();
                    $catgories = $data->categories;
                    foreach($catgories as $key => $cat){
                        $tiles = $cat->tiles;
                        foreach($tiles as $key => $tile){
                            if(strpos($tile->query->displayText, 'gif') == false){
                                $array = array(
                                    "id" => $tile->image->imageId,
                                    'title' => $tile->query->displayText,
                                    'website'=>$tile->query->webSearchUrl,
                                    'type' => $getType,
                                    'image'=>$tile->image->contentUrl,
                                );
                                array_push($elementsArray,$array);
                            }
                        }
                    }
                    return response([
                        'responseCode' => Response::HTTP_OK,
                        'responseMessage' => "",
                        "searchType" => 'image',
                        "total_page" => 10,
                        "nextOffset" => $request->get('offset',0)+1,
                        "count" => $count,
                        "searchResults" => $elementsArray
                    ],Response::HTTP_OK);


                case 'video':
                    $elementsArray = array();
                    $catgories = $data->categories;
                    foreach($catgories as $key => $cat){
                        $subcats = $cat->subcategories;
                        foreach($subcats as $key => $subcat){
                            $tiles = $subcat->tiles;
                            foreach($tiles as $key => $tile){
                                $idDesc = "";
                                try{
                                    $idDesc = substr($tile->image->description , 0, 20);
                                    if(json_encode($idDesc) == false){
                                        $idDesc = substr(preg_replace("/[^a-zA-Z0-9]+/", "", $tile->image->description) , 0, 20);
                                    }
                                }catch (\Exception $e){
                                    $idDesc = substr(preg_replace("/[^a-zA-Z0-9]+/", "", $tile->image->description) , 0, 20);
                                }
                                $id = $tile->query->text.$idDesc;
                                $array = array(
                                    "id" => $id,
                                    'title' => $tile->query->text,
                                    'website'=>$tile->image->contentUrl,
                                    'description'=>$tile->query->displayText,
                                    'type' => $getType,
                                    'image' => $tile->image->thumbnailUrl,
                                );
                                array_push($elementsArray,$array);
                            }
                        }
                    }

                    return response([
                        'responseCode' => Response::HTTP_OK,
                        'responseMessage' => "Trending Videos",
                        "searchType" => 'video',
                        "total_page" => 10,
                        "nextOffset" => $request->get('offset',0)+1,
                        "searchResults" => $elementsArray
                    ],Response::HTTP_OK);
            }
        }catch (\Exception $e){
            return response([
                'responseCode' => Response::HTTP_BAD_REQUEST,
                'responseMessage' => "Server error, please try again.",
                'error_log' => $e->getMessage()
            ],Response::HTTP_OK);
        }
    }

    public function search_data(Request $request){

    }

    private function duration($datePublished){
        try{
            $publishedDate = Carbon::parse($datePublished);
            if($publishedDate->isToday()){
                $hourDiff = Carbon::now()->diffInHours($publishedDate);
                return ($hourDiff==0)?Carbon::now()->diffInMinutes($publishedDate)."m":$hourDiff."h";
            }elseif ($publishedDate->isYesterday()) {
                return "Yesterday";
            }else {
                return $publishedDate->format("d M");
            }
        }catch (\Exception $e){
            return "";
        }
    }


    private function videoDuration($youtubeDuration){
        $start = new DateTime('@0'); // Unix epoch
        $start->add(new DateInterval($youtubeDuration));
        if( strpos( $youtubeDuration, "H" ) !== false) {
            return $start->format('H:i:s');
        }else {
            return $start->format('i:s');
        }

    }


    private function durationFull($datePublished){

        if($datePublished==null || strlen($datePublished)==0){
            return "";
        }
        try{
            $publishedDate = Carbon::parse($datePublished);
            if($publishedDate->isToday()){
                $hourDiff = Carbon::now()->diffInHours($publishedDate);
                return ($hourDiff==0)?Carbon::now()->diffInMinutes($publishedDate)." minutes ago":$hourDiff." hours ago";
            }elseif ($publishedDate->isYesterday()) {
                return "Yesterday";
            }else {
                $monthsDiff = Carbon::now()->diffInMonths($publishedDate);
                if($monthsDiff>12){
                    return Carbon::now()->diffInYears($publishedDate)." years ago";
                }
                return ($monthsDiff==0)?Carbon::now()->diffInDays($publishedDate)." days ago":$monthsDiff." months ago";
            }
        }catch (\Exception $e){
            return "";
        }

    }


    public function getImage(Request $request){
        try{
            return response([
                'responseCode' => Response::HTTP_OK,
                'responseMessage' => "",
                "image" => $this->getSiteMetaData2(trim($request->url))

            ],Response::HTTP_OK);

            /*return response([
                'responseCode' => Response::HTTP_OK,
                'responseMessage' => "",
                "image" => $this->getSiteImage($request->url),
                "url" => $request->url
            ],Response::HTTP_OK);*/
        }catch (\Exception $e){
            return response([
                'responseCode' => Response::HTTP_BAD_REQUEST,
                'responseMessage' => "",
                "error" => $e->getMessage()
            ],Response::HTTP_OK);
        }

    }

    private function getSiteDeepData($url,$data){

        $data = array();

        if($url==null || strlen($url)==0){
            return $data;
        }
        $xpath = null;
        try {
            $document = $this->file_get_html(trim($url));
            if($document!=null){
                $nodes = $document->getElementsByTagName('title');
                $data['title'] = preg_replace('/\n/','',rtrim(ltrim($nodes->item(0)->nodeValue)));
                $xpath = new DOMXPath($document);
            } else{
                return $data;
            }
        } catch (\Exception $e) {
            return $data;
        }


        if($xpath == null){
            return $data;
        }


        foreach ($xpath->query("//meta[@property='og:image']") as $el) {
            if(null !== $el->getAttribute("content") && strlen($el->getAttribute("content")) > 0)
                $data['image'] = $el->getAttribute("content");
        }

        $partsSite = parse_url($url);
        $siteScheme = $partsSite["scheme"];
        $siteHost = $partsSite["host"];

        if(strpos($url,".amp") !== false){
            $imagesTag = $xpath->query('//amp-img');
        }else {
            $imagesTag = $xpath->query('//img');
        }

        if($imagesTag){
            $maxSize = 100;
            $i=0;
            foreach ($imagesTag as $imageTag) {
                $style = $imageTag->getAttribute('style');
                if(isset($style) && strpos($imageTag->getAttribute('style'),"none") !== false){
                    continue;
                }

                $imgViewHeight = $imageTag->getAttribute('height');


                if((isset($imgViewHeight) && strlen($imgViewHeight) != 0)){
                    if($imgViewHeight < 50) {
                        continue;
                    }
                }

                $image = $imageTag->getAttribute('src');
                try{
                    if($image && strpos($image,".gif") == false && strpos($image,".tif") == false){
                        $image = str_replace("_.webp","",$image);
                        if(strpos($image,"white_bg.") !== false){
                            continue;
                        }

                        if(strpos($image, "?width=") !== false){
                            $imageUrlPart = explode("?width=",$image);
                            if($imageUrlPart != null && strlen($imageUrlPart) > 1 && is_numeric($imageUrlPart[1])){
                                $image = $imageUrlPart[0];
                            }
                        }

                        if(strpos($image, "base64") !== false && strlen($image) > 2000){
                            $dataOldHires = $imageTag->getAttribute('data-old-hires');
                            if(isset($dataOldHires) && strlen($dataOldHires) > 10){
                                $image = $dataOldHires;
                            }
                        }


                        $p = parse_url($image);
                        if($p){
                            if(array_key_exists("scheme",$p) && $p["scheme"]){
                            }else {
                                $cleanedRelativePath = implode("/",array_filter(explode("/", $image)));
                                if(array_key_exists("host",$p) && strlen($p["host"])>0){
                                    $image = $siteScheme."://".$cleanedRelativePath;
                                }else{
                                    $image =  $siteScheme."://".$siteHost."/".$cleanedRelativePath;
                                }
                            }
                        }

                        $size = getimagesize($image);
                        if($size){
                            $avgSize = $size[0]+$size[1]/2;
                            if ($avgSize > $maxSize && $size[1]>100) {
                                $maxSize = $avgSize;
                                $data['image'] = $image;
                                if($size[0]>300 && $size[1]>200){
                                    return $data;
                                }
                                if($maxSize>800 || ($size[0]>600 && $size[1]>500)){
                                    return $data;
                                }

                            }
                        }
                        if($i == 80){
                            return $data;
                        }
                    }

                }catch (\Exception $e){
                }
                $i++;
            }
            return $data;
        }

        return $data;
    }


    private function getSiteDeepData2($url, $data){


        if($url==null || strlen($url)==0){
            $data['log'] = "url null";
            return $data;
        }
        $xpath = null;
        try {
            $document = $this->file_get_html2(trim($url));
            if($document!=null){
                if(!isset($data['title']) || strlen($data['title']) == 0){
                    $nodes = $document->getElementsByTagName('title');
                    $data['title'] = preg_replace('/\n/','',rtrim(ltrim($nodes->item(0)->nodeValue)));
                }
                $xpath = new DOMXPath($document);
            } else{
                $data['log'] = "document null";
                return $data;
            }
        } catch (\Exception $e) {
        }


        if($xpath == null){
            $data['log'] = "Xpath null";
            return $data;
        }

        foreach ($xpath->query("//meta[@property='og:image']") as $el) {
            $data['image'] = $el->getAttribute("content");
        }

        $partsSite = parse_url($url);
        $siteScheme = $partsSite["scheme"];
        $siteHost = $partsSite["host"];

        if(strpos($url,".amp") !== false){
            $imagesTag = $xpath->query('//amp-img');
        }else {
            $imagesTag = $xpath->query('//img');
        }
        if($imagesTag){
            $maxSize = 100;
            $i=0;
            $imageArr = array();
            foreach ($imagesTag as $imageTag) {
                $data['internal'] = "entered";
                $style = $imageTag->getAttribute('style');
                if(isset($style) && strpos($imageTag->getAttribute('style'),"none") !== false){
                    continue;
                }

                $data['internal'] = "get imgViewHeight";
                $imgViewHeight = $imageTag->getAttribute('height');
                $data['internal'] = "imgViewHeight".$imgViewHeight;
                if((isset($imgViewHeight) && strlen($imgViewHeight) != 0)){
                    if($imgViewHeight < 50) {
                        $data['internal'] = "imgViewHeight found but < 50 so continue";
                        continue;
                    }
                }
                $data['internal'] = "entered3";
                $image = $imageTag->getAttribute('src');
                try{
                    if($image && strpos($image,".gif") == false && strpos($image,".tif") == false){
                        if(strpos($image,"white_bg.") !== false){
                            continue;
                        }
                        if(strpos($image, "?width=") !== false){
                            $imageUrlPart = explode("?width=",$image);
                            if($imageUrlPart != null && strlen($imageUrlPart) > 1 && is_numeric($imageUrlPart[1])){
                                $image = $imageUrlPart[0];
                            }
                        }
                        $image = str_replace("_.webp","",$image);
                        if(strpos($image, "base64") !== false && strlen($image) > 2000){
                            $dataOldHires = $imageTag->getAttribute('data-old-hires');
                            if(isset($dataOldHires) && strlen($dataOldHires) > 10){
                                $image = $dataOldHires;
                            }
                        }
                        $p = parse_url($image);
                        if($p){
                            if(array_key_exists("scheme",$p) && $p["scheme"]){
                            }else {
                                $cleanedRelativePath = implode("/",array_filter(explode("/", $image)));
                                if(array_key_exists("host",$p) && strlen($p["host"])>0){
                                    $image = $siteScheme."://".$cleanedRelativePath;
                                }else{
                                    $image =  $siteScheme."://".$siteHost."/".$cleanedRelativePath;
                                }
                            }
                        }
                        //ini_set('memory_limit', '128M');
                        $size = getimagesize($image);
                        if($size){
                            $avgSize = $size[0]+$size[1]/2;
                            if ($avgSize > $maxSize && $size[1]>100) {
                                $maxSize = $avgSize;
                                $data['image'] = $image;
                                if($size[0]>300 && $size[1]>200){
                                    $data['log'] = "size[0]>300 && size[1]>200";
                                    return $data;
                                }
                                if($maxSize>800 || ($size[0]>600 && $size[1]>500)){
                                    $data['log'] = "maxSize>800 || (size[0]>600 && size[1]>500";
                                    return $data;
                                }

                            }
                        }
                        if($i == 100){
                            //$data['internal'] = "100 reached, size issue";
                            $data['log'] = "100 reached";
                            return $data;
                        }
                    }

                }catch (\Exception $e){
                    continue;
                }
                $i++;
            }
            $data['internal'] = json_encode($imageArr);
            $data['log'] = "No image tags";
            return $data;
        }

        $data['internal'] = "nothing";
        $data['log'] = "nothing";
        return $data;
    }

    function getimagesize($url, $referer = '')
    {
        $headers = array(
            'Range: bytes=0-32768'
        );

        /* Hint: you could extract the referer from the url */
        if (!empty($referer)) array_push($headers, 'Referer: '.$referer);

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $data = curl_exec($curl);
        curl_close($curl);

        $image = \imagecreatefromstring($data);

        $return = array(imagesx($image), imagesy($image));

        imagedestroy($image);

        return $return;
    }

    private function file_get_html($urldecode){
        $document = new DOMDocument();
        try{
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($curl, CURLOPT_TIMEOUT, 180);
            curl_setopt($curl, CURLOPT_HEADER, false);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($curl, CURLOPT_URL, $urldecode);
            curl_setopt($curl, CURLOPT_REFERER, $urldecode);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_BINARYTRANSFER, true);
            curl_setopt($curl, CURLOPT_HTTPGET, true);
            curl_setopt($curl,CURLOPT_ENCODING,'');

            //$user_agent = "Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.2; .NET CLR 1.1.4322)";
            $user_agent = "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_10_3) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/44.0.2403.89 Safari/537.36";
            curl_setopt($curl, CURLOPT_USERAGENT, $user_agent );


            $response = curl_exec($curl);

            curl_close($curl);

            if($response)
            {
                libxml_use_internal_errors(true);
                $document->loadHTML($response);
                libxml_clear_errors();
            }else{
                return null;
            }

        }catch (\Exception $e){
            return null;
        }

        return $document;
    }

    private function file_get_html2($urldecode){
        $document = new DOMDocument();
        try{
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($curl, CURLOPT_TIMEOUT, 180);
            curl_setopt($curl, CURLOPT_HEADER, false);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($curl, CURLOPT_URL, $urldecode);
            curl_setopt($curl, CURLOPT_REFERER, $urldecode);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_BINARYTRANSFER, true);
            curl_setopt($curl, CURLOPT_HTTPGET, true);
            curl_setopt($curl,CURLOPT_ENCODING,'');

            //$user_agent = "Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.2; .NET CLR 1.1.4322)";
            $user_agent = "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_10_3) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/44.0.2403.89 Safari/537.36";
            curl_setopt($curl, CURLOPT_USERAGENT, $user_agent );


            $response = curl_exec($curl);

            curl_close($curl);

            if($response)
            {
                libxml_use_internal_errors(true);
                $document->loadHTML($response);
                libxml_clear_errors();
            }else{
                return "null data";
            }

        }catch (\Exception $e){
            return $e->getMessage();
        }

        return $document;
    }

    private function generateBiggerImage($image){
        if($image==null){
            return "";
        }
        return $image->contentUrl."&w=".$image->width."&h=".$image->height;
    }


    public function fetchLocalBusinesses(Request $request){

        try{
            $limit = isset($request->limit) ? $request->limit : 5;
            $page = isset($request->page) ? $request->page : 0;
            $query = isset($request->query) ? $request->get('query') : "";
            // key for Bundle S10
            $key = "3eb1beeb80a8497ab90e943294dc6863";
            $headers = "Ocp-Apim-Subscription-Key: $key\r\nAccept-Language: en\r\n";
            $options = array ('http' => array (
                'header' => $headers,
                'method' => 'GET'));
            $context = stream_context_create($options);
            $finalUrl =  "https://api.cognitive.microsoft.com/bing/v7.0/localbusinesses/search" . "?mkt=en-us&q=" . urlencode($query)."&count=".$limit."&offset=".$limit*$page;

            $result = file_get_contents($finalUrl, false, $context);

            $mainObject = json_decode($result);
            if($mainObject != null && isset($mainObject->places) && $mainObject->places != null && $mainObject->places->value!=null){
                $placesWrapper = $mainObject->places;
                $places = $placesWrapper->value;
                $finalPlaces = array();
                foreach ($places as $place) {
                    $id = isset($place->telephone)?$place->telephone:$place->name;
                    if(isset($place->geo)){
                        array_push($finalPlaces, array('id' => $id, 'name' => $place->name, 'website' => isset($place->url)?$place->url:"", 'latlng' => $place->geo,
                            'phone' => isset($place->telephone)?$place->telephone:"", 'address' => array('text' => $place->address->text,'neighborhood'=>$place->address->neighborhood,
                                'locality' => $place->address->addressLocality),'detail_url' => 'https://www.bing.com/entityexplore?q='.urlencode($place->name.' '.$place->address->addressLocality.' '.$place->address->addressRegion).'&ypid=YN873x133249473&cp='.$place->geo->latitude.'~'.$place->geo->longitude.'&eeptype=EntityFull&qpvt='.urlencode($query).'&PC=D2C'));
                    }
                }

            }else {
                return response([
                    'responseCode' => Response::HTTP_OK,
                    'responseMessage' => "No data found",
                    'total_page' => 0,
                    "places" => array()
                ],Response::HTTP_OK);
            }

            return response([
                'responseCode' => Response::HTTP_OK,
                'responseMessage' => "",
                'total_page' => ceil(($placesWrapper!=null && isset($placesWrapper->totalEstimatedMatches))?$placesWrapper->totalEstimatedMatches/$limit:0),
                'next_page' => $page + 1,
                'nextPage' => $page + 1,
                'searched_location' =>isset($placesWrapper->searchActio)?$placesWrapper->searchAction->location[0]->name:"",
                "places" => $finalPlaces
            ],Response::HTTP_OK);
        }catch (\Exception $e){
            return response([
                'responseCode' => Response::HTTP_BAD_REQUEST,
                'responseMessage' => "Server error, please try again.",
                'error_log' => $e->getMessage()
            ],Response::HTTP_OK);
        }

    }

    //TODO not final, need to work on the sites for which description is not defined in meta tag of the website
    private function getSiteMetaData($url){

        if(strpos($url, "google.com/search")){
            $url  = str_replace(" ","+",$url);
        }
        $data = array();
        try{
            ini_set('user_agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_10_3) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/44.0.2403.89 Safari/537.36');
            $metaTags = get_meta_tags($url);
            if(sizeof($metaTags) == 0){
                ini_set('user_agent','');
                $metaTags = get_meta_tags($url);
            }
            foreach ($metaTags as $key => $value) {
                if(strpos($key, ":image") != false || $key == "image"){
                    if(strpos($value,".gif") == false && strpos($value,"http") !== false)
                        $data['image'] = $value;
                }

                if(strpos($key, ":title") != false || $key == "title"){
                    $data['title'] = $value;
                }

                if(strpos($key, ":description") != false || $key == "description"){
                    $data['description'] = $value;
                }
            }

            if(!array_key_exists("title", $data) || !array_key_exists("image", $data)) {
                $extractedData = $this->getSiteDeepData($url,$data);

                if(array_key_exists("title", $extractedData)) {
                    $data['title'] = $extractedData['title'];
                }

                if(array_key_exists("image", $extractedData)) {
                    $data['image'] = $extractedData['image'];
                }
            }

            if(strpos($url,"/wiki/") !== false && !array_key_exists("description", $data)){

                $data['description'] = $this->fetchWikiDescription(explode("/wiki/",$url)[1]);

            }

        }catch (\Exception $e){
            return $data;
        }

        return $data;

    }



    private function getSiteMetaData2($url){
        if(strpos($url, "google.com/search")){
            $url  = str_replace(" ","+",$url);
        }
        $data = array();
        try{
            ini_set('user_agent', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/73.0.3683.86 Safari/537.36');
            $metaTags = get_meta_tags($url);

            if(sizeof($metaTags) == 0){
                ini_set('user_agent','');
                $metaTags = get_meta_tags($url);
            }


            foreach ($metaTags as $key => $value) {
                if(strpos($key, ":image") != false || $key == "image"){
                    if(strpos($value,".gif") == false && strpos($value,"http") !== false)
                        $data['image'] = $value;
                }

                if(strpos($key, ":title") != false || $key == "title"){
                    $data['title'] = $value;
                }

                if(strpos($key, ":description") != false || $key == "description"){
                    $data['description'] = $value;
                }
            }


            if(!array_key_exists("image", $data)) {
                $extractedData = $this->getSiteDeepData2($url,$data);
                //return $extractedData;
                if(array_key_exists("title", $extractedData)) {
                    $data['title'] = $extractedData['title'];
                }

                if(array_key_exists("image", $extractedData)) {
                    $data['image'] = $extractedData['image'];
                }

                if(array_key_exists("log", $extractedData)) {
                    $data['log'] = $extractedData['log'];
                }

                if(array_key_exists("internal", $extractedData)) {
                    $data['internal'] = $extractedData['internal'];
                }
            }

            if(strpos($url,"/wiki/") !== false && !array_key_exists("description", $data)){

                $data['description'] = $this->fetchWikiDescription(explode("/wiki/",$url)[1]);

            }
        }catch (\Exception $e){
            return $e->getMessage();
        }

        return $data;

    }

    public function getImageFromBase64($base64){
        $type = "";
        if(strpos($base64,"data:image/png;base64") !== false){
            $type = "png";
            $image = str_replace('data:image/png;base64,', '', $base64);
        }else if(strpos($base64,"data:image/jpeg;base64") !== false){
            $type = "jpeg";
            $image = str_replace('data:image/jpeg;base64,', '', $base64);
        } else if(strpos($base64,"data:image/webp;base64") !== false){
            $type = "jpg";
            $image = str_replace('data:image/webp;base64,', '', $base64);
        } else {
            return "";
        }
        $image = str_replace(' ', '+', $image);
        $imageName = time().'.'.$type;
        file_put_contents(app()->basePath('public/posts/image').$imageName,$image);

        return url("posts/image").$imageName;
    }

    private function fetchWikiDescription($wikiHandle){
        try{
            $wikiHandle  = str_replace("+","_",$wikiHandle);
            $url = "https://en.wikipedia.org/api/rest_v1/page/summary/".$wikiHandle;

            $curl = curl_init();
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($curl, CURLOPT_TIMEOUT, 180);
            curl_setopt($curl, CURLOPT_HEADER, false);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_REFERER, $url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_BINARYTRANSFER, true);
            curl_setopt($curl, CURLOPT_HTTPGET, true);
            curl_setopt($curl,CURLOPT_ENCODING,'');

            //$user_agent = "Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.2; .NET CLR 1.1.4322)";
            $user_agent = "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_10_3) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/44.0.2403.89 Safari/537.36";
            curl_setopt($curl, CURLOPT_USERAGENT, $user_agent );


            $response = curl_exec($curl);

            curl_close($curl);


            if($response != null && strlen($response) > 0){
                $object = json_decode($response);
                return $object->extract;
            }else {
                return "";
            }
        }catch (\Exception $e){
            return "";
        }

        return "";
    }

    private function getTitleFromDescription($description){
        try{
            if($description == null || strlen($description) == 0){
                return $description;
            }
            if(strpos($description,":") !== false){
                $descriptionPart = explode(":",$description);
                foreach ($descriptionPart as $item) {
                    if(strcasecmp(trim($item) ,"amazon.com") != 0 && strcasecmp(trim($item) ,"www.amazon.com") != 0 && strcasecmp(trim($item) ,":") != 0){
                        return $item;
                    }
                }
            }
        }catch (\Exception $e){

        }


        return $description;
    }


    private function yahooRSS($url, $type, $page){

        if($page > 0){
            return response([
                'responseCode' => Response::HTTP_OK,
                'responseMessage' => "Search Results",
                "searchQuery" => "",
                "searchType" => $type,
                "total_page" => 1,
                "nextOffset" => 2,
                "count" => 0,
                "searchResults" => array()
            ],Response::HTTP_OK);
        }
        try {
            $xml = simplexml_load_file($url);

            $news = array();
            $i = 1;
            foreach ( $xml->channel->item as $item ) {
                try{
                    try {
                        $title = $item->title;
                    }catch (\Exception $e) {}
                    try{
                        $pubDate = $item->pubDate;
                    }catch (\Exception $e) {}
                    try{
                        $link = (string)$item->link;
                    }catch (\Exception $e) {}
                    try{
                        $provider = (string)$item->source;
                    }catch (\Exception $e) {}



                    try{
                        $description = strip_tags($item->description);
                        if($description == null || strlen($description) == 0){
                            $description = $title;
                        }
                        if(strlen($description) > 200){
                            $description = substr($description, 0, 200);
                        }
                    }catch (\Exception $e) {}
                    $image = "";
                    try{
                        $namespaces = $item->getNameSpaces(true);
                        $media = $item->children($namespaces['media']);
                        foreach($media as $i){
                            $image = (string)$i->attributes()->url;
                            if(strlen($image) > 0){
                                $imageArr =  explode('-/',$image);
                                $image = $imageArr[sizeof($imageArr)-1];
                                break;

                            }
                        }

                    }catch (\Exception $e) {$image = "";}

                    if($image == null || strlen($image) == 0){
                        continue;
                    }

                    try{
                        $description = utf8_encode(html_entity_decode($description, ENT_QUOTES));
                    }catch (\Exception $e){

                    }

                    $idDesc = "";
                    try{
                        $idDesc = substr($description , 0, 20);
                        if(json_encode($idDesc) == false){
                            $idDesc = substr(preg_replace("/[^a-zA-Z0-9]+/", "", $description) , 0, 20);
                        }
                    }catch (\Exception $e){
                        $idDesc = substr(preg_replace("/[^a-zA-Z0-9]+/", "", $description) , 0, 20);
                    }
                    $id = $title.$idDesc;
                    $array = array(
                        'id' => utf8_encode($id),
                        'title' => utf8_encode($title),
                        'datetime' => $this->duration(isset($pubDate)?$pubDate:null),
                        'website'=>$link,
                        'provider'=>($provider != null && strlen($provider)>0)?$provider:"",
                        'provider_icon'=> "https://via.placeholder.com/1",
                        'description'=> $description,
                        'image' => $image
                    );
                    array_push($news, $array);
                    $i = $i + 1;
                }catch (\Exception $e) { continue; }


            }

            return response([
                'responseCode' => Response::HTTP_OK,
                'responseMessage' => "Search Results",
                "searchQuery" => "",
                "searchType" => $type,
                "total_page" => 1,
                "nextOffset" => 2,
                "count" => sizeof($news),
                "searchResults" => $news
            ],Response::HTTP_OK);
        } catch (\Exception $e) {
            return response([
                'responseCode' => Response::HTTP_BAD_REQUEST,
                'responseMessage' => "Server error, please try again.",
                'error_log' => $e->getMessage()
            ],Response::HTTP_OK);
        }
    }


    public function test(Request $request){
        try {
            $xml = simplexml_load_file("https://news.yahoo.com/rss/mostviewed");

            $news = array();
            foreach ( $xml->channel->item as $item ) {
                try{
                    try {
                        $title = $item->title;
                    }catch (\Exception $e) {}
                    try{
                        $pubDate = $item->pubDate;
                    }catch (\Exception $e) {}
                    try{
                        $link = (string)$item->link;
                    }catch (\Exception $e) {}
                    try{
                        $provider = (string)$item->source;
                    }catch (\Exception $e) {}
                    try{
                        $description = strip_tags($item->description);
                        if(strlen($description) > 200){
                            $description = substr($description, 0, 200);
                        }
                    }catch (\Exception $e) {}
                    $image = "";
                    try{
                        $namespaces = $item->getNameSpaces(true);
                        $media = $item->children($namespaces['media']);
                        foreach($media as $i){
                            $image = (string)$i->attributes()->url;
                            if(strlen($image) > 0){
                                $imageArr =  explode('-/',$image);
                                $image = $imageArr[sizeof($imageArr)-1];
                                break;

                            }
                        }
                    }catch (\Exception $e) {$image = "";}
                    $idDesc = "";
                    try{
                        $idDesc = substr($description , 0, 20);
                        if(json_encode($idDesc) == false){
                            $idDesc = substr(preg_replace("/[^a-zA-Z0-9]+/", "", $description) , 0, 20);
                        }
                    }catch (\Exception $e){
                        $idDesc = substr(preg_replace("/[^a-zA-Z0-9]+/", "", $description) , 0, 20);
                    }
                    $id = $title.$idDesc;
                    $array = array(
                        'id' => utf8_encode($id),
                        'title' => utf8_encode($title),
                        'datetime' => $this->duration(isset($pubDate)?$pubDate:null),
                        'website'=>$link,
                        'provider'=>($provider != null && strlen($provider)>0)?$provider:"",
                        'provider_icon'=> "https://via.placeholder.com/1",
                        'description'=>utf8_encode($description),
                        'image' => $image
                    );
                    array_push($news, $array);
                }catch (\Exception $e) { continue; }


            }

            return response([
                'responseCode' => Response::HTTP_OK,
                'responseMessage' => "",
                "searchType" => 'all',
                "total_page" => 1,
                "nextOffset" => 1,
                "count" => sizeof($news),
                "searchResults" => $news
            ],Response::HTTP_OK);
        } catch (\Exception $e) {
            return response([
                'responseCode' => Response::HTTP_BAD_REQUEST,
                'responseMessage' => "Server error, please try again.",
                'error_log' => $e->getMessage()
            ],Response::HTTP_OK);
        }
    }
}
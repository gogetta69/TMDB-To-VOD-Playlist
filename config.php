<?php
// Created By gogetta.teams@gmail.com
// Please leave this in this script.
//https://github.com/gogetta69/TMDB-To-VOD-Playlist

/// Set to true for debugging. Set to false to run as production.
$GLOBALS['DEBUG'] = false; // Developer option.

// This script no longer by default loads the user created playlist. If you would prefer to create your own playlist
// change the setting $userCreatePlaylist = true;

// Next, go into the HeadlessVidX/Install Instructions.txt and follow the instructions on setting up HeadlessVidX. TheTvApp // which is a Live TV playlist needs to have this installed before it can be used. 

// Replace 'YOUR_API_KEY' with your TMDb API key - https://www.themoviedb.org/
//Entering your key here may be visible through google drive. Check your sharing settings.
$apiKey = '';

// Replace this with your Real-Debrid Private API token - https://real-debrid.com/apitoken
// Don't worry about this setting if you aren't planning on using Real Debrid.
$PRIVATE_TOKEN = '';

// Replace this with your Premiumize Private API token - https://www.premiumize.me/account
// Don't worry about this setting if you aren't planning on using Premiumize.
$premiumizeApiKey = '';

// By default, on a local network the server identifies as "localhost" or "127.0.0.1" which isn't
// accessible from other devices in your local network. Set this if you're running the script on
// a local server and want to access it from other devices (firestick, android, etc. If so, specify
// the server's local IP (e.g., '192.168.x.x') for network access. Leave this blank for default server
// address or if installing on a public accessibe server.
$userSetHost = ''; // Example: 192.168.0.100 see the help file or video for more information.

// Note: The $HTTP_PROXY is utilized only during the scraping of direct movie links. This is particularly necessary if you are making a large number of requests to obtain streaming links, such as when running this script as a service. It is recommended to use backconnect proxies from providers like stormproxies.com to avoid being blocked by streaming websites.
$HTTP_PROXY = "";

//Enable or disable the $HTTP_PROXY setting.
$USE_HTTP_PROXY = false;

//When set to true your playist is created by running the 'create_playlist.php' and 'create_tv_playlist.php'
//When set to false the the movie and tv show playlist will be loaded from github. The playlists on github 
//are around 45k movies and around 12k series.
$userCreatePlaylist = false; // Set to false if you don't want to create any playlist.

// Adds approximately 10,000 full-length adult movies to the VOD Movie playlist
//under the category 'XXX Adult Movies'. This playlist is refreshed every Sunday.
$INCLUDE_ADULT_VOD = false; // Set to true to include adult content.

// Set how many movies and TV series you want in your playlist. TMDB shows 20 items on each page.
// For instance, setting $totalPages to 150 could fetch approximately 35,000 movies across various genres and categories.
// Adjust this for a bigger or smaller playlist. Be aware: generating a playlist based on this number might range 
// from a few minutes to an hour + to complete.
$totalPages = 25; // Adjust this if needed

// Leave blank for any language.
$language = 'en-US'; // TMDB search setting (language)

// Leave blank for any country to be included in the series playlist.
$series_with_origin_country = 'US'; // TMDB search setting (with_origin_country)

// Leave blank for any country to be included in the movies playlist.
$movies_with_origin_country = 'US'; // TMDB search setting (with_origin_country)

// Leave this setting as false if you aren't intending on using Real-Debrid links.
// set it to true if you want to use realdebrid when streaming torrents. 
// Example: The value can be either true or false.
$useRealDebrid = false; // Requires a real debrid private token added above.

// Leave this setting as false if you aren't intending on using Premiumize links.
// set it to true if you want to use premiumize when streaming torrents. 
// Example: The value can be either true or false.
$usePremiumize = false; // Requires a Premiumize API Key added above.

// maxResolution is the upper limit for video resolution preference in
// pixels (e.g., 1080 for 1080p). If no links match this exact resolution,
// the closest available resolution will be selected. If you don't have the
// internet speed for higher quality you should select a lower resolution, or
// you may experience constant freezing and buffering.
// Example: 1080P is 1080
$maxResolution = 1080; // numerical value only

// HEADLESSVIDX_MAX_THREADS controls the maximum number of concurrent curl requests (threads) 
// that the script will handle simultaneously. Being headless browser operations, higher 
// numbers of concurrent threads will have a greater impact on CPU and memory usage. 
// Adjust this value based on your server's capacity and the desired balance between 
// performance and resource consumption.
define('HEADLESSVIDX_MAX_THREADS', 3); // Numerical value only.


// Sets the execution order of the sites stored in HeadlessVidX_sitelist. Also, check out the training guide
// at http://localhost:3202/ to learn how to add your own list of streaming api websites to pull links from.
$HeadlessVidXRunOrder = 'random'; // Options: random, ascending, or descending

// The $cacheSize setting is used to control the size of a cache system, ensuring that
// it doesn't grow to large and consume excessive storage space.
// Example 10 MB is 10
$cacheSize = 10; // numerical value only

// Define the cache expiration duration variable (in hours)
// Most of the non real debrid links last around 3 to 4 hours before their
// token expires. So setting this to 3 or 4 hours should be good enough.
$expirationHours = 3; // Default: 3 (numerical value only)

// The timeout setting has only been added to the video link extractors.
// If you set this to low you might not get any links to return.
// Example: 20 seconds is 20
$timeOut = 20; // numerical value only

// List of Live TV services that can be included in the Live TV Playlist.
// - Set to true to include the service in the Live TV Playlist.
// - Set to false to exclude the service from the Live TV Playlist.
$LiveTVServices = [
    "MoveOnJoy" => true,
    "TheTVApp" => true,
    "DaddyLive" => true,
    "TopEmbed" => true,
    "Pluto" => true,
];

// Change the run order here. This can be Used to speed up the process of finding a link.
// Cut the entire line and paste it above or below another. The list is ran
// from top to bottom. You can also disable a website by commenting it out with //
// Example: take 'theMovieArchive_site', and put above or below another.
// Be sure to grab the entire line including the comma.

// showBox_media requires a login and a cookie string to be added to sessions/showbox_media_cookies.txt
// in order to extract links. Taking the time to add these cookies is well worth it if you aren't using
// a premium link service and are seeking the best quality possible. How to: videos/how_to_showbox_media_cookie.mp4

$userDefinedOrder = [
'torrentSites',
'showBox_media',
'vidsrc_rip',
'myfilestorage_xyz',
'primewire_tf',
'autoembed_cc',
'vidsrc_pro', 
'twoembed_skin',
'oneTwothreeEmbed_net',
'superEmbed_stream',
'frembed_pro',
'upMovies_to',
'HeadlessVidX',
 ];
 
/* Archived list
//'shegu_net_links',
//'warezcdn_com',
//'justBinge_site',
//'vidsrc_to',
//'rive_vidsrc_scrapper',
//'smashyStream_com',
*/
 
// On my todo list.
// Language mapping between TMDB and Torrent Site. 
$languageMapping = [
    "TorrentGalaxy" => [
        "en-US" => "1",    // English (United States)
        "fr-FR" => "2",    // French (France)
        "de-DE" => "3",    // German (Germany)
        "it-IT" => "4",    // Italian (Italy)
        "ja-JP" => "5",    // Japanese (Japan)
        "es-ES" => "6",    // Spanish (Spain)
        "ru-RU" => "7",    // Russian (Russia)
        "nb-NO" => "12",   // Norwegian (Norway)
        "hi-IN" => "8",    // Hindi (India)
        "ko-KR" => "10",   // Korean (South Korea)
        "da-DK" => "11",   // Danish (Denmark)
        "nl-NL" => "13",   // Dutch (Netherlands)
        "zh-CN" => "14",   // Chinese (Simplified, China)
        "pt-PT" => "15",   // Portuguese (Portugal)
        "pl-PL" => "17",   // Polish (Poland)
        "tr-TR" => "18",   // Turkish (Turkey)
        "te-IN" => "19",   // Telugu (India)
        "sv-SE" => "22",   // Swedish (Sweden)
        "cs-CZ" => "26",   // Czech (Czech Republic)
        "ar-SA" => "21",   // Arabic (Saudi Arabia)
        "ro-RO" => "23",   // Romanian (Romania)
        "bn-BD" => "16",   // Bengali (Bangladesh)
        "ur-PK" => "20",   // Urdu (Pakistan)
        "th-TH" => "24",   // Thai (Thailand)
        "ta-IN" => "25",   // Tamil (India)
        "hr-HR" => "27",   // Croatian (Croatia)
        "other" => "9",    // Other / Multiple
    ],
	
	"Glodls" => [
        "en-US" => "1",    // English (United States)
        "fr-FR" => "2",    // French (France)
        "de-DE" => "3",    // German (Germany)
        "it-IT" => "4",    // Italian (Italy)
        "ja-JP" => "5",    // Japanese (Japan)
        "es-ES" => "6",    // Spanish (Spain)
        "ru-RU" => "7",    // Russian (Russia)
        "nb-NO" => "12",   // Norwegian (Norway)
        "hi-IN" => "8",    // Hindi (India)
        "ko-KR" => "10",   // Korean (South Korea)
        "da-DK" => "11",   // Danish (Denmark)
        "nl-NL" => "13",   // Dutch (Netherlands)
        "zh-CN" => "11",   // Chinese (Simplified, China)
        "pt-PT" => "15",   // Portuguese (Portugal)
        "te-IN" => "14",   // Telugu (India)
        "bn-BD" => "12",   // Bengali (Bangladesh)
        "ta-IN" => "9",    // Tamil (India)
    ]
	
];

//Setup for HeadlessVidX.
$filePath = 'HeadlessVidX/listening-port.txt';			
if (!file_exists($filePath)) {
	die('Port file not found.');
}
$hvxport = trim(file_get_contents($filePath));
if (!is_numeric($hvxport) || $hvxport <= 0 || $hvxport > 65535) {
	$hvxport = '3202';
}

$HeadlessVidX_ServerPort = "127.0.0.1:$hvxport";

function locateBaseURL() {
    global $userSetHost;

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";

    $domain = isset($userSetHost) && !empty($userSetHost) ? $protocol . $userSetHost : $protocol . $_SERVER['HTTP_HOST'];

    $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
    $scriptDir = ($scriptDir === '/' || $scriptDir === '\\') ? '' : trim($scriptDir, '/\\');

    $baseUrl = rtrim($domain, '/') . '/' . $scriptDir;
    $baseUrl = rtrim($baseUrl, '/') . '/'; // Ensure only one trailing slash

    return $baseUrl;
}


	

function accessLog() {
    $logFile = 'access.log';
	
    $urlComponents = parse_url($_SERVER['REQUEST_URI']);
    $queryString = isset($urlComponents['query']) ? $urlComponents['query'] : '';
	
    parse_str($queryString, $queryParams);
    
    if (!isset($queryParams['dev'])) {
        $queryParams['dev'] = 'true'; // Set only if not already set
    }
	
    $newQueryString = http_build_query($queryParams);
	
    $modifiedUri = $urlComponents['path'];
    if (!empty($newQueryString)) {
        $modifiedUri .= '?' . $newQueryString;
    }
    if (isset($urlComponents['fragment'])) {
        $modifiedUri .= '#' . $urlComponents['fragment'];
    }
    
    // Log the data with the modified URI
    $logData = date('Y-m-d H:i:s') . ' ' . $_SERVER['REMOTE_ADDR'] . ' ' . $_SERVER['REQUEST_METHOD'] . ' ' . $modifiedUri . PHP_EOL;
    file_put_contents($logFile, $logData, FILE_APPEND);
}

 
?>
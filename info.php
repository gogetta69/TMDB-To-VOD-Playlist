<?php
// Created By gogetta.teams@gmail.com
// Please leave this in this script.
// https://github.com/gogetta69/TMDB-To-VOD-Playlist

require_once 'config.php';
$baseUrl = locateBaseURL();

// Function to check if an extension is loaded
function check_extension($extension) {
    return extension_loaded($extension) ? '✔️' : '❌';
}

// Function to check if mod_rewrite is enabled
function check_mod_rewrite() {
    if (in_array('mod_rewrite', apache_get_modules())) {
        return '✔️';
    } else {
        return '❌';
    }
}


// Function to check if HeadlessVidX is running.
function HeadlessVidX_online() {   
 
   global $HeadlessVidX_ServerPort;
   
   $response = @file_get_contents('http://' . $HeadlessVidX_ServerPort . '/ping');
    if(strpos($response, 'Server is running')){
        return "✔️ - <strong>Server: </strong><a href='http://$HeadlessVidX_ServerPort/' target=_blank'>http://$HeadlessVidX_ServerPort/</a>";
    } else {
        $nodejsMessage = '<span style="color:red;font-size:14px;">❌ <br> Follow the "HeadlessVidX/Install Instructions.txt"</span>';
        return $nodejsMessage;
    }
}


?>

<!DOCTYPE html>
<html>
<head>
    <title>Information and URLs</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto">

    <style>
    body {
        font-family: 'Roboto', sans-serif;
        max-width: 800px; /* Adjust the maximum width as needed */
        margin: 0 auto; /* Center the body horizontally */
        padding: 20px; /* Add padding for better readability */
    }

    /* Set the font size and color */
    h1 {
        font-size: 30px;
        color: #667;
    }

    /* Set the font size and color */
    h3 {
        font-size: 21px;
        color: #667;
    }

    p {
        font-size: 16px;
        color: #667; 
    }

    span {
        font-size: 16px;
        color: #0808ff;; 
    }

    .status {
        font-size: 18px;
    }
    </style>
</head>
<body>

<h3>System Check</h3>
<div class="check">
<p class="status"><strong>DOM extension (PHP XML extension):</strong> <?= check_extension('dom'); ?></p>
<p class="status"><strong>cURL extension:</strong> <?= check_extension('curl'); ?></p>
<p class="status"><strong>Mod Rewrite Enabled:</strong> <?= check_mod_rewrite(); ?></p>
<p class="status"><strong>HeadlessVidX Online:</strong> <?= HeadlessVidX_online(); ?></p>
</div>

<h1>Information and URLs</h1>

<p><strong>Important Note:</strong> Please ensure that you place this script and its files in the root directory. Some applications may not work well with subdirectories. While it's possible to run this script from a subdirectory, I recommend using the root directory. It worked seamlessly with apps like Tivimate and Streamers but not with NextTV.</p>

<p>The first step is to set up the `config.php` file. If you haven't done this yet, please configure the `config.php` file using any text editor of your choice. Once you've completed this step, proceed to load this page in your browser using your domain or IP address.</p>

<p>Example URL: http://192.168.0.93/info.php</p>

<p>This page is exclusively designed for listing the URLs required to operate the script. For more detailed instructions and setup videos, please <a href="https://github.com/gogetta69/TMDB-To-VOD-Playlist" target="_blank">click here</a>.</p>

<h3>- Create Playlists</h3>

<p>You no longer need to manually run `create_playlist.php` and `create_tv_playlist.php`. With the workflow set up on GitHub, these playlists are automatically generated twice daily. Simply set `$userCreatePlaylist` to `false` in the `config.php` file to use this feature.</p>

<p>If you prefer to create the playlists manually, you can still run the following scripts: 
- <strong>Movies:</strong> `create_playlist.php`
- <strong>TV Series:</strong> `create_tv_playlist.php`

You can schedule these scripts to run once or twice daily using Windows Scheduler or as a cron job through your hosting panel. For a step-by-step guide, watch the [ <a href="https://vimeo.com/875236252?share=copy" target="_blank">Setup Video</a> ].</p>

 

<p>Note: Running the playlist create scripts can take a while to complete.</p>

<p><strong>Create Movie Playlist:</strong> <span><?=$baseUrl?>create_playlist.php </span></p>

<p><strong>Create Series Playlist:</strong> <span><?=$baseUrl?>create_tv_playlist.php</span></p>

<h3>- Xtream Codes</h3>

<p>Using Xtream Codes to load your playlist in various apps is highly recommended. Most applications that support playlist importation are compatible with Xtream Codes. By entering your Xtream Codes details into the app, your movies, live TV, and TV shows will be automatically added, providing an efficient and streamlined experience. This method is generally preferred over the m3u8 option for its ease of use and comprehensive integration.</p>

<p><strong>Server Address: </strong> <span><?= rtrim($baseUrl, '/') ?></span></p>

<p><strong>Username: </strong> <span>Enter anything into the field...</span></p>

<p><strong>Password: </strong> <span>Enter anything into the field...</span></p>

<h3>- Loading Playlists</h3>

<p>Your playlist will automatically load into your apps when using the Xtream Codes option. Alternatively, you can manually enter the M3U8 list. Please note that the M3U8 option works only with Live TV and Movies playlists. TV Shows do not have an M3U8 playlist available.</p> 

<p>The EPG (XML) is already embedded within the Live TV Playlist, so manual loading is unnecessary.</p>

<p>With TheTVAPP now featuring sports content, it's recommended setting your playlist and EPG to update at least every 12 hours within your app if you're an avid sports fan who prefers up-to-date data.</p>

<p><strong>M3U8 Live TV Playlist: </strong> <span><?=$baseUrl?>tv.m3u8</span></p>

<p><strong>M3U8 Movies Playlist: </strong> <span><?=$baseUrl?>movies.m3u8</span></p>

<p><strong>EPG TV Guide: </strong> <span><?=$baseUrl?>xmltv.php</span></p>

<h3>- View HTML Logs</h3>

<p>The log may not be perfect, but it's much better than it was before. You can use the log to identify the sources of the links, as well as the extractors and services being utilized. This helps in determining whether a site should be commented out or moved further down the list under the `$userDefinedOrder` in the `config.php` file.</p>

<p><strong>Detailed HTML Log:</strong> <span><?=$baseUrl?>detailed_log.html</span></p>

<p>Tip: Clicking on the 'Access URL' will allow you to gain further insights into what's taking place.</p>

</body>
</html>

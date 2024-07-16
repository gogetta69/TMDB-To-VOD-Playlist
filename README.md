# TMDB To VOD Playlist
Create Movies and TV Series Video on Demand (VOD) Playlist's using Xtream Codes or M3U8 Format.

Generate video-on-demand movie and TV series playlists effortlessly with this script. The script utilizes TMDB and Real Debrid (with a few direct sources that do not require a Real Debrid API key) to dynamically create playlists. By emulating Xtream Codes apps like Tivimate, IPTV Streamers Pro, XCIPTV Player, and others, it provides comprehensive metadata including descriptions, cast and crew details, trailers, poster images, and backdrop images.

<table>
  <tr>
    <td align="center">
      <img src="https://github.com/gogetta69/TMDB-To-VOD-Playlist/raw/main/images/101623110311.png" width="400">
    </td>
    <td align="center">
      <img src="https://github.com/gogetta69/TMDB-To-VOD-Playlist/raw/main/images/101623110433.png" width="400">
    </td>
    <td align="center">
      <img src="https://github.com/gogetta69/TMDB-To-VOD-Playlist/raw/main/images/101623110501.png" width="400">
    </td>
  </tr>
  <tr>
    <td align="center">
      <img src="https://github.com/gogetta69/TMDB-To-VOD-Playlist/raw/main/images/101623110535.png" width="400">
    </td>
    <td align="center">
      <img src="https://github.com/gogetta69/TMDB-To-VOD-Playlist/raw/main/images/101623110653.png" width="400">
    </td>
    <td align="center">
      <img src="https://github.com/gogetta69/TMDB-To-VOD-Playlist/raw/main/images/101623110819.png" width="400">
    </td>
  </tr>
  <tr>
    <td align="center">
      <img src="https://github.com/gogetta69/TMDB-To-VOD-Playlist/raw/main/images/101623110832.png" width="400">
    </td>
    <td align="center">
      <img src="https://github.com/gogetta69/TMDB-To-VOD-Playlist/raw/main/images/101623110847.png" width="400">
    </td>
    <td align="center">
      <img src="https://github.com/gogetta69/TMDB-To-VOD-Playlist/raw/main/images/101623111001.png" width="400">
    </td>
  </tr>
  <tr>
    <td align="center">
      <img src="https://github.com/gogetta69/TMDB-To-VOD-Playlist/raw/main/images/101623111026.png" width="400">
    </td>
    <!-- Add more images and rows as needed -->
  </tr>
</table>

## Features

- Dynamic playlist generation for movies and TV series
- Integration with TMDB and Real Debrid for enhanced content retrieval
- Emulation of Xtream Codes apps for full metadata details
- Inclusion of Daddylive HD and TheTVApp as a Live TV source.  
  If you get a 403 error while playing the live tv try using an external player like the MxPlayer.
- Support for configuring referrer for playing live streams
- Automatic caching of found links for efficient playback

## Getting Started

[![Video Thumbnail](https://raw.githubusercontent.com/gogetta69/TMDB-To-VOD-Playlist/main/images/thumb.PNG)](https://rumble.com/embed/v54v3nx/?pub=4)

1. **Configuration**: Begin by configuring the script with a mandatory free TMDB API key and an optional Real Debrid private key.

2. **Xtream Codes Integration**: Enter the IP address or domain as an Xtream Codes server. Any username and password will work since the script doesn't require authentication. This will automatically load the Live TV, Movies and TV Series playlists into the app.

3. **Non-Xtream Codes Apps**: If your app does not support Xtream Codes, load http://IP_ADDRESS/player_api.php?action=get_vod_streams (replace IP_ADDRESS with your computers ip address) in your browser, then locate the `playlist.m3u8` in the same folder as the script and load it as an M3U playlist. Note that the M3U8 playlists are available for movies and live TV only; TV series cannot be loaded as an M3U playlist.

5. **Playback**: Once everything is set up and the playlists are loaded, you should be able to play a video. Clicking the play button will trigger the script to search multiple websites in the background for a playable link. Please be patient and allow some time for a link to be found and streaming to commence. The script caches and stores the found link for approximately 3 hours, aligning with the typical access token expiration of most direct sources, which occurs at around 4 hours.

5. **Local Hosting**: If you lack a hosting company to run this extremely lightweight script, you can install and run software on your desktop computer like Xampp.

## Contribution and Feedback

This project started as a weekend experiment to learn how to code. I'm committed to refining and expanding it if there's enough interest from users like you. Your feedback and support are invaluable!

- Added the Premiumize service as an alternative to Real-Debrid. (used only with torrent sites)
- Added threads when searching torrent sites for magnet links. (speeds up the time it takes to find a link)
- Added and fixed direct movie and TV show sources as well as more link extractors.
- Added TheTvApp sports section in the Live TV Playlist (set your app to load EPG and playlist every 12 hours or less.)
- Added PlutoTV to the live TV playlist (Multi Languages Here: https://github.com/matthuisman/i.mjh.nz)
- Redesigned the Live TV and DaddyLive functions and playlist. (all of the images in the playlist are working)
- Fixed a lot of bugs in the torrent search and filtering functions. (it finds links much more often now)
- Fixed the sorting by resolution and more likely to get higher quality links (torrent sites)


## Updated (07/11/2024):

Added adult movies to vod (disabled by default)<br>
Added more direct movie scrapers<br>
Added more browsers to HeadlessVidX<br>
Fixed some bugs in HeadlessVidX<br>
Fixed Daddylive <br>
Fixed EPG for DaddyLive<br>
Fixed TheTVApp Sports section<br>
Fixed showBox_media<br><br>

showBox_media requires a login and a cookie string to be added to sessions/showbox_media_cookies.txt
in order to extract links. Taking the time to add these cookies is well worth it if you aren't using
a premium link service and are seeking the best quality possible. How to: videos/how_to_showbox_media_cookie.mp4

## What is HeadlessVidX?​
HeadlessVidX is a tool designed to simplify the development of video extractors for streaming websites. It provides an easy-to-use solution for users, regardless of their programming skills, to quickly add video streaming sites to tools such as 'TMDB TO VOD'.
<table>
  <tr>
<td align="center">
        <img src="https://raw.githubusercontent.com/gogetta69/TMDB-To-VOD-Playlist/main/images/Screenshot%202024-06-14%20at%2016-41-13%20HeadlessVidX%20-%20Home.png" width="400">
    </td>
    <td align="center">
     <img src="https://raw.githubusercontent.com/gogetta69/TMDB-To-VOD-Playlist/main/images/Screenshot%202024-06-14%20at%2016-40-15%20HeadlessVidX%20-%20Trainer.png" width="400">   
    </td>
  </tr>
</table>

## Creating Playlist:​
You no longer need to manually run create_playlist.php and create_tv_playlist.php. With the workflow set up on GitHub, these playlists are automatically generated twice a day. To create your own movies and series playlist, simply set $userCreatePlaylist to true in the config.php file.

[Download & Watch User Create Movies & Series Playlist](https://github.com/gogetta69/public-files/raw/main/videos/user_create_movies_series_playlist.mp4)



## Legal Disclaimer

This script retrieves movie information from TMDB and searches for related content on third-party websites. The legality of streaming or downloading content through these websites is uncertain. Please exercise caution and consider the legal and ethical implications of using this script to access and consume copyrighted content. Always respect copyright laws and the terms of service of the websites you visit.


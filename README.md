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
- Inclusion of Daddylive HD as a Live TV source (load daddylive_playlist_m3u.php as an M3U List).  
  If you get a 403 error while playing the live tv try using an external player like the MxPlayer.
- Support for configuring referrer for playing live streams
- Automatic caching of found links for efficient playback

## Getting Started

[![Video Thumbnail](https://raw.githubusercontent.com/gogetta69/TMDB-To-VOD-Playlist/main/images/thumb.PNG)](https://vimeo.com/875236252?share=copy)

1. **Configuration**: Begin by configuring the script with a mandatory free TMDB API key and an optional Real Debrid private key.

2. **Run the Scripts**: Execute `create_playlist.php` for movies and `create_tv_playlist.php` for TV series. Schedule these two files to run once or twice daily using Windows Scheduler or as a cron job through your hosting panel.

3. **Xtream Codes Integration**: Once the scripts have been executed at least once, you can enter your IP address or domain as an Xtream Codes server. The username and password can be set to anything since the script doesn't require authentication. This will automatically load the previously generated Movies and TV Series playlists into the app.

4. **Non-Xtream Codes Apps**: If your app does not support Xtream Codes, locate the `playlist.m3u8` in the same folder after running `create_playlist.php` and load it as an M3U playlist. Note that M3U playlists are available for movies and live TV only; TV series cannot be loaded as an M3U playlist.

5. **Playback**: Once everything is set up and the playlists are loaded, you should be able to play a video. Clicking the play button will trigger the script to search multiple websites in the background for a playable link. Please be patient and allow some time for a link to be found and streaming to commence. The script caches and stores the found link for approximately 3 hours, aligning with the typical access token expiration of most direct sources, which occurs at around 4 hours.

6. **Local Hosting**: If you lack a hosting company to run this extremely lightweight script, you can install and run software on your desktop computer like Xampp.

## Contribution and Feedback

This project started as a weekend experiment to learn how to code. I'm committed to refining and expanding it if there's enough interest from users like you. Your feedback and support are invaluable!

## Updated (12/08/2023):

- Added the Premiumize service as an alternative to Real-Debrid. (used only with torrent sites)
- Added threads when searching torrent sites for magnet links. (speeds up the time it takes to find a link)
- Added and fixed direct movie and TV show sources as well as more link extractors.
- Added TheTvApp sports section in the Live TV Playlist (set your app to load EPG and playlist every 12 hours or less.)
- Added PlutoTV to the live TV playlist (Multi Languages Here: https://github.com/matthuisman/i.mjh.nz)
- Redesigned the Live TV and DaddyLive functions and playlist. (all of the images in the playlist are working)
- Fixed a lot of bugs in the torrent search and filtering functions. (it finds links much more often now)
- Fixed the sorting by resolution and more likely to get higher quality links (torrent sites)

After installation open the Read Me.txt or load the http://YOUR_IP_ADDRESS/info.php in your browser.

Overall this update should fix a lot of the problems I am sure that you were having. I'm learning as I go and the old code had a lot of mistakes that I have now fixed.

## Updated (01/11/2024):

Fixed the DaddyLive as well fixed an issue that was stopping the EPG from updating now that i.mjh.nz has disabled Pluto. Once they start updating Pluto again the links should start showing back within your app.

DaddyLive has now started using AES Encryption within their playlist and i have added a fix within the hls_proxy.php for this. However, if you are using Tivimate you will need to use the included DADDYLIVE (tivimate).m3u8 file instead. I will continue to look for a more permanent solution, but this is what I have for now.

Files changed:

hls_proxy.php<br>
xmltv.php<br>
live_play.php<br>
generate_live_playlist.php<br>
DADDYLIVE (tivimate).m3u8 - Included the DaddyLive m3u8 since Tivimate keeps restarting the stream when a key is used while proxing. If you are using Tivimate and notice the stream restarting every 5 seconds try using this m3u8.


## Legal Disclaimer

This script retrieves movie information from TMDB and searches for related content on third-party websites. The legality of streaming or downloading content through these websites is uncertain. Please exercise caution and consider the legal and ethical implications of using this script to access and consume copyrighted content. Always respect copyright laws and the terms of service of the websites you visit.


My Contact: https://t.me/gogetta69

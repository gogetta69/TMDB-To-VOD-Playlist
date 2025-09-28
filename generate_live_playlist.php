<?php

require_once 'config.php';

function channelIdFromName($name) {
    // Deterministic hash for channel name
    $hash = crc32($name);
    $id = sprintf('%u', $hash); // make unsigned
    return $id % 1000000000;    // under 1 billion
}

function runLivePlaylistGenerate() {
		global $INCLUDE_ADULT_VOD;

		$include = filter_var($INCLUDE_ADULT_VOD ?? false, FILTER_VALIDATE_BOOLEAN);

		$playlistUrl = $include
				? 'https://raw.githubusercontent.com/Drewski2423/DrewLive/refs/heads/main/MergedPlaylist.m3u8'
				: 'https://raw.githubusercontent.com/Drewski2423/DrewLive/refs/heads/main/MergedCleanPlaylist.m3u8';
	    
    $categoriesFile = __DIR__ . "/channels/get_live_categories.json";

    $playlist = @file_get_contents($playlistUrl);

    if ($playlist === false) {
        die("Failed to fetch playlist");
    }

		$playlist = preg_replace(
    '#(?<=url-tvg=")[^"]+(?=")#',
    'https://github.com/lubby1234/b/raw/refs/heads/main/merged2_epg.xml.gz',
    $playlist);

file_put_contents('channels/live_playlist.m3u8', $playlist);

    $lines = explode("\n", $playlist);

    $parsedData = [];
    $categories = [];
    $categoryMap = [];
    $num = 1;
    $catCounter = 200;

    $usedIds = []; // to track collisions

    $streamUrl = "";
    foreach ($lines as $line) {
        $line = trim($line);
        if (strpos($line, "#EXTINF:") === 0) {
            if (preg_match('/tvg-id="([^"]*)".*tvg-logo="([^"]*)".*group-title="([^"]*)",(.*)$/', $line, $matches)) {
                $epgId = $matches[1];
                $logo = $matches[2];
                $group = $matches[3];
                $channelName = $matches[4];

                // Assign category ID
                if (!isset($categoryMap[$group])) {
                    $categoryMap[$group] = $catCounter++;
                    $categories[] = [
                        "category_id" => (string)$categoryMap[$group],
                        "category_name" => $group,
                        "parent_id" => 0
                    ];
                }

                // Generate stable stream_id from channel name
                $streamId = channelIdFromName($channelName);
                while (isset($usedIds[$streamId])) {
                    // handle collisions by incrementing
                    $streamId = ($streamId + 1) % 1000000000;
                }
                $usedIds[$streamId] = true;

                // Prepare entry
                $parsedData[] = [
                    "num" => $streamId,
                    "name" => trim($channelName),
                    "stream_type" => "live",
                    "stream_id" => $streamId,  // stable unique ID
                    "stream_icon" => $logo,
                    "epg_channel_id" => $epgId,
                    "added" => time(),
                    "category_id" => $categoryMap[$group],
                    "custom_sid" => "",
                    "tv_archive" => 0,
                    "direct_source" => "",
                    "tv_archive_duration" => 0,
                    "video_url" => ""
                ];
                $num++;
            }
        } elseif ($line && strpos($line, "#") !== 0) {
            // Assume this is the stream URL following EXTINF
            $lastIndex = count($parsedData) - 1;
            if ($lastIndex >= 0) {
                $parsedData[$lastIndex]["direct_source"] = $line;
                $parsedData[$lastIndex]["video_url"] = $line;
            }
        }
    }

    // Save categories
    if (!is_dir(dirname($categoriesFile))) {
        mkdir(dirname($categoriesFile), 0777, true);
    }
    file_put_contents($categoriesFile, json_encode($categories, JSON_PRETTY_PRINT));
    file_put_contents('channels/live_playlist.json', json_encode($parsedData, JSON_PRETTY_PRINT));

    return $parsedData;
}

// If run directly, output JSON
if (php_sapi_name() === "cli" || isset($_GET["debug"])) {
    header("Content-Type: application/json");
    echo json_encode(runLivePlaylistGenerate(), JSON_PRETTY_PRINT);
}

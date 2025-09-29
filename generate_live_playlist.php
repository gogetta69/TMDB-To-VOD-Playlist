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
        $playlist
    );

    file_put_contents('channels/live_playlist.m3u8', $playlist);

    $lines = explode("\n", $playlist);

    $parsedData  = [];
    $categories  = [];
    $categoryMap = [];
    $catCounter  = 200;
    $usedIds     = [];

    foreach ($lines as $line) {
        $line = trim($line);

        if (strpos($line, "#EXTINF:") === 0) {
            $attrs = [];
            if (preg_match_all('/([\w\-]+)\s*=\s*"([^"]*)"/', $line, $m)) {
                foreach ($m[1] as $i => $key) {
                    $attrs[$key] = $m[2][$i];
                }
            }
            $channelName = '';
            if (preg_match('/,(.*)$/', $line, $m)) {
                $channelName = $m[1];
            }

            $epgId = $attrs['tvg-id']   ?? '';
            $logo  = $attrs['tvg-logo'] ?? '';
            $group = trim($attrs['group-title'] ?? 'Uncategorized');

            if ($channelName && $epgId && $group) {
                if (!isset($categoryMap[$group])) {
                    $categoryMap[$group] = $catCounter++;
                    $categories[] = [
                        "category_id"   => (string)$categoryMap[$group],
                        "category_name" => $group,
                        "parent_id"     => 0
                    ];
                }

                $streamId = channelIdFromName($channelName);
                while (isset($usedIds[$streamId])) {
                    $streamId = ($streamId + 1) % 1000000000;
                }
                $usedIds[$streamId] = true;

                $parsedData[] = [
                    "num"                => $streamId,
                    "name"               => trim($channelName),
                    "stream_type"        => "live",
                    "stream_id"          => $streamId,
                    "stream_icon"        => $logo,
                    "epg_channel_id"     => $epgId,
                    "added"              => time(),
                    "category_id"        => $categoryMap[$group],
                    "custom_sid"         => "",
                    "tv_archive"         => 0,
                    "direct_source"      => "",
                    "tv_archive_duration"=> 0,
                    "video_url"          => ""
                ];
            }
        } elseif ($line && strpos($line, "#") !== 0) {
            $idx = count($parsedData) - 1;
            if ($idx >= 0) {
                $parsedData[$idx]["direct_source"] = $line;
                $parsedData[$idx]["video_url"]     = $line;
            }
        }
    }

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
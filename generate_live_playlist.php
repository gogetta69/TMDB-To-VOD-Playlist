<?php

require_once 'config.php';
if (!$GLOBALS['DEBUG']) {
    error_reporting(0);	
} 	

function getPlutoTV($m3uContent) {
    $plutoM3uContent = @file_get_contents('https://raw.githubusercontent.com/gogetta69/public-files/main/Pluto-TV/us.m3u8');
	
    if (!$plutoM3uContent) {
        return false;
    }

    $lines = explode("\n", $plutoM3uContent);
    
    array_shift($lines);
	$newM3uContent ='';
    $streamId = 4000;

	$pattern = '/#EXTINF:0 tvg-id="([^"]*)" tvg-logo="([^"]*)" group-title="([^"]*)"\s*,\s*(.*)/';

    for ($i = 0; $i < count($lines); $i++) {
        $line = $lines[$i];
        if (strpos($line, '#EXTINF:') === 0) {
            if (preg_match($pattern, $line, $matches)) {
                $channelUrl = $lines[$i + 1] ?? ''; 
				preg_match('/(?<=tvg-id=").*?(?=")/', $line, $tvgId);
				$channelName = iconv('UTF-8', 'ASCII//TRANSLIT', $matches[4]);
				$channelName = str_replace('"', "'", $channelName);

                $newM3uContent .= "#EXTINF:-1 tvg-id=\"{$tvgId[0]}\" tvg-name=\"{$channelName}\" tvg-logo=\"{$matches[2]}\" group-title=\"{$matches[3]} (PlutoTV)\" streamId=\"$streamId\" channel-number=\"$streamId\",{$channelName}\n";
                $newM3uContent .= $channelUrl . "\n\n";

                $streamId++;
            }
        }
    }
	
    if (empty($newM3uContent)) {
        $oldM3uContent = file_get_contents('channels/pluto_tv_data.dat');
		$m3uContent = str_replace('#[PLUTO_TV]#', "\n" . $oldM3uContent . "\n", $m3uContent);
    } else {
	$m3uContent = str_replace('#[PLUTO_TV]#', "\n" . $newM3uContent . "\n", $m3uContent);
		file_put_contents('channels/pluto_tv_data.dat', $newM3uContent);
    
	}
	
	return $m3uContent;
}

function getTheAppSports($m3uContent){
$urls = [
    "https://thetvapp.to/nba",
    "https://thetvapp.to/mlb",
	"https://thetvapp.to/nhl",
	"https://thetvapp.to/nfl",
	"https://thetvapp.to/ncaaf",
	"https://thetvapp.to/ncaab",
	"https://thetvapp.to/soccer",
	"https://thetvapp.to/ppv"
];

// Create multiple cURL handles
$multiCurl = curl_multi_init();
$curlHandles = array();

foreach ($urls as $i => $url) {
    $curlHandles[$i] = curl_init($url);
    curl_setopt($curlHandles[$i], CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curlHandles[$i], CURLOPT_FOLLOWLOCATION, true);
    curl_multi_add_handle($multiCurl, $curlHandles[$i]);
}

$running = null;
do {
    curl_multi_exec($multiCurl, $running);
} while ($running);

foreach ($curlHandles as $curlHandle) {
    curl_multi_remove_handle($multiCurl, $curlHandle);
}
curl_multi_close($multiCurl);

$sportsData = [];
$sportsDataM3u = '';
$sportsDataItems = [];
$channelId = 3000;

$epgData = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
$epgData .= '<tv>' . "\n";

foreach ($curlHandles as $handle) {
    $htmlContent = curl_multi_getcontent($handle);

    $doc = new DOMDocument();
    @$doc->loadHTML($htmlContent);
	
	$xpath = new DOMXPath($doc);
	$h3Elements = $xpath->query("//div[contains(@class, 'row')]//h3");

	if ($h3Elements->length > 0) {
		$eventType = $h3Elements->item(0)->nodeValue;		
	} else {
		$eventType = null; 
	}
		
    foreach ($doc->getElementsByTagName('a') as $link) {
        
        if ($link->getAttribute('class') === 'list-group-item') {
			
            $href = $link->getAttribute('href');

            $text = trim($link->textContent);
            $time = '';

            // Extracting time span and removing it from text
            foreach ($link->getElementsByTagName('span') as $span) {
                $timeUTC = trim($span->textContent);

                // Convert to DateTime and adjust to EST
                $date = new DateTime($timeUTC);
                $date->setTimezone(new DateTimeZone('America/New_York')); // EST timezone

                // Format the date               
				$formattedDate = $date->format('h:i A T - (m/d/Y)');				
				$hourFormat = $date->format('g:i A');


                $time = $formattedDate; // Use formatted time

                // Remove the time from the text
                $text = str_replace($timeUTC, '', $text);
                $text = trim($text);
            }			

			$eventTime = DateTime::createFromFormat('h:i A T - (m/d/Y)', $formattedDate);
			$currentDateTime = new DateTime('now', new DateTimeZone('America/New_York'));

			// Extend the event time by 2 hours
			$extendedEventTime = clone $eventTime;
			$extendedEventTime->modify('+7200 seconds'); // Add 2 hours

			// Skip events that have already passed (considering the 2-hour extension)
			if ($extendedEventTime instanceof DateTime && $currentDateTime instanceof DateTime) {
				if ($extendedEventTime < $currentDateTime) {
					continue;
				} 
			}

			
			$tvgId = md5($text);
			$tvgId = substr($tvgId, 0, 10);
			$xmlText = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

			
			date_default_timezone_set('America/New_York');
			$currentTime = new DateTime();
			$startTime = $currentTime->setTime(0, 0, 0);
			$endTime = clone $startTime;
			$endTime->modify('+28 hours'); // Used to keep the guide data populated.
			$startTimeFormatted = $startTime->format('YmdHis') . ' -0500';
			$endTimeFormatted = $endTime->format('YmdHis') . ' -0500';

			// Generating a random event ID
			$EventId = mt_rand(1000, 9999);			
			
			$epgData .= "<channel id=\"{$tvgId}\">\n";
			$epgData .= "\t<display-name lang=\"en\">{$xmlText} - {$time}</display-name>\n";
			$epgData .= "</channel>\n";

			$epgData .= "\t<programme channel=\"" . $tvgId . "\" start=\"" . $startTimeFormatted . "\" stop=\"" . $endTimeFormatted . "\">\n";
			$epgData .= "\t\t<title>" . $xmlText . " - " . $time . "</title>\n";
			$epgData .= "\t\t<desc>Description for " . $xmlText . " - " . $time . "</desc>\n";
			$epgData .= "\t</programme>\n";

            $fullUrl = 'https://thetvapp.to' . $href;
			
			$logo = null;
			
			if(stripos($eventType, 'nba')){
				$logo = 'https://raw.githubusercontent.com/tv-logo/tv-logos/635e715cb2f2c6d28e9691861d3d331dd040285b/countries/united-states/nba-tv-icon-us.png';
			}	
			
			if(stripos($eventType, 'mlb') !== false){
				$logo = 'https://raw.githubusercontent.com/tv-logo/tv-logos/635e715cb2f2c6d28e9691861d3d331dd040285b/countries/united-states/mlb-network-us.png';
			}
			
			if(stripos($eventType, 'nhl') !== false){
				$logo = 'https://raw.githubusercontent.com/tv-logo/tv-logos/635e715cb2f2c6d28e9691861d3d331dd040285b/countries/united-states/nhl-network-us.png';
			}
			
			if(stripos($eventType, 'nfl') !== false){
				$logo = 'https://raw.githubusercontent.com/tv-logo/tv-logos/635e715cb2f2c6d28e9691861d3d331dd040285b/countries/united-states/nfl-icon-us.png';
			}
			
			if (stripos($eventType, 'ncaaf') !== false || stripos($eventType, 'College Football') !== false) {
				$logo = 'https://raw.githubusercontent.com/gogetta69/TMDB-To-VOD-Playlist/main/images/ncaaf-transparent.png';
			}

			if(stripos($eventType, 'ncaab') !== false){
				$logo = 'https://raw.githubusercontent.com/gogetta69/TMDB-To-VOD-Playlist/main/images/ncaab-transparent.png';
			}				
			
			if(stripos($eventType, 'mls') !== false){
				$logo = 'https://raw.githubusercontent.com/gogetta69/TMDB-To-VOD-Playlist/main/images/mls-transparent.png';
			}
			
			if(stripos($eventType, 'ppv') !== false){
				$logo = 'https://raw.githubusercontent.com/gogetta69/TMDB-To-VOD-Playlist/main/images/ppv.png';
			}
			
			if($logo === null){
				$logo = 'https://raw.githubusercontent.com/gogetta69/TMDB-To-VOD-Playlist/main/images/live-tv-transparent.png';
			}			
			 
		$m3uLine = "#EXTINF:-1 tvg-id=\"{$tvgId}\" tvg-name=\"{$text} - {$time}\" tvg-logo=\"{$logo}\" group-title=\"Sports (TheTVApp)\" streamId=\"{$channelId}\" channel-number=\"{$channelId}\",{$hourFormat} - {$text} - {$time}\n{$fullUrl}";
			
			$sportsDataItems[] = $m3uLine;			
			
			$channelId++;			

        }
    }  
}

$epgData .= '</tv>';

file_put_contents('channels/thetvapp_sports_epg.xml', $epgData);

if (!empty($sportsDataItems)) {
    
    $sportsDataM3u = '';
    foreach ($sportsDataItems as $index => $item) {
        $sportsDataM3u .= $item;
        if ($index < count($sportsDataItems) - 1) {
            $sportsDataM3u .= "\n\n";
        }
    }
    
    $m3uContent = str_replace('#[THETVAPP_SPORTS]#', "\n" . $sportsDataM3u . "\n", $m3uContent);
	
} else {
    
    $m3uContent = str_replace('#[THETVAPP_SPORTS]#', '', $m3uContent);
}

return $m3uContent;

}

function getTopEmbedSports($m3uContent){
    $url = "https://topembed.pw/old.php?exclude_tennis=true";

    // Initialize cURL
    $curl = curl_init($url);
    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, Gecko) Chrome/58.0.3029.110 Safari/537.36';
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($curl, CURLOPT_USERAGENT, $userAgent);

    // Execute cURL session
    $htmlContent = curl_exec($curl);
    curl_close($curl);

    $sportsDataItems = [];
    $channelId = 5000;

    $doc = new DOMDocument();
    @$doc->loadHTML($htmlContent);
    $xpath = new DOMXPath($doc);

    // Find the first date header
    $firstDateHeader = $xpath->query("//tr[contains(@class, 'bg-gray-800 text-white')][1]")->item(0);

    if ($firstDateHeader) {
        $rows = $xpath->query("following-sibling::tr", $firstDateHeader);
        foreach ($rows as $row) {
            // Stop if another date header is encountered
            if (strpos($row->getAttribute('class'), 'bg-gray-800 text-white') !== false) {
                break;
            }

            $tds = $xpath->query('td', $row);
            if ($tds->length == 5) {
                // TIME
                $timestamp = $tds->item(0)->getAttribute('data-timestamp');
                $date = new DateTime("@$timestamp");
                $date->setTimezone(new DateTimeZone('America/New_York'));
                $hourFormat = $date->format('g:i A');

                // CATEGORY
                $category = trim($tds->item(1)->textContent);

                // INFO
                $info = trim($tds->item(2)->textContent);

                // TITLE
                $title = trim($tds->item(3)->textContent);

                // URL
                $inputField = $tds->item(4)->getElementsByTagName('input')->item(0);
                $url = $inputField->getAttribute('value');

                $group = 'TopEmbed (Other)';

                // Set logo based on category
                $logo = 'https://raw.githubusercontent.com/gogetta69/TMDB-To-VOD-Playlist/main/images/live-tv-transparent.png'; // Default logo
                if ($category === 'Football') {
                    $logo = 'https://i.imgur.com/yqrF43n.png';
                    $group = 'TopEmbed (Football)';
                }
                
                if (stripos($category, 'Basketball') !== false) {
                    $logo = 'https://i.imgur.com/h9V9Ywc.png';
                    $group = 'TopEmbed (Basketball)';
                }
                
                if (stripos($category, 'American Football') !== false) {
                    $logo = 'https://i.imgur.com/kO1j5Mb.png';
                    $group = 'TopEmbed (Am. Football)';
                }
                
                if (stripos($category, 'Rugby') !== false) {
                    $logo = 'https://i.imgur.com/qkDx0or.png';
                    $group = 'TopEmbed (Rugby)';
                }
                
                if (stripos($category, 'Cricket') !== false) {
                    $logo = 'https://i.imgur.com/2eR9C15.png';
                    $group = 'TopEmbed (Cricket)';
                }
                
                if (stripos($category, 'MMA') !== false) {
                    $logo = 'https://i.imgur.com/hgeD1wK.png';
                    $group = 'TopEmbed (MMA)';
                }
                
                if (stripos($category, 'Baseball') !== false) {
                    $logo = 'https://i.imgur.com/SkRCcl7.png';
                    $group = 'TopEmbed (Baseball)';
                }
                
                if (stripos($category, 'Aussie rules') !== false) {
                    $logo = 'https://i.imgur.com/omHYtc7.png';
                    $group = 'TopEmbed (Aussie rules)';
                }
                
                if (stripos($category, 'Volleyball') !== false) {
                    $logo = 'https://i.imgur.com/h1SxV7u.png';
                    $group = 'TopEmbed (Volleyball)';
                }
                
                if (stripos($category, 'Boxing') !== false) {
                    $logo = 'https://i.imgur.com/HI2cjRZ.png';
                    $group = 'TopEmbed (Boxing)';
                }

                $tvgId = substr(md5($title), 0, 10);
                $formattedTime = $date->format('h:i A T - (m/d/Y)');
                $m3uLine = "#EXTINF:-1 tvg-id=\"$tvgId\" tvg-name=\"$hourFormat $title - $formattedTime\" tvg-logo=\"$logo\" group-title=\"$group\" streamId=\"{$channelId}\" channel-number=\"{$channelId}\",$hourFormat - $title - $formattedTime\n$url";

                $sportsDataItems[] = $m3uLine;
                $channelId++;
            }
        }
    }

    if (!empty($sportsDataItems)) {
        $sportsDataM3u = implode("\n\n", $sportsDataItems);
        return str_replace('#[TOP_EMBED_SPORTS]#', "\n$sportsDataM3u\n", $m3uContent);
    } else {
        return str_replace('#[TOP_EMBED_SPORTS]#', '', $m3uContent);
    }
}

function setupLiveStreams($m3uContent)
{		
	global $LiveTVServices;
	
    $baseUrl = locateBaseURL();
	$lastUpdatedFile = "channels/last_updated_channels.txt";
    $lines = explode("\n", $m3uContent);
    $parsedData = [];
	$num = 0;

	$groupTitleMapping = [];
	$categories = [];
	$groupNumber = 200; 
	
	foreach ($lines as $line) {
		if (strpos($line, '#EXTINF:') === 0) {
			preg_match('/group-title="([^"]*)"/', $line, $matches);
			if ($matches) {
				$groupTitle = $matches[1];
				if (!array_key_exists($groupTitle, $groupTitleMapping)) {
					
					$groupTitleMapping[$groupTitle] = $groupNumber;

					$categories[] = [
						"category_id" => (string)$groupNumber,
						"category_name" => $groupTitle,
						"parent_id" => 0
					];

					$groupNumber++;
				}
			}
		}
	}
	
	$parsedData = [];
	$num = 0;
	
	if (!isset($LiveTVServices) || $LiveTVServices['DaddyLive'] === true) {
		$daddyData = getDaddyLiveSource('https://dlhd.so/embed/stream-303.php');
	}
	
	for ($i = 0; $i < count($lines); $i++) {
		$line = $lines[$i];
		if (strpos($line, '#EXTINF:') === 0) {
			preg_match('/#EXTINF:-1 tvg-id="([^"]*)" tvg-name="([^"]*)" tvg-logo="([^"]*)" group-title="([^"]*)" streamId="([^"]*)" channel-number="([^"]*)",([^
	]*)/', $line, $matches);
			if ($matches) {
				$urlLine = $lines[$i + 1];
				$categoryTitle = $matches[4];
				$categoryId = array_key_exists($categoryTitle, $groupTitleMapping) ? $groupTitleMapping[$categoryTitle] : 0;
				$streamUrl = $baseUrl . 'live_play.php?streamId=' . (int)$matches[5];
				$m3uContent = str_replace($urlLine, $streamUrl, $m3uContent);
				
				if(stripos($urlLine, 'dlhd.') || stripos($categoryTitle, 'daddy')){					
					if (isset($LiveTVServices) && $LiveTVServices['DaddyLive'] !== true) {
						continue;
					}
					$urlLine = 'DaddyLive|' . replaceDaddyLiveUrl($daddyData['url'], $urlLine) . $daddyData['ref'];
				}

				if(stripos($urlLine, 'thetvapp.') || stripos($categoryTitle, 'thetvapp')){					
					if (isset($LiveTVServices) && $LiveTVServices['TheTVApp'] !== true) {
						continue;
					}
				}	

				if(stripos($urlLine, 'moveonjoy.') || stripos($categoryTitle, 'moveonjoy')){					
					if (isset($LiveTVServices) && $LiveTVServices['MoveOnJoy'] !== true) {
						continue;
					}
				}					
								
				$parsedData[] = [
					'num' => (int)$num, 
					'name' => trim($matches[7]),
					'stream_type' => 'live', 
					'stream_id' => (int)$matches[5], 
					'stream_icon' => $matches[3],
					'epg_channel_id' => $matches[1], 
					'added' => time(), 
					'category_id' => $categoryId,
					'custom_sid' => '', 
					'tv_archive' => 0,					
					'direct_source' => $streamUrl,
					'tv_archive_duration' => 0,
					'video_url' => trim($urlLine),
				];
				$i++;
			}
			$num++;
		}
	}	

	
	if (is_array($categories) && !empty($categories)) {	
		file_put_contents('channels/get_live_categories.json', json_encode($categories, JSON_PRETTY_PRINT));
	}	
	
	if ($m3uContent) {	
		file_put_contents('channels/live_playlist.m3u8', $m3uContent);
	}

	if (is_array($parsedData) && !empty($parsedData)) {		
		file_put_contents($lastUpdatedFile, time());
		file_put_contents('channels/live_playlist.json', json_encode($parsedData, JSON_PRETTY_PRINT));
	}
	return $m3uContent;
}

function getDaddyLiveSource($url) {
    $url = str_replace(["/cast/", "/stream/"], "/embed/", $url);
    
    $headers = [
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/117.0",
        "Referer: https://dlhd.so",
        "Accept-Language: en-US,en;q=0.5",
        "Connection: keep-alive",
        "Upgrade-Insecure-Requests: 1",
        "Sec-Fetch-Des: document",
        "Sec-Fetch-Mode: navigate",
        "Sec-Fetch-Site: same-origin",
        "Sec-Fetch-User: ?1",
        "Pragma: no-cache",
        "Cache-Control: no-cache"
    ];

    // Initial request to get the iframe src
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        return false;
    }

    preg_match('/<iframe src="([^"]+)"/', $response, $matches);
    curl_close($ch);

    if (!isset($matches[1])) {
        return false;
    }

    $iframe_src = $matches[1];
   
    // Second request to follow the iframe src
    $ch = curl_init($iframe_src);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HEADER, true);

    $response2 = curl_exec($ch);
    if (curl_errno($ch)) {
        return false;
    }
	
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body = substr($response2, $header_size);

    curl_close($ch);

    $parsed_url_iframe = parse_url($iframe_src);
    $ref = $parsed_url_iframe['scheme'] . '://' . $parsed_url_iframe['host'] . '/';
    $org = $parsed_url_iframe['scheme'] . '://' . $parsed_url_iframe['host'];	
	
	// set a fallback url.
	$liveUrl = 'https://webhdrus.onlinehdhls.ru/lb/premium302/index.m3u8';
	
	/*echo $body;
	 exit; */	 
	
	if (preg_match('#(?<=encryptedEmbed \= ").*?(?=")#', $body, $matches)) {
		
		$liveUrl = base64_decode($matches[0]);
		
	} elseif (preg_match('#http.*?premium[0-9]*/index\.m3u8#', $body, $matches)) {
		
		$liveUrl = $matches[0];
	}


	$m3u8_url = $liveUrl;
	
    // Return the m3u8 URL with the appropriate ref and org headers
    return [
        'url' => $m3u8_url,
        'ref' => '|Referer="' . $ref . '"|User-Agent="Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:123.0) Gecko/20100101 Firefox/126.0"|Origin="' . $org . '"'
    ];
}

function replaceDaddyLiveUrl($originalString, $replacementSource) {
    // Extract digits from the replacement source that follow "-"
    preg_match('/stream-(\d+)/', $replacementSource, $replacementMatches);
    if (!empty($replacementMatches)) {
        $replacementDigits = $replacementMatches[1];

        // Extract the last sequence of digits in the original string before "/index.m3u8"
        preg_match('/\d+(?=\/index\.m3u8$)/', $originalString, $originalMatches);
        if (!empty($originalMatches)) {
            // Replace the digits in the original string
            return str_replace($originalMatches[0], $replacementDigits, $originalString);
        }
    }
    return $originalString;
}

function runLivePlaylistGenerate(){

global $LiveTVServices;

$remoteUrl = 'https://raw.githubusercontent.com/gogetta69/public-files/main/m3u_formatted.dat';
$localFilePath = 'channels/m3u_formatted.dat';

$m3uContent = @file_get_contents($remoteUrl);

if ($m3uContent === false) {

    $m3uContent = file_get_contents($localFilePath);
   
    if ($m3uContent === false) {        
        exit('Error: Unable to fetch m3u content.');
    }
} else {    
    if (file_put_contents($localFilePath, $m3uContent) === false) {        
        error_log('Failed to save the remote m3u content to the local file.');
    }
}

$baseUrl = locateBaseURL();
$m3uContent = str_replace('[XML_EPG]', $baseUrl . 'xmltv.php', $m3uContent);

//Setup TheTVApp
if (!isset($LiveTVServices) || $LiveTVServices['TheTVApp'] === true) {
	$m3uAddSportsContent = getTheAppSports($m3uContent);
	if ($m3uAddSportsContent) {
		$m3uContent = $m3uAddSportsContent;
	}	
}

//Setup TopEmbed.
if (!isset($LiveTVServices) || $LiveTVServices['TopEmbed'] === true) {
	$m3uAddSportsContent = getTopEmbedSports($m3uContent);
	if ($m3uAddSportsContent) {
		$m3uContent = $m3uAddSportsContent;
	}
}

//Setup pluto.
if (!isset($LiveTVServices) || $LiveTVServices['Pluto'] === true) {
	$plutoM3uContent = getPlutoTV($m3uContent);
	if($plutoM3uContent){
		$m3uContent = $plutoM3uContent;	
	}
}

//Setup TopEmbed.
if (!isset($LiveTVServices) || $LiveTVServices['TopEmbed'] === true) {
	$topEmbedContent = @file_get_contents('channels/top-embed.txt');
	if (strpos($m3uContent, '#[TOP_EMBED]#') !== false && $topEmbedContent !== false) {
	   $m3uContent = str_replace('#[TOP_EMBED]#', $topEmbedContent, $m3uContent);
	}
}

$m3uContent = setupLiveStreams($m3uContent);

return $m3uContent;

}

function DaddyLivedecode($d, $e, $f) {
    try {
        $g = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ+/';
        $h = substr($g, 0, $e);
        $i = substr($g, 0, $f);

        if (!$h || !$i) {
            throw new Exception("Invalid parameters for substrings.");
        }

        $dArray = array_reverse(str_split($d));

        $j = array_reduce(array_keys($dArray), function($a, $c) use ($dArray, $h, $e) {
            $b = $dArray[$c];
            $pos = strpos($h, $b);
            if ($pos === false) {
                throw new Exception("Character not found in base set.");
            }
            return $a + $pos * pow($e, $c);
        }, 0);

        $k = '';
        while ($j > 0) {
            $k = $i[$j % $f] . $k;
            $j = intdiv($j, $f);
        }

        return $k ?: '0';
    } catch (Exception $ex) {       
        error_log("Error in DaddyLivedecode: " . $ex->getMessage());
        return false;
    }
}

function DaddyLivedecrypt($h, $n, $t, $e) {
    try {
        $r = '';
        $len = strlen($h);

        for ($i = 0; $i < $len; $i++) {
            $s = '';
            while ($i < $len && $h[$i] !== $n[$e]) {
                $s .= $h[$i];
                $i++;
            }
            for ($j = 0; $j < strlen($n); $j++) {
                $s = str_replace($n[$j], $j, $s);
            }

            $decodedValue = DaddyLivedecode($s, $e, 10);
            if ($decodedValue === false) {
                throw new Exception("Decoding failed.");
            }
            $charCode = $decodedValue - $t;
            if ($charCode < 0) {
                $charCode += 256;
            }

            $r .= chr($charCode);
        }

        return $r;
    } catch (Exception $ex) {        
        error_log("Error in DaddyLivedecrypt: " . $ex->getMessage());
        return false; 
    }
}


?>
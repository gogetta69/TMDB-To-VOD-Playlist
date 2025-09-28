<?php
// Created By gogetta.teams@gmail.com
// Please leave this in this script.
//https://github.com/gogetta69/TMDB-To-VOD-Playlist



require_once 'libs/JavaScriptUnpacker.php';
require_once 'config.php';
accessLog();


if (isset($_GET['dev']) && $_GET['dev'] === 'true') {
$GLOBALS['DEBUG'] = true;	
}	
if (!$GLOBALS['DEBUG']) {
    error_reporting(0);	
} 

if (isset($GLOBALS['DEBUG']) && isset($HTTP_PROXY) && isset($USE_HTTP_PROXY) && $USE_HTTP_PROXY === true) {
    echo "Proxy Enabled - Proxy Server: $HTTP_PROXY <br><br>";
}	

////////////////////////////// Run Script ///////////////////////////////

cleanupCacheFiles(); // Check cache cleanup
//Run the script.
$expirationDuration = $expirationHours * 3600;

if (isset($_GET['movieId']) && !empty($_GET['movieId'])) {
    $movieId = $_GET['movieId'];

    $type = $_GET['type'] ?? 'movies';
    $episodeData = isset($_GET['data']) ? base64_decode($_GET['data']) : '';
} else {
    echo 'The movieId parameter was not passed or is empty!';
    exit();
}

$globalTitle = '';
$globalYear = '';
$logTitle = '';
$torrentData = [];
$deleteRDFiles = [];

$userAgent = $_SERVER['HTTP_USER_AGENT'];

/* FindVideoExtractor("https://filelions.to/v/hjiquphp47la", "filelions", "https://www.primewire.mov", "filelions");
exit; */


//Run movies
if ($type == 'movies') {
	// Check if client is 'MXPlayer' and throttle their multiple request.
	if (stripos($userAgent, 'MXPlayer') !== false) {
		throttleMxPlayerRequests($movieId);
	}
	if (intval($movieId) > 10000000) {
		playAdultVideo($movieId);
	}
if (movieDetails_TMDB($movieId, $apiKey, $useRealDebrid) !== false) {
    http_response_code(404);
    echo "The requested resource was not found.";
	exit();
} else {
    echo "Should have redirected to the video.";
	exit();
}
//Run series
} elseif ($type == 'series'){
	$episodeData = explode(':', $episodeData);
	$subEpData = explode('/', $episodeData[1]);
	
	$movieId = $subEpData[0];
	
	//Store season number
	$seasonNoPad = $subEpData[2];
	$subEpData[2] = str_pad($subEpData[2], 2, "0", STR_PAD_LEFT);
	$season = $subEpData[2];
	//Store episode number
	$episodeNoPad = $subEpData[4];
	$subEpData[4] = str_pad($subEpData[4], 2, "0", STR_PAD_LEFT);
	$episode = $subEpData[4];
	$episodeId = 's'.$subEpData[2].'e'.$subEpData[4];
	$seriesCode = $episodeId;
	
	// Check if client is 'MXPlayer' and throttle their multiple request.
	if (stripos($userAgent, 'MXPlayer') !== false) {
		throttleMxPlayerRequests($movieId);
	}

	seriesDetails_TMDB($movieId, $apiKey, $useRealDebrid, $episodeData);
}


////////////////////////////// List of Functions ///////////////////////////////

////////////////////////////// The Movie Database ///////////////////////////////

function movieDetails_TMDB($movieId, $apiKey, $useRealDebrid)
{
    global $userDefinedOrder, $language, $usePremiumize;

    // Define the cache key
    $key = $movieId . '_tmdb_url';
		
	// Try to read the URL from cache
	$cachedUrl = readFromCache($key);	
	
	if($cachedUrl === '_failed_' && $GLOBALS['DEBUG'] === false){
		http_response_code(404);
		echo "The requested resource was not found.";
		exit();				
	}		

	// If the URL is found in cache and hasn't expired, perform a 301 redirect	 
	if ($cachedUrl !== null && $cachedUrl !== '_running_' && checkLinkStatusCode($cachedUrl)) {
		if ($GLOBALS['DEBUG']) {
			echo "Service: Pulled from the cache - Url: " . $cachedUrl . "</br></br>";
			echo 'Debugging: Redirection to the video would have taken place here.</br></br>';			
		} else {			
			header("HTTP/1.1 301 Moved Permanently");
			header("Location: $cachedUrl");
			exit();
		}
	}
	
	if (!$GLOBALS['DEBUG']){
		if($cachedUrl === '_running_'){			
			throttleRequest($key);			
		} else {
			writeToCache($key, '_running_', '120', false);	
		}	
	}
	
    $baseUrl = 'https://api.themoviedb.org/3/movie/';
    $url = $baseUrl . $movieId . '?api_key=' . $apiKey . '&language=' . $language;

    $response = @file_get_contents($url);

    if ($response !== false) {
        $movieData = json_decode($response, true);
        $imdbId = $movieData['imdb_id'];
        $title = $movieData['title'];
        $year = substr($movieData['release_date'], 0, 4);		
		$GLOBALS['globalTitle'] .= $title . ' ' . $year;
		$GLOBALS['logTitle'] .= $title . ' ' . '(' . $year . ')';
		$GLOBALS['globalYear'] .= $year;
        if ($imdbId) {
            if ($GLOBALS['DEBUG']) {
                // Log the extracted information
                echo 'IMDb ID: ' . $imdbId . "</br></br>";
                echo 'Title: ' . $title . "</br></br>";
                echo 'Year: ' . $year . "</br></br>";
            }

            $predefinedFunctions = ['theMovieArchive_site', 'shegu_net_links', 'primewire_tf', 'torrentSites', 'goMovies_sx', 'upMovies_to', 'superEmbed_stream', 'smashyStream_com', 'tvembed_cc', 'blackvid_space', 'HeadlessVidX', 'justBinge_site', 'frembed_pro', 'warezcdn_com', 'twoembed_skin', 'showBox_media', 'myfilestorage_xyz', 'oneTwothreeEmbed_net', 'vidsrc_pro', 'vidsrc_to', 'rive_vidsrc_scrapper', 'watch_movies_com_pk', 'autoembed_cc', 'vidsrc_rip', 'stremioSites'];

            $successfulFunctionName = '';

            // Iterate through the user-defined order and execute functions accordingly
            foreach ($userDefinedOrder as $functionName) {
                if (in_array($functionName, $predefinedFunctions) && function_exists($functionName)) {

                    // Check if Stremio Sites should run.
                    if (!$useRealDebrid && in_array($functionName, ['stremioSites'])) {
                        continue; //
                    }

                    // Check if torrents should run.
                    if (!$useRealDebrid && !$usePremiumize && in_array($functionName, ['torrentSites'])) {
                        continue; //
                    }

                    // Define an array of parameters to pass to the function
                    $params = [$movieId, $imdbId, $title, $year];

                    if ($functionName == 'stremioSites') {
                        $params = [$imdbId];
                    }

                    if ($functionName == 'torrentSites') {
                        $params = [$movieId, $imdbId, $title, $year];
                    }

                    if ($functionName == 'shegu_net_links') {
                        $params = [$title, $year];
                    }


                    if ($functionName == 'theMovieArchive_site') {
                        $params = [$movieId, $title];
                    }
					
					if ($functionName == 'justBinge_site') {
                        $params = [$movieId, $title];
                    }
					
					if ($functionName == 'showBox_media') {
                        $params = [$movieId, $title, $imdbId];
                    }
					
					if ($functionName == 'blackvid_space') {
                        $params = [$movieId, $title];
                    }
					
					if ($functionName == 'goMovies_sx') {
                        $params = [$title, $year];
                    }
					
					if ($functionName == 'warezcdn_com') {
                        $params = [$movieId, $imdbId, $title];
                    }
					
					if ($functionName == 'upMovies_to') {
                        $params = [$title, $year];
                    }		

					if ($functionName == 'superEmbed_stream') {
                        $params = [$imdbId, $title, $year];
                    }
					if ($functionName == 'smashyStream_com') {
                        $params = [$movieId, $imdbId, $title];
                    }	
					
					if ($functionName == 'tvembed_cc') {
                        $params = [$movieId, $imdbId, $title];
                    }	

					if ($functionName == 'primewire_tf') {
                        $params = [$title, $year, $movieId, $imdbId];
                    }		
					
					if ($functionName == 'frembed_pro') {
                        $params = [$title, $year, $movieId, $imdbId];
                    }	
					
					if ($functionName == 'twoembed_skin') {
                        $params = [$title, $year, $movieId, $imdbId];
                    }

					if ($functionName == 'HeadlessVidX') {
                        $params = [$movieId, $imdbId, $title];
                    }		

					if ($functionName == 'myfilestorage_xyz') {
                        $params = [$movieId, $title];
                    }		

					if ($functionName == 'oneTwothreeEmbed_net') {
                        $params = [$movieId, $title];
                    }	
					
					if ($functionName == 'vidsrc_pro') {
                        $params = [$movieId, $title];
                    }	

					if ($functionName == 'vidsrc_to') {
                        $params = [$movieId, $title];
                    }	

					if ($functionName == 'rive_vidsrc_scrapper') {
                        $params = [$movieId, $title];
                    }

					if ($functionName == 'watch_movies_com_pk') {
                        $params = [$movieId, $title, $year];
                    }	
					
					if ($functionName == 'autoembed_cc') {
                        $params = [$movieId, $title, $imdbId];
                    }	

					if ($functionName == 'vidsrc_rip') {
                        $params = [$movieId, $title];
                    }					

                    // Call the function with appropriate arguments
                    $result = call_user_func_array($functionName, $params);

                    // Check the result and continue or stop based on success
                    if ($result !== false) {
                        // Store the successful function name
                        $successfulFunctionName = $functionName;

                        if ($functionName !== 'torrentSites') {
							if (strpos($result, 'video_proxy.php') === false){
								$lCheck = checkLinkStatusCode($result);
							} else {
								$lCheck = true;
							}
                        } else {
                            $lCheck = true;
                        }

                        if ($GLOBALS['DEBUG']) {
                            // Log the extracted information with the successful function name
                            if ($lCheck !== false) {
                                echo "Service: " . $successfulFunctionName . ' - Url: ' . $result . "</br></br>";
                                echo 'Debugging: Redirection to the video would have taken place here.</br></br>';
                                writeToCache($key, $result);
                                exit();
                            }
                        } else {
                            // Put write to the cache here
                            if ($lCheck !== false) {
                                writeToCache($key, $result);
                                header("HTTP/1.1 301 Moved Permanently");
                                header("Location: $result");
                                exit();
                            }
                        }
                    }
                }
            }
			if (!$GLOBALS['DEBUG']) {
				writeToCache($key, '_failed_', '3600', false);
			 }
            http_response_code(404);
            echo "The requested resource was not found.";
            exit();

        } else {
            if ($GLOBALS['DEBUG']) {
                echo 'IMDb ID not found for the movie.' . "</br></br>";
            }
			if (!$GLOBALS['DEBUG']) {
				writeToCache($key, '_failed_', '3600', false);
			 }
            http_response_code(404);
            echo "The requested resource was not found.";
            exit();
        }
    } else {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: Unable to retrieve movie details.' . "</br></br>";
        }
		if (!$GLOBALS['DEBUG']) {
			writeToCache($key, '_failed_', '3600', false);
		}
        http_response_code(404);
        echo "The requested resource was not found.";
        exit();
    }
}

function seriesDetails_TMDB($movieId, $apiKey, $useRealDebrid, $episodeData)
{	
    global $userDefinedOrder, $episodeId, $language, $usePremiumize;
	

    // Define the cache key
    $key = $movieId . '_series_' . $episodeId . '_url';

    // Try to read the URL from cache
    $cachedUrl = readFromCache($key);
	
	if($cachedUrl === '_failed_' && $GLOBALS['DEBUG'] === false){
		http_response_code(404);
		echo "The requested resource was not found.";
		exit();				
	}	

    // If the URL is found in cache and hasn't expired, perform a 301 redirect
	if ($cachedUrl !== null && $cachedUrl !== '_running_' && checkLinkStatusCode($cachedUrl)) {
		if ($GLOBALS['DEBUG']) {
			echo "Service: Pulled from the cache - Url: " . $cachedUrl . "</br></br>";
			echo 'Debugging: Redirection to the video would have taken place here.</br></br>';
		} else {

			header("HTTP/1.1 301 Moved Permanently");
			header("Location: $cachedUrl");
			exit();
		}
	}
	
	if (!$GLOBALS['DEBUG']){
		if($cachedUrl === '_running_'){			
			throttleRequest($key);			
		} else {
			writeToCache($key, '_running_', '120', false);	
		}
	}
    $baseUrl = 'https://api.themoviedb.org/3/tv/';
    $url = $baseUrl . $movieId . '?api_key=' . $apiKey . '&language=' . $language;

    $response = @file_get_contents($url);

    if ($response !== false) {
        $seriesData = json_decode($response, true);
        $imdbId = $episodeData[0];
		$title = $seriesData['name'];
        $setitle = $seriesData['name'].' '.$episodeId;
        $year = substr($seriesData['first_air_date'], 0, 4);
		$GLOBALS['globalYear'] .= $year;
		$GLOBALS['globalTitle'] .= $setitle;
		$GLOBALS['logTitle'] .= $title . ' ' . '(' . $year . ')' ;
		
        if ($imdbId) {
            if ($GLOBALS['DEBUG']) {
                // Log the extracted information
                echo 'IMDb ID: ' . $imdbId . "</br></br>";
                echo 'Title: ' . $title . "</br></br>";
                echo 'Year: ' . $year . "</br></br>";
            }	

            $predefinedFunctions = ['superEmbed_stream', 'shegu_net_links',
                'torrentSites', 'goMovies_sx', 'smashyStream_com', 'upMovies_to', 'primewire_tf', 'tvembed_cc', 'blackvid_space', 'HeadlessVidX', 'justBinge_site', 'frembed_pro', 'warezcdn_com', 'twoembed_skin', 'showBox_media', 'oneTwothreeEmbed_net', 'vidsrc_pro', 'vidsrc_to', 'autoembed_cc', 'vidsrc_rip', 'stremioSites'];

            $successfulFunctionName = '';

            // Iterate through the user-defined order and execute functions accordingly
            foreach ($userDefinedOrder as $functionName) {
                if (in_array($functionName, $predefinedFunctions) && function_exists($functionName)) {

                    // Check if Stremio Sites should run.
                    if (!$useRealDebrid && in_array($functionName, ['stremioSites'])) {
                        continue; //
                    }

                    // Check if torrents should run.
                    if (!$useRealDebrid && !$usePremiumize && in_array($functionName, ['torrentSites'])) {
                        continue;
                    }

                    // Define an array of parameters to pass to the function
                    $params = [$movieId, $imdbId, $setitle, $year];

                    if ($functionName == 'stremioSites') {
                        $params = [$imdbId];
                    }

                    if ($functionName == 'torrentSites') {
                        $params = [$movieId, $imdbId, $setitle];
                    }

                    if ($functionName == 'shegu_net_links') {
                        $params = [$title, $year];
                    }
					
					if ($functionName == 'blackvid_space') {
                        $params = [$movieId, $title];
                    }
					
					if ($functionName == 'goMovies_sx') {
                        $params = [$title, $year];
                    }
					
					if ($functionName == 'justBinge_site') {
                        $params = [$movieId, $title];
                    }
					
					if ($functionName == 'vidsrc_pro') {
                        $params = [$movieId, $title];
                    }
					
					if ($functionName == 'vidsrc_to') {
                        $params = [$movieId, $title];
                    }
					
					if ($functionName == 'rive_vidsrc_scrapper') {
                        $params = [$movieId, $title];
                    }
					
					if ($functionName == 'upMovies_to') {
                        $params = [$title, $year];
                    }
					
					if ($functionName == 'superEmbed_stream') {
                        $params = [$imdbId, $title, $year];
                    }	
					
					if ($functionName == 'warezcdn_com') {
                        $params = [$movieId, $imdbId, $title];
                    }
					
					if ($functionName == 'showBox_media') {
                        $params = [$movieId, $title, $imdbId];
                    }
					
					if ($functionName == 'oneTwothreeEmbed_net') {
                        $params = [$movieId, $title];
                    }
					
					if ($functionName == 'smashyStream_com') {
                        $params = [$movieId, $imdbId, $title];
                    }	
					
					if ($functionName == 'tvembed_cc') {
                        $params = [$movieId, $imdbId, $title];
                    }						
					
					if ($functionName == 'primewire_tf') {
                        $params = [$title, $year, $movieId, $imdbId];
                    }	
					
					if ($functionName == 'twoembed_skin') {
                        $params = [$title, $year, $movieId, $imdbId];
                    }
					
					if ($functionName == 'frembed_pro') {
                        $params = [$title, $year, $movieId, $imdbId];
                    }	
					
					if ($functionName == 'HeadlessVidX') {
                        $params = [$movieId, $imdbId, $title];
                    }
					
					if ($functionName == 'autoembed_cc') {
                        $params = [$movieId, $title, $imdbId];
                    }
					
					if ($functionName == 'vidsrc_rip') {
                        $params = [$movieId, $title];
                    }

                    // Call the function with appropriate arguments
                    $result = call_user_func_array($functionName, $params);

                    // Check the result and continue or stop based on success
                    if ($result !== false) {
                        // Store the successful function name
                        $successfulFunctionName = $functionName;

                        if ($functionName !== 'torrentSites') {
                            $lCheck = checkLinkStatusCode($result);
                        } else {
                            $lCheck = true;
                        }

                        if ($GLOBALS['DEBUG']) {
                            // Log the extracted information with the successful function name
                            if ($lCheck !== false) {
                                echo "Service: " . $successfulFunctionName . ' - Url: ' . $result . "</br></br>";
                                echo 'Debugging: Redirection to the video would have taken place here.</br></br>';
                                writeToCache($key, $result);
                                exit();
                            }
                        } else {
                            // Put write to the cache here
                            if ($lCheck !== false) {
                                writeToCache($key, $result);
                                header("HTTP/1.1 301 Moved Permanently");
                                header("Location: $result");
                                exit();
                            }
                        }
                    }
                }
            }
			if (!$GLOBALS['DEBUG']) {
				writeToCache($key, '_failed_', '3600', false);
			}
            http_response_code(404);
            echo "The requested resource was not found.";
            exit();

        } else {
            if ($GLOBALS['DEBUG']) {
                echo 'IMDb ID not found for the movie.' . "</br></br>";
            }
			if (!$GLOBALS['DEBUG']) {
				writeToCache($key, '_failed_', '3600', false);
			}
            http_response_code(404);
            echo "The requested resource was not found.";
            exit();
        }
    } else {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: Unable to retrieve movie details.' . "</br></br>";
        }
		if (!$GLOBALS['DEBUG']) {
			writeToCache($key, '_failed_', '3600', false);
		}
        http_response_code(404);
        echo "The requested resource was not found.";
        exit();
    }
}

function playAdultVideo($movieId) {
	$key = $movieId . '_adult_url';
    try {
				
		$cachedUrl = readFromCache($key);	
		
		if($cachedUrl === '_failed_' && $GLOBALS['DEBUG'] === false){
			http_response_code(404);
			echo "The requested resource was not found.";
			exit();				
		}	

		if ($cachedUrl !== null && $cachedUrl !== '_running_' && checkLinkStatusCode($cachedUrl)) {
			if ($GLOBALS['DEBUG']) {
				echo "Service: Pulled from the cache - Url: " . $cachedUrl . "</br></br>";
				echo 'Debugging: Redirection to the video would have taken place here.</br></br>';			
			} else {			

				header("HTTP/1.1 301 Moved Permanently");
				header("Location: $cachedUrl");
				exit();
			}
		}
		
		if (!$GLOBALS['DEBUG']){
			if($cachedUrl === '_running_'){			
				throttleRequest($key);			
			} else {
				writeToCache($key, '_running_', '120', false);	
			}	
		}
		
        $fetchDetails = @file_get_contents('adult-movies.json');
        
        if ($fetchDetails === false) {
            throw new Exception("Failed to fetch adult movie details");
        }

        $movies = json_decode($fetchDetails, true);
        
        if ($movies === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Failed to decode adult movie details: " . json_last_error_msg());
        }		
		  
		$index = array_search($movieId, array_column($movies, 'stream_id'));

        if (!isset($movies[$index])) {
			if ($GLOBALS['DEBUG']) {
				echo "Adult movie not found";
			} else {
				writeToCache($key, '_failed_', '3600', false);
			}
			http_response_code(404);           
            exit;
        }

        $details = $movies[$index];
		
		$GLOBALS['globalTitle'] = $details['name'];
		$GLOBALS['logTitle'] = $details['name'];
		

        if (!isset($details['sources']) || !is_string($details['sources'])) {
            if ($GLOBALS['DEBUG']) {
                echo "No encoded sources found for adult video.";
            } else {
                writeToCache($key, '_failed_', '3600', false);
            }
            http_response_code(404);      
            exit();
        }

        $decoded = base64_decode($details['sources']);
        $sources = json_decode($decoded, true);

        if (!is_array($sources)) {
            if ($GLOBALS['DEBUG']) {
                echo "Failed to decode sources string.";
            } else {
                writeToCache($key, '_failed_', '3600', false);
            }
            http_response_code(404);
            exit();
        }

        foreach ($sources as $source) {
				// Fetch the HTML of the post page
				$html = @file_get_contents($source);
				if (!$html) {
						continue;
				}

				// Parse and extract all <a href> links inside #pettabs
				$dom = new DOMDocument();
				libxml_use_internal_errors(true);
				$dom->loadHTML($html);
				libxml_clear_errors();

				$xpath = new DOMXPath($dom);
				$nodes = $xpath->query('//div[@id="pettabs"]//a[@href]');

				$embedLinks = [];
				foreach ($nodes as $node) {
						$href = trim($node->getAttribute('href'));
						if (!empty($href)) {
								$embedLinks[] = $href;
						}
				}

				// Now run FindVideoExtractor on each extracted embed link
				foreach ($embedLinks as $embedUrl) {
						$extractReturn = FindVideoExtractor($embedUrl, 'playAdultVideo', $embedUrl);

						if ($extractReturn !== false) {
								if ($GLOBALS['DEBUG']) {
										echo "Service: Adult Video - Url: " . $extractReturn . "</br></br>";
										echo 'Debugging: Redirection to the video would have taken place here.</br></br>';
										exit;
								} else {
										writeToCache($key, $extractReturn);
										header("HTTP/1.1 301 Moved Permanently");
										header("Location: $extractReturn");
										exit;
								}
						}
				}
		}

		if ($GLOBALS['DEBUG']) {
			echo "No valid sources found";
		}  else {
				writeToCache($key, '_failed_', '3600', false);
			}
		http_response_code(404);      
        exit();
    } catch (Exception $e) {
		if ($GLOBALS['DEBUG']) {
			echo "Error: " . $e->getMessage();
		}  else {
			writeToCache($key, '_failed_', '3600', false);
		}
		http_response_code(404);      
        exit();
    }
	writeToCache($key, '_failed_', '3600', false);
	http_response_code(404);      
	exit();
}

////////////////////////////// Real Debrid ///////////////////////////////

function instantAvailability_RD($torrents, $tSite) {
    global $DEBUG;
    
    if ($DEBUG) {
        echo "<br>=== instantAvailability_RD DEBUG START ===<br>";
        echo "Site: $tSite<br>";
        echo "Torrents received: " . count($torrents) . "<br>";
        echo "Torrents data: " . json_encode($torrents) . "<br><br>";
    }
    
    foreach ($torrents as $index => $torrent) {
        if ($DEBUG) {
            echo "--- Processing Torrent #$index ---<br>";
            echo "Torrent: " . json_encode($torrent) . "<br>";
        }
        
        $magnet = null;
        $tvpack = false;
        
        // --- Case 1: torrentSites style (hash only) ---
        if (!empty($torrent['hash'])) {
            $magnet = "magnet:?xt=urn:btih:" . $torrent['hash'];
            $tvpack = isset($torrent['tvpack']) ? $torrent['tvpack'] : false;
            if ($DEBUG) {
                echo "✅ Built magnet from hash: " . $torrent['hash'] . "<br>";
                echo "TVPack: " . ($tvpack ? 'Yes' : 'No') . "<br>";
            }
        }
        
        // --- Case 2: stremioSites style (infoHash/url/behaviorHints) ---
        if (!$magnet && (!empty($torrent['infoHash']) || !empty($torrent['url']))) {
            $infoHash = $torrent['infoHash'] ?? '';
            
            // fallback: extract hash from URL
            if (!$infoHash && !empty($torrent['url'])) {
                $urlParts = explode('/', $torrent['url']);
                foreach ($urlParts as $part) {
                    if (preg_match('/^[a-f0-9]{40}$/i', $part)) {
                        $infoHash = $part;
                        break;
                    }
                }
            }
            
            $filename = $torrent['behaviorHints']['filename']
                ?? (!empty($torrent['url']) ? urldecode(basename($torrent['url'])) : 'video');
            
            if ($infoHash) {
                $magnet = "magnet:?xt=urn:btih:$infoHash&dn=" . urlencode($filename);
                if ($DEBUG) {
                    echo "✅ Built magnet from infoHash: $infoHash<br>";
                    echo "Filename: $filename<br>";
                }
            }
        }
        
        // --- Case 3: already has magnet ---
        if (!$magnet && !empty($torrent['magnet'])) {
            $magnet = $torrent['magnet'];
            if ($DEBUG) {
                echo "✅ Using existing magnet<br>";
            }
        }
        
        if (!$magnet) {
            if ($DEBUG) echo "❌ No magnet built - skipping<br><br>";
            continue;
        }
        
        if ($DEBUG) {
            echo "🧲 Magnet: $magnet<br>";
            echo "📤 Adding to Real-Debrid...<br>";
        }
        
        // === Real-Debrid flow ===
        $added = Stremio_rd_api('torrents/addMagnet', ['magnet' => $magnet], 'POST');
        
        if ($DEBUG) {
            echo "📥 Add response: " . json_encode($added) . "<br>";
        }
        
        if (!isset($added['id'])) {
            if ($DEBUG) echo "❌ Failed to add magnet to RD<br><br>";
            continue;
        }
        
        $torrentId = $added['id'];
        if ($DEBUG) {
            echo "✅ Added to RD with ID: $torrentId<br>";
            echo "📋 Getting torrent info...<br>";
        }
        
        $info = Stremio_rd_api("torrents/info/$torrentId", [], 'GET');
        
        if ($DEBUG) {
            echo "📋 Info response: " . json_encode($info) . "<br>";
        }
        
        if (empty($info['files'])) {
            if ($DEBUG) echo "❌ No files in torrent - deleting<br>";
            Stremio_rd_api("torrents/delete/$torrentId", [], 'DELETE');
            continue;
        }
        
        if ($DEBUG) {
            echo "📁 Files found: " . count($info['files']) . "<br>";
            echo "🔍 Looking for video file...<br>";
        }
        
        $video = findVideoIndex_RD($info['files']);
        
        if (!$video) {
            if ($DEBUG) echo "❌ No suitable video file found - deleting torrent<br>";
            Stremio_rd_api("torrents/delete/$torrentId", [], 'DELETE');
            continue;
        }
        
        if ($DEBUG) {
            echo "✅ Video file selected: " . json_encode($video) . "<br>";
            echo "⚙️ Selecting files in RD...<br>";
        }
        
        $selectResult = Stremio_rd_api("torrents/selectFiles/$torrentId", ['files' => $video['id']], 'POST');
        
        if ($DEBUG) {
            echo "⚙️ Select response: " . json_encode($selectResult) . "<br>";
            echo "🔄 Getting updated torrent info...<br>";
        }
        
        $info = Stremio_rd_api("torrents/info/$torrentId", [], 'GET');
        
        if ($DEBUG) {
            echo "🔄 Updated info: " . json_encode($info) . "<br>";
        }
        
        if (!empty($info['links'])) {
            $rdUrl = $info['links'][0];
            if ($DEBUG) {
                echo "🔗 RD Link found: $rdUrl<br>";
                echo "🔓 Unrestricting link...<br>";
            }
            
            $unrestricted = Stremio_rd_api("unrestrict/link", ['link' => $rdUrl], 'POST');
            
            if ($DEBUG) {
                echo "🔓 Unrestrict response: " . json_encode($unrestricted) . "<br>";
            }
            
            Stremio_rd_api("torrents/delete/$torrentId", [], 'DELETE');
            
            if (!empty($unrestricted['download'])) {
                if ($DEBUG) {
                    echo "🎉 SUCCESS! Download link: " . $unrestricted['download'] . "<br>";
                    echo "=== instantAvailability_RD DEBUG END ===<br><br>";
                }
                return $unrestricted['download'];
            } else {
                if ($DEBUG) echo "❌ No download link in unrestrict response<br>";
            }
        } else {
            if ($DEBUG) echo "❌ No links available (torrent not cached)<br>";
        }
        
        if ($DEBUG) echo "🗑️ Cleaning up torrent...<br>";
        Stremio_rd_api("torrents/delete/$torrentId", [], 'DELETE');
    }
    
    if ($DEBUG) {
        echo "❌ No working torrents found<br>";
        echo "=== instantAvailability_RD DEBUG END ===<br><br>";
    }
    
    return false;
}

function addMagnetLink_RD($torrents, $magnetLink, $tSite, $tvpack)
{
    global $PRIVATE_TOKEN;	

    try {
		$url = 'https://api.real-debrid.com/rest/1.0/torrents/addMagnet';
		$postData = 'magnet=' . urlencode($magnetLink);
		$headers = [
			'Authorization: Bearer ' . $PRIVATE_TOKEN,
			'Content-Type: application/x-www-form-urlencoded',
		];
		
		$ch = curl_init($url);

		// Set cURL options
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
		
		$response = curl_exec($ch);
		
		$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		
		curl_close($ch);
		
        if ($statusCode !== 200 && $statusCode !== 201) {
            if ($GLOBALS['DEBUG']) {
                echo 'HTTP Error Code: ' . $statusCode . "</br></br>";
                echo 'Error Response: ' . $response . "</br></br>";
            }
            return false;
        }
        $data = json_decode($response, true);
        $id = $data['id'];
        $uri = urldecode($data['uri']);
        if ($GLOBALS['DEBUG']) {
            echo 'ID: ' . $id . "</br></br>";
            echo 'URI: ' . $uri . "</br></br>";
        }
        return selectMultipleFiles_RD($torrents, $id, $tSite, $tvpack);
    }
    catch (exception $e) {
        if ($GLOBALS['DEBUG']) {
            echo "Error in addMagnetLink_RD: " . $e->getMessage() . "</br></br>";
        }
        return false; 
    }
}

function selectFile_RD($torrentId, $videoFileID, $tvpack)
{
    global $PRIVATE_TOKEN;
	if ($GLOBALS['DEBUG']) {
		echo "<br> Select File ID: " . $videoFileID. "<br><br>";
	}
    try {
		
		$url = 'https://api.real-debrid.com/rest/1.0/torrents/selectFiles/' . $torrentId;		
		
		if ($tvpack) {
			$postData = 'files=all';
		} else {
			$postData = 'files=' . $videoFileID;
		}	

		$headers = [
			'Authorization: Bearer ' . $PRIVATE_TOKEN,
			'Content-Type: application/x-www-form-urlencoded',
		];
		
		$ch = curl_init($url);
		
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HEADER, true);
	
		$response = curl_exec($ch);
		
		$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		$header = substr($response, 0, $header_size);
		$body = substr($response, $header_size);
		
		$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		
		curl_close($ch);		

        // Define the array of error codes
        $errorCodes = [202 => 'Action already done', 400 =>
            'Bad Request (see error message)', 401 => 'Bad token (expired, invalid)', 403 =>
            'Permission denied (account locked, not premium)', 404 =>
            'Wrong parameter (invalid file id(s)) / Unknown resource (invalid id)',            
            ];

        // Check if the status code is in the array of error codes
        if (array_key_exists($statusCode, $errorCodes)) {
            if ($GLOBALS['DEBUG']) {
                echo 'HTTP Error Code: ' . $statusCode . "</br></br>";           

				// Output the error description
				echo 'Reason: ' . $errorCodes[$statusCode] . "</br></br>";

				echo 'Error Response: ' . $response . "</br></br>";
			}
			return false;
        } 

		if ($GLOBALS['DEBUG']) {				
			print_r('selectFile_RD - Response: ' .  $statusCode . "</br></br>");
		}
		return true;

    }
    catch (exception $e) {
        if ($GLOBALS['DEBUG']) {
            echo "Error in selectFile_RD: " . $e->getMessage() . "</br></br>";
        }
        return false; 
    }
}

function selectMultipleFiles_RD($torrents, $torrentId, $tSite, $tvpack)
{
    global $PRIVATE_TOKEN, $type, $episodeId;

    try {
		$url = 'https://api.real-debrid.com/rest/1.0/torrents/info/' . $torrentId;
		$headers = [
			'Authorization: Bearer ' . $PRIVATE_TOKEN,
			'Content-Type: application/x-www-form-urlencoded',
		];

		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$response = curl_exec($ch);
		$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		
		
        if ($statusCode === 200) {
            if ($GLOBALS['DEBUG']) {
                echo 'HTTP Response Content: ' . $response . "</br></br>";
            }
        } else {
            if ($GLOBALS['DEBUG']) {
                echo 'HTTP Error Code: ' . $statusCode . "</br></br>";
                echo 'Error Response: ' . $response . "</br></br>";
            }
            return false;
        }
        $data = json_decode($response, true);
        $files = $data['files'];		
  
        $videoFileID = findVideoIndex_RD($files);
		
		if($videoFileID){
			
			selectFile_RD($torrentId, $files[$videoFileID['index']]['id'], $tvpack);			
			
			return getDlLink_RD($torrents, $torrentId, $tSite, $videoFileID['index'], $tvpack);
			
		} else {
			
			return false;			
		
		}

    }
    catch (exception $e) {
        if ($GLOBALS['DEBUG']) {
            echo "Error in selectMultipleFiles_RD: " . $e->getMessage() . "</br></br>";
        }
        return false;
    }
}

function findVideoIndex_RD($files) {
    global $type, $episodeId, $seriesCode, $globalTitle, $season, $episode, $seasonNoPad, $episodeNoPad;

    foreach ($files as $index => $file) {
        if ($GLOBALS['DEBUG']) {
            echo "Find Video ID: " . $file['path'] . "<br>";
        }

        // Skip "sample" files
        if (stripos($file['path'], 'sample') !== false) {
            if ($GLOBALS['DEBUG']) {
                echo "❌ Skipped sample file: " . $file['path'] . "<br>";
            }
            continue;
        }

        // Validate extension first
        $videoExtensions = ['mp4','mkv','avi','mov','flv','wmv','mpg','mpeg','m4v'];
        $extension = strtolower(pathinfo($file['path'], PATHINFO_EXTENSION));
        if (!in_array($extension, $videoExtensions)) {
            if ($GLOBALS['DEBUG']) {
                echo "❌ Not a video file: " . $file['path'] . "<br>";
            }
            continue;
        }

        // For series, try exact episode matching first
        if ($type == 'series') {
            $filePath = strtolower($file['path']);
            $matched = false;
            
            // EXACT episode matching patterns (prioritize these)
            $exactPatterns = [
                strtolower($seriesCode),                              // s01e03 (original)
                sprintf("s%de%d", $seasonNoPad, $episodeNoPad),      // s1e3
                sprintf("s%02d e%02d", $season, $episode),           // s01 e03
                sprintf("s%d e%d", $seasonNoPad, $episodeNoPad),     // s1 e3
                sprintf("%dx%02d", $seasonNoPad, $episode),          // 1x03
                sprintf("%dx%d", $seasonNoPad, $episodeNoPad),       // 1x3
                sprintf("season %d episode %d", $seasonNoPad, $episodeNoPad), // season 1 episode 3
                sprintf("season.%d.episode.%d", $seasonNoPad, $episodeNoPad), // season.1.episode.3
                sprintf(".%d%02d.", $seasonNoPad, $episode),         // .103.
                sprintf("e%02d", $episode),                          // e03 (if season matches separately)
                sprintf("episode %d", $episodeNoPad),                // episode 3
                sprintf("ep%02d", $episode),                         // ep03
                sprintf("ep%d", $episodeNoPad),                      // ep3
            ];
            
            // Check each EXACT pattern first
            foreach ($exactPatterns as $pattern) {
                if (stripos($filePath, $pattern) !== false) {
                    $matched = true;
                    if ($GLOBALS['DEBUG']) {
                        echo "✅ Episode matched with EXACT pattern: '$pattern' in file: " . $file['path'] . "<br>";
                    }
                    break;
                }
            }
            
            if (!$matched) {
                if ($GLOBALS['DEBUG']) {
                    echo "❌ Episode pattern not matched for: " . $file['path'] . "<br>";
                }
                continue; // Skip to next file - don't do season fallback here
            }
        }

        // For movies or if series matching passed, do title comparison
        if (!empty($globalTitle)) {
            $filename = basename($file['path']);
            $titleMatched = filterCompareTitles($filename, $globalTitle, ($type == 'series'));
            
            if (!$titleMatched && $type == 'series') {
                // For series, if episode pattern matched but title doesn't, still allow it
                if ($GLOBALS['DEBUG']) {
                    echo "⚠️  Episode pattern matched but title comparison failed - allowing anyway: " . $file['path'] . "<br>";
                }
            } elseif (!$titleMatched && $type == 'movies') {
                if ($GLOBALS['DEBUG']) {
                    echo "❌ Title comparison failed for movie: " . $file['path'] . "<br>";
                }
                continue;
            }
        }

        // Passed all checks - this is our match!
        if ($GLOBALS['DEBUG']) {
            echo "✅ Final selection: " . $file['path'] . " (index: $index, id: " . $file['id'] . ")<br><br>";
        }
        
        return [
            'index' => $index,
            'id'    => $file['id']
        ];
    }

    // If no exact episode match found, try season pack fallback as last resort
    if ($type == 'series') {
        if ($GLOBALS['DEBUG']) {
            echo "No exact episode match found, trying season pack fallback...<br>";
        }
        
        $largestVideoFile = null;
        $largestSize = 0;
        
        foreach ($files as $index => $file) {
            // Skip non-video files
            $extension = strtolower(pathinfo($file['path'], PATHINFO_EXTENSION));
            $videoExtensions = ['mp4','mkv','avi','mov','flv','wmv','mpg','mpeg','m4v'];
            if (!in_array($extension, $videoExtensions)) {
                continue;
            }
            
            // Skip sample files
            if (stripos($file['path'], 'sample') !== false) {
                continue;
            }
            
            // Check if this file is larger and has season info
            if (isset($file['bytes']) && $file['bytes'] > $largestSize) {
                $filePath = strtolower($file['path']);
                $seasonPatterns = [
                    sprintf("s%02d", $season),     // s01
                    sprintf("s%d", $seasonNoPad),  // s1
                    sprintf("season %d", $seasonNoPad), // season 1
                    sprintf("season.%d", $seasonNoPad), // season.1
                ];
                
                foreach ($seasonPatterns as $seasonPattern) {
                    if (stripos($filePath, $seasonPattern) !== false) {
                        $largestVideoFile = [
                            'index' => $index,
                            'id' => $file['id'],
                            'path' => $file['path'],
                            'bytes' => $file['bytes']
                        ];
                        $largestSize = $file['bytes'];
                        break;
                    }
                }
            }
        }
        
        if ($largestVideoFile) {
            if ($GLOBALS['DEBUG']) {
                echo "✅ Season pack fallback - selected largest file: " . $largestVideoFile['path'] . " (index: " . $largestVideoFile['index'] . ", id: " . $largestVideoFile['id'] . ")<br><br>";
            }
            return [
                'index' => $largestVideoFile['index'],
                'id' => $largestVideoFile['id']
            ];
        }
    }

    if ($GLOBALS['DEBUG']) {
        echo "❌ No suitable video file found in torrent<br><br>";
    }
    return false;
}

function findVideoIndex_RD_old($files) {
    global $type, $episodeId, $seriesCode, $globalTitle;

    foreach ($files as $index => $file) {
        if ($GLOBALS['DEBUG']) {
            echo "Find Video ID: " . $file['path'] . "<br><br>";
        }

        // Skip "sample" files
        if (stripos($file['path'], 'sample') !== false) {
            continue;
        }

        // If series, enforce episode/season code
        if ($type == 'series' && stripos(strtolower($file['path']), strtolower($seriesCode)) === false) {
            continue;
        }

        // Validate extension
        $videoExtensions = ['mp4','mkv','avi','mov','flv','wmv','mpg','mpeg','m4v'];
        $extension = strtolower(pathinfo($file['path'], PATHINFO_EXTENSION));
        if (!in_array($extension, $videoExtensions)) {
            continue;
        }

        // ✅ New: Compare against global title
        $filename = basename($file['path']);
        if (!empty($globalTitle) && !filterCompareTitles($filename, $globalTitle, ($type == 'series'))) {
            continue;
        }

        // Passed all checks
        return [
            'index' => $index,
            'id'    => $file['id']
        ];
    }

    return false;
}

function getDlLink_RD($torrents, $torrentId, $tSite, $index, $tvpack)
{
	
	
    global $PRIVATE_TOKEN, $type, $deleteRDFiles;	

    try {
		$url = 'https://api.real-debrid.com/rest/1.0/torrents/info/' . $torrentId;
		$headers = [
			'Authorization: Bearer ' . $PRIVATE_TOKEN,
			'Content-Type: application/x-www-form-urlencoded',
		];

		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$response = curl_exec($ch);
		$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

        if ($statusCode === 200) {
            $data = json_decode($response, true);
			
			$linksArray = $data['links'];		
								
			if(!$tvpack){
				$index = 0;
			} 	else {
				// Filter out elements where 'selected' is 0
				$filteredFiles = array_filter($data['files'], function($file) {
					return $file['selected'] != 0;
				});

				$data['files'] = array_values($filteredFiles);
				$getindex = findVideoIndex_RD($data['files']);
				$index = $getindex['index'];             
			}				

/* 			echo "getDlLink_RD Index: " . $index . "<br><br>";
			echo "linksArray Links: " . print_r($linksArray);	
			echo "<br><br>";	 */
			
            if ($linksArray && count($linksArray) > 0) {
                $firstLink = $linksArray[$index];
			
                $payload = ['link' => $firstLink, 'remote' => 0, ];
                $context = stream_context_create(['http' => ['method' => 'POST', 'header' =>
                    implode("\r\n", $headers), 'content' => http_build_query($payload), ], ]);
                $unrestrictResponse = file_get_contents('https://api.real-debrid.com/rest/1.0/unrestrict/link', false,
                    $context);
                $unrestrictData = json_decode($unrestrictResponse, true);
                $downloadLink = $unrestrictData['download'];
                if ($GLOBALS['DEBUG']) {
				
                    echo 'getDlLink_RD - Video link: ' . $downloadLink . "</br></br>";
                }
                $fileNameMatch = preg_match('#/([^/]+)$#', $downloadLink, $fileNameMatches);
				
                if ($fileNameMatch) {
                    $filenameNoExt = urldecode($fileNameMatches[1]);
                } else {
                    $filenameNoExt = 'Unknown';
                }

                return $downloadLink;
            } else {
                if ($GLOBALS['DEBUG']) {

                    echo 'getDlLink_RD: No links found in the array.' . "</br></br>";

                }
				$deleteRDFiles[] = $torrentId;
                return false;
            }
        } else {
            if ($GLOBALS['DEBUG']) {
                echo 'HTTP Error Code: ' . $statusCode . "</br></br>";
                echo 'Error Response: ' . $response . "</br></br>";
            }
        }
    }
    catch (exception $e) {
        if ($GLOBALS['DEBUG']) {
            echo "Error in getDlLink_RD: " . $e->getMessage() . "</br></br>";
        }
		$deleteRDFiles[] = $torrentId;
        return false;
    }
}

function deleteFiles_RD($torrentIds) {
    global $PRIVATE_TOKEN;

    $mh = curl_multi_init();
    $curlHandles = [];

    foreach ($torrentIds as $torrentId) {
        $url = 'https://api.real-debrid.com/rest/1.0/torrents/delete/' . $torrentId;
        $headers = [
            'Authorization: Bearer ' . $PRIVATE_TOKEN,
            'Content-Type: application/x-www-form-urlencoded',
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        curl_multi_add_handle($mh, $ch);
        $curlHandles[$torrentId] = $ch;
    }

    $running = null;
    do {
        curl_multi_exec($mh, $running);
    } while ($running);

    foreach ($curlHandles as $torrentId => $ch) {
        $response = curl_multi_getcontent($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($GLOBALS['DEBUG']) {
            if ($statusCode === 204) {
                echo 'Deleted File: ' . $torrentId . '</br></br> Response: ' . $response . '</br></br>';
            } else {
                echo 'HTTP Error Code: ' . $statusCode . "</br></br>";
                echo 'Error Response: ' . $response . "</br></br>";
            }
        }

        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }

    curl_multi_close($mh);

    // For simplicity, this function just executes the requests and logs the results.
    // It always returns true but you can modify it to handle errors more precisely.
    return true;
}

////////////////////////////// Premiumize ///////////////////////////////

function instantAvailability_PM($hashes, $torrents){
    global $premiumizeApiKey;
    $chunkSize = 100;
	try {
		// Splitting the hashes array into chunks
		$hashChunks = array_chunk($hashes, $chunkSize);
		$combinedAvailabilityData = [];

		// Initialize the multi cURL handler
		$mh = curl_multi_init();
		$curlHandles = [];

		foreach ($hashChunks as $i => $chunk) {
			
			$hashString = implode("&items%5B%5D=", $chunk);
			$url = "https://www.premiumize.me/api/cache/check?items%5B%5D={$hashString}&apikey={$premiumizeApiKey}";
			
			$curlHandles[$i] = curl_init($url);
			curl_setopt($curlHandles[$i], CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($curlHandles[$i], CURLOPT_SSL_VERIFYHOST, false);
			curl_setopt($curlHandles[$i], CURLOPT_RETURNTRANSFER, true);
			curl_multi_add_handle($mh, $curlHandles[$i]);
		}
		
		$running = null;
		do {
			curl_multi_exec($mh, $running);
		} while ($running);

		// Collecting results
		foreach ($curlHandles as $ch) {
			$response = curl_multi_getcontent($ch);
			$availabilityData = json_decode($response, true);
			$combinedAvailabilityData = array_merge($combinedAvailabilityData, $availabilityData['response']);
			curl_multi_remove_handle($mh, $ch);
			curl_close($ch);
		}

		curl_multi_close($mh);

		if (count($hashes) !== count($availabilityData['response'])) {			
			throw new Exception("Premiumize: Mismatch in the number of hashes and response data");
		}
		
		$hashAvailabilityMap = array_combine($hashes, $availabilityData['response']);

		$torrents = array_filter($torrents, function($torrent) use ($hashAvailabilityMap) {
			$hash = strtolower($torrent['hash']);			
			return isset($hashAvailabilityMap[$hash]) && $hashAvailabilityMap[$hash] == 1;
		});
	} catch (Exception $e) {
		if ($GLOBALS['DEBUG']) {
			echo "Error: " . $e->getMessage();
		}
	}
    
    return $torrents;
}

function getStreamingLink_PM($torrents, $magnetLink, $tSite) {
    global $premiumizeApiKey, $globalTitle, $seriesCode, $type;

    $url = "https://www.premiumize.me/api/transfer/directdl";

    $postData = [
        'apikey' => $premiumizeApiKey,
        'src' => $magnetLink,
    ];

    // Initialize cURL session
    $ch = curl_init($url);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
		if ($GLOBALS['DEBUG']) {
			echo "cURL Error: " . curl_error($ch) . " in getStreamingLink_PM</br></br>";
		}  
        return false;
    }

    curl_close($ch);
    
    $responseData = json_decode($response, true);

	if (isset($responseData['content'])) {
		foreach ($responseData['content'] as $content) {			
			if (isset($content['path']) && $type == 'series') {
			
				$strippedPath = preg_replace('/[^a-zA-Z0-9]/', '', $content['path']);
				 
				if (stripos(strtolower($strippedPath), strtolower($seriesCode)) === false) {
					continue;
				}		

			}	
	
			
			if (isset($content['link']) && !empty($content['link']) && videoExtensionCheck($content['link'])) {				
				return $content['link'];
			}
		}
	}
	
	if ($GLOBALS['DEBUG']) {
		echo "Couldn\'t get the streaming link in getStreamingLink_PM.</br></br>";
	} 

    return false;
}	

function videoExtensionCheck($url){
	
	$videoExtensions = ['mp4', 'mkv', 'avi', 'mov', 'flv', 'wmv', 'mpg', 'mpeg', 'm4v'];
	$extension = strtolower(pathinfo($url, PATHINFO_EXTENSION));
	
	if (in_array($extension, $videoExtensions)) {
		return true;
	} else {
		return false;
	}	
	
}	

////////////////////////////// Processing ///////////////////////////////

function makeGetRequest($url, $referer = null, $additionalHeaders = [], $headOnly = false) {
    global $HTTP_PROXY, $timeOut, $USE_HTTP_PROXY;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeOut);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);	

    if ($headOnly) {
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
    }

    if (isset($HTTP_PROXY) && isset($USE_HTTP_PROXY) && $USE_HTTP_PROXY === true) {
        curl_setopt($ch, CURLOPT_PROXY, $HTTP_PROXY);       
    }

    $headers = [
        "Accept: */*",
        "Accept-Language: en-US,en;q=0.5",
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0"
    ];

    if ($referer) {
        $headers[] = "Referer: $referer";
    }

    if (!empty($additionalHeaders)) {
        $headers = array_merge($headers, $additionalHeaders);
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        curl_close($ch);
        return false;
    }
    curl_close($ch);

    return $headOnly ? $httpStatus : $response;
}

function makePostRequest($url, $referer = null, $postData, $contentType = 'application/x-www-form-urlencoded', $additionalHeaders = []) {
    global $HTTP_PROXY, $timeOut, $USE_HTTP_PROXY;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeOut);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_POST, true); // Set the request to POST

    // Handle different content types
    switch ($contentType) {
        case 'application/json':
            $postData = json_encode($postData);
            break;
        case 'application/x-www-form-urlencoded':
            $postData = http_build_query($postData);
            break;
        // Add more cases as needed for different content types
    }

    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData); // Set the data to be posted

    if (isset($HTTP_PROXY) && isset($USE_HTTP_PROXY) && $USE_HTTP_PROXY === true) {
        curl_setopt($ch, CURLOPT_PROXY, $HTTP_PROXY);       
    }
	
	if($contentType !== false){
		$contentType = "Content-Type: $contentType";
	} else {
		$contentType = '';
	}

    $headers = [
        "Accept: */*",
        "Accept-Language: en-US,en;q=0.5",
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0",
        $contentType
    ];

    if ($referer) {
        $headers[] = "Referer: $referer";
    }

    if (!empty($additionalHeaders)) {
        $headers = array_merge($headers, $additionalHeaders);
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        curl_close($ch);
        return false;
    }
    curl_close($ch);

    return $response;
}

function sortTorrentsByQuality(&$torrents) {
    usort($torrents, function($a, $b) {
        // Check tvpack status
        $tvpackA = isset($a['tvpack']) && $a['tvpack'] == 1 ? 1 : 0;
        $tvpackB = isset($b['tvpack']) && $b['tvpack'] == 1 ? 1 : 0;

        if ($tvpackA != $tvpackB) {
            // Prioritize torrents without tvpack
            return $tvpackA - $tvpackB;
        }

        // If tvpack status is the same, then sort by quality
        $qualityA = intval(preg_replace('/[^0-9]/', '', $a['quality']));
        $qualityB = intval(preg_replace('/[^0-9]/', '', $b['quality']));

        // Sort in descending order
        return $qualityB - $qualityA;
    });
}

function filterTorrentsByResolution($torrents, $maxResolution) {
    $filteredTorrents = [];

    foreach ($torrents as $torrent) {
        $resolution = intval(preg_replace('/[^0-9]/', '', $torrent['quality']));
        if ($resolution <= $maxResolution) {
            $filteredTorrents[] = $torrent;
        }
    }

    // If there are fewer than 5 torrents, add additional items with lower resolution
    $additionalNeeded = 5 - count($filteredTorrents);
    if ($additionalNeeded > 0) {
        foreach ($torrents as $torrent) {
            if (count($filteredTorrents) >= 5) {
                break;
            }
            $resolution = intval(preg_replace('/[^0-9]/', '', $torrent['quality']));
            if ($resolution < $maxResolution) {
                $filteredTorrents[] = $torrent;
            }
        }
    }

    return $filteredTorrents;
}

function highlightMatch($text, $pattern) {    
    return str_ireplace($pattern, "<span style='background-color: #7fff26'>" . $pattern . "</span>", $text);
}

function filterCompareTitles($firstTitle, $secondTitle, $tvpack=false){
	
	global $season, $seasonNoPad, $globalTitle, $globalSeriesYear, $type;

	$showFiltered = true;
		
	//Replace non alphanumeric characters
    $firstTitle_adjusted = preg_replace('/[^a-zA-Z0-9]/', '', $firstTitle);
    $secondTitle_adjusted = preg_replace('/[^a-zA-Z0-9]/', '', $secondTitle);	
	
    $firstTitle_adjusted = strtolower($firstTitle_adjusted);
    $secondTitle_adjusted = strtolower($secondTitle_adjusted);
	
	// Check the first 3 characters match. Prevents 'Fear the Walking Dead' from matching 'The Walking Dead'.	
	if(substr($firstTitle_adjusted, 0, 3) !== substr($secondTitle_adjusted, 0, 3)){
		if ($GLOBALS['DEBUG'] && $showFiltered) {
			echo "<br>Original: ". $firstTitle . "<br>FILTERED! - Compare: " . $firstTitle_adjusted . ' to ' . $secondTitle_adjusted . "<br><br>";
		}		
		return false;
	}	

	//If no year is found or the year doesn't match return false. (good for movies, not for tv shows)
	if ($type == 'movies' && strpos($firstTitle_adjusted, $GLOBALS['globalYear']) === false) {
		if ($GLOBALS['DEBUG'] && $showFiltered) {
			echo "<br>Original: ". $firstTitle . "<br>FILTERED! - Compare: " . $firstTitle_adjusted . ' to ' . $secondTitle_adjusted . "<br><br>";
		}
		return false;
	} else {
		//Now strip the years from both titles before comparing.
		$firstTitle_adjusted = str_replace($GLOBALS['globalYear'], '', $firstTitle_adjusted);
		$secondTitle_adjusted = str_replace($GLOBALS['globalYear'], '', $secondTitle_adjusted);
	}	
	
	//Run this if tvpack is true..
	if($tvpack){
				
		//Replace 'complete season 1' with 'season 1' when comparing.
		$firstTitle_adjusted = str_replace('completeseason', 'season', $firstTitle_adjusted);
	
		//First compare the years to make sure they match.
		if(stripos($firstTitle_adjusted, $GLOBALS['globalYear']) !== false){
			//Strip the year if starting with 19 or 20
			$firstTitle_adjusted = preg_replace('/(19|20)\d{2}/', '', $firstTitle_adjusted);
			$secondTitle_adjusted = preg_replace('/(19|20)\d{2}/', '', $secondTitle_adjusted);
		}
		
		//Replace S01 with season1 if not followed by e01
		if(stripos($firstTitle_adjusted, 's'.$season) !== false){
			$firstTitle_adjusted = preg_replace('/s\d{2}(?!e\d{2})/', 'season'.$seasonNoPad, $firstTitle_adjusted);	
		}
        
		// Prevent double digit matching, this will match "season1" but not "season11
		$seasonRegex = "/season" . preg_quote($seasonNoPad, '/') . "(?!\d)/";
		if (!preg_match($seasonRegex, $firstTitle_adjusted)) {
			if ($GLOBALS['DEBUG'] && $showFiltered) {
				echo "<br>Original: ". $firstTitle . "<br>FILTERED! - Compare: " . $firstTitle_adjusted . ' to ' . $secondTitle_adjusted . "<br><br>";
			}
			return false;
		}		

	}
	
    if (stripos($firstTitle_adjusted, $secondTitle_adjusted) !== false) {  
	if ($GLOBALS['DEBUG'] && $showFiltered) {
		$match = $secondTitle_adjusted;
		$highlightedFirstTitle = highlightMatch($firstTitle_adjusted, $match);
		$highlightedSecondTitle = highlightMatch($secondTitle_adjusted, $match);
		echo "<br>Original: " . $firstTitle . "<br>MATCHED! - Compare: " . $highlightedFirstTitle . ' to ' . $highlightedSecondTitle . "<br><br>";
	}	
        return true;
    } else {  
		if ($GLOBALS['DEBUG'] && $showFiltered) {
			echo "<br>Original: ". $firstTitle . "<br>FILTERED! - Compare: " . $firstTitle_adjusted . ' to ' . $secondTitle_adjusted . "<br><br>";
		}
			return false;
		
    }
}	

function torrentSites($movieId, $imdbId, $title, $year = null)
{
    global $timeOut, $maxResolution, $torrentData, $type, $season, $episode, $seasonNoPad, $episodeNoPad, $useRealDebrid, $usePremiumize, $deleteRDFiles;
	
	$torrentTimeOut = 5;

    // The order of lines must match the same order & number of 
    // lines in the $processingFunctions array or errors will occur.
    $requests = [];
		$requests[] = initialize_torrentio_strem_fun($movieId, $imdbId, $title, $year);
		$requests[] = initialize_bitsearch_to($movieId, $imdbId, $title, $year);	
       $requests[] = initialize_torrents_csv_com($movieId, $imdbId, $title, $year);
    //$requests[] = initialize_MagnetDL_com($movieId, $imdbId, $title, $year);
    //$requests[] = initialize_bitLordSearch_com($movieId, $imdbId, $title, $year);
    $requests[] = initialize_thepiratebay_org($movieId, $imdbId, $title, $year);
    $requests[] = initialize_torrentDownload_info($movieId, $imdbId, $title, $year);
    $requests[] = initialize_popcornTime($movieId, $imdbId, $title);
    //$requests[] = initialize_torrentGalaxy_to($movieId, $imdbId, $title);
    $requests[] = initialize_glodls_to($movieId, $imdbId, $title, $year);
    $requests[] = initialize_limetorrents_cc($movieId, $imdbId, $title, $year);
    $requests[] = initialize_torrentz2_nz($movieId, $imdbId, $title, $year);
    $requests[] = initialize_knaben_eu($movieId, $imdbId, $title, $year);
    $requests[] = ($type == "series") ? initialize_ezTV_re($movieId, $imdbId, $title) : null;
    $requests[] = ($type == "movies") ? initialize_yts_mx($movieId, $imdbId, $title) : null;
	$requests[] = ($type == "movies") ? initialize_rutor_info($movieId, $imdbId, $title, $year) : null;

    // Run additional threads to search for Season TV Pack.
    $seasonTitle = preg_replace('/s\d{2}e\d{2}/i', 'Season ' . $seasonNoPad, $title);
	
	$requests[] = ($type == "series") ?  initialize_torrentio_strem_fun($movieId, $imdbId, $title, $year, true) : null;
    $requests[] = ($type == "series") ? initialize_bitsearch_to($movieId, $imdbId, $title, $year, true) : null;
    $requests[] = ($type == "series") ? initialize_rutor_info($movieId, $imdbId, $title, $year, true) : null;
    $requests[] = ($type == "series") ? initialize_torrents_csv_com($movieId, $imdbId, $title, $year, true) : null;
    //$requests[] = ($type == "series") ? initialize_MagnetDL_com($movieId, $imdbId, $seasonTitle, $year, true) : null;
    //$requests[] = ($type == "series") ? initialize_bitLordSearch_com($movieId, $imdbId, $seasonTitle, $year, true) : null;
    $requests[] = ($type == "series") ? initialize_thepiratebay_org($movieId, $imdbId, $seasonTitle, $year, true) : null;
    $requests[] = ($type == "series") ? initialize_torrentDownload_info($movieId, $imdbId, $seasonTitle, $year, true) : null;
    //$requests[] = ($type == "series") ? initialize_torrentGalaxy_to($movieId, $imdbId, $seasonTitle, true) : null;
    $requests[] = ($type == "series") ? initialize_glodls_to($movieId, $imdbId, $seasonTitle, $year, true) : null;
    $requests[] = ($type == "series") ? initialize_limetorrents_cc($movieId, $imdbId, $seasonTitle, $year, true) : null;
    $requests[] = ($type == "series") ? initialize_torrentz2_nz($movieId, $imdbId, $seasonTitle, $year, true) : null;
    $requests[] = ($type == "series") ? initialize_knaben_eu($movieId, $imdbId, $seasonTitle, $year, true) : null;
    $requests[] = ($type == "series") ? initialize_ezTV_re($movieId, $imdbId, $seasonTitle, true) : null;

    $headers = [
        'Connection: keep-alive',
        'Accept: text/html, application/json'
    ];

    // Initialize the multi cURL handler
    $mh = curl_multi_init();
    $curlHandles = [];
    $responses = [];
    $startTimes = [];
    $endTimes = [];

    foreach ($requests as $index => $request) {
        if ($request === null) {
            continue;
        }
        $url = $request;

        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $torrentTimeOut);
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/128.0");
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        curl_multi_add_handle($mh, $ch);
        $curlHandles[$index] = $ch;
        $startTimes[$index] = microtime(true);
    }

    // Execute all queries simultaneously
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh);

        // Check for completed requests
        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $index = array_search($ch, $curlHandles, true);
            if ($index !== false) {
                $endTimes[$index] = microtime(true);
                $responses[$index] = curl_multi_getcontent($ch);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
        }
    } while ($running > 0);

    curl_multi_close($mh);

    // Mapping the response processing functions
    $processingFunctions = [
				'torrentio_strem_fun' => 'torrentio_strem_fun', 
        'bitsearch_to' => 'bitsearch_to',	
        'torrents_csv_com' => 'torrents_csv_com',
        //'magnetdl_com' => 'magnetdl_com',
        //'bitLordSearch_com' => 'bitLordSearch_com',
        'thepiratebay_org' => 'thepiratebay_org',
        'torrentDownload_info' => 'torrentDownload_info',
        'popcornTime' => 'popcornTime',
        //'torrentGalaxy_to' => 'torrentGalaxy_to',
        'glodls_to' => 'glodls_to',
        'limetorrents_cc' => 'limetorrents_cc',
        'torrentz2_nz' => 'torrentz2_nz',
        'knaben_eu' => 'knaben_eu',
        'ezTV_re' => ($type == "series") ? 'ezTV_re' : null,
        'yts_mx' => ($type == "movies") ? 'yts_mx' : null,
		'rutor_info' => ($type == "movies") ? 'rutor_info' : null,
		'torrentio_strem_fun_TVPack' => ($type == "series") ? 'torrentio_strem_fun' : null,
		'bitsearch_to_TVPack' => ($type == "series") ? 'bitsearch_to' : null,		
        'rutor_info_TVPack' => ($type == "series") ? 'rutor_info' : null,		
        'torrents_csv_com_TVPack' => ($type == "series") ? 'torrents_csv_com' : null,
        //'magnetdl_com_TVPack' => ($type == "series") ? 'magnetdl_com' : null,
        //'bitLordSearch_com_TVPack' => ($type == "series") ? 'bitLordSearch_com' : null,
        'thepiratebay_org_TVPack' => ($type == "series") ? 'thepiratebay_org' : null,
        'torrentDownload_info_TVPack' => ($type == "series") ? 'torrentDownload_info' : null,       
        //'torrentGalaxy_to_TVPack' => ($type == "series") ? 'torrentGalaxy_to' : null,
        'glodls_to_TVPack' => ($type == "series") ? 'glodls_to' : null,
        'limetorrents_cc_TVPack' => ($type == "series") ? 'glodls_to' : null,
        'torrentz2_nz_TVPack' => ($type == "series") ? 'torrentz2_nz' : null,
        'knaben_eu_TVPack' => ($type == "series") ? 'knaben_eu' : null,
        'ezTV_re_TVPack' => ($type == "series") ? 'ezTV_re' : null,
    ];

    $results = [];
    foreach ($processingFunctions as $key => $func) {
        $index = array_search($key, array_keys($processingFunctions));
        if ($func !== null && isset($responses[$index])) {
            $startTime = $startTimes[$index];
            $endTime = $endTimes[$index];
            $timeDifference = round($endTime - $startTime, 2);

            // Check if the key contains 'Pack'
            if (strpos($key, 'TVPack') !== false) {
                // Call the function with $responses[$index] and true
                $totalAdded = $func($responses[$index], true) ?: 0;
            } else {
                // Call the function normally
                $totalAdded = $func($responses[$index]) ?: 0;
            }

            // Format the result
            $results[$key] = '(' . $totalAdded . ') - ' . $timeDifference . ' sec.';
        }
    }

    $randomId = uniqid('id_', true);
    $hashedRandomId = md5($randomId . rand());

    $htmlContent = '<div id="' . $hashedRandomId . '" style="display: none;">';
    foreach ($results as $functionName => $value) {
        $htmlContent .= "<li>$functionName $value</li>";
    }

    $service = 'RealDebrid';

    if (!empty($torrentData) && count($torrentData) > 0) {

        $returnedPremiumLink = '';

        // Run real debrid service.
        if ($useRealDebrid === true && $service == 'RealDebrid') {
			$premStartTime = microtime(true);
            $returnedPremiumLink = selectHashByPreferences($torrentData, $maxResolution, 'torrentSites', 'RealDebrid');
			$premEndTime= microtime(true);
			$premTimeDifference = round($premEndTime - $premStartTime, 2);
            if (count($deleteRDFiles) > 0) {
                // Run the deleteFiles_RD function to clean up.
                deleteFiles_RD($deleteRDFiles);
            }
        }

        if (empty($returnedPremiumLink)) {
            if ($useRealDebrid === true && $service == 'RealDebrid') {
                $pageUrl = 'https://real-debrid.com/';
                $htmlContent .= "<li>RealDebrid (0) - $premTimeDifference sec.</li>";
            }
            $service = 'Premiumize';
        }

        // Run premiumize service.
        if ($usePremiumize === true && $service == 'Premiumize') {
            $returnedPremiumLink = selectHashByPreferences($torrentData, $maxResolution, 'torrentSites', 'Premiumize');
        }

        if ($returnedPremiumLink !== false) {

            if ($useRealDebrid === true && $service == 'RealDebrid') {
                $pageUrl = 'https://real-debrid.com/';
                $htmlContent .= "<li>RealDebrid ($returnedPremiumLink[1]) - $premTimeDifference sec.</li>";
            }
            if ($usePremiumize === true && $service == 'Premiumize') {
                $pageUrl = 'https://premiumize.me/';
                $htmlContent .= "<li>Premiumize ($returnedPremiumLink[1]) - $premTimeDifference sec.</li>";
            }
            $htmlContent .= '</div><a href="javascript:void(0);" onclick="openPopup(\'' . $hashedRandomId . '\')">Click to view...</a>';
            logDetails('torrentSites', $htmlContent, 'successful', $GLOBALS['logTitle'], $pageUrl, $returnedPremiumLink[0], $type, $GLOBALS['movieId'], $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');

            return $returnedPremiumLink[0];
        } else {

            if ($useRealDebrid === true && $service == 'RealDebrid') {
                $pageUrl = 'https://real-debrid.com/';
                $htmlContent .= "<li>RealDebrid (0) - $premTimeDifference sec.</li>";
            }
            if ($usePremiumize === true && $service == 'Premiumize') {
                $pageUrl = 'https://premiumize.me/';
                $htmlContent .= "<li>Premiumize (0) - $premTimeDifference sec.</li>";
            }

            $htmlContent .= '</div><a href="javascript:void(0);" onclick="openPopup(\'' . $hashedRandomId . '\')">Click to view...</a>';
            logDetails('torrentSites', $htmlContent, 'failed', $GLOBALS['logTitle'], $pageUrl, 'n/a', $type, $GLOBALS['movieId'], $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');

            return false;
        }
    } else {

        if ($useRealDebrid === true && $service == 'RealDebrid') {
            $pageUrl = 'https://real-debrid.com/';
            $htmlContent .= "<li>RealDebrid: 0</li>";
        }
        if ($usePremiumize === true && $service == 'Premiumize') {
            $pageUrl = 'https://premiumize.me/';
            $htmlContent .= "<li>Premiumize: 0</li>";
        }

        $htmlContent .= '</div><a href="javascript:void(0);" onclick="openPopup(\'' . $hashedRandomId . '\')">Click to view...</a>';
        logDetails('torrentSites', $htmlContent, 'failed', $GLOBALS['logTitle'], $pageUrl, 'n/a', $type, $GLOBALS['movieId'], $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');

        return false;
    }
}

function checkLinkStatusCode($url, $verify = false)
{
	global $HTTP_PROXY, $USE_HTTP_PROXY;
		
    // Existing code for checking 'video_proxy.php' and 'hls_proxy.php'
    if (strpos($url, 'video_proxy.php') !== false || strpos($url, 'hls_proxy.php') !== false) {
        /* return true; */
		$url = locateBaseURL() . $url;
    }
	
    // Check if the URL is empty
    if (empty($url)) {
        if ($GLOBALS['DEBUG']) {
            echo 'Link Checker - URL is empty.</br></br>';
        }
        return false;
    }

    // Check if the URL contains headers
    $headers = [];
    if (strpos($url, '|') !== false) {
        list($actualUrl, $headersStr) = explode('|', $url, 2);
        $headersStr = trim($headersStr);
        if (!empty($headersStr)) {
            $headersArr = explode('|', $headersStr);
            foreach ($headersArr as $header) {
                list($headerName, $headerValue) = explode('=', $header, 2);
                $headers[] = $headerName . ': ' . $headerValue;
            }
        }
        $url = $actualUrl;
    }

    // Initialize cURL
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_NOBODY, true); 
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); 
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
	
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    // Execute cURL
    $response = curl_exec($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

    // Close cURL
    curl_close($ch);	

    if ($response !== false) {
        // Check for specific error codes
        if ($httpStatus >= 400) {
            if ($GLOBALS['DEBUG']) {
                echo 'Link Checker - The URL returned a ' . $httpStatus . ' status.</br></br>';
            }
            return false;
        }

        // Check Content-Type for video formats
		$videoExtensions = ['.mp4', '.m3u8', '.mkv', '.mov'];

		$isVideoExtension = false;
		foreach ($videoExtensions as $extension) {
			if (stripos($url, $extension) !== false) {
				$isVideoExtension = true;
				break;
			}
		}

		if (stripos($contentType, 'video') !== false || 
			stripos($contentType, 'mpegurl') !== false || 
			(stripos($contentType, 'text') !== false && stripos($url, '.m3u8') !== false) || 
			(stripos($contentType, 'application/force-download') !== false && $isVideoExtension)) {
			
			if ($GLOBALS['DEBUG']) {
				echo 'Link Checker - Successful: The URL is accessible and valid for streaming.</br></br>';
			}
			return true;
		}

        if ($GLOBALS['DEBUG']) {
            echo 'Link Checker - The URL does not point to a valid video format.</br></br>';
        }
        return false;
    }

    if ($GLOBALS['DEBUG']) {
        echo 'Link Checker - Failed to fetch the URL or an error occurred.</br></br>';
    }
    return false;
}

function selectHashByPreferences($torrents, $maxResolution, $tSite, $service)
{
    global $PRIVATE_TOKEN, $usePremiumize, $useRealDebrid, $seasonNoPad;

    // === Filter out DTS ===
    $filteredOutCount = 0;
    $torrents = array_filter($torrents, function ($torrent) use (&$filteredOutCount) {
        if (stripos($torrent['extracted_title'], 'DTS') !== false) {
            $filteredOutCount++;
            return false;
        }
        return true;
    });

    if ($GLOBALS['DEBUG']) {
        echo "Total torrents filtered out due to DTS audio: " . $filteredOutCount . "</br></br>";
    }
    if (empty($torrents)) {
        if ($GLOBALS['DEBUG']) {
            echo "No torrents available after filtering out DTS audio.</br></br>";
        }
        return false;
    }

    // === Remove duplicate hashes ===
    $uniqueTorrents = [];
    foreach ($torrents as $torrent) {
        $key = strtolower($torrent['hash']);
        if (!array_key_exists($key, $uniqueTorrents)) {
            $uniqueTorrents[$key] = $torrent;
        }
    }
    $torrents = array_values($uniqueTorrents);

    $initialCount = count($torrents);
    $availableCountRealDebrid = $initialCount;
    $availableCountPremiumize = $initialCount;

    // === Sorting + resolution filtering before any service checks ===
    sortTorrentsByQuality($torrents);
    $sortedTorrents = filterTorrentsByResolution($torrents, $maxResolution);

    $attemptCount = 0;

    foreach ($sortedTorrents as $torrent) {
        $selectedHash = $torrent['hash'];

        // === RealDebrid branch ===
        if ($useRealDebrid === true && $service === 'RealDebrid') {
            // Use new RD flow: instantAvailability_RD handles addMagnet→select→unrestrict
            $streamUrl = instantAvailability_RD([$torrent], $tSite);
            if ($streamUrl) {
                return [
                    $streamUrl,
                    $availableCountRealDebrid,
                ];
            }
        }

        // === Premiumize branch ===
        elseif ($usePremiumize === true && $service === 'Premiumize') {
            $getStreamingLinkReturn = getStreamingLink_PM([$torrent], 'magnet:?xt=urn:btih:' . $selectedHash, $tSite);
            if ($getStreamingLinkReturn) {
                return [
                    $getStreamingLinkReturn,
                    $availableCountPremiumize,
                ];
            }
        }

        $attemptCount++;
        if ($attemptCount >= 5) {
            return false; // don’t loop endlessly
        }
    }

    if ($GLOBALS['DEBUG']) {
        echo "No hash was found to be suitable.</br></br>";
    }
    return false;
}

function extractResolution($quality)
{
    $regex = '/(\d+)P/i';
    preg_match($regex, $quality, $match);

    if ($match && $match[1]) {
        return intval($match[1]);
    }
    return null;
}

//Function for Primewire.tf (search key).
function generatePWSearchKey($query) {	
    $hardcodedKey = "hx4NNrPLs688H9x";
    $combinedString = $query . $hardcodedKey;
    $hashedString = sha1($combinedString);
    return substr($hashedString, 0, 10);
}

//Function for Primewire.tf (user data).
function decryptPWuserData($encryptedData) {
    // Check if encryptedData is provided
    if (!$encryptedData) {
        return false;
    }

    // Extract the last 10 characters as the key
    $key = substr($encryptedData, -10);

    // Remove the last 10 characters from encryptedData
    $encryptedData = substr($encryptedData, 0, -10);

    // Decode the remaining encryptedData
    $data = base64_decode($encryptedData);
    if ($data === false) {
        // Return false if base64_decode fails
        return false;
    }

    // Set decryption options
    $opts = OPENSSL_RAW_DATA | OPENSSL_DONT_ZERO_PAD_KEY | OPENSSL_ZERO_PADDING;

    // Decrypt the data
    $decrypted = openssl_decrypt($data, 'BF-ECB', $key, $opts);
    if ($decrypted === false) {
        // Return false if decryption fails
        return false;
    }

    // Split the decrypted string into parts of 5 characters each
    $keys = str_split($decrypted, 5);

    return $keys;
}

function getLastRedirectUrl($url) {
	
	global $timeOut;
	
    $ch = curl_init($url);

    // Set cURL options
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Follow redirects
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Return the transfer as a string
    curl_setopt($ch, CURLOPT_HEADER, true);         // Include the header in the output
    curl_setopt($ch, CURLOPT_NOBODY, true);         // No need to download the body
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeOut);    // Set timeout

    // Set User-Agent
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0");

    // Execute the request
    curl_exec($ch);

    // Check if any error occurred
    if (!curl_errno($ch)) {
        $lastUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL); // Get the last effective URL
    } else {
        // Handle error, e.g., by throwing an exception or returning null
        $lastUrl = null;
    }

    // Close the cURL session
    curl_close($ch);

    return $lastUrl;
}

//Base64 Decode for upMovies_to
function decode64UpMovies($url) {
	global $timeOut;
    try {
        $context = stream_context_create([
            'http' => [
                'timeout' => $timeOut, 
                'header' => 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0',
            ], 
        ]);

        $response = @file_get_contents($url, false, $context);

        // Check if the request was successful
        if ($response === false) {
            throw new Exception("Failed to fetch the URL: $url");
        }

        preg_match('#(?<=document\.write\(Base64\.decode\(").*?(?=")#', $response, $matches);

        if (isset($matches[0])) {
            $iframe = base64_decode($matches[0]);
            if (preg_match('#(?<=src=").*?(?=")#', $iframe, $iframeMatches)) {
                return $iframeMatches[0];
            }
        }

        throw new Exception("Couldn't find url in decoded base64 on page.");

    } catch (Exception $e) {        
        return false; // or return "Error: " . $e->getMessage();
    }
}

//Decryption for smashyStream_com
function decryptSmashyStreamSources($x) {
    // Provided keys
    $v = array(
        "bk0" => "SFL/dU7B/Dlx",
        "bk1" => "0ca/BVoI/NS9",
        "bk2" => "box/2SI/ZSFc",
        "bk3" => "Hbt/WFjB/7GW",
        "bk4" => "xNv/T08/z7F3",
        "file3_separator" => "//"
    );

    $a = substr($x, 2);

    for ($i = 4; $i > -1; $i--) {
        if (isset($v["bk" . $i]) && $v["bk" . $i] !== "") {
            // Base64 encode (b1 equivalent)
            $encoded = base64_encode(implode(array_map(function($c) {
                return chr(hexdec($c));
            }, str_split(bin2hex($v["bk" . $i]), 2))));

            $a = str_replace($v["file3_separator"] . $encoded, "", $a);
        }
    }

    try {
        // Base64 decode (b2 equivalent)
        $decoded = implode(array_map(function($c) {
            return urldecode('%' . dechex(ord($c)));
        }, str_split(base64_decode($a))));

        $a = $decoded;
    } catch (Exception $e) {
        return false;
    }

    return $a;
}

//Decryption for superEmbed_stream
function superEmbedBaseConvert($value, $fromBase, $toBase) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ+/';
    $fromCharacters = substr($characters, 0, $fromBase);
    $toCharacters = substr($characters, 0, $toBase);
    $decimalValue = 0;

    // Reversing the string and converting from the base $fromBase to decimal
    for ($i = 0; $i < strlen($value); $i++) {
        $decimalValue += strpos($fromCharacters, $value[$i]) * pow($fromBase, strlen($value) - $i - 1);
    }

    // Converting from decimal to the base $toBase
    $result = '';
    while ($decimalValue > 0) {
        $result = $toCharacters[$decimalValue % $toBase] . $result;
        $decimalValue = intdiv($decimalValue, $toBase);
    }
    
    return $result ?: '0';
}
//Decryption for superEmbed_stream
function superEmbedDecodeString($encodedString, $dictionary, $fromBase, $shift, $index) {
    $decoded = '';
    $len = strlen($encodedString);
    for ($i = 0; $i < $len; $i++) {
        $temp = '';
        while ($i < $len && $encodedString[$i] !== $dictionary[$index]) {
            $temp .= $encodedString[$i];
            $i++;
        }
        $temp = str_replace(str_split($dictionary), range(0, strlen($dictionary) - 1), $temp);
        $decoded .= chr(superEmbedBaseConvert($temp, $index, 10) - $shift);
    }
    return rawurldecode($decoded);
}

function decode_unicode_sequence($str) {
    return preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function ($matches) {
        return mb_convert_encoding(pack('H*', $matches[1]), 'UTF-8', 'UCS-2BE');
    }, $str);
}

function decryptAesGCM($data, $pass) {
    
    function generateKeyAndIv($pass) {
        
        function getCurrentUTCDateString() {
            return gmdate('D, d M Y H:i:s') . ' GMT';
        }

        $datePart = substr(getCurrentUTCDateString(), 0, 16);
        $hexString = $datePart . $pass;
        $digest = hash('sha256', $hexString, true);

        $key = substr($digest, 0, 16);
        $iv = substr($digest, 16, 16);

        return array($key, $iv);
    }

    list($key, $iv) = generateKeyAndIv($pass);

    $tag = substr($data, -16);
    $ciphertext = substr($data, 0, -16);

    $decrypted = openssl_decrypt($ciphertext, 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    
    return $decrypted !== false ? $decrypted : false;
}

function throttleRequest($key) {
	//For impatient client who abandons the request and sends another.
    $maxWaitTime = 60;
    $waited = 0;
    
    if ($GLOBALS['DEBUG']) {
        echo 'Throttling: Primary request started, all subsequent requests are throttled.<br><br>';
    } 

    while ($waited < $maxWaitTime) {
        $runningUrl = readFromCache($key, false);

        if ($runningUrl !== '_running_') {
            break;
        }
        
        sleep(1);
        $waited++;
    }
    
    if ($runningUrl === '_running_' || $runningUrl === null || $runningUrl === '_failed_') {
        http_response_code(404);
        echo "The requested resource was not found.";
        exit;
    }

    if ($GLOBALS['DEBUG']) {
        echo "Service: Pulled from the cache - Url: " . $runningUrl . "<br><br>";
        echo 'Debugging: Redirection to the video would have taken place here.<br><br>';           
        exit;
    } 

    header("HTTP/1.1 301 Moved Permanently");
    header("Location: $runningUrl");
    exit();        
}

function throttleMxPlayerRequests($movieId) {
    global $type, $episodeId;
	    
    $movieId = intval($movieId);
	
	if(intval($movieId) > 10000000){
		$keyPart = '_adult_url';
	}  elseif($type === 'movies'){
		$keyPart = '_tmdb_url';
	} else {
		$keyPart = '_series_' . $episodeId . '_url';
	} 
    
    $key = $movieId . $keyPart;
    $keyB = ($movieId + 1) . $keyPart;
    $keyC = ($movieId - 1) . $keyPart;


    $cacheFilePath = 'cache.json';
    $now = time();
    $throttlePeriod = 120; // 120 seconds

    // Read existing cache data
    $cacheData = json_decode(file_get_contents($cacheFilePath), true) ?: [];

    // Check the addedTime for the keys
    $keysToCheck = [$key, $keyB, $keyC];
    foreach ($keysToCheck as $checkKey) {
        if (isset($cacheData[$checkKey]) && isset($cacheData[$checkKey]['addedTime']) && ($now - $cacheData[$checkKey]['addedTime']) <= $throttlePeriod) {
            http_response_code(404);
            echo "The requested resource was not found.";
            exit;
        }
    }
}

////////////////////////////// Direct Movies & Tv Shows Websites ///////////////////////////////

function watch_movies_com_pk($movieId, $title, $year) {
    global $DEBUG, $logTitle;
    
    if ($DEBUG) {
        echo 'Started running watch-movies_com_pk </br></br>';
    }

    $cleanTitle = preg_replace('/[^a-zA-Z0-9]/', '', strtolower($title)); 
	$searchQuery = preg_replace('/[^a-zA-Z0-9 ]/', '', strtolower($title)) . ' ' . $year;

    try {
        $urlSearch = "https://www.watch-movies.com.pk/wp-json/wp/v2/posts?categories=719&search=" . urlencode($searchQuery) . "&_embed";
		
		if ($DEBUG) {
			echo "Search Url: " . $urlSearch . "<br><br>";
		}

        $response = file_get_contents($urlSearch);
        $posts = json_decode($response, true);
        
        foreach ($posts as $post) {
            $postTitle = preg_replace('/[^a-zA-Z0-9]/', '', strtolower($post['title']['rendered']));
			
			if ($DEBUG) {
				echo "Checking if: " . $postTitle . " contains " . $cleanTitle . $year . "<br><br>";
			}
			
			if (strpos($postTitle, $cleanTitle . $year) !== false) {
                if ($DEBUG) {
                    echo "Matching post found: " . $post['title']['rendered'] . "</br></br>";
                }
                
                $content = $post['content']['rendered'];
                preg_match_all('/<iframe[^>]*\s+src=["\']([^"\']+)["\']/i', $content, $matches);

                $embedpkUrls = [];
                $otherUrls = [];
                
                foreach ($matches[1] as $iframeSrc) {
                    if (strpos($iframeSrc, 'embedpk.net') !== false) {
                        $embedpkUrls[] = $iframeSrc;
                    } else {
                        $otherUrls[] = $iframeSrc;
                    }
                }

                $allUrls = array_merge($otherUrls, $embedpkUrls);

                foreach ($allUrls as $urlToCheck) {
                    $tSite = "watch-movies_com_pk";
                    $referer = "https://www.watch-movies.com.pk/";

                    if ($DEBUG) {
                        echo "Checking iframe URL: " . $urlToCheck . "</br></br>";
                    }

                    $urlReturn = FindVideoExtractor($urlToCheck, $tSite, $referer);
                    
                    if ($urlReturn !== false) {
                        return $urlReturn;
                    }
                }
            }
        }

        return false;
    } catch (Exception $error) {
        if ($DEBUG) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
        }
        return false;
    }
	return false;
}

function rive_vidsrc_scrapper($movieId, $title) {
    global $timeOut, $maxResolution, $type, $seasonNoPad, $episodeNoPad, $DEBUG, $logTitle;

    $services = [
        '/upcloud',
        '/rgshows-pptr',
        '/rgshows-api',
		'/vidsrcto',
        '/vidsrcpro'
    ];

    if ($DEBUG) {
        echo 'Started running rive_vidsrc_scrapper </br></br>';
    }

    $baseURL = "https://rive-vidsrc-scrapper.vercel.app";

    foreach ($services as $service) {
        try {
            if ($type == 'series') {
                $urlSearch = $baseURL . $service . "/tv/$movieId/$seasonNoPad/$episodeNoPad";
            } else {
                $urlSearch = $baseURL . $service . "/movie/$movieId";
            }

            $response = file_get_contents($urlSearch);
            $json = json_decode($response, true);

            if (isset($json['data']['sources'][0]['url'])) {
                $sourceUrl = $json['data']['sources'][0]['url'];
                logDetails('rive_vidsrc_scrapper', str_replace('/', '', $service), 'successful', $logTitle, $urlSearch, $sourceUrl, $type, $movieId, $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');
                return $sourceUrl;
            }

        } catch (Exception $error) {
            if ($DEBUG) {
                echo 'Error: ' . $error->getMessage() . "</br></br>";
            }

            logDetails('rive_vidsrc_scrapper', str_replace('/', '', $service), 'failed', $logTitle, $urlSearch, 'n/a', $type, $movieId, $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');
        }
    }
    if ($DEBUG) {
        echo 'Finished running rive_vidsrc_scrapper </br></br>';
    }
    return false;
}

function vidsrc_rip($movieId, $title) {
    global $timeOut, $maxResolution, $type, $seasonNoPad, $episodeNoPad, $DEBUG, $logTitle;
	
	require_once 'libs/vidsrc_rip.php';

    if ($DEBUG) {
        echo 'Started running vidsrc_rip </br></br>';
    }
	
    try {
		$streamsData = [];
		if ($type == 'movies') {
			$urlSearch = "https://vidsrc.rip/embed/movie/$movieId";
			$vidsrcReturn = getVidSrcRip($movieId, $type, $streamsData);
		} else {
			$urlSearch = "https://vidsrc.rip/embed/tv/$movieId/$seasonNoPad/$episodeNoPad";
			$vidsrcReturn = getVidSrcRip($movieId, $type, $streamsData, $seasonNoPad, $episodeNoPad);
		}
		
		if ($vidsrcReturn !== false && !empty($vidsrcReturn)){
			
			
			foreach ($vidsrcReturn as $item) {
				
				if (!isset($item['link'])) {
					continue;
				}
				
				$sourceUrl = $item['link'];
				$checkData = $sourceUrl;
				$lCheck = checkLinkStatusCode($checkData, true);
				if ($lCheck !== true) {
					continue;
				} else {
					if ($GLOBALS['DEBUG']) {
						echo "Video link: " . $sourceUrl . "<br><br>";
					}
					logDetails('vidsrc_rip', 'none', 'successful', $logTitle, $urlSearch, $sourceUrl, $type, $movieId, $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');
					return $sourceUrl;
				} 
				
			}
		} else {
			throw new Exception('No links found on vidsrc_rip');
		}
			
			logDetails('vidsrc_rip', 'none', 'failed', $logTitle, $urlSearch, 'n/a', $type, $movieId, $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');
			return false;	
    } catch (Exception $error) {
        if ($DEBUG) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
			echo 'Finished running vidsrc_rip </br></br>';
        }

		logDetails('vidsrc_rip', 'none', 'failed', $logTitle, $urlSearch, 'n/a', $type, $movieId, $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');
        return false;
    }
}

function vidsrc_to($movieId, $title) {
    global $timeOut, $maxResolution, $type, $seasonNoPad, $episodeNoPad, $DEBUG, $logTitle;
	
	require_once 'libs/vidscr.php';

    if ($DEBUG) {
        echo 'Started running vidsrc_to </br></br>';
    }
	
    try {
		
		if ($type == 'series') {
			$urlSearch = "https://vidsrc.to/embed/tv/$movieId/$seasonNoPad/$episodeNoPad";
			$vidsrcReturn = vidplayExtract($movieId, $seasonNoPad, $episodeNoPad);
		} else {
			$urlSearch = "https://vidsrc.to/embed/movie/$movieId";
			$vidsrcReturn = vidplayExtract($movieId);
		}
		
		if($vidsrcReturn !== false){
			logDetails('vidsrc_to', 'vidplayExtract', 'successful', $logTitle, $urlSearch, $vidsrcReturn, $type, $movieId, $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');
			return $vidsrcReturn;
		} else {
			logDetails('vidsrc_to', 'vidplayExtract', 'failed', $logTitle, $urlSearch, 'n/a', $type, $movieId, $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');
			return false;			
		}  
    } catch (Exception $error) {
        if ($DEBUG) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
        }

        logDetails('vidsrc_to', 'vidplayExtract', 'failed', $logTitle, $urlSearch, 'n/a', $type, $movieId, $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');
        return false;
    }
}

function autoembed_cc($movieId, $title, $imdbId) {
    global $timeOut, $maxResolution, $type, $seasonNoPad, $episodeNoPad, $DEBUG, $logTitle, $globalTitle, $globalYear;
    if ($DEBUG) {
        echo 'Started running autoembed_cc </br></br>';
    }
    $encodedTitle = str_replace(' ', '%20', $title);
    
    try {
        $baseUrl = "https://test.autoembed.cc";
        $serversUrl = "$baseUrl/api/servers";
        $additionalHeaders = [
            "Origin: $baseUrl"
        ];
        $response = makeGetRequest($serversUrl, $baseUrl, $additionalHeaders);

				if ($GLOBALS['DEBUG']) {
						echo "Servers: $response <br><br>";
				}
        if ($response === FALSE) {
            throw new Exception('Error fetching the Servers URL: ' . $serversUrl);
        }
        
        $data = json_decode($response, true);
        $englishServers = [];
        foreach ($data['servers'] as $server) {
            if (
                (isset($server['language']) && stripos($server['language'], 'English') !== false) ||
                (isset($server['audioLanguage']) && stripos($server['audioLanguage'], 'English') !== false)
            ) {
                $englishServers[] = $server['server'];
            }
        }
        
        foreach ($englishServers as $serverNumber) {
            if ($type == 'series') {
                $url = "https://test.autoembed.cc/api/server?id=$movieId&sr=$serverNumber&ep=$episodeNoPad&ss=$seasonNoPad&args=$encodedTitle*$globalYear*$imdbId";
            } else {
                $url = "https://test.autoembed.cc/api/server?id=$movieId&sr=$serverNumber&args=$encodedTitle*$globalYear*$imdbId";
            }   
            
            $response = makeGetRequest($url, $baseUrl, $additionalHeaders);
            if ($response === FALSE) {
                continue;
            }
            
            $data = json_decode($response, true);
            if (!$data || !isset($data['data'])) {
                continue;
            }
            
            $b64 = $data['data'];
            $decryptedJson = decryptAutoembed($b64);
            $decryptedArr = json_decode($decryptedJson, true);
            if (!$decryptedArr || !isset($decryptedArr['url'])) {
                continue;
            }
            
            $firstUrl = $decryptedArr['url'];
            
						if (preg_match('#^https?://#i', $firstUrl)) {
								$fullUrl = $firstUrl;
						} else {
								$fullUrl = rtrim($baseUrl, '/') . $firstUrl;
						}


            
            if ($fullUrl) {
 
                $combineHeaders = '';
                $combineHeaders .= "|Referer=$baseUrl";
                $combineHeaders .= "|Origin=$baseUrl";
                $combineHeaders .= "|User-Agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0";
                if (strpos($fullUrl, $baseUrl) !== false) {
									if (strpos($fullUrl, '.mp4') !== false) {
										$sourceUrl = 'video_proxy.php?url=' . urlencode($fullUrl) . '&auto=1&data=' . base64_encode($combineHeaders);
									} else {
										$sourceUrl = 'hls_proxy.php?url=' . urlencode($fullUrl) . '&data=' . base64_encode($combineHeaders);
										
									}
                    if ($GLOBALS['DEBUG']) {
                        echo "Proxy Url: " . $sourceUrl . "<br><br>";
                    }
									$checkData = $sourceUrl;
                } else {
									$sourceUrl = $fullUrl;
									$checkData = $sourceUrl;
								}

                $lCheck = checkLinkStatusCode($checkData, true);
                if ($lCheck !== true) {
                    continue;
                } else {
                    if ($GLOBALS['DEBUG']) {
                        echo "Video link: " . $sourceUrl . "<br><br>";
                    }
                    logDetails('autoembed_cc', 'none', 'successful', $logTitle, $url, $sourceUrl, $type, $movieId, $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');
                    return $sourceUrl;
                }
            }
        }
        
        logDetails('autoembed_cc', 'none', 'failed', $logTitle, isset($url) ? $url : 'n/a', 'n/a', $type, $movieId, $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');
        return false;
        
    } catch (Exception $error) {
        if ($DEBUG) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
            echo 'Finished running autoembed_cc </br></br>';
        }
        logDetails('autoembed_cc', 'none', 'failed', $logTitle, isset($url) ? $url : 'n/a', 'n/a', $type, $movieId, $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');
        return false;
    }
}

function decryptAutoembed($b64) {
  
    $json = base64_decode($b64);
    if (!$json) return false;
    $data = json_decode($json, true);
    if (!$data) return false;
    $password = $data['key'];
    $salt = hex2bin($data['salt']);
    $iv = hex2bin($data['iv']);
    $iterations = (int)$data['iterations'];
    $ciphertext = base64_decode($data['encryptedData']);
    $key = hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true);
    $decrypted = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    $pad = ord(substr($decrypted, -1));
    if ($pad > 0 && $pad <= 16) {
        $decrypted = substr($decrypted, 0, -$pad);
    }
		if ($GLOBALS['DEBUG']) {
				echo "Decrypted Data: " . $decrypted . "<br><br>";
		}

    return $decrypted;
}

function vidsrc_pro($movieId, $title) {
    global $timeOut, $maxResolution, $type, $seasonNoPad, $episodeNoPad, $DEBUG, $logTitle;

    if ($DEBUG) {
        echo 'Started running vidsrc_pro </br></br>';
    }

    $PROVIDER = 'VidsrcPro';
    $DOMAIN = "https://vidsrc.pro";

    if ($type == 'series') {
        $urlSearch = "$DOMAIN/embed/tv/$movieId/$seasonNoPad/$episodeNoPad";
    } else {
        $urlSearch = "$DOMAIN/embed/movie/$movieId";
    }

    try {

        $htmlSearch = makeGetRequest($urlSearch, 'https://vidsrc.pro/');
        if (!$htmlSearch) {
            throw new Exception('Failed to fetch search HTML');
        }

        $hash = '';
        if (preg_match('/hash\" *\: *\"([^\"]+)/i', $htmlSearch, $matches)) {
            $hash = $matches[1];
        }

        if (!$hash) {
            throw new Exception('No hash found');
        }

        $decodeHash = function ($a) {
            return base64_decode(strrev($a));
        };

        $parseHash = json_decode($decodeHash($hash), true);
        if (!$parseHash) {
            throw new Exception('Failed to decode hash');
        }

        $urlDirect = '';
        foreach ($parseHash as $item) {
            $urlDirect = "$DOMAIN/api/e/" . $item['hash'];
            $dataDirect = makeGetRequest($urlDirect, 'https://vidsrc.pro/');
            $dataDirect = json_decode($dataDirect, true);
            if (!$dataDirect || !isset($dataDirect['source'])) {
                continue;
            }

            $urlDirect = $dataDirect['source'];
            break;
        }

        if (empty($urlDirect)) {
            throw new Exception('No valid video URL found');
        }

        // Handle case where urlDirect is from the api/e endpoint
        if (strpos($urlDirect, "$DOMAIN/api/e/") !== false) {
            $jsonResponse = file_get_contents($urlDirect, false, $context);
            $jsonData = json_decode($jsonResponse, true);
            if (isset($jsonData['source'])) {
                $urlDirect = $jsonData['source'];
            } else {
                throw new Exception('No source found in JSON response');
            }
        }

        $q = '';
        if (preg_match('/\?base\=([A-z0-9.]+)/i', $urlDirect, $qMatches)) {
            $q = $qMatches[1];
        }

        $endpoint = '';
        if (preg_match('/proxy\/[A-z]+([A-z0-9_\/\.\-]+\.m3u8)/i', $urlDirect, $endpointMatches)) {
            $endpoint = $endpointMatches[1];
        }

        if ($q && $endpoint) {
            $urlDirect = "https://$q$endpoint";
        }

        if ($DEBUG) {
            echo 'Returned Url: ' . $urlDirect . "</br></br>";
        }

        if (checkLinkStatusCode($urlDirect, false, false)) {
            if ($DEBUG) {
                echo 'Video Link: ' . $urlDirect . "</br></br>";
            }
            logDetails('vidsrc_pro', 'none', 'successful', $logTitle, $urlSearch, $urlDirect, $type, $movieId, $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');
            return $urlDirect;
        }

        throw new Exception('Couldn\'t find a source on vidsrc_pro.');
    } catch (Exception $error) {
        if ($DEBUG) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
        }

        logDetails('vidsrc_pro', 'none', 'failed', $logTitle, $urlSearch, 'n/a', $type, $movieId, $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');
        return false;
    }
}

function showBox_media($movieId, $title, $imdbId) {
    global $timeOut, $maxResolution, $type, $seasonNoPad, $episodeNoPad, $DEBUG, $logTitle, $movieId, $seriesCode, $globalYear, $HeadlessVidX_Address;
	
	$cors = base64_decode('aHR0cHM6Ly9jcnMuMXByb3h5LndvcmtlcnMuZGV2Lz91cmw9');
	
	$url = "https://s.movieboxpro.app/api/api/index.html?srchtxt=" . $imdbId ."&srchmod=42&page=1&page_size=32&filter=&srchsort=&qf=1&language=en";	

    if ($type == 'movies') {
        $contentType = '1';        
    } else {
        $contentType = '2';       
    }
    $season = $seasonNoPad;
    $episode = $episodeNoPad;

    $strippedTitle = preg_replace("/[^a-zA-Z0-9 ]/", "", strtolower($title));
    $strippedTitle = str_replace(" ", "-", $strippedTitle);
    $url .= $strippedTitle . "-" . $globalYear;
    if ($DEBUG) {
        echo 'Started running showBox_media </br></br>';
    }
	
	$additionalHeaders = [
		'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:126.0) Gecko/20100101 Firefox/126.0',
		'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
		'Accept-Language: en-US,en;q=0.5',
		'DNT: 1',
		'Sec-GPC: 1',
		'Connection: keep-alive',		
		'Upgrade-Insecure-Requests: 1',
		'Sec-Fetch-Dest: document',
		'Sec-Fetch-Mode: navigate',
		'Sec-Fetch-Site: none',
		'Sec-Fetch-User: ?1',
		'Priority: u=1',
		'Pragma: no-cache',
		'Cache-Control: no-cache'
	];

    try {			
	

	
        $detailsPage = makeGetRequest($url, 'https://www.showbox.media/');

        if ($detailsPage === false) {
            throw new Exception('HTTP Error: showBox_media details page.</br></br>');
        }
		
        if (preg_match('/"id":"(?:movie|tv)_(\d+)"/', $detailsPage, $matches)) {
            $showId = $matches[1];
        } else {
            throw new Exception('Error: showBox_media couldn\'t locate the share id.</br></br>');
        }

        $febBoxUrl = $cors . urlencode("https://showbox.media/index/share_link?id={$showId}&type=" . $contentType);

        $febBoxResult = makeGetRequest($febBoxUrl);		
	
        if ($febBoxResult === false) {
            throw new Exception('HTTP Error: showBox_media febBox result</br></br>');
        }	
	
		if (preg_match('/(?<=link":").*?(?=")/', $febBoxResult, $matches)) {				
				$shareLink = str_replace('\\', '', $matches[0]);				
			} else {
				throw new Exception('Error fetching febBox data</br></br>');
		}	

        $febBoxExtractedData = extractFebBox($shareLink, $seasonNoPad, $episodeNoPad);
		
		// Return how_to_showbox_media_cookie.mp4
		if(strpos($febBoxExtractedData, 'videos/how_to_showbox_media_cookie.mp4') !== false){
			return $febBoxExtractedData;
		}	

        if (!$febBoxExtractedData) {
            throw new Exception('No valid data extracted from febBox</br></br>');
        }

        $febBoxExtractedData = json_decode($febBoxExtractedData, true);

        if (!is_array($febBoxExtractedData)) {
            throw new Exception('Invalid extracted data structure</br></br>');
        }

        // Prioritize the URL based on $maxResolution
        $selectedUrl = null;
        $resolutions = array_map(function($item) {
            return (int) rtrim($item['quality'], 'P');
        }, $febBoxExtractedData);

        usort($resolutions, function($a, $b) {
            return $b - $a; // Sort descending
        });

        foreach ($resolutions as $resolution) {
            if ($resolution <= $maxResolution) {
                $selectedUrl = array_values(array_filter($febBoxExtractedData, function($item) use ($resolution) {
                    return (int) rtrim($item['quality'], 'P') === $resolution;
                }))[0]['url'];
                break;
            }
        }

        // If no appropriate resolution is found, use the highest available resolution
        if (!$selectedUrl) {
            $selectedUrl = $febBoxExtractedData[0]['url'];
        }

		$checkData = $selectedUrl . "|Referer='https://www.febbox.com/'|User-Agent='Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/127.0'";
			
		$urlData = 'video_proxy.php?data=' . urlencode(base64_encode($checkData));		
		
		$lCheck = checkLinkStatusCode($urlData);
		if ($lCheck == true) {
			if ($GLOBALS['DEBUG']) {
				echo "Video link: " . $urlData . "<br><br>";
			}
			logDetails('showBox_media', 'extractFebBox', 'successful', $logTitle, $shareLink, $urlData, $type, $movieId, $type === 'series' ? $seriesCode : 'n/a');
			return $urlData;
		} else {
			throw new Exception('Link checker failed!</br></br>');
		}
    } catch (Exception $error) {
        if ($DEBUG) {
            echo 'Failed to fetch source data from showBox_media Error: ' . $error->getMessage() . "</br></br>";
            echo 'Finished running showBox_media </br></br>';
        }
        logDetails('showBox_media', 'extractFebBox', 'failed', $logTitle, $url, 'n/a', $type, $movieId, $type === 'series' ? $seriesCode : 'n/a');
        return false;
    }
}

function myfilestorage_xyz($movieId, $title) {
    global $timeOut, $maxResolution, $type, $season, $seasonNoPad, $episodeNoPad, $episode;

    if ($GLOBALS['DEBUG']) {
        echo 'Started running myfilestorage_xyz </br></br>';
    }
    
    if ($type == 'movies') {
        $url = "https://myfilestorage.xyz/{$movieId}.mp4";
    } else {
        $url = "https://myfilestorage.xyz/tv/{$movieId}/s{$season}/e{$episode}.mp4";
    }
   
    try {
		
		$referer = 'https://bflix.gs/';		
		$options = [
			'http' => [
				'header' => "Referer: $referer\r\n"
			]
		];

		$context = stream_context_create($options);
		$headers = get_headers($url, 1, $context);
		$status_code = null;

		if (strpos($headers[0], '200') !== false) {
			$status_code = 200;
		}

		if ($status_code !== 200) {			
			throw new Exception('HTTP Error: myfilestorage_xyz no video file. </br></br>');
		} 	
				

    } catch (Exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo $error->getMessage() . "</br></br>";
            echo 'Finished running myfilestorage_xyz </br></br>';
        }
        logDetails('myfilestorage_xyz', 'none', 'failed', $GLOBALS['logTitle'], $url, 'n/a', $type, $GLOBALS['movieId'], $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');
        return false;
    }
	
	$proxHeaders = "|Referer='" . $referer . "'|User-Agent='Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0'";
	$downloadUrl = 'video_proxy.php?data=' . base64_encode($url . $proxHeaders);	
	
	$lCheck = checkLinkStatusCode($downloadUrl);
	if ($lCheck !== true) {
		logDetails('myfilestorage_xyz', 'none', 'failed', $GLOBALS['logTitle'], $url, 'n/a', $type, $GLOBALS['movieId'], $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');
        return false;
	} else {
		if ($GLOBALS['DEBUG']) {
			echo "Video link: " . $downloadUrl . "<br><br>";
		}
	}
	
	logDetails('myfilestorage_xyz', 'none', 'successful', $GLOBALS['logTitle'], $url, $downloadUrl, $type, $GLOBALS['movieId'], $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');
	
	return $downloadUrl;
	
}

function oneTwothreeEmbed_net($movieId, $title) {
    global $timeOut, $maxResolution, $type, $seasonNoPad, $episodeNoPad;

    if ($GLOBALS['DEBUG']) {
        echo 'Started running 123embed_net </br></br>';
    }    
  
    if ($type == 'movies') {
        $url = "https://play2.123embed.net/server/3?path=/movie/{$movieId}";
    } else {
        $url = "https://play2.123embed.net/server/3?path=/tv/{$movieId}/{$seasonNoPad}/{$episodeNoPad}";
    }
	
    try {
			
		$response = makeGetRequest($url);
		
		if ($response === false) {
			throw new Exception('HTTP Error: 123embed_net</br></br>');
		}	
		
		$data = json_decode($response, true);		

		if (!isset($data['playlist'][0]['file'])) {
			throw new Exception('123embed_net File not found in playlist');
		}
		$vurl = $data['playlist'][0]['file'];	
		parse_str(parse_url($vurl, PHP_URL_QUERY), $params);
		$vurl = urldecode($params['url']);		
		$Referer = urldecode($params['referer']);
		sleep(1);		
		
		echo $Referer;

		$combineHeaders = '';		
		

		if (isset($Referer)) {
			$combineHeaders .= '|Referer=' . $Referer;
			$combineHeaders .= '|Origin=' . $Referer;
		}
		$combineHeaders .= '|User-Agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0';			

		$vurl = 'hls_proxy.php?url=' . urlencode($vurl) . '&data=' . base64_encode($combineHeaders);		
		
		
		if (checkLinkStatusCode($vurl, false, false)) {
			if ($GLOBALS['DEBUG']) {
				echo 'Video Link: ' . $vurl . "</br></br>";
			}
			logDetails('123embed_net', 'none', 'successful', $GLOBALS['logTitle'], $url, $vurl, $type, $GLOBALS['movieId'], $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');
			return $vurl;
		}
		throw new Exception('Couldn\'t find a source on 123embed_net.');
    } catch (Exception $error) {
		
		if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
        }

		logDetails('123embed_net', 'none', 'failed', $GLOBALS['logTitle'], $url, 'n/a', $type, $GLOBALS['movieId'], $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');
		return false;
	}
}

function twoembed_skin($title, $year, $movieId, $imdbId)
{
	
    if ($GLOBALS['DEBUG']) {
        echo 'Started running twoembed_skin </br></br>';
    }
	global $timeOut, $maxResolution, $type, $seasonNoPad, $episodeNoPad, $movieId;
	
    $tSite = 'twoembed_skin';
	
	if ($type == 'movies') {
		$apiUrl = "https://www.2embed.cc/embed/" . $movieId;
		$refer = "https://streamsrcs.2embed.cc/swish?id=" . $movieId;
	} else {
		$apiUrl = "https://www.2embed.cc/embedtv/" . $movieId . "&s=" . $seasonNoPad . "&e=" . $episodeNoPad;
		$refer = "https://streamsrcs.2embed.cc/swish?id=" . $movieId . "&s=" . $seasonNoPad . "&e=" . $episodeNoPad;
	}		

    try {

        $response = makeGetRequest($apiUrl,$refer);
		
        if ($response === false) {
            throw new Exception('HTTP Error: twoembed_skin');
        }

    }
    catch (exception $error) {

        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
        }
		logDetails('twoembed_skin', 'StreamwishExtract', 'failed', $GLOBALS['logTitle'], $apiUrl, 'n\a', $type, $GLOBALS['movieId'], $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');

        return false;
    }

    try {

		
	if (preg_match("/(?<=swish\?id=)[0-9a-z]{12}/", $response, $matches)) {
		
		$refer = "https://streamsrcs.2embed.cc/swish?id=" . $matches[0];	

		$extractorReturn = StreamwishExtract('https://streamwish.to/e/'.$matches[0], $tSite, $refer);	

		if ($extractorReturn !== false) {
			
		logDetails('twoembed_skin', 'StreamwishExtract', 'successful', $GLOBALS['logTitle'], $apiUrl, $extractorReturn, $type, $GLOBALS['movieId'], $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');
			
			return $extractorReturn;
		}
	}
		throw new Exception("Couldn't locate swish link on twoembed_skin.");
    }
    catch (exception $error) {
        if ($GLOBALS['DEBUG']) {           
			echo 'Error: ' . $error->getMessage() . "</br></br>";
        }
		logDetails('twoembed_skin', 'StreamwishExtract', 'failed', $GLOBALS['logTitle'], $apiUrl, 'n\a', $type, $GLOBALS['movieId'], $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');
	
        return false;
    }
    if ($GLOBALS['DEBUG']) {
        echo "Couldn't locate a link on twoembed_skin. </br></br>";
    }
	logDetails('twoembed_skin', 'StreamwishExtract', 'failed', $GLOBALS['logTitle'], $apiUrl, 'n\a', $type, $GLOBALS['movieId'], $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');
	return false;
}

function warezcdn_com($movieId, $imdbId, $title)
{
    global $timeOut, $maxResolution, $type, $seasonNoPad, $episodeNoPad, $apiKey, $language, $episode;

    $tSite = 'warezcdn_com';
	
	if ($GLOBALS['DEBUG']) {
        echo 'Started running warezcdn_com </br></br>';
    }	
	$url = "n/a";
	try {
		
		if ($type == 'movies'){
			$VideoID = $movieId;
			$searchQuery = $movieId;
			$embedURL = 'https://embed.warezcdn.com/filme/' . $imdbId;
		} else {		
			$embedURL = 'https://embed.warezcdn.com/serie/' . $imdbId . '/' . $seasonNoPad . '/' . $episodeNoPad;
			$wID = false;	
			$wID = warezcdn_com_Get_EpisodeID('https://embed.warezcdn.com/serie/' . $imdbId . '/' . $seasonNoPad . '/' . $episodeNoPad, $episodeNoPad);	
			if($wID){
				$VideoID = $wID;
				$searchQuery = $wID;
			} else {
				throw new Exception('Couldn\'t get episode id on warezcdn_com');
			}
		}

		$url = "https://warezcdn.com/player/player.php?id=$searchQuery";
		
		
		$cdnListing = [50, 51, 52, 53, 54, 55, 56, 57, 58, 59, 60, 61, 62, 63, 64];
		
		$refer = 'https://warezcdn.com/embed/getEmbed.php?id=' . $movieId . '&sv=warezcdn';
		
		$response = makeGetRequest($url,$refer);		
		
		if ($response === false) {
            throw new Exception('HTTP Error: warezcdn_com');
		}				
		
		// Extract the allowance key from the HTML content
		$matches = [];
		preg_match('/let allowanceKey = "([^"]+)";/', $response, $matches);
		if (!isset($matches[1])) {
			throw new Exception('Allowance key not found in the HTML content');
		}
		$allowanceKey = $matches[1];
		
	  // Fetch the encrypted video ID using cURL
		$url = "https://warezcdn.com/player/functions.php";

		$postData = [
			'getVideo' => $VideoID,
			'key' => $allowanceKey
		];
		
		$addToHeaders = [
			'Origin: https://warezcdn.com'
		];
		
		$response = makePostRequest($url, $refer, $postData, 'application/x-www-form-urlencoded', $addToHeaders);

		if ($response === FALSE) {
			throw new Exception('Error fetching video details');
		}
		
		if ($GLOBALS['DEBUG']) {		
			echo "Raw response: " . $response . "<br><br>";
		}	

		// Check if the response is valid JSON
		$data = json_decode($response, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new Exception('Invalid JSON response: ' . json_last_error_msg());
		}

		if (!isset($data['status']) || $data['status'] !== 'success') {
			throw new Exception('Error: ' . ($data['status'] ?? 'Unknown error'));
		}
		
		if (!isset($data['id'])) {
			throw new Exception('Encrypted video ID not found in the response');
		}
		$encryptedVideoId = $data['id'];

		// Decrypt the video ID
		$e = base64_decode($encryptedVideoId);
		$e = trim($e);
		$e = strrev($e);
		$last = substr($e, -5);
		$last = strrev($last);
		$e = substr($e, 0, -5);
		$movieIdDecrypted = $e . $last;		
		
		// Get a random CDN URL
		$randomCdn = $cdnListing[array_rand($cdnListing)];
		$firstSourceUrl = "https://workerproxy.warezcdn.workers.dev/?url=https://cloclo" . $randomCdn . ".cloud.mail.ru/weblink/view/" . $movieIdDecrypted;
		         
        if(!$firstSourceUrl){
			throw new Exception("No links found on warezcdn_com.");
		} 		
		
		logDetails('warezcdn_com', 'none', 'successful', $GLOBALS['logTitle'], $embedURL, $firstSourceUrl, $type, $GLOBALS['movieId'], $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');		
   
        return $firstSourceUrl;
  
    } catch (exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
            echo 'Finished running warezcdn_com </br></br>';
        }
		logDetails('warezcdn_com', 'none', 'failed', $GLOBALS['logTitle'], $embedURL, 'n/a', $type, $GLOBALS['movieId'], $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');

        return false;
    }
}

function warezcdn_com_Get_EpisodeID($url, $episodeNumber) {	

    $response = makeGetRequest($url);

    if ($response === FALSE) {
        return false;
    }

    $dom = new DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML($response);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $query = "//div[@class='item' and div[@class='name']='Episódio " . $episodeNumber . "']";
    $items = $xpath->query($query);

    if ($items->length === 0) {
        return false;
    }
	
    $dataLoadEpisodeContent = $items->item(0)->getAttribute('data-load-episode-content');
	
    return $dataLoadEpisodeContent;
}

function HeadlessVidX($movieId, $imdbId, $title)
{
    /* ───── IMPORT GLOBALS ─────────────────────────────────────────────── */
    global $globalYear, $type, $seasonNoPad, $episodeNoPad, $apiKey, $language,
           $episode, $HeadlessVidXRunOrder, $HeadlessVidX_Address,
           $HeadlessVidX_Max_Threads, $HEADLESSVIDX_THREAD_DELAY,
           $DEBUG, $logTitle, $seriesCode, $movieId;   // ← originals

    $seasonId = $episodeId = '';
    if ($DEBUG) echo 'Started running HeadlessVidX<br><br>';
    set_time_limit(300);

    /* ───── LOAD SITE LISTS ────────────────────────────────────────────── */
    $moviesFile = __DIR__.'/HeadlessVidX_sitelist/movies.txt';
    $seriesFile = __DIR__.'/HeadlessVidX_sitelist/series.txt';
    if (!file_exists($moviesFile) || !file_exists($seriesFile))
        throw new Exception('Command files not found.');

    $moviesCmds = file($moviesFile,  FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
    $seriesCmds = file($seriesFile,  FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);

    foreach ([$moviesCmds, $seriesCmds] as &$arr) {
        if ($HeadlessVidXRunOrder === 'random')       shuffle($arr);
        elseif ($HeadlessVidXRunOrder === 'ascending')  sort($arr);
        elseif ($HeadlessVidXRunOrder === 'descending') rsort($arr);
    }

    /* ───── SERIES LOOK-UP (unchanged) ────────────────────────────────── */
    if ($type !== 'movies') {
        $url = "https://api.themoviedb.org/3/tv/$movieId/season/$seasonNoPad"
             . "?api_key=$apiKey&language=$language";
        $resp = @file_get_contents($url);
        if ($resp === false) return false;
        $seasonData = json_decode($resp, true);
        foreach ($seasonData['episodes'] as $ep)
            if (str_pad($ep['episode_number'], 2, '0', STR_PAD_LEFT) == $episode)
                { $episodeId = $ep['id']; break; }
        if (!$episodeId) return false;
        $seasonId = $seasonData['id'];
    }
    $commands = ($type === 'movies') ? $moviesCmds : $seriesCmds;

    /* ───── MULTI-CURL FAST EXIT LOOP ─────────────────────────────────── */
    $mh       = curl_multi_init();
    $handles  = [];
    $idx      = 0;
    $total    = count($commands);
    $foundUrl = $apiUrl = null;
    $logRun   = '';

    while ($foundUrl === null && ($idx < $total || $handles)) {

        /* fill pipeline */
        while ($idx < $total && count($handles) < $HeadlessVidX_Max_Threads) {
            $cmd = $commands[$idx++];
            $dashTitle = strtolower(
                str_replace(' ', '-', preg_replace('/[^a-zA-Z0-9 ]/', '', $title))
            );
            $cmd = str_replace(
                ['[[YEAR]]','[[DASH-TITLE]]','[[TMDB]]','[[IMDB]]',
                 '[[SID]]','[[EID]]','[[S]]','[[E]]'],
                [$globalYear, $dashTitle, $movieId, $imdbId,
                 $seasonId, $episodeId, $seasonNoPad, $episodeNoPad],
                $cmd
            );

            /* remember apiUrl in case this one wins */
            $currentApi = $cmd;
            $reqUrl     = "http://$HeadlessVidX_Address/get-video?url="
                        . urlencode($cmd);

            $ch = curl_init($reqUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_multi_add_handle($mh, $ch);
            $handles[(int)$ch] = ['handle'=>$ch,'api'=>$currentApi];
            //Wait 0.5 seconds between starting threads.
            usleep(500000);
        }

        /* pump */
        do { $mrc = curl_multi_exec($mh, $active); }
        while ($mrc === CURLM_CALL_MULTI_PERFORM);

        /* harvest */
        while (($info = curl_multi_info_read($mh)) !== false) {
            $hData   = $handles[(int)$info['handle']];
            $ch      = $hData['handle'];
            $apiUsed = $hData['api'];

            $out = curl_multi_getcontent($ch);
            $dat = json_decode($out, true);

            /* success? */
            if (json_last_error()===JSON_ERROR_NONE &&
                ($dat['status'] ?? '') === 'ok' &&
                ($tmpUrl = $dat['url'] ?? null) &&
                checkLinkStatusCode($tmpUrl, true) === true) {

                /* vidsrc tweak */
                if (parse_url($tmpUrl,PHP_URL_HOST) === 'vidsrc.pro')
                    $tmpUrl = str_replace('playlist.m3u8',
                                          '1080/index.m3u8', $tmpUrl);

                /* header combo */
                $hdr = '';
                foreach (['Referer','Origin','User-Agent'] as $h)
                    if (isset($dat[$h])) $hdr .= "|$h=".$dat[$h];

                if ((isset($dat['Content-Type']) &&
                     stripos($dat['Content-Type'],'mpegurl')!==false) ||
                     stripos($tmpUrl,'m3u8')!==false) {
                    $tmpUrl = 'hls_proxy.php?url='.urlencode($tmpUrl)
                            . '&data='.base64_encode($hdr);
                } else {
                    $tmpUrl = 'video_proxy.php?data='
                            . base64_encode($tmpUrl.$hdr);
                }

                $foundUrl = $tmpUrl;
                $apiUrl   = $apiUsed;

                /* DEBUG + logRun */
                $logRun .= "WebSite: ".curl_getinfo($ch,CURLINFO_EFFECTIVE_URL)
                        ."\n\nReturn: ".print_r($out,true)
                        ."\n\nStream Url: $foundUrl\n\n\n";
                if ($DEBUG) {
                    echo "WebSite: ".curl_getinfo($ch,CURLINFO_EFFECTIVE_URL)."<br><br>";
                    echo "Return: "; print_r($out); echo "<br><br>";
                    echo "Stream Url: $foundUrl<br><br><br>";
                }
            }

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            unset($handles[(int)$ch]);
        }
    }
    curl_multi_close($mh);

    /* ───── REPORTING & RETURN ────────────────────────────────────────── */
    if ($foundUrl) {
        file_put_contents('HeadlessVidX/logs/HeadlessVidX-last-run.txt', $logRun);

        logDetails(
            'HeadlessVidX', 'none', 'successful', $logTitle,
            $apiUrl, $foundUrl, $type, $movieId,
            ($type === 'series') ? $seriesCode : 'n/a'
        );

        return $foundUrl;
    }

    if ($DEBUG) echo 'Error: No links found with HeadlessVidX.<br><br>';
    return false;
}

function HeadlessVidX_old($movieId, $imdbId, $title)
{
    global $globalTitle, $globalYear, $timeOut, $maxResolution, $type, $seasonNoPad, $episodeNoPad, $apiKey, $language, $episode, $HeadlessVidXRunOrder, $HTTP_PROXY, $USE_HTTP_PROXY, $HeadlessVidX_Address, $HeadlessVidX_Max_Threads;

    $seasonId = '';
    $episodeId = '';

    $tSite = 'HeadlessVidX';

    if ($GLOBALS['DEBUG']) {
        echo 'Started running HeadlessVidX </br></br>';
    }

    // Increase maximum execution time
    set_time_limit(300);
    
    // The Commands references are now just passing URLs since the code has been 
    // changed to support HeadlessVidX server instead of using the command line.
    // Paths to the command files
    $moviesCommandsFile = __DIR__ . DIRECTORY_SEPARATOR . 'HeadlessVidX_sitelist' . DIRECTORY_SEPARATOR . 'movies.txt';
    $seriesCommandsFile = __DIR__ . DIRECTORY_SEPARATOR . 'HeadlessVidX_sitelist' . DIRECTORY_SEPARATOR . 'series.txt';

    // Check if files exist
    if (!file_exists($moviesCommandsFile) || !file_exists($seriesCommandsFile)) {
        throw new Exception("Command files not found.");
    }

    // Load file contents into arrays
    $moviesCommands = file($moviesCommandsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $seriesCommands = file($seriesCommandsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    // Run order
    if ($HeadlessVidXRunOrder == 'random') {
        shuffle($moviesCommands);
        shuffle($seriesCommands);
    } elseif ($HeadlessVidXRunOrder == 'ascending') {
        sort($moviesCommands);
        sort($seriesCommands);
    } elseif ($HeadlessVidXRunOrder == 'descending') {
        rsort($moviesCommands);
        rsort($seriesCommands);
    } else {
        throw new InvalidArgumentException("Invalid order type: $HeadlessVidXRunOrder");
    }

    // Set the commands based on the type
    if ($type == 'movies') {
        $commands = $moviesCommands;
    } else {
        $baseUrl = 'https://api.themoviedb.org/3/tv/';
        $url = $baseUrl . $movieId . '/season/' . $seasonNoPad . '?api_key=' . $apiKey . '&language=' . $language;
        $response = @file_get_contents($url);

        if ($response !== false) {
            $seasonData = json_decode($response, true);
            $episodeId = null;

            // Search for the correct episode within the season
            foreach ($seasonData['episodes'] as $episodeData) {
                if (str_pad($episodeData['episode_number'], 2, "0", STR_PAD_LEFT) == $episode) {
                    $episodeId = $episodeData['id'];
                    break;
                }
            }

            if ($episodeId !== null) {
                $seasonId = $seasonData['id'];
            } else {
                return false;
            }
        } else {
            return false;
        }

        $commands = $seriesCommands;
    }

    $logRun = '';

    $multiHandle = curl_multi_init();
    $curlHandles = [];
    $activeHandles = 0;

    try {
        foreach ($commands as $command) {
            $strippedTitle = preg_replace("/[^a-zA-Z0-9 ]/", "", $title);
            $strippedTitle = str_replace(" ", "-", $strippedTitle);
            $command = str_replace("[[YEAR]]", $globalYear, $command);
            $command = str_replace('[[DASH-TITLE]]', strtolower($strippedTitle), $command);
            $command = str_replace('[[TMDB]]', $movieId, $command);
            $command = str_replace('[[IMDB]]', $imdbId, $command);
            $command = str_replace('[[SID]]', $seasonId, $command);
            $command = str_replace('[[EID]]', $episodeId, $command);
            $command = str_replace('[[S]]', $seasonNoPad, $command);
            $command = str_replace('[[E]]', $episodeNoPad, $command);

            $runCommand = $command;
            $url = 'http://' . $HeadlessVidX_Address . '/get-video?url=' . urlencode($runCommand);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_multi_add_handle($multiHandle, $ch);
            $curlHandles[$url] = $ch;
            $activeHandles++;
						if ($HEADLESSVIDX_THREAD_DELAY > 0) {
								// usleep() takes micro-seconds
								usleep(500000);
						}

            // If max threads limit is reached, process the handles
            if ($activeHandles >= $HeadlessVidX_Max_Threads) {
                do {
                    $status = curl_multi_exec($multiHandle, $active);
                    if ($active) {
                        curl_multi_select($multiHandle);
                    }
                } while ($active && $status == CURLM_OK);

                // Check for completed requests
                foreach ($curlHandles as $url => $ch) {
                    $output = curl_multi_getcontent($ch);
                    $data = json_decode($output, true);

                    if (json_last_error() == JSON_ERROR_NONE && isset($data['status']) && $data['status'] == "ok") {
                        $firstSourceUrl = $data['url'] ?? null;
						if ($firstSourceUrl) {
							$logRun .= "WebSite: $url \n\n Return: " . print_r($output, true) . "\n\n Stream Url: $firstSourceUrl \n\n\n";
							if ($GLOBALS['DEBUG']) {
								echo "WebSite: $url </br></br>";
								echo "Return: ";
								print_r($output);
								echo "</br></br>";
								echo "Stream Url: $firstSourceUrl";
								echo "</br></br></br>";
							}

							$parsedUrl = parse_url($firstSourceUrl);
							if ($parsedUrl['host'] === 'vidsrc.pro') {
							// Vidsrc pro adjustment
								$firstSourceUrl = str_replace("playlist.m3u8", "1080/index.m3u8", $firstSourceUrl);
							}
/* 							if (stripos($firstSourceUrl, '?destination=') !== false) {
								// Worker Dev adjustment
								$pos = strpos($firstSourceUrl, '?destination=');
								if ($pos !== false) {
									$firstSourceUrl = substr($firstSourceUrl, $pos + strlen('?destination='));
									
								}
							} */
							$combineHeaders = '';

							if (isset($data['Referer'])) {
								$combineHeaders .= '|Referer=' . $data['Referer'];
							}
							if (isset($data['Origin'])) {
								$combineHeaders .= '|Origin=' . $data['Origin'];
							}
							if (isset($data['User-Agent'])) {
								$combineHeaders .= '|User-Agent=' . $data['User-Agent'];
							}

							if ((isset($data['Content-Type']) && stripos($data['Content-Type'], 'mpegurl') !== false) || stripos($firstSourceUrl, 'm3u8') !== false) {
								$firstSourceUrl = 'hls_proxy.php?url=' . urlencode($firstSourceUrl) . '&data=' . base64_encode($combineHeaders);
							} else {
								$firstSourceUrl = 'video_proxy.php?data=' . base64_encode($firstSourceUrl . $combineHeaders);
							}

							$checkData = $firstSourceUrl;
							$lCheck = checkLinkStatusCode($checkData, true);
							if ($lCheck !== true) {
								continue;
							} else {
								if ($GLOBALS['DEBUG']) {
									echo "Video link: " . $firstSourceUrl . "<br><br>";
								}
							}
						}

                        curl_multi_remove_handle($multiHandle, $ch);
                        curl_close($ch);

                        // Close all remaining handles
                        foreach ($curlHandles as $handle) {
                            curl_multi_remove_handle($multiHandle, $handle);
                            curl_close($handle);
                        }
                        curl_multi_close($multiHandle);

                        file_put_contents('HeadlessVidX/logs/HeadlessVidX-last-run.txt', $logRun);
                        $apiUrl = $runCommand;

                        logDetails('HeadlessVidX', 'none', 'successful', $GLOBALS['logTitle'], $apiUrl, $firstSourceUrl, $type, $GLOBALS['movieId'], $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');

                        return $firstSourceUrl;
                    }

                    curl_multi_remove_handle($multiHandle, $ch);
                    curl_close($ch);
                    $activeHandles--;
                }
            }
        }

        // Process any remaining handles
        do {
            $status = curl_multi_exec($multiHandle, $active);
            if ($active) {
                curl_multi_select($multiHandle);
            }
        } while ($active && $status == CURLM_OK);

        foreach ($curlHandles as $url => $ch) {
            $output = curl_multi_getcontent($ch);
            $data = json_decode($output, true);

            if (json_last_error() == JSON_ERROR_NONE && isset($data['status']) && $data['status'] == "ok") {
                $firstSourceUrl = $data['url'] ?? null;
                if ($firstSourceUrl) {
                    $logRun .= "WebSite: $url \n\n Return: " . print_r($output, true) . "\n\n Stream Url: $firstSourceUrl \n\n\n";
                    if ($GLOBALS['DEBUG']) {
                        echo "WebSite: $url </br></br>";
                        echo "Return: ";
                        print_r($output);
                        echo "</br></br>";
                        echo "Stream Url: $firstSourceUrl";
                        echo "</br></br></br>";
                    }

					$parsedUrl = parse_url($firstSourceUrl);
					if ($parsedUrl['host'] === 'vidsrc.pro') {
					// Vidsrc pro adjustment
						$firstSourceUrl = str_replace("playlist.m3u8", "1080/index.m3u8", $firstSourceUrl);
					}
/* 					if (stripos($firstSourceUrl, '?destination=') !== false) {
						// Worker Dev adjustment
						$pos = strpos($firstSourceUrl, '?destination=');
						if ($pos !== false) {
							$firstSourceUrl = substr($firstSourceUrl, $pos + strlen('?destination='));
							
						}
					} */
					$combineHeaders = '';

					if (isset($data['Referer'])) {
						$combineHeaders .= '|Referer=' . $data['Referer'];
					}
					if (isset($data['Origin'])) {
						$combineHeaders .= '|Origin=' . $data['Origin'];
					}
					if (isset($data['User-Agent'])) {
						$combineHeaders .= '|User-Agent=' . $data['User-Agent'];
					}

					if ((isset($data['Content-Type']) && stripos($data['Content-Type'], 'mpegurl') !== false) || stripos($firstSourceUrl, 'm3u8') !== false) {
						$firstSourceUrl = 'hls_proxy.php?url=' . urlencode($firstSourceUrl) . '&data=' . base64_encode($combineHeaders);
					} else {
						$firstSourceUrl = 'video_proxy.php?data=' . base64_encode($firstSourceUrl . $combineHeaders);
					}

					$checkData = $firstSourceUrl;
					$lCheck = checkLinkStatusCode($checkData, true);
					if ($lCheck !== true) {
						continue;
					} else {
						if ($GLOBALS['DEBUG']) {
							echo "Video link: " . $firstSourceUrl . "<br><br>";
						}
					}
                }

                curl_multi_remove_handle($multiHandle, $ch);
                curl_close($ch);

                // Close all remaining handles
                foreach ($curlHandles as $handle) {
                    curl_multi_remove_handle($multiHandle, $handle);
                    curl_close($handle);
                }
                curl_multi_close($multiHandle);

                file_put_contents('HeadlessVidX/logs/HeadlessVidX-last-run.txt', $logRun);
                $apiUrl = $runCommand;

                logDetails('HeadlessVidX', 'none', 'successful', $GLOBALS['logTitle'], $apiUrl, $firstSourceUrl, $type, $GLOBALS['movieId'], $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');

                return $firstSourceUrl;
            }

            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }

        curl_multi_close($multiHandle);
        throw new Exception("No links found with HeadlessVidX.");
    } catch (Exception $e) {
        // Handle any errors gracefully
        if ($GLOBALS['DEBUG']) {
            echo "Error: " . $e->getMessage() . "<br><br>";
        }
        return false;
    }
}

function justBinge_site($movieId, $title, $type = 'movie') {
    global $timeOut, $maxResolution, $type, $seasonNoPad, $episodeNoPad;

    if ($GLOBALS['DEBUG']) {
        echo 'Started running justBinge_lol </br></br>';
    }
    
    // Select urls based on type
    if ($type == 'movies') {
        $url = "https://prod-6.justbinge.lol/api/sources/{$movieId}";
    } else {
        $url = "https://prod-6.justbinge.lol/api/sources/{$movieId}/{$seasonNoPad}/{$episodeNoPad}";
    }

   
    try {
		
		$response = makeGetRequest($url);
		
        if ($response === false) {
            throw new Exception('HTTP Error: justBinge_lol</br></br>');
        }
    } catch (Exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Failed to fetch source data from justBinge_lol Error: ' . $error->getMessage() . "</br></br>";
            echo 'Finished running justBinge_lol </br></br>';
        }
        logDetails('justBinge_site', 'none', 'failed', $GLOBALS['logTitle'], $url, 'n/a', $type, $GLOBALS['movieId'], $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');
        return false;
    }


    $data = json_decode($response, true);

    if ($data) {
        if (isset($data['sources']) && is_array($data['sources'])) {
            foreach ($data['sources'] as $source) {
                if (is_array($source) && isset($source['url'])) {
                    $vurl = $source['url'];
                    if (checkLinkStatusCode($vurl)) {
                        if ($GLOBALS['DEBUG']) {
                            echo 'Video Link: ' . $vurl . "</br></br>";
                        }
                        logDetails('justBinge_site', 'none', 'successful', $GLOBALS['logTitle'], $url, $vurl, $type, $GLOBALS['movieId'], $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');
                        return $vurl;
                    }
                }
            }
        }

        if (isset($data['downloads']) && is_array($data['downloads'])) {
            foreach ($data['downloads'] as $download) {
                $downloadUrl = $download['download_url'];
                if (checkLinkStatusCode($downloadUrl)) {
                    if ($GLOBALS['DEBUG']) {
                        echo 'Download Link: ' . $downloadUrl . "</br></br>";
                    }
                    logDetails('justBinge_site', 'none', 'successful', $GLOBALS['logTitle'], $url, $downloadUrl, $type, $GLOBALS['movieId'], $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');
                    return $downloadUrl;
                }
            }
        }
    }

    if ($GLOBALS['DEBUG']) {
        echo "No valid sources or downloads found in the JSON data.";
        echo 'Finished running justBinge_lol </br></br>';
    }
    logDetails('justBinge_site', 'none', 'failed', $GLOBALS['logTitle'], $url, 'n/a', $type, $GLOBALS['movieId'], $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');
    return false;
}

function frembed_pro($title, $year, $movieId, $imdbId)
{
	
    if ($GLOBALS['DEBUG']) {
        echo 'Started running frembed_pro </br></br>';
    }
	global $timeOut, $maxResolution, $type, $seasonNoPad, $episodeNoPad, $movieId;
	
    $tSite = 'frembed_pro';
	
	if ($type == 'movies') {
		$apiUrl = "https://player.frembed.pro/api/films?id=$movieId";
		$referer = "https://player.frembed.pro/films?id=$movieId";
	} else {
		$apiUrl = "https://player.frembed.pro/api/series?id=$movieId&sa=$seasonNoPad&epi=$episodeNoPad&idType=tmdb";
	}	$referer = "https://player.frembed.pro/series?id=$movieId&sa=$seasonNoPad&epi=$episodeNoPad";	

    try {	

	$response = makeGetRequest($apiUrl, $referer);
	
	if ($response === false) {
            throw new Exception('HTTP Error: frembed_pro');
        }

    } catch (exception $error) {

        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
        }

        return false;
    }

    try {
		
		$data = json_decode($response, true);
		if ($GLOBALS['DEBUG']) {
			echo 'Json Reponse: ';
			print_r($data);
			echo '</br></br>';
		}
		
		if ($data) {
			foreach ($data as $key => $value) {				
				if (strpos($key, 'link') === 0 && !empty($value)) {
					$nextHost = $value;
					$parsedUrl = parse_url($nextHost);
					
					

					if (isset($parsedUrl['host']) && isset($parsedUrl['scheme'])) {
						$hostDomain = $parsedUrl['host'];
						$referer = $parsedUrl['scheme'] . '://' . $hostDomain;
						$hostNameParts = explode('.', $hostDomain);
						array_pop($hostNameParts);
						$identifier = implode('.', $hostNameParts);

						if ($GLOBALS['DEBUG']) {
							echo "Looking for an extractor for " . $hostDomain . "</br></br>";
						}

						$extractorReturn = FindVideoExtractor($nextHost, $tSite, $referer, $identifier);

						if ($extractorReturn !== false) {
							return $extractorReturn;
						}
					}
				}
			}
			throw new Exception("Couldn't locate an extractor for the provided links.");
		}
		throw new Exception("Invalid JSON response or no data found.");
	} catch (exception $error) {
        if ($GLOBALS['DEBUG']) {           
			echo 'Error: ' . $error->getMessage() . "</br></br>";
        }
	
        return false;
    }
    if ($GLOBALS['DEBUG']) {
        echo "Couldn't locate a link on frembed_pro. </br></br>";
    }
	
	return false;
}

function primewire_tf($title, $year, $movieId, $imdbId)
{
    $primeWireDomain = 'https://www.primewire.mov';
		$referer = $primeWireDomain;

    if ($GLOBALS['DEBUG']) {
        echo 'Started running primewire_tf </br></br>';
    }

    global $type, $seasonNoPad, $episodeNoPad;

    $tSite = 'primewire_tf';

    $apiUrl = ($type === 'movies')
        ? "$primeWireDomain/api/v1/s?tmdb=$movieId&type=movie"
        : "$primeWireDomain/api/v1/s?tmdb=$movieId&season=$seasonNoPad&episode=$episodeNoPad&type=tv";

    try {
        $response = makeGetRequest($apiUrl);
        if ($response === false) {
            throw new Exception('HTTP Error: primewire_tf');
        }

        $json = json_decode($response, true);
        $servers = $json['servers'] ?? [];

        if (empty($servers)) {
            throw new Exception('No servers found in Primewire response.');
        }

        foreach ($servers as $server) {
            $hostName = $server['name'] ?? null;
            $key = $server['key'] ?? null;

            if (!$hostName || !$key) {
                continue;
            }

            // ✅ Use lowercase identifier for extractor testing
            $identifier = strtolower($hostName);

						if ($GLOBALS['DEBUG']) {
								echo "Looking for extractor for: $identifier</br>";
						}

            // Step 1: Test for extractor availability (no extraction)
            $hasExtractor = FindVideoExtractor('https://test.com', $tSite, $referer, $identifier, true);
            if ($hasExtractor === false) {
                if ($GLOBALS['DEBUG']) {
                    echo "No extractor for: $identifier</br></br>";
                }
                continue;
            }

            if ($GLOBALS['DEBUG']) {
                echo "Extractor available for: $identifier (key: $key)</br>";
            }

            // Step 2: Fetch actual video link from Primewire
            $linkApi = "$primeWireDomain/api/v1/l?key=$key";
            $linkResponse = makeGetRequest($linkApi);
            if ($linkResponse === false) {
                continue;
            }

            $linkData = json_decode($linkResponse, true);
            $finalLink = $linkData['link'] ?? null;

            if (!$finalLink) {
                continue;
            }

            $parsedUrl = parse_url($finalLink);
            if (!isset($parsedUrl['scheme'], $parsedUrl['host'])) {
                continue;
            }

            
            if ($GLOBALS['DEBUG']) {
                echo "Attempting final extraction for: $finalLink</br>";
            }

            // Step 3: Actual video extraction
            $extractorReturn = FindVideoExtractor($finalLink, $tSite, $referer);
            if ($extractorReturn !== false) {
                if ($GLOBALS['DEBUG']) {
                    echo "Extractor succeeded: $identifier</br></br>";
                }
                return $extractorReturn;
            } else {
                if ($GLOBALS['DEBUG']) {
                    echo "Extractor failed at final link stage for: $identifier</br></br>";
                }
            }
        }

    } catch (Exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
        }
        return false;
    }

    if ($GLOBALS['DEBUG']) {
        echo "No working extractors found in primewire_tf. </br></br>";
    }

    return false;
}

function upMovies_to($title, $year)
{
    if ($GLOBALS['DEBUG']) {
        echo 'Started running upMovies_to </br></br>';
    }
	global $timeOut, $maxResolution, $type, $seasonNoPad, $episodeNoPad, $movieId;
	
    $tSite = 'upMovies_to';

    $etitle = str_replace("%20", "+", urlencode($title));   
	
	if ($type == 'movies'){
		$searchQuery = $title . ' (' . $year . ')';
	} else {
		$searchQuery = $title . ' ' . $year . ' season ' . $seasonNoPad;	
	
	}
	
	$searchQuery = str_replace("%20", "+", urlencode($searchQuery));
	$apiUrl = 'https://upmovies.net/search-movies/' . $searchQuery . '.html';
	

    try {
       			
		$response = makeGetRequest($apiUrl);	
		
			
        if ($response === false) {
            throw new Exception('HTTP Error: upMovies_to');
        }

    }
    catch (exception $error) {

        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
        }

        return false;
    }

    try {
		
        // Find the position of the media data.
		$pattern = '/(?<=<div class="itemBody">)[\s\S]*?(?=<div class="description">)/';
		
		if (preg_match_all($pattern, $response, $matches)) {
			foreach ($matches[0] as $index => $match) {
				
				$titlePattern = '/<div class="title"><a href="[^"]+">([^<]+)<\/a>/i';
				$yearPattern = '/<p>Year: (\d+)<\/p>/';

				if (preg_match($titlePattern, $match, $titleMatch) && preg_match($yearPattern, $match, $yearMatch)) {
					$extractedTitle = $titleMatch[1];
					$extractedYear = $yearMatch[1];						
										
					if ($type == 'movies'){
						$compareTitle = $title;
					} else {
						$compareTitle = $title . ': Season ' . $seasonNoPad;
					}
				
					if (strcasecmp($extractedTitle, $compareTitle) === 0 && $extractedYear == $year) {
					
						if (preg_match('/https:\/\/upmovies\.net\/watch.*?.html/', $match, $matches2)) {					

							break;
						}
					}
				}
			}
		}


        if (empty($matches2)) {
            throw new Exception("No links found on upMovies_to.");
        }

       
		$response = makeGetRequest($matches2[0]);		
	
		if ($response === false) {
            throw new Exception('HTTP Error: upMovies_to');
        }
		
		if ($type == 'series'){			
			if(preg_match('/(?<=href=")[^"]*?episode-'.$episodeNoPad.'\.html(?=")/s', $response, $matches)){
			
			$response = makeGetRequest($matches[0]);
			
			} else {
			throw new Exception('Couldn\'t locate the episode page on upMovies_to.');
		}
			
		}
		
        
        if(!preg_match_all('/<div class="server_line[\s\S]*?<\/div>/s', $response, $matches)){
			throw new Exception('Couldn\'t locate the divBlock\'s on upMovies_to.');
		}


        foreach ($matches[0] as $divBlock) {
            
            if ($GLOBALS['DEBUG']) {
                echo "Looking for a match in div: " . $divBlock . "</br></br>";
            }
			
			//Run the FindVideoExtractor function here.
            if ($divBlock !== false) {

                $urlPattern = '/(?<=<a href=")([^"]+)(?=")/';

                if (preg_match($urlPattern, $divBlock, $urlMatches)) {
                    if ($GLOBALS['DEBUG']) {
                        echo 'Page containing source: ' . $urlMatches[1] . "</br></br>";

                    }	
					
					$srcUrl = decode64UpMovies($urlMatches[1]);
					if ($srcUrl !== false){
					   $extractorReturn = FindVideoExtractor($srcUrl, 'upMovies_to', 'https://upmovies.net/');
					if ($extractorReturn !== false) {
				
						return $extractorReturn;
					}
				}   

                }


            }

        }


    }
    catch (exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo "Error: " ,  $error->getMessage(), "</br></br>";
        }

        return false;
    }
    if ($GLOBALS['DEBUG']) {
        echo "Couldn't locate a link on upMovies_to. </br></br>";
    }
	
	return false;
}

function superEmbed_stream($imdbId, $title, $year)
{
	    global $timeOut, $maxResolution, $type, $seasonNoPad, $episodeNoPad, $USE_HTTP_PROXY, $HTTP_PROXY;
		
    if ($GLOBALS['DEBUG']) {
        echo 'Started running superEmbed_stream </br></br>';
    }
    global $timeOut;
    global $maxResolution;
    $tSite = 'superEmbed_stream';	
	
	$refer = 'https://streambucket.net/';
	
	$url = "https://multiembed.mov/?video_id=" . $imdbId;

	if ($type != "movies") {
		$url .= "&s=" . $seasonNoPad . "&e=" . $episodeNoPad;
	}

    try {
		
		$content = makeGetRequest($url, $refer);

		if (preg_match('/(?<=decodeURIComponent\(escape\(r\)\))[\s\S]*?\)/', $content, $matches)) {
			
			$extractedString = $matches[0];
			
			if ($GLOBALS['DEBUG']) {
				echo 'Encrypted data extracted: ' . $extractedString . "</br></br>";
			}
			
			$extracted = str_getcsv($matches[1]);
			

		if (preg_match('/\((.*)\)/', $extractedString, $matches)) {		


			$decryptedData = superEmbedDecodeString($extracted[0], $extracted[2], $extracted[1], $extracted[3], $extracted[4]);
			if (preg_match('/(?<=file:").*?(?=")/', $decryptedData, $matches)) {
				
				return $matches[0];
			} else {

				throw new Exception('Couldn\'t find the file link on superEmbed_stream.');
			}				
		
		} else {
			throw new Exception('Couldn\'t locate the encrypted host on superEmbed_stream.');
		}	   
		} else {			

			//Try and get the list of sources.
			if (preg_match('/(?<=document\.referrer\);var w=btoa\(").*?play.*?(?=")/', $content, $matches) && preg_match('/(?<=play=)(.*)/', $matches[0], $token)) {	
			
				$apiurl = "https://streambucket.net/?play=" . urlencode($token[1]);
				
				$queryString = 'button-click=ZEhKMVpTLVF0LVBTLVF0TmpnNExTLVF5TkRndEwtMC1WMk8tMGc1LVB6VXdPREl5T0RZLTU%3D&button-referer=';	
				parse_str($queryString, $postData);
				
				$content = makePostRequest($apiurl, $refer, $postData, 'application/x-www-form-urlencoded', ['X-Requested-With: XMLHttpRequest']);

				if (preg_match('/(?<=load_sources\(").*?(?="\))/', $content, $token2)) {	
					if ($GLOBALS['DEBUG']) {
						echo 'Found the 2nd token: ' . $token2[0] . '</br></br>';
					}
				} else {
					throw new Exception('Couldn\'t locate the 2nd token on superEmbed_stream.');
				}
				
				$url = "https://streambucket.net/response.php";
				
				$queryString = 'token=' . urlencode($token2[0]);	
				parse_str($queryString, $postData);

				$content = makePostRequest($url, $refer, $postData, 'application/x-www-form-urlencoded', ['X-Requested-With: XMLHttpRequest']);				
				
				if (preg_match_all('/<li data-id="[\s\S]*?<\/li>/', $content, $servers)) {

					if ($GLOBALS['DEBUG']) {
						echo 'Found the list of servers: </br>';
					}

					// Loop through servers to find a matching extractor.
					foreach ($servers as $serverGroup) {
						foreach ($serverGroup as $server) {

							if (!is_string($server)) {
								continue;
							}

							// Extract data-id value
							preg_match('/data-id="([^"]+)"/', $server, $dataIdMatches);
							$dataId = $dataIdMatches[1] ?? null;

							// Extract data-server value
							preg_match('/data-server="(\d+)"/', $server, $dataServerMatches);
							$dataServer = $dataServerMatches[1] ?? null;

							// Extract server name
							preg_match('/server-image server-(\w+).*?<\/div>\s*(\w+)/', $server, $serverNameMatches);
							$serverName = $serverNameMatches[2] ?? null;
							
							$formUrl = "https://streambucket.net/playvideo.php?video_id=" . urlencode($dataId) . "&server_id=" . urlencode($dataServer) . "&token=" . urlencode($token2[0]) . "&init=0";


							if ($GLOBALS['DEBUG']) {
								echo "Server Name: $serverName, Data ID: $dataId, Data Server: $dataServer<br>";
							}

							//Run the FindVideoExtractor function here.
							if ($serverName) {
								
								$content = makeGetRequest($formUrl, $refer);		
								
								if (preg_match('/(?<=frameborder="0" src=").*?(?=" scrolling="no")/', $content, $hostUrl)) {
									if ($GLOBALS['DEBUG']) {
										echo "Found $serverName url: " . $hostUrl[0] . "</br></br>";
									}
									$DirectLink = FindVideoExtractor($hostUrl[0], 'superEmbed_stream', 'https://streambucket.net/', $serverName);
									if ($DirectLink !== false) {										

										return $DirectLink;
									}
								} else {
										
									if ($GLOBALS['DEBUG']) {
										echo 'Couldn\'t locate the upstream url for superEmbed_stream.</br>';
									}

								}

							}

							
						}

					}

				} else {
					throw new Exception('Couldn\'t get the source list on superEmbed_stream.');
				}
				
				
			} else {
				throw new Exception('Couldn\'t get the source list on superEmbed_stream.');
			}
		}

    }
    catch (exception $error) {

        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
        }
			
        return false;
    }

    return false;
}

function smashyStream_com($movieId, $imdbId, $title)
{
    global $timeOut, $maxResolution, $type, $seasonNoPad, $episodeNoPad, $DEBUG, $logTitle, $movieId, $seriesCode;

    $tSite = 'smashyStream_com';
    
    if ($DEBUG) {
        echo 'Started running smashyStream_com </br></br>';
    }    

    if ($type == 'movies') {
        $searchQuery = $movieId;
    } else {
        $searchQuery = $movieId . '&season=' . $seasonNoPad . '&episode=' . $episodeNoPad;
    }

    $url = "https://embed.smashystream.com/dataa.php?tmdb=$searchQuery";    
	
    try {
        
        $response = makeGetRequest($url, 'https://player.smashy.stream/'); 	
        
        if ($response === false) {
            throw new Exception('HTTP Error: smashyStream_com');
        }        

        $data = json_decode($response, true);

        if (isset($data['url_array']) && is_array($data['url_array'])) {
            foreach ($data['url_array'] as $urlItem) {
                if (isset($urlItem['type']) && $urlItem['type'] === 'player') {
                    
                    $apiUrl = $urlItem['url'];
                     
                    if ($DEBUG) {
                        echo 'Checking: ' . $apiUrl . " for video sources.</br></br>";                        
                    }                    
                    $response = makeGetRequest($apiUrl, 'https://player.smashy.stream/');    
									
                    
                    if ($response === false) {
                        throw new Exception('HTTP Error: smashyStream_com');
                    }
                    $dataSource = json_decode($response, true);
                    
                    if (isset($dataSource['sourceUrls'][0]) && is_array($dataSource['sourceUrls'])) {
                    
                        $sourceUrl = $dataSource['sourceUrls'][0];
                        
                        if ($DEBUG) {
                            echo 'Encrypted Url: ' . $sourceUrl . "</br></br>";                        
                        }
                        $decSourceUrl = decryptSmashyStreamSources($sourceUrl);
                        if ($decSourceUrl === false) {
                            if ($DEBUG) {
                                echo 'Decryption failed! </br></br>';                        
                            }
                            continue;
                        } else {
                            if ($DEBUG) {
                                echo 'Decrypted Url: ' . $decSourceUrl . "</br></br>";                        
                            }
												                
						$combineHeaders = "|Referer='https://player.smashy.stream/'|Origin='https://player.smashy.stream|User-Agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:127.0) Gecko/20100101 Firefox/127.0'|Accept='*/*'";
						
                        $decSourceUrl = 'hls_proxy.php?url=' . urlencode($decSourceUrl) . '&data=' . base64_encode($combineHeaders);
 							
                            $lCheck = checkLinkStatusCode($decSourceUrl, true);
                            if ($lCheck == true) {

                                if ($DEBUG) {
                                    echo "Video link: " . $decSourceUrl . "<br><br>";
                                }
                                
                                logDetails('smashyStream_com', 'none', 'successful', $logTitle, $url, $decSourceUrl, $type, $movieId, $type === 'series' ? $seriesCode : 'n/a');
                                
                                return $decSourceUrl;

                            } else {
                                continue;
                            }
                        }                        
                    
                    } else {
                        if ($DEBUG) {
                            echo 'Couldn\'t get sources from: ' . $apiUrl . "</br></br>";                        
                        }
                        continue;
                    }
                                        
                }
            }
            throw new Exception("No links found on smashyStream_com.");
        } else {
            throw new Exception("No links found on smashyStream_com.");    
        }
  
    } catch (Exception $error) {
        if ($DEBUG) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
            echo 'Finished running smashyStream_com </br></br>';
        }
        logDetails('smashyStream_com', 'none', 'failed', $logTitle, $url, 'n/a', $type, $movieId, $type === 'series' ? $seriesCode : 'n/a');

        return false;
    }
}

function shegu_net_links($title, $year)
{
    if ($GLOBALS['DEBUG']) {
        echo 'Started running shegu_net_links </br></br>';
    }

    global $maxResolution, $type, $seasonNoPad, $episodeNoPad, $movieId;
    $movieIdShe = null; // Initialize $movieIdShe to null

    // Define constants
    $iv = base64_decode("d0VpcGhUbiE=");
    $key = base64_decode("MTIzZDZjZWRmNjI2ZHk1NDIzM2FhMXc2");
    $sites = [
        base64_decode("aHR0cHM6Ly9zaG93Ym94LnNoZWd1Lm5ldC9hcGkvYXBpX2NsaWVudC9pbmRleC8="),
        base64_decode("aHR0cHM6Ly9tYnBhcGkuc2hlZ3UubmV0L2FwaS9hcGlfY2xpZW50L2luZGV4Lw==")
    ];
    $appName = base64_decode("bW92aWVib3g=");
    $appId = base64_decode("Y29tLnRkby5zaG93Ym94");

    $searchParams = [
        "module" => "Search3",
        "page" => "1",
        "type" => "all",
        "keyword" => $title,
        "pagelimit" => "20"
    ];

    try {
        $searchResponse = shegu_net_request($searchParams, false);

        if (isset($searchResponse['msg']) && $searchResponse['msg'] === 'no search result') {
            if ($GLOBALS['DEBUG']) {
                echo 'Search shegu_net_request Response: </br></br>';
                print_r($searchResponse);
                echo '</br></br>';
                echo 'Finished running shegu_net_links </br></br>';
            }

            logDetails('shegu_net_links', 'none', 'failed', $GLOBALS['logTitle'], $sites[0], 'n/a', $type, $GLOBALS['movieId'], $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');

            return false;
        }

        if ($GLOBALS['DEBUG']) {
            echo 'Search shegu_net_request Response: </br></br>';
            print_r($searchResponse);
            echo "</br></br>";
        }

        if (!isset($searchResponse['data']) || !is_array($searchResponse['data'])) {
            throw new Exception('Invalid response format: data is missing or not an array.');
        }

        foreach ($searchResponse['data'] as $movie) {
            if ($movie['year'] == $year) {
                $movieIdShe = $movie['id'];
                break; // exit the loop once the correct year is found
            }
        }

        if ($movieIdShe !== null) {
            // $movieIdShe contains the id of the movie with the matching year
            if ($GLOBALS['DEBUG']) {
                echo "Movie ID: " . $movieIdShe . '</br></br>';
            }
        } else {
            if ($GLOBALS['DEBUG']) {
                echo "No movie found for the specified year.</br></br>";
                echo 'Finished running shegu_net_links </br></br>';
            }

            logDetails('shegu_net_links', 'none', 'failed', $GLOBALS['logTitle'], $sites[0], 'n/a', $type, $GLOBALS['movieId'], $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');

            return false;
        }

        if ($type == 'movies') {
            // Call the sub-function again to get the download URLs using the movie ID
            $urlParams = [
                "module" => "Movie_downloadurl_v3",
                "mid"    => $movieIdShe,
                "oss"    => "1",
                "group"  => ""
                // ...
            ];
        } else {
            // Call the sub-function again to get the download URLs using the tv ID
            $urlParams = [
                "module"  => "TV_downloadurl_v3",
                "tid"     => $movieIdShe,
                "season"  => $seasonNoPad,
                "episode" => $episodeNoPad,
                "oss"     => "1",
                "group"   => ""
            ];
        }

        $urlResponse = shegu_net_request($urlParams, false);

        if ($urlResponse === false || !isset($urlResponse['data']['list']) || !is_array($urlResponse['data']['list']) || empty($urlResponse['data']['list'])) {
            if ($GLOBALS['DEBUG']) {
                echo "The First attempt response:<br>";
                print_r($urlResponse);
                echo "</br><br>";
                echo "The first URL attempt was unsuccessful. Initiating attempt with the second URL.<br>";
            }
            $urlResponse = shegu_net_request($urlParams, true);
        }

        // Process the URLs and return the appropriate one based on $maxResolution
        if (isset($urlResponse['data']['list'])) {

            $urls = $urlResponse['data']['list'];
            if ($GLOBALS['DEBUG']) {
                echo 'Get Links shegu_net_request Response: </br></br>';
                print_r($urlResponse);
                echo "</br><br>";
            }

        } else {
            throw new Exception('Failed to get links from shegu_net_request.');
        }

        function convertQualityToInt($quality)
        {
            // If the quality is '4K', return 2160
            if (strtolower($quality) == '4k') {
                return 2160;
            }

            // Remove the 'p' or 'P' character and convert the remaining string to an integer
            return intval(str_ireplace('p', '', $quality));
        }

        // Initialize variables
        $closestDifference = PHP_INT_MAX;
        $closestQuality = null;
        $closestMovie = null;

        // Loop through the array of movies
        foreach ($urls as $movie) {

            if (empty($movie['path'])) {
                continue;
            }

            // Convert the real_quality value to an integer for comparison
            $realQuality = convertQualityToInt($movie['real_quality']);

            // Calculate the difference between the real_quality and the maxResolution
            $difference = abs($realQuality - $maxResolution);

            if ($GLOBALS['DEBUG']) {
                echo "Movie video path: " . $movie['path'] . "<br><br>";
            }

            // Check if this movie is a closer match than the previous closest match
            if ($difference < $closestDifference) {
                $closestQuality = $realQuality;
                $closestDifference = $difference;
                $closestMovie = $movie;
            }
        }

        // Check if a closest match was found
        if ($closestMovie !== null) {
            // $closestMovie contains the movie version with the closest available quality

            if ($GLOBALS['DEBUG']) {
                echo "Closest Quality: " . $closestQuality . "</br><br>";
                echo "Movie Path: " . $closestMovie['path'] . "</br><br>";
            }

        } else {
            throw new Exception('No movie found with the closest quality.');
        }

        if (isset($closestMovie['path'])) {
            logDetails('shegu_net_links', 'none', 'successful', $GLOBALS['logTitle'], $sites[0], $closestMovie['path'], $type, $GLOBALS['movieId'], $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');

            return $closestMovie['path'];
        } else {
            throw new Exception('Finished running shegu_net_links');
        }

    } catch (Exception $e) {
        if ($GLOBALS['DEBUG']) {
            echo 'Caught exception: ' . $e->getMessage() . "<br>";
        }
        logDetails('shegu_net_links', 'none', 'failed', $GLOBALS['logTitle'], $sites[0], 'n/a', $type, $GLOBALS['movieId'], $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');
        return false;
    }
}

function shegu_net_request($data, $useSecondUrl = false)
{
    global $timeOut;
    global $DEBUG;
    // Define constants
    $iv = base64_decode("d0VpcGhUbiE=");
    $key = base64_decode("MTIzZDZjZWRmNjI2ZHk1NDIzM2FhMXc2");
    $urls = [
        //base64_decode("aHR0cHM6Ly9zaG93Ym94LnNoZWd1Lm5ldC9hcGkvYXBpX2NsaWVudC9pbmRleC8="),
        base64_decode("aHR0cHM6Ly9tYnBhcGkuc2hlZ3UubmV0L2FwaS9hcGlfY2xpZW50L2luZGV4Lw=="),
		base64_decode("aHR0cHM6Ly9tYnBhcGkuc2hlZ3UubmV0L2FwaS9hcGlfY2xpZW50L2luZGV4Lw==")
    ];
    $appName = base64_decode("bW92aWVib3g=");
    $appId = base64_decode("Y29tLnRkby5zaG93Ym94");

    // Helper function to encrypt data using 3DES
    $encrypt = function ($data, $key, $iv) {
        return openssl_encrypt($data, 'des-ede3-cbc', $key, 0, $iv);
    };

    // Helper function to get verify token
    $getVerify = function ($encryptedData, $appName, $key) {
        return $encryptedData ? md5(md5($appName) . $key . $encryptedData) : null;
    };

    // Helper function to get the current timestamp plus 12 hours
    $getExpiredDate = function () {
        return time() + 60 * 60 * 12;
    };

    // Define request parameters
    $params = [
        "childmode" => "0",
        "app_version" => "11.5",
        "appid" => $appId,
        "lang" => "en",
        "expired_date" => (string) time() + 60 * 60 * 12,
        "platform" => "android",
        "channel" => "Website",
        "uid" => ""
        // ... (other parameters)
    ];

    // Merge input data with default parameters
    $requestData = array_merge($params, $data);

    // Encrypt the request data
    $encryptedData = $encrypt(json_encode($requestData), $key, $iv);

    // Get the app_key and verify token
    $appKey = md5($appName);
    $verify = $getVerify($encryptedData, $appName, $key);

    // Base64 encode the payload
    $payload = base64_encode(json_encode(['app_key' => $appKey, 'verify' => $verify, 'encrypt_data' => $encryptedData]));

    // Choose the URL based on the $useSecondUrl flag
    $url = $useSecondUrl ? $urls[1] : $urls[0];

    // Define additional parameters to be sent in the request body
    $bodyParams = [
        'data' => $payload,
        'appid' => '27',
        'platform' => 'android',
        'version' => '129',
        'medium' => 'Website'
    ];

    // Initialize cURL session
    $ch = curl_init($url);

    // Set cURL options
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($bodyParams));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Platform: android', 'Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($ch, CURLOPT_REFERER, 'https://movie-web.app');
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeOut);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeOut);

    try {
        // Execute cURL session and get the response
        $response = curl_exec($ch);

        // Check for cURL errors and handle them
        if (curl_errno($ch)) {
            $errorMessage = 'cURL Error: ' . curl_error($ch);
            if ($DEBUG) {
                echo $errorMessage . "<br>";
            }
            throw new Exception($errorMessage);
        }

        // Close cURL session
        curl_close($ch);

        // Check for response errors and handle them
        if (!$response) {
            $errorMessage = 'HTTP Error: Failed to fetch movie source data.';
            if ($DEBUG) {
                echo $errorMessage . "<br>";
            }
            throw new Exception($errorMessage);
        }

        // Return the response
        return json_decode($response, true);
    } catch (Exception $e) {
        if ($DEBUG) {
            echo 'Caught exception: ' . $e->getMessage() . "<br>";
        }
        return null; // Return null or handle the error as needed
    }
}

//Dead, no longer running.
function tvembed_cc($movieId, $imdbId, $title)
{
    global $timeOut, $maxResolution, $type, $seasonNoPad, $episodeNoPad;

    $tSite = 'tvembed_cc';
	
	if ($GLOBALS['DEBUG']) {
        echo 'Started running tvembed_cc </br></br>';
    }	
	
	$apiUrl  = 'https://tvembed.cc';
	
	if ($type == 'movies'){
		$searchQuery = '/movie/' . $movieId;
	} else {
		$searchQuery = '/tv/' . $movieId . '/' . $seasonNoPad . '/' . $episodeNoPad;
	}

	$apiUrl = $apiUrl . $searchQuery;	
	
	try {
		
				$contextOptions = [
			'http' => [
				'method' => "GET",
				'header' => "Accept-Language: en-US,en;q=0.5\r\n" .	
							"Accept: application/json, text/javascript, */*; q=0.01\r\n" .
							"X-Requested-With: XMLHttpRequest\r\n" .							
							"User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/119.0\r\n" .
							"Referer: $apiUrl\r\n",
				'timeout' => $timeOut,
			]
		];		
				
		$context = stream_context_create($contextOptions);
		$response = @file_get_contents($apiUrl, false, $context);
		
		if ($response === false) {
            throw new Exception('HTTP Error: tvembed_cc');
		}				

		if(!preg_match_all('#(?<=,url:\").*?(?=\")#', $response, $urlMatches)){	
		 
			throw new Exception("No links found on tvembed_cc.");		
			
		 }		
		 
		$firstSourceUrl = null;

		foreach ($urlMatches[0] as $urlMatch) {
			
			if (isset($urlMatch) && !empty($urlMatch)) {
				$firstSourceUrl = $urlMatch;
				if ($GLOBALS['DEBUG']) {
					echo "Compressed url found: " . $firstSourceUrl . "<br><br>";
				}
				break;
			}
		}
		
		if(!$firstSourceUrl){
			throw new Exception("No links found on tvembed_cc.");
		}	
		
	    $ch = curl_init();
		
		$url = 'https://script.google.com/macros/s/AKfycbyBjcxEnbp3JHBkJzlBYt3w0ZcSXLPdc7RFdWg3mqhuFgTi6dmapMfgYGtaoMGuJtzeVg/exec?data='.urlencode(decode_unicode_sequence($firstSourceUrl));	

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $response = curl_exec($ch);
		
		if ($response === false) {
            throw new Exception("Curl error: " . curl_error($ch));
        }
		
		if($response){
			$firstSourceUrl = $response;
			
			if ($GLOBALS['DEBUG']) {
				echo "Video link: " . $firstSourceUrl . "<br><br>";
			}
		} else {
			throw new Exception("Decompression failed on tvembed_cc.");
		}
		
		logDetails('tvembed_cc', 'none', 'successful', $GLOBALS['logTitle'], isset($apiUrl) ? $apiUrl : 'n/a', $firstSourceUrl, $type, $GLOBALS['movieId'], $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');	
	
		
        return $firstSourceUrl;
  
    } catch (exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
            echo 'Finished running tvembed_cc </br></br>';
        }
		
			logDetails('tvembed_cc', 'none', 'failed', $GLOBALS['logTitle'], isset($apiUrl) ? $apiUrl : 'n/a', 'n/a', $type, $GLOBALS['movieId'], $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');

        return false;
    }
}
//Dead, no longer running.
function blackvid_space($movieId, $title)
{
    global $timeOut, $movieId, $type, $maxResolution, $seasonNoPad, $episodeNoPad;

    if ($GLOBALS['DEBUG']) {
        echo 'Started running blackvid_space </br></br>';
    }	
	
	if($type == 'series'){
		$url = "https://prod.api.blackvid.space/v3/tv/sources/" . $movieId;
		$url .= '/' . $seasonNoPad  . '/' . $episodeNoPad;
	} else {
		$url = "https://prod.api.blackvid.space/v3/movie/sources/" . $movieId;
	}		
	
	$url .= '?key=b6055c533c19131a638c3d2299d525d5ec08a814';
    
    $options = ['http' => ['method' => "GET", 'header' =>
        "Content-Type: application/json"]];

    try {
        $context = stream_context_create(['http' => ['timeout' => $timeOut]]);
        $response = @file_get_contents($url, false, $context);
		
        if ($response === false) {
            throw new Exception('HTTP Error: blackvid_space</br></br>');
        }
    }
    catch (exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Failed to fetch movie source data from blackvid_space Error: ' . $error->
                getMessage() . "</br></br>";
            echo 'Finished running blackvid_space </br></br>';

        }
			logDetails('blackvid_space', 'none', 'failed', $GLOBALS['logTitle'], $url, 'n/a', $type, $GLOBALS['movieId'], $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');					

        return false;
    }

    $statusCode = http_response_code();
	
    if ($statusCode !== 200) {
        if ($GLOBALS['DEBUG']) {
            echo "Failed to fetch movie source data from blackvid_space Response code: " .
                $statusCode . "<br></br>";
            echo 'Finished running blackvid_space </br></br>';
        }
			logDetails('blackvid_space', 'none', 'failed', $GLOBALS['logTitle'], $url, 'n/a', $type, $GLOBALS['movieId'], $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');
        return false;
    }
	
	$decryptedJson = decryptAesGCM($response, '2378f8e4e844f2dc839ab48f66e00acc2305a401');
    $data = json_decode($decryptedJson, true);
	
	 if ($GLOBALS['DEBUG']) {
		echo 'The Decrypted Json Response: </br>';
		print_r($decryptedJson);
		echo '</br></br>';
	}

	if ($data && isset($data['sources'])) {
		$bestLowerResolutionUrl = null;

		foreach ($data['sources'] as $source) {
			foreach ($source['sources'] as $videoSource) {
				$quality = preg_replace('/4k/i', '2160', $videoSource['quality']);
				$vurl = $videoSource['url'];
				
				if (strtolower($quality ) === 'auto') {
					$quality  = '720';
				}
				
				$quality = intval($quality );
				
				if ($quality === $maxResolution) {
					if ($GLOBALS['DEBUG']) {
						echo 'Video Link: ' . $vurl . "</br></br>";
					}
					logDetails('blackvid_space', 'none', 'successful', $GLOBALS['logTitle'], $url, $vurl, $type, $GLOBALS['movieId'], $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');						
										
					return $vurl;
				} elseif (intval($quality) < intval($maxResolution) && !$bestLowerResolutionUrl) {
					$bestLowerResolutionUrl = $vurl;
				}
			}
		}

		if ($bestLowerResolutionUrl) {
			if ($GLOBALS['DEBUG']) {
				echo 'Video Link: ' . $bestLowerResolutionUrl . "</br></br>";
			}
			logDetails('blackvid_space', 'none', 'successful', $GLOBALS['logTitle'], $url, $vurl, $type, $GLOBALS['movieId'], $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');					
			return $bestLowerResolutionUrl;
		} else {
			if ($GLOBALS['DEBUG']) {
				echo "No suitable URL found. </br></br>";
			}
			echo 'Finished running blackvid_space </br></br>';
			logDetails('blackvid_space', 'none', 'failed', $GLOBALS['logTitle'], $url, 'n/a', $type, $GLOBALS['movieId'], $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');			
			return false;
		}
	} else {

        if ($GLOBALS['DEBUG']) {
            echo "No sources found in the JSON data.";
        }
        echo 'Finished running blackvid_space </br></br>';
			logDetails('blackvid_space', 'none', 'failed', $GLOBALS['logTitle'], $url, 'n/a', $type, $GLOBALS['movieId'], $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');		
        return false;
    }
}

//Dead, no longer running.
function goMovies_sx($title, $year)
{
	global $timeOut, $maxResolution, $type, $seasonNoPad, $episodeNoPad, $movieId;
		
    if ($GLOBALS['DEBUG']) {
        echo 'Started running goMovies_sx </br></br>';
    }
    global $timeOut, $maxResolution;
	
    $tSite = 'goMovies_sx';

    $apiUrl = 'https://gomovies.sx/ajax/search';

    try {
       
		$postData = [
			'keyword' => $title,
		];
		
		$response = makePostRequest($apiUrl, 'https://gomovies.sx/', $postData, 'application/x-www-form-urlencoded');

        if ($response === false) {
            throw new Exception('HTTP Error: goMovies_sx');
        }

        $doc = new DOMDocument();
        $doc->loadHTML($response);

        $divElements = $doc->getElementsByTagName('div');
        $matchingHref = null;

        $divElements = $doc->getElementsByTagName('a');

        foreach ($divElements as $a) {
            // Check if this <a> element has the expected class "nav-item"
            if ($a->getAttribute('class') == 'nav-item') {
                // Find the title and year elements within this <a> element
                $titleElement = $a->getElementsByTagName('h3')->item(0);
                $yearElement = $a->getElementsByTagName('span')->item(0);				

                if ($GLOBALS['DEBUG']) {
                    echo "Looking for a match by class: nav-item </br></br>";
                }

                // Check if the title and year match your criteria
				if (strtolower(trim($title)) == strtolower(trim($titleElement->textContent)) &&
					($type != 'movies' || strtolower(trim($year)) == strtolower(trim($yearElement->textContent)))){

                    // Get the href link
                    if ($a->getAttribute('href')) {
                        $Pagehref = "https://gomovies.sx" . $a->getAttribute('href');
                        if (preg_match('/(\d+)$/', $Pagehref, $matches)) {
                            $number = $matches[0];
                            if ($GLOBALS['DEBUG']) {
                                echo "Located watch id $number on goMovies_sx </br></br>";
                            }
							
							if ($type == "movies"){

								$url = "https://gomovies.sx/ajax/movie/episodes/" . $number;
							} else {
								
								$url = "https://gomovies.sx/ajax/season/list/" . $number;
							}
                            $context = stream_context_create(['http' => ['timeout' => $timeOut, 'header' =>
                                "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0\r\n",
                                "X-Requested-With: XMLHttpRequest"], ]);

                            $response = @file_get_contents($url, false, $context);					
													
							if ($type == "series"){
								$pattern = '/<a\s+data-id="(\d+)"\s*[^>]*\s*>Season\s+' . $seasonNoPad . '<\/a>/';
							if(!preg_match($pattern, $response, $matches)){
								throw new Exception('Couldn\'t locate season id on goMovies_sx');						 
							} else {
							if ($GLOBALS['DEBUG']) {
								 echo 'Located season id: ' . $matches[1] . "</br></br>";
							 }
							 $url ='https://gomovies.sx/ajax/season/episodes/' . $matches[1];
							 $response = @file_get_contents($url, false, $context);
							 $pattern = '/<a\s+id="episode-(\d+)"\s*[^>]*\s*Eps\s+' .$episodeNoPad.'\:/';
							if(!preg_match($pattern, $response, $matches)){
								throw new Exception('Couldn\'t episode id on goMovies_sx');						 
							} else {
							if ($GLOBALS['DEBUG']) {
								 echo 'Located episode id: ' . $matches[1] . "</br></br>";
							 }
							 $url ='https://gomovies.sx/ajax/episode/servers/' . $matches[1];
							 $response = @file_get_contents($url, false, $context);
							}								
							}
							}
							
                            if ($GLOBALS['DEBUG']) {
                                print_r('Video servers located: ' . $response . "</br></br>");
                            }
                            if ($response === false) {
                                throw new Exception('HTTP Error: goMovies_sx');
                            }
                            $doc = new DOMDocument();
                            $doc->loadHTML($response);

                            $xpath = new DOMXPath($doc);

                            $liElements = $xpath->query('//li[@class="nav-item"]');

                            if ($liElements !== null) {
                                foreach ($liElements as $li) {
                                    // Find the <a> element within the current <li> element
                                    $aElement = $xpath->query('.//a', $li)->item(0);

                                    if ($aElement !== null) {
                                        $title = $aElement->getAttribute('title');
										
										if($type == 'movies'){
											$dataLinkid = $aElement->getAttribute('data-linkid');
										} else {
											 $dataLinkid = $aElement->getAttribute('data-id');
										}
										
										if(!$title){
											continue;
										}	
                                        //Run the FindVideoExtractor function here.				
                                            
										if ($GLOBALS['DEBUG']) {
											echo "Page containing $title: $dataLinkid </br></br>";

										}
										
										try {
											$url = "https://gomovies.sx/ajax/sources/" . $dataLinkid;
											$response = @file_get_contents($url, false, $context);

											if ($response !== false) {
												// Decode the JSON data into an associative array
												$data = json_decode($response, true);

												if ($data !== null && isset($data['link'])) {
													if ($GLOBALS['DEBUG']) {
														print_r("The returned Json for $title: $response </br></br>");
													}
													
													$returnExtractor = FindVideoExtractor($data['link'], $tSite, 'https://gomovies.sx/', $title);
													if ($returnExtractor !== false) {
														
														return $returnExtractor;
													}
												}
											} else {
												throw new Exception("Couldn't find the $title on UpCloud for goMovies_sx");
											}
										} catch (Exception $e) {
											
											echo 'Caught exception: ', $e->getMessage();
										}                                     


                                    } else {
                                        throw new Exception('Couldn\'t locate links on goMovies_sx');
                                    }
                                }
                            } else {
                                if ($GLOBALS['DEBUG']) {
                                    echo "No <li> elements with class 'nav-item' found.\n";
                                }
                            }


                        } else {
                            throw new Exception('Couldn\'t get the watch id on goMovies_sx');
                        }

                    }
                }
            }
        }

    }
    catch (exception $error) {

        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
        }

        return false;
    }

    return false;
}

//Dead, no longer running.
function theMovieArchive_site($movieId, $title)
{
    global $timeOut, $movieId, $type;

    if ($GLOBALS['DEBUG']) {
        echo 'Started running theMovieArchive_site </br></br>';
    }

    $url = "https://prod.omega.themoviearchive.site/v3/movie/sources/" . $movieId;
    $options = ['http' => ['method' => "GET", 'header' =>
        "Content-Type: application/json"]];

    try {
        $context = stream_context_create(['http' => ['timeout' => $timeOut]]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new Exception('HTTP Error: theMovieArchive_site</br></br>');
        }
    }
    catch (exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Failed to fetch movie source data from theMovieArchive_site Error: ' . $error->
                getMessage() . "</br></br>";
            echo 'Finished running theMovieArchive_site </br></br>';

        }
			logDetails($title, $url, 'n/a', 'movie', $movieId, 'failed', $type === 'series' ? $seasonNoPad : '', $type === 'series' ? $episodeNoPad : '');
        return false;
    }

    $statusCode = http_response_code(); // Get the current response status code
    if ($statusCode !== 200) {
        if ($GLOBALS['DEBUG']) {
            echo "Failed to fetch movie source data from theMovieArchive_site Response code: " .
                $statusCode . "<br></br>";
            echo 'Finished running theMovieArchive_site </br></br>';
        }
			logDetails($title, $url, 'n/a', 'movie', $movieId, 'failed', $type === 'series' ? $seasonNoPad : '', $type === 'series' ? $episodeNoPad : '');
        return false;
    }

    $data = json_decode($response, true);

    if ($data && isset($data['sources'])) {
        $foundUrl = false;
        // Define the qualities to check
        $qualitiesToCheck = ['2160', '1080', '720', 'auto'];

        foreach ($data['sources'] as $source) {
            foreach ($source['sources'] as $videoSource) {
                $quality = $videoSource['quality'];
                $vurl = $videoSource['url'];

                if (in_array($quality, $qualitiesToCheck)) {

                    if ($GLOBALS['DEBUG']) {
                        echo 'Video Link: ' . $vurl . "</br></br>";

                    }
						logDetails($title, $url, $vurl, $type, $movieId, 'successful', $type === 'series' ? $seasonNoPad : '', $type === 'series' ? $episodeNoPad : '');					
                    return $vurl;


                }
            }
        }

        if ($GLOBALS['DEBUG']) {
            echo "No suitable URL found. </br></br>";
        }
        echo 'Finished running theMovieArchive_site </br></br>';
			logDetails($title, $url, 'n/a', 'movie', $movieId, 'failed', $type === 'series' ? $seasonNoPad : '', $type === 'series' ? $episodeNoPad : '');			
        return false;
    } else {

        if ($GLOBALS['DEBUG']) {
            echo "No sources found in the JSON data.";
        }
        echo 'Finished running theMovieArchive_site </br></br>';
			logDetails($title, $url, 'n/a', 'movie', $movieId, 'failed', $type === 'series' ? $seasonNoPad : '', $type === 'series' ? $episodeNoPad : '');		
        return false;
    }
}

////////////////////////////// Torrents Movies & Tv Shows Websites ///////////////////////////////

//Start Stremio Plugins Functions

function stremioSites($imdbId)
{
    global $PRIVATE_TOKEN, $type, $maxResolution, $seasonNoPad, $episodeNoPad, $DEBUG, $maxFileSize;

    if ($DEBUG) {
        echo 'Started running stremioSites </br></br>';
    }

    $id = $imdbId;
    $urls = [
        "https://comet.elfhosted.com",
        "https://torrentio.strem.fun",
        "https://torrentsdb.com",
        "https://addon.peerflix.mov"
    ];

    $endpoints = [];
    foreach ($urls as $base) {
        $suffix = ($type === 'series') ? "stream/series/$id:$seasonNoPad:$episodeNoPad.json" : "stream/movie/$id.json";
        $endpoints[] = "$base/realdebrid=real-debrid-key/$suffix";
    }

    // Parallel curl requests
    $mh = curl_multi_init();
    $handles = [];

    foreach ($endpoints as $url) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_multi_add_handle($mh, $ch);
        $handles[] = $ch;
    }

    $running = null;
    do {
        curl_multi_exec($mh, $running);
        usleep(100000);
    } while ($running);

    $streams = [];
    foreach ($handles as $ch) {
        $res = curl_multi_getcontent($ch);
        $json = json_decode($res, true);
        if (!empty($json['streams'])) {
            foreach ($json['streams'] as $s) {
                if (isset($s['url']) || isset($s['infoHash'])) {
                    $streams[] = $s;
                }
            }
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);

    if ($DEBUG) {
        echo 'Total Torrents: ' . count($streams) . '</br></br>';
    }

    // ✅ Apply max file size filter
    $maxBytes = $maxFileSize * 1024 * 1024;
    $validStreams = [];
    $allStreams   = $streams;

    foreach ($streams as $s) {
        $fileSize = null;

        // 1) behaviorHints.videoSize
        if (isset($s['behaviorHints']['videoSize'])) {
            $fileSize = (int)$s['behaviorHints']['videoSize'];
        }

        // 2) Parse human-readable GB/MB from title/description
        if (!$fileSize && (isset($s['description']) || isset($s['title']))) {
            $text = $s['description'] ?? $s['title'];
            if (preg_match('/([\d\.]+)\s*(GB|MB)/i', $text, $m)) {
                $num  = (float)$m[1];
                $unit = strtoupper($m[2]);
                $fileSize = ($unit === 'GB')
                    ? $num * 1024 * 1024 * 1024
                    : $num * 1024 * 1024;
            }
        }

        if ($fileSize && $fileSize <= $maxBytes) {
            $validStreams[] = $s;
            if ($DEBUG) echo "✅ Accepted (" . round($fileSize/1024/1024/1024,2) . " GB) <= {$maxFileSize} MB<br>";
        } elseif ($fileSize && $fileSize > $maxBytes) {
            if ($DEBUG) echo "❌ Filtered (" . round($fileSize/1024/1024/1024,2) . " GB) > {$maxFileSize} MB<br>";
        } else {
            // unknown size → allow
            $validStreams[] = $s;
        }
    }

    $streams = !empty($validStreams) ? $validStreams : $allStreams;

    // Sort by resolution descending, but capped by $maxResolution
    usort($streams, function ($a, $b) use ($maxResolution) {
        $resA = extractResolution($a['title'] ?? '') ?? 0;
        $resB = extractResolution($b['title'] ?? '') ?? 0;
        $resA = ($resA <= $maxResolution) ? $resA : 0;
        $resB = ($resB <= $maxResolution) ? $resB : 0;
        return $resB - $resA;
    });

    $filteredCount = count($streams);

		if ($DEBUG) {
				echo '</br></br>Total Torrents After Filesize Filter: ' . count($streams) . '</br></br>';
		}

    if (!empty($streams)) {
        $streamUrl = instantAvailability_RD($streams, 'stremioSites');
        if ($streamUrl !== false) {
						logDetails("stremioSites ($filteredCount)", 'none', 'successful', $GLOBALS['logTitle'], $url, $streamUrl, $type, $GLOBALS['movieId'], $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');
            return $streamUrl;
        }
    }

		logDetails('stremioSites', 'none', 'failed', $GLOBALS['logTitle'], $url, 'n/a', $type, $GLOBALS['movieId'], $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a');
    return false;
}

function Stremio_rd_api($endpoint, $post = [], $method = 'POST') {
    global $PRIVATE_TOKEN;
    $url = "https://api.real-debrid.com/rest/1.0/$endpoint";

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $PRIVATE_TOKEN"]
    ];

    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
    } elseif ($method === 'DELETE') {
        $opts[CURLOPT_CUSTOMREQUEST] = 'DELETE';
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    curl_close($ch);
    return json_decode($resp, true);
}

//End Stremio Plugins Functions

function initialize_Jackett_webServer($movieId, $imdbId, $title, $year, $tvpack=false)
{	
    global $timeOut, $maxResolution, $torrentData, $language, $languageMapping, $type, $season, $seasonNoPad, $episode, $JACKETT_IP_PORT, $JACKETT_API_KEY;

    $tSite = 'Jackett_webServer';
	
	if ($GLOBALS['DEBUG']) {
        echo 'Started running Jackett_webServer </br></br>';
    }
	
    $key = $movieId . 'Jackett_webServer';
	$cleanedTitle = preg_replace('/[^a-zA-Z0-9 ]/', '', $title);
	
	if($type == "movies"){	
			
		$searchQuery = urlencode($cleanedTitle . ' ' . $year);		
		$apiUrl = "http://$JACKETT_IP_PORT/api/v2.0/indexers/all/results?apikey=$JACKETT_API_KEY&Query=$searchQuery&Category%5B%5D=2040";
		
	} else {

		$searchQuery = urlencode($cleanedTitle);			
		$apiUrl = "http://$JACKETT_IP_PORT/api/v2.0/indexers/all/results?apikey=$JACKETT_API_KEY&Query=$searchQuery&Category%5B%5D=5000";
	}

	return $apiUrl;

}

function Jackett_webServer($response, $tvpack = false)
{
    global $timeOut, $maxResolution, $torrentData, $type, $season, $episode;
    $tSite = 'Jackett_webServer';
	

    try {
        if ($response === false) {
            throw new Exception('HTTP Error: Jackett_webServer');
        }

        $data = json_decode($response, true);

        if (!isset($data['Results']) || !is_array($data['Results'])) {
            return false;
        }

        $torrents = $data['Results'];

        if ($type == 'series') {
            $filtered = array_filter($torrents, function ($ep) use ($season, $episode) {
                $seasonEpisodeStr = sprintf("S%02dE%02d", $season, $episode);
                $seasonStr = sprintf("Season %d", $season);
                return isset($ep['Title']) && (strpos($ep['Title'], $seasonEpisodeStr) !== false || strpos($ep['Title'], $seasonStr) !== false);
            });

            if (empty($filtered)) {
                return false;
            }

            $torrents = $filtered;
        }

        if ($tvpack) {
            $title = preg_replace('/s\d{2}e\d{2}/i', 'Season ' . $GLOBALS['seasonNoPad'], $GLOBALS['globalTitle']);
        } else {
            $title = $GLOBALS['globalTitle'];
        }

        $totalAdded = 0;
        if (is_array($torrents) && !empty($torrents)) {
            foreach ($torrents as $torrentInfo) {
                $extractedTitle = $torrentInfo['Title'];
                $matchedHash = $torrentInfo['InfoHash'];

                // Extract quality from the title using regex
                preg_match('/(2160|1080|720|480|360|240)[pP]/i', $extractedTitle, $matches);
                $quality = $tvpack ? 'unknown' : (isset($matches[1]) ? $matches[1] : '480');

                if (isset($matchedHash) && isset($extractedTitle)) {
                    if (filterCompareTitles($extractedTitle, $title, $tvpack)) {
                        $torrentData[] = [
                            'title_long' => $title,
                            'hash' => $matchedHash,
                            'quality' => $quality,
                            'extracted_title' => $extractedTitle,
                            'tvpack' => $tvpack
                        ];
                        $totalAdded++;
                    }
                }
            }
        } else {
            throw new Exception('Data does not meet criteria');
        }

        if ($GLOBALS['DEBUG']) {
            echo 'Finished running Jackett_webServer (' . $totalAdded . ') </br></br>';
        }
        return $totalAdded;

    } catch (Exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
            echo 'Finished running Jackett_webServer </br></br>';
        }

        return false;
    }
}

function initialize_torrentio_strem_fun($movieId, $imdbId, $title, $year, $tvpack=false)
{	
    global $timeOut, $maxResolution, $torrentData, $language, $languageMapping, $type, $season, $seasonNoPad, $episodeNoPad, $episode;

    $tSite = 'torrentio_strem_fun';
	
	if ($GLOBALS['DEBUG']) {
        echo 'Started running torrentio_strem_fun </br></br>';
    }

	if($type == "movies"){				
		
		$apiUrl = "https://torrentio.strem.fun/stream/movie/$imdbId.json";
		
	} else {
			
		$apiUrl = "https://torrentio.strem.fun/stream/series/$imdbId:$seasonNoPad:$episodeNoPad.json";
	}

	return $apiUrl;

}

function torrentio_strem_fun($response, $tvpack = false)
{
    global $timeOut, $maxResolution, $torrentData, $type, $season, $episode;
    $tSite = 'torrentio_strem_fun';

    try {
        if ($response === false) {
            throw new Exception('HTTP Error: torrentio_strem_fun');
        }

        $data = json_decode($response, true);

        if (!isset($data['streams']) || !is_array($data['streams'])) {
            return false;
        }

        $torrents = $data['streams'];

        if ($type == 'series') {
            $filtered = array_filter($torrents, function ($ep) use ($season, $episode) {
                $seasonEpisodeStr = sprintf("S%02dE%02d", $season, $episode);
                $seasonStr = sprintf("Season %d", $season);
                return isset($ep['title']) && (strpos($ep['title'], $seasonEpisodeStr) !== false || strpos($ep['title'], $seasonStr) !== false);
            });

            if (empty($filtered)) {
                return false;
            }

            $torrents = $filtered;
        }

        if ($tvpack) {
            $title = preg_replace('/s\d{2}e\d{2}/i', 'Season ' . $GLOBALS['seasonNoPad'], $GLOBALS['globalTitle']);
        } else {
            $title = $GLOBALS['globalTitle'];
        }

        $totalAdded = 0;
        if (is_array($torrents) && !empty($torrents)) {
            foreach ($torrents as $torrentInfo) {
                $extractedTitle = $torrentInfo['title'];
                $matchedHash = $torrentInfo['infoHash'];

                // Extract quality from the title using regex
                preg_match('/(2160|1080|720|480|360|240)[pP]/i', $extractedTitle, $matches);
                $quality = $tvpack ? 'unknown' : (isset($matches[1]) ? $matches[1] : '480');

                if (isset($matchedHash) && isset($extractedTitle)) {
                    if (filterCompareTitles($extractedTitle, $title, $tvpack)) {
                        $torrentData[] = [
                            'title_long' => $title,
                            'hash' => $matchedHash,
                            'quality' => $quality,
                            'extracted_title' => $extractedTitle,
                            'tvpack' => $tvpack
                        ];
                        $totalAdded++;
                    }
                }
            }
        } else {
            throw new Exception('Data does not meet criteria');
        }

        if ($GLOBALS['DEBUG']) {
            echo 'Finished running torrentio_strem_fun (' . $totalAdded . ') </br></br>';
        }
        return $totalAdded;

    } catch (Exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
            echo 'Finished running torrentio_strem_fun </br></br>';
        }

        return false;
    }
}

function initialize_bitsearch_to($movieId, $imdbId, $title, $year, $tvpack=false)
{	
    global $timeOut, $maxResolution, $torrentData, $language, $languageMapping, $type, $season, $seasonNoPad, $episode;

    $tSite = 'bitsearch_to';
	
	if ($GLOBALS['DEBUG']) {
        echo 'Started running bitsearch_to </br></br>';
    }
	
    $key = $movieId . 'bitsearch_to';
	$cleanedTitle = preg_replace('/[^a-zA-Z0-9 ]/', '', $title);
	
	if($type == "movies"){	
			
		$searchQuery = urlencode($cleanedTitle . ' ' . $year);		
		$apiUrl = 'https://bitsearch.to/search?q='. $searchQuery;
		
	} else {

		$searchQuery = urlencode($cleanedTitle);			
		$apiUrl = 'https://bitsearch.to/search?q='. $searchQuery;
	}
	
	return $apiUrl;

}

function bitsearch_to($response, $tvpack=false)
{
    global $timeOut, $maxResolution, $torrentData, $language, $languageMapping, $type, $season, $seasonNoPad, $episode;
    $tSite = 'bitsearch_to';	
    $data = $response;
    
    try {
        // First decode HTML entities to make magnet links easier to parse
        $data = html_entity_decode($data);
        
        // Extract all titles
        preg_match_all('/<a[^>]*href="\/torrent\/[^"]*"[^>]*class="[^"]*hover:text-primary[^"]*"[^>]*>\s*(.*?)\s*<\/a>/s', $data, $title_matches);
        
        // Extract all magnet hashes (after HTML decoding)
        preg_match_all('/magnet:\?xt=urn:btih:([A-Fa-f0-9]{40})/', $data, $hash_matches);
        
        if (count($title_matches[1]) === 0 || count($hash_matches[1]) === 0) {
            throw new Exception("No valid torrents found on bitsearch_to. Titles: " . count($title_matches[1]) . ", Hashes: " . count($hash_matches[1]));
        }
        
        $totalAdded = 0;
        $minCount = min(count($title_matches[1]), count($hash_matches[1]));
        
        for ($i = 0; $i < $minCount; $i++) {
            $extractedTitle = trim($title_matches[1][$i]);
            $magnetHash = $hash_matches[1][$i];
            
            if (empty($extractedTitle) || empty($magnetHash)) {
                continue;
            }
            
            // Replace '4K' or '4k' with '2160p' before extracting the resolution
            $processedTitle = str_ireplace("4K", "2160p", $extractedTitle);
            
            // Extract resolution
            preg_match('/(2160|1080|720|480|360|240)[pP]/i', $processedTitle, $matchedRes);
            
            // If matchedRes wasn't found, set it to 480p (or unknown for tvpacks)
            if ($tvpack) {
                $resolution = 'unknown';
            } elseif (!$matchedRes) {
                $resolution = '480p';
            } else {
                $resolution = $matchedRes[0];
            }
            
            if ($tvpack) {           
                $title = preg_replace('/s\d{2}e\d{2}/i', 'Season ' . $GLOBALS['seasonNoPad'], $GLOBALS['globalTitle']);
            } else {
                $title = $GLOBALS['globalTitle'];
            }
            
            if (filterCompareTitles($extractedTitle, $title, $tvpack)) {
                $torrentData[] = [
                    'title_long'      => $title, 
                    'hash'            => $magnetHash, 
                    'quality'         => $resolution,
                    'extracted_title' => $extractedTitle,						
                    'tvpack'          => $tvpack
                ];				
                $totalAdded++;
            }
        }
        
        if ($GLOBALS['DEBUG']) {
            echo 'Finished running bitsearch_to (' .  $totalAdded . ') </br></br>';
        }
        return $totalAdded;
        
    } catch (Exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
            echo 'Finished running bitsearch_to </br></br>';
        }
        return false;
    }
}

function initialize_rutor_info($movieId, $imdbId, $title, $year, $tvpack=false)
{	
    global $timeOut, $maxResolution, $torrentData, $language, $languageMapping, $type, $season, $seasonNoPad, $episode;

    $tSite = 'rutor_info';
	
	if ($GLOBALS['DEBUG']) {
        echo 'Started running rutor_info </br></br>';
    }
	
    $key = $movieId . 'rutor_info';
	$cleanedTitle = preg_replace('/[^a-zA-Z0-9 ]/', '', $title);
	
	if($type == "movies"){	
			
		$searchQuery = urlencode($cleanedTitle . ' ' . $year);		
		$apiUrl = 'https://rutor.info/search/0/1/000/0/'. $searchQuery;
		
	} else {
		$cleanedTitle = preg_replace('/s\d{2}e\d{2}/i', '', $cleanedTitle);
		$searchQuery = urlencode($cleanedTitle);			
		$apiUrl = 'https://rutor.info/search/0/4/000/0/'. $searchQuery;
	}
	
	return $apiUrl;

}

function rutor_info($response, $tvpack=false)
{
    global $timeOut, $maxResolution, $torrentData, $language, $languageMapping, $type, $season, $seasonNoPad, $episode;

    $tSite = 'rutor_info';	

    $data = $response;

    try {
        // Perform pattern matching to extract torrent data
        preg_match_all('/(?<=<tr class="(gai|tum)">)[\s\S]*?(?=<\/tr>)/', $data, $matches);

        if (count($matches[0]) === 0) {
            throw new Exception("No links found on rutor_info."); // Throw a custom exception
        }
		$totalAdded = 0;
         // Loop through the 'matches' array
        for ($i = 0; $i < count($matches[0]); $i++) {
			
			// Extracted data from 'matches'
            $extractedData = $matches[0][$i];				
			
			// Replace '4K' or '4k' with '2160p' before extracting the resolution
			$extractedData = str_ireplace("4K", "2160p", $extractedData);

            // Apply the regex patterns to extract 'matchedRes' and 'matchedHash'
            preg_match('/(2160|1080|720|480|360|240)[pP]/i', $extractedData, $matchedRes);
            preg_match('/(?<=magnet:\?xt=urn:btih:)([A-F|a-z\d]{40})/', $extractedData, $matchedHash);
			if(preg_match('/<a[^>]*>([^<]*)<\/a>/', $extractedData, $matchedTitle)){

				$extractedTitle = html_entity_decode($matchedTitle[1]);
				
			} else {
				 continue;
			}	
			
			// If matchedRes wasn't found, set it to 480p
			if ($tvpack) {
				$matchedRes[0] = 'unknown';
			} else if (!$matchedRes) {
				$matchedRes[0] = '480p';
			}
			
            // Check if both 'matchedRes' and 'matchedHash' were found
            if (($matchedRes && $matchedRes[0] && $matchedHash && $matchedHash[0]) || ($tvpack === true && $matchedHash[0])){
/*                 if ($GLOBALS['DEBUG']) {
                    echo "matchedRes: " . $matchedRes[0] . "</br></br>";
                    echo "matchedHash: " . $matchedHash[0] . "</br></br>";
                } */
				if ($tvpack) {           
					$title = preg_replace('/s\d{2}e\d{2}/i', 'Season ' . $GLOBALS['seasonNoPad'], $GLOBALS['globalTitle']);
				} else {
					$title = $GLOBALS['globalTitle'];
				}
				if(filterCompareTitles($extractedTitle, $title, $tvpack)){
					
					$torrentData[] = [
						'title_long' => $title, 
						'hash' => $matchedHash[0], 
						'quality' => $matchedRes[0],
						'extracted_title' => $extractedTitle,						
						'tvpack' => $tvpack
					];				
					$totalAdded++;
				}
            }
        }

		if ($GLOBALS['DEBUG']) {
			echo 'Finished running rutor_info (' .  $totalAdded . ') </br></br>';
		}

        return $totalAdded;
    }
    catch (exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
            echo 'Finished running rutor_info </br></br>';
        }

        return false;
    }
}

function initialize_torrents_csv_com($movieId, $imdbId, $title, $year, $tvpack=false)
{	
    global $timeOut, $maxResolution, $torrentData, $language, $languageMapping, $type, $season, $seasonNoPad, $episode;

    $tSite = 'torrents-csv_com';
	
	if ($GLOBALS['DEBUG']) {
        echo 'Started running torrents-csv_com </br></br>';
    }
	
    $key = $movieId . 'torrents-csv_com';
	$cleanedTitle = preg_replace('/[^a-zA-Z0-9 ]/', '', $title);
	
	if($type == "movies"){	
			
		$searchQuery = urlencode($cleanedTitle . ' ' . $year);		
		$apiUrl = 'https://torrents-csv.com/service/search?q='. $searchQuery;
		
	} else {

		$searchQuery = urlencode($cleanedTitle);			
		$apiUrl = 'https://torrents-csv.com/service/search?q='. $searchQuery;
	}
	
	return $apiUrl;

}

function torrents_csv_com($response, $tvpack = false)
{
    global $timeOut, $maxResolution, $torrentData, $type, $season, $episode;
    $tSite = 'torrents-csv_com';

    try {
        if ($response === false) {
            throw new Exception('HTTP Error: torrents-csv_com');
        }

        $data = json_decode($response, true);

        if (!isset($data['torrents']) || !is_array($data['torrents'])) {
            return false;
        }

        $torrents = $data['torrents'];

        if ($type == 'series') {
            $filtered = array_filter($torrents, function ($ep) use ($season, $episode) {
                $seasonEpisodeStr = sprintf("S%02dE%02d", $season, $episode);
                $seasonStr = sprintf("Season %d", $season);
                return isset($ep['name']) && (strpos($ep['name'], $seasonEpisodeStr) !== false || strpos($ep['name'], $seasonStr) !== false);
            });

            if (empty($filtered)) {
                return false;
            }

            $torrents = $filtered;
        }

        if ($tvpack) {
            $title = preg_replace('/s\d{2}e\d{2}/i', 'Season ' . $GLOBALS['seasonNoPad'], $GLOBALS['globalTitle']);
        } else {
            $title = $GLOBALS['globalTitle'];
        }

        $totalAdded = 0;
        if (is_array($torrents) && !empty($torrents)) {
            foreach ($torrents as $torrentInfo) {
                $extractedTitle = $torrentInfo['name'];
                $matchedHash = $torrentInfo['infohash'];

                // Extract quality from the title using regex
                preg_match('/(2160|1080|720|480|360|240)[pP]/i', $extractedTitle, $matches);
                $quality = $tvpack ? 'unknown' : (isset($matches[1]) ? $matches[1] : '480');

                if (isset($matchedHash) && isset($extractedTitle)) {
                    if (filterCompareTitles($extractedTitle, $title, $tvpack)) {
                        $torrentData[] = [
                            'title_long' => $title,
                            'hash' => $matchedHash,
                            'quality' => $quality,
                            'extracted_title' => $extractedTitle,
                            'tvpack' => $tvpack
                        ];
                        $totalAdded++;
                    }
                }
            }
        } else {
            throw new Exception('Data does not meet criteria');
        }

        if ($GLOBALS['DEBUG']) {
            echo 'Finished running torrents-csv_com (' . $totalAdded . ') </br></br>';
        }
        return $totalAdded;

    } catch (Exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
            echo 'Finished running torrents-csv_com </br></br>';
        }

        return false;
    }
}

function initialize_knaben_eu($movieId, $imdbId, $title, $year, $tvpack=false)
{	
    global $timeOut, $maxResolution, $torrentData, $language, $languageMapping, $type, $season, $seasonNoPad, $episode;

    $tSite = 'knaben_eu';
	
	if ($GLOBALS['DEBUG']) {
        echo 'Started running knaben_eu </br></br>';
    }
	
    $key = $movieId . 'knaben_eu';
	
	if($type == "movies"){
		$searchQuery = urlencode($title . ' ' . $year);
		
		$apiUrl = 'https://knaben.eu/search/'. $searchQuery . '/3000000/1/seeders';
		
	} else {

		$searchQuery = urlencode($title);
			
		$apiUrl = 'https://knaben.eu/search/'. $searchQuery . '/2000000/1/seeders';	
	}
	
	return $apiUrl;

}

function knaben_eu($response, $tvpack=false)
{
    global $timeOut, $maxResolution, $torrentData, $language, $languageMapping, $type, $season, $seasonNoPad, $episode;

    $tSite = 'knaben_eu';	

    $data = $response;

    try {
        // Perform pattern matching to extract torrent data
        preg_match_all('/(?<=class="text-wrap w-100">)[\s\S]*?(?=<\/td>)/', $data, $matches);

        if (count($matches[0]) === 0) {
            throw new Exception("No links found on knaben_eu."); // Throw a custom exception
        }
		$totalAdded = 0;
         // Loop through the 'matches' array
        for ($i = 0; $i < count($matches[0]); $i++) {
			
			// Extracted data from 'matches'
            $extractedData = $matches[0][$i];				
			
			// Replace '4K' or '4k' with '2160p' before extracting the resolution
			$extractedData = str_ireplace("4K", "2160p", $extractedData);

            // Apply the regex patterns to extract 'matchedRes' and 'matchedHash'
            preg_match('/(2160|1080|720|480|360|240)[pP]/i', $extractedData, $matchedRes);
            preg_match('/(?<=magnet:\?xt=urn:btih:)([A-F|a-z\d]{40})/', $extractedData, $matchedHash);
			if(preg_match_all('/(?<=title=").*?(?=")/', $extractedData, $matchedTitle)){
				$extractedTitle = html_entity_decode($matchedTitle[0][0]);
				
			} else {
				 continue;
			}	
			
			// If matchedRes wasn't found, set it to 480p
			if ($tvpack) {
				$matchedRes[0] = 'unknown';
			} else if (!$matchedRes) {
				$matchedRes[0] = '480p';
			}
			
            // Check if both 'matchedRes' and 'matchedHash' were found
            if (($matchedRes && $matchedRes[0] && $matchedHash && $matchedHash[0]) || ($tvpack === true && $matchedHash[0])){
/*                 if ($GLOBALS['DEBUG']) {
                    echo "matchedRes: " . $matchedRes[0] . "</br></br>";
                    echo "matchedHash: " . $matchedHash[0] . "</br></br>";
                } */
				if ($tvpack) {           
					$title = preg_replace('/s\d{2}e\d{2}/i', 'Season ' . $GLOBALS['seasonNoPad'], $GLOBALS['globalTitle']);
				} else {
					$title = $GLOBALS['globalTitle'];
				}
				if(filterCompareTitles($extractedTitle, $title, $tvpack)){
					
					$torrentData[] = [
						'title_long' => $title, 
						'hash' => $matchedHash[0], 
						'quality' => $matchedRes[0],
						'extracted_title' => $extractedTitle,						
						'tvpack' => $tvpack
					];				
					$totalAdded++;
				}
            }
        }

		if ($GLOBALS['DEBUG']) {
			echo 'Finished running knaben_eu (' .  $totalAdded . ') </br></br>';
		}

        return $totalAdded;
    }
    catch (exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
            echo 'Finished running knaben_eu </br></br>';
        }

        return false;
    }
}

function initialize_torrentz2_nz($movieId, $imdbId, $title, $year, $tvpack=false)
{	
    global $timeOut, $maxResolution, $torrentData, $language, $languageMapping, $type, $season, $seasonNoPad, $episode;

    $tSite = 'torrentz2_nz';
	
	if ($GLOBALS['DEBUG']) {
        echo 'Started running torrentz2_nz </br></br>';
    }
	
    $key = $movieId . 'torrentz2_nz';
	
	if($type == "movies"){
		$searchQuery = preg_replace('/[^a-zA-Z0-9 ]/', '', $title . ' ' . $year);
		$searchQuery = str_replace(" ", "+", $searchQuery);
		
		$apiUrl = 'https://torrentz2.nz/search?q=' . $searchQuery;	
		
	} else {

		$searchQuery = preg_replace('/[^a-zA-Z0-9 ]/', '', $title);;
		$searchQuery = str_replace(" ", "+", $searchQuery);
			
		$apiUrl = 'https://torrentz2.nz/search?q=' . $searchQuery;	
	}
	
	return $apiUrl;

}

function torrentz2_nz($response, $tvpack=false)
{
    global $timeOut, $maxResolution, $torrentData, $language, $languageMapping, $type, $season, $seasonNoPad, $episode;
    $tSite = 'torrentz2_nz';	
    $data = $response;
    
    try {
        // If it uses the exact same layout as bitsearch.to, use the same approach
        // First decode HTML entities to handle encoded characters
        $data = html_entity_decode($data);
        
        // Extract all titles using the same pattern as bitsearch.to
        preg_match_all('/<a[^>]*href="\/torrent\/[^"]*"[^>]*class="[^"]*hover:text-primary[^"]*"[^>]*>\s*(.*?)\s*<\/a>/s', $data, $title_matches);
        
        // Extract all magnet hashes (after HTML decoding)
        preg_match_all('/magnet:\?xt=urn:btih:([A-Fa-f0-9]{40})/', $data, $hash_matches);
        
        if (count($title_matches[1]) === 0 || count($hash_matches[1]) === 0) {
            throw new Exception("No valid torrents found. Titles: " . count($title_matches[1]) . ", Hashes: " . count($hash_matches[1]));
        }
        
        $totalAdded = 0;
        $minCount = min(count($title_matches[1]), count($hash_matches[1]));
        
        for ($i = 0; $i < $minCount; $i++) {
            $extractedTitle = trim($title_matches[1][$i]);
            $magnetHash = $hash_matches[1][$i];
            
            if (empty($extractedTitle) || empty($magnetHash)) {
                continue;
            }
            
            // Replace '4K' or '4k' with '2160p' before extracting the resolution
            $processedTitle = str_ireplace("4K", "2160p", $extractedTitle);
            
            // Extract resolution
            preg_match('/(2160|1080|720|480|360|240)[pP]/i', $processedTitle, $matchedRes);
            
            // If matchedRes wasn't found, set it to 480p (or unknown for tvpacks)
            if ($tvpack) {
                $resolution = 'unknown';
            } elseif (!$matchedRes) {
                $resolution = '480p';
            } else {
                $resolution = $matchedRes[0];
            }
            
            if ($tvpack) {           
                $title = preg_replace('/s\d{2}e\d{2}/i', 'Season ' . $GLOBALS['seasonNoPad'], $GLOBALS['globalTitle']);
            } else {
                $title = $GLOBALS['globalTitle'];
            }
            
            if (filterCompareTitles($extractedTitle, $title, $tvpack)) {
                $torrentData[] = [
                    'title_long'      => $title, 
                    'hash'            => $magnetHash, 
                    'quality'         => $resolution,
                    'extracted_title' => $extractedTitle,						
                    'tvpack'          => $tvpack
                ];				
                $totalAdded++;
            }
        }
        
        if ($GLOBALS['DEBUG']) {
            echo 'Finished running torrentz2_nz (' .  $totalAdded . ') </br></br>';
        }
        return $totalAdded;
        
    } catch (Exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
            echo 'Finished running torrentz2_nz </br></br>';
        }
        return false;
    }
}

function initialize_limetorrents_cc($movieId, $imdbId, $title, $year, $tvpack=false)
{	
    global $timeOut, $maxResolution, $torrentData, $language, $languageMapping, $type, $season, $seasonNoPad, $episode;

    $tSite = 'limetorrents_cc';
	
	if ($GLOBALS['DEBUG']) {
        echo 'Started running limetorrents_cc </br></br>';
    }
	
    $key = $movieId . 'limetorrents_cc';
	
	if($type == "movies"){
		$searchQuery = preg_replace('/[^a-zA-Z0-9 ]/', '', $title . ' ' . $year);
		$searchQuery = str_replace(" ", "-", $searchQuery);
		
		$apiUrl = 'https://www.limetorrents.lol/search/movies/' . $searchQuery;	
		
	} else {

		$searchQuery = preg_replace('/[^a-zA-Z0-9 ]/', '', $title);;
		$searchQuery = str_replace(" ", "-", $searchQuery);
			
		$apiUrl = 'https://www.limetorrents.lol/search/tv/' . $searchQuery;	
	}
	
	return $apiUrl;

}

function limetorrents_cc($response, $tvpack=false)
{
    global $timeOut, $maxResolution, $torrentData, $language, $languageMapping, $type, $season, $seasonNoPad, $episode;

    $tSite = 'limetorrents_cc';	

    $data = $response;
	
    try {
        // Perform pattern matching to extract torrent data
        preg_match_all('/(?<=tdleft)[\s\S]*?(?=<\/td>)/', $data, $matches);

        if (count($matches[0]) === 0) {
            throw new Exception("No links found on limetorrents_cc."); // Throw a custom exception
        }
		$totalAdded = 0;
         // Loop through the 'matches' array
        for ($i = 0; $i < count($matches[0]); $i++) {
			
			// Extracted data from 'matches'
            $extractedData = $matches[0][$i];				
			
			// Replace '4K' or '4k' with '2160p' before extracting the resolution
			$extractedData = str_ireplace("4K", "2160p", $extractedData);

            // Apply the regex patterns to extract 'matchedRes' and 'matchedHash'
            preg_match('/(2160|1080|720|480|360|240)[pP]/i', $extractedData, $matchedRes);	
            preg_match('/(?<=\/torrent\/)([A-F|a-z\d]{40})/', $extractedData, $matchedHash);	
		
			if(preg_match_all('/(?<=title=).*?(?=")/', $extractedData, $matchedTitle)){	
			
				$extractedTitle = html_entity_decode(strip_tags($matchedTitle[0][0]));
				
			} else {
				 continue;
			}	
			
			// If matchedRes wasn't found, set it to 480p
			if ($tvpack) {
				$matchedRes[0] = 'unknown';
			} else if (!$matchedRes) {
				$matchedRes[0] = '480p';
			}
			
            // Check if both 'matchedRes' and 'matchedHash' were found
            if (($matchedRes && $matchedRes[0] && $matchedHash && $matchedHash[0]) || ($tvpack === true && $matchedHash[0])){
/*                 if ($GLOBALS['DEBUG']) {
                    echo "matchedRes: " . $matchedRes[0] . "</br></br>";
                    echo "matchedHash: " . $matchedHash[0] . "</br></br>";
                } */
				if ($tvpack) {           
					$title = preg_replace('/s\d{2}e\d{2}/i', 'Season ' . $GLOBALS['seasonNoPad'], $GLOBALS['globalTitle']);
				} else {
					$title = $GLOBALS['globalTitle'];
				}
				if(filterCompareTitles($extractedTitle, $title, $tvpack)){
					
					$torrentData[] = [
						'title_long' => $title, 
						'hash' => $matchedHash[0], 
						'quality' => $matchedRes[0],
						'extracted_title' => $extractedTitle,						
						'tvpack' => $tvpack
					];				
					$totalAdded++;
				}
            }
        }

		if ($GLOBALS['DEBUG']) {
			echo 'Finished running limetorrents_cc (' .  $totalAdded . ') </br></br>';
		}

        return $totalAdded;
    }
    catch (exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
            echo 'Finished running limetorrents_cc </br></br>';
        }

        return false;
    }
}

function initialize_thepiratebay_org($movieId, $imdbId, $title, $year, $tvpack=false)
{
    global $timeOut, $maxResolution, $torrentData, $type, $season, $episode, $seasonNoPad;
    $tSite = 'thepiratebay_org';

    if ($GLOBALS['DEBUG']) {
        echo 'Started running thepiratebay_org </br></br>';
    }
	
    $base_url = "https://apibay.org";
  
    if ($type == 'movies') {
        $apiUrl = $base_url . '/q.php?q=' . urlencode($title . ' ' . $year) . '&cat=207,202,201';
    } else {
        $apiUrl = $base_url . '/q.php?q=' . urlencode($title) . '&cat=208,205';
    }

    return $apiUrl;
}

function thepiratebay_org($response, $tvpack=false)
{
    global $timeOut, $maxResolution, $torrentData, $language, $languageMapping, $type, $season, $episode;

    $tSite = 'thepiratebay_org';


try {

    if ($response === false) {
        throw new Exception('HTTP Error: thepiratebay');
    }

}
catch (exception $error) {
    if ($GLOBALS['DEBUG']) {
        echo 'Error: ' . $error->getMessage() . "</br></br>";
    }

    return false;
}

$data = json_decode($response, true);

try {		
	
	$totalAdded = 0;
    foreach ($data as $torrent) {
		
		$extractedTitle = $torrent['name'];
        $matchedRes = $torrent['name'];
        $matchedHash = $torrent['info_hash'];
		
        
        preg_match('/(2160|1080|720|480|360|240)[pP]/i', $matchedRes, $resolution);
		
		// If resolution wasn't found, set it to 480p
		if (!$resolution) {
			$resolution[0] = '480p';
		}

        if (isset($resolution[0])) {
/*             if ($GLOBALS['DEBUG']) {
                echo "matchedRes: " . $resolution[0] . "</br></br>";
                echo "matchedHash: " . $matchedHash . "</br></br>";
            } */
			if ($tvpack) {           
				$title = preg_replace('/s\d{2}e\d{2}/i', 'Season ' . $GLOBALS['seasonNoPad'], $GLOBALS['globalTitle']);
			} else {
				$title = $GLOBALS['globalTitle'];
			}
			if(filterCompareTitles($extractedTitle, $title, $tvpack)){			
				$torrentData[] = [
					'title_long' => $title, 
					'hash' => $matchedHash, 
					'quality' => $resolution[0],
					'extracted_title' => $extractedTitle,				
					'tvpack' => $tvpack
				];	
						
				$totalAdded++;
			}
        }
    }
	if ($GLOBALS['DEBUG']) {
		echo 'Finished running thepiratebay_org (' .  $totalAdded . ') </br></br>';
	}	
    return $totalAdded;
}
catch (exception $error) {
    if ($GLOBALS['DEBUG']) {
        echo 'Error: ' . $error->getMessage() . "</br></br>";
    }

    return false;
}



}

function initialize_popcornTime($movieId, $imdbId, $title, $tvpack=false)
{
    global $timeOut, $maxResolution, $torrentData, $type, $season, $episode;
    $tSite = 'popcornTime';

    if ($GLOBALS['DEBUG']) {
        echo 'Started running popcornTime </br></br>';
    }

	 if ($type == 'movies'){
		$apiUrl = 'https://yrkde.link/movie/' . $imdbId;		
	 } else {
		$apiUrl = 'https://yrkde.link/show/' . $imdbId;
	 }

	 return $apiUrl;
}	 

function popcornTime($response, $tvpack=false)
{
    global $timeOut, $maxResolution, $torrentData, $type, $season, $episode;
    $tSite = 'popcornTime';

    try {

        if ($response === false) {
            throw new Exception('HTTP Error: popcornTime');
        }

        $data = json_decode($response, true);

		if ($type == 'series'){
			//Run for series.
			if (!isset($data['episodes']) || !is_array($data['episodes'])) {
				throw new Exception('Couldn\'t locate episodes');		
				
			}
			
			$filtered = array_filter($data['episodes'], function ($ep) use ($season, $episode) {
				$hasAllKeys = isset($ep['season'], $ep['episode'], $ep['torrents']);
				return $hasAllKeys && $ep['season'] == $season && $ep['episode'] == $episode;
			});			
		
			if (empty($filtered) || !isset(current($filtered)['torrents'])) {
				throw new Exception('No matching episodes found or torrents are missing.');
			}

		} else {

			//Run for movies.
			if (!isset($data['torrents'])) {
				throw new Exception('Data does not meet criteria');
			}

	/*         if ($GLOBALS['DEBUG']) {
				echo 'The Json Response: ' . print_r($response) . '</br></br>';
			} */
					
			if (isset($data['torrents']['en'])) {
				$torrents = $data['torrents']['en'];
				
			} else {
				
				if ($GLOBALS['DEBUG']) {
					echo 'Couldn\'t locate any torrents on popcornTime. </br></br>';
				}

				return false;
			}
		}
		if ($tvpack) {           
			$title = preg_replace('/s\d{2}e\d{2}/i', 'Season ' . $GLOBALS['seasonNoPad'], $GLOBALS['globalTitle']);
		} else {
			$title = $GLOBALS['globalTitle'];
		}
		
		if ($type == 'series'){
			
			$totalAdded = 0;			
			$filtered = array_filter($data['episodes'], function ($ep) use ($season, $episode) {
				$hasAllKeys = isset($ep['season'], $ep['episode'], $ep['torrents']);
				return $hasAllKeys && $ep['season'] == $season && $ep['episode'] == $episode;
			});

			if (empty($filtered) || !isset(current($filtered)['torrents'])) {
				throw new Exception('No matching episodes or torrents found');
			}

			$torrents = current($filtered)['torrents'];

			if (isset($torrents) && is_array($torrents) && !empty($torrents)) {
				foreach ($torrents as $resolutionKey => $torrentInfo) {
					$extractedTitle = $torrentInfo['title'] . ' (' . $data['year'] . ')';

					preg_match('/(?<=btih:)([A-F|a-z\d]{40})/', $torrentInfo['url'], $matchedHash);

					if (isset($matchedHash[0]) && isset($extractedTitle)) {
						if (filterCompareTitles($extractedTitle, $title, $tvpack)) {
							$torrentData[] = [
								'title_long' => $title,
								'hash' => $matchedHash[0],
								'quality' => $resolutionKey,
								'extracted_title' => $extractedTitle,
								'tvpack' => $tvpack
							];
							$totalAdded++;
						}
					}
				}
			} else {
				throw new Exception('Data does not meet criteria');
			}
			
		} else {

			$totalAdded = 0;
			if (isset($torrents) && is_array($torrents) && !empty($torrents)) {
				foreach ($torrents as $resolutionKey => $torrentInfo) {
					
					$extractedTitle = $torrentInfo['title'].' ('.$data['year'].')';	
					
					preg_match('/(?<=btih:)([A-F|a-z\d]{40})/', $torrentInfo['url'], $matchedHash);				

					if (isset($matchedHash[0]) && isset($extractedTitle)) {
						if(filterCompareTitles($extractedTitle, $title, $tvpack)){
							$torrentData[] = [
								'title_long' => $title,
								'hash' => $matchedHash[0],
								'quality' => $resolutionKey,
								'extracted_title' => $extractedTitle,								
								'tvpack' => $tvpack
							];
							$totalAdded++;
						}
					}
				}
			} else {
				throw new Exception('Data does not meet criteria');
			}
		
		}
/*      if ($GLOBALS['DEBUG']) {
            echo 'Torrents: ' . json_encode($torrentData) . "</br></br>";
        }*/
		
        if ($GLOBALS['DEBUG']) {
            echo 'Finished running popcornTime (' .  $totalAdded . ') </br></br>';
        }
        return $totalAdded;

    }
    catch (exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
            echo 'Finished running popcornTime </br></br>';
        }
	
        return false;
    }
}

function initialize_torrentGalaxy_to($movieId, $imdbId, $title, $tvpack=false)
{
    global $timeOut, $maxResolution, $torrentData, $language, $languageMapping, $type, $season, $seasonNoPad, $episode;

    $tSite = 'Torrent Galaxy';
	
	if ($GLOBALS['DEBUG']) {
        echo 'Started running torrentGalaxy_to </br></br>';
    }
	
    $key = $movieId . '_torrentGalaxy_to';
	if ($type == 'movies'){
		$searchQuery = $imdbId;
	} else {

		$searchQuery = preg_replace('/[^a-zA-Z0-9 ]/', '', $title);
	}
	
    $siteLanguage = $languageMapping['TorrentGalaxy'][$language] ?? null;	

	$apiUrl = 'https://torrentgalaxy.to/torrents.php?search=' . urlencode($searchQuery) . '&nox=2';	

	if ($siteLanguage !== null) {
		$apiUrl .= '&lang=' . $siteLanguage;
	}	
	if ($tvpack) {
		$apiUrl .= '&c6=1';
	}
	return $apiUrl;
}	

function torrentGalaxy_to($response, $tvpack=false)
{
    global $timeOut, $maxResolution, $torrentData, $language, $languageMapping, $type, $season, $episode;

    $tSite = 'Torrent Galaxy';	

    try {

        if ($response === false) {
            throw new Exception('HTTP Error: torrentGalaxy_to');
        }


    }
    catch (exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
        }

        return false;
    }

    $data = $response;
	

    try {
        // Perform pattern matching to extract torrent data
        preg_match_all('/(?<=tgxtablerow)[\s\S]*?(?=<\/table>)/', $data, $matches);

        if (count($matches[0]) === 0) {
            throw new Exception("No links found on torrentGalaxy_to."); // Throw a custom exception
        }

		$totalAdded = 0;
        // Loop through the 'matches' array
        for ($i = 0; $i < count($matches[0]); $i++) {

            // Extracted data from 'matches'
            $extractedData = $matches[0][$i];

			// Replace '4K' or '4k' with '2160p' before extracting the resolution
			$extractedData = str_ireplace("4K", "2160p", $extractedData);

            // Apply the regex patterns to extract 'matchedRes' and 'matchedHash'
            preg_match('/(2160|1080|720|480|360|240)[pP]/i', $extractedData, $matchedRes);
            preg_match('/(?<=urn:btih:)([A-F|a-z\d]{40})/', $extractedData, $matchedHash);
			if(preg_match_all('/(?<=title=").*?(?=")/', $extractedData, $matchedTitle)){
				$extractedTitle = html_entity_decode($matchedTitle[0][0]);
			} else {
				 continue;
			}	
			
			// If matchedRes wasn't found, set it to 480p
			if (!$matchedRes) {
				$matchedRes[0] = '480p';
			}
			
            // Check if both 'matchedRes' and 'matchedHash' were found
            if ($matchedRes && $matchedRes[0] && $matchedHash && $matchedHash[0]){
/*                 if ($GLOBALS['DEBUG']) {
                    echo "matchedRes: " . $matchedRes[0] . "</br></br>";
                    echo "matchedHash: " . $matchedHash[0] . "</br></br>";
                } */
				if ($tvpack) {           
					$title = preg_replace('/s\d{2}e\d{2}/i', 'Season ' . $GLOBALS['seasonNoPad'], $GLOBALS['globalTitle']);
				} else {
					$title = $GLOBALS['globalTitle'];
				}
				if(filterCompareTitles($extractedTitle, $title, $tvpack)){				
					$torrentData[] = [
						'title_long' => $title, 
						'hash' => $matchedHash[0], 
						'quality' => $matchedRes[0],
						'extracted_title' => $extractedTitle,						
						'tvpack' => $tvpack
					];	
					$totalAdded++;
				}
            }
        }
	    if ($GLOBALS['DEBUG']) {
            echo 'Finished running torrentGalaxy_to (' .  $totalAdded . ') </br></br>';
        }

        return $totalAdded;
    }
    catch (exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
            echo 'Finished running torrentGalaxy_to </br></br>';
        }

        return false;
    }
}

function initialize_glodls_to($movieId, $imdbId, $title, $year, $tvpack=false)
{
    global $timeOut, $maxResolution, $torrentData, $language, $languageMapping, $type, $season, $seasonNoPad, $episode;

    $tSite = 'glodls_to';
	
	if($type == "movies"){
		$searchQuery = preg_replace('/[^a-zA-Z0-9 ]/', '', $title . ' ' . $year);
	} else {

		$searchQuery = preg_replace('/[^a-zA-Z0-9 ]/', '', $title);	
	}

	if ($GLOBALS['DEBUG']) {
        echo 'Started running glodls_to </br></br>';
    }
	
    $key = $movieId . '_glodls_to';
	
    $siteLanguage = $languageMapping['Glodls'][$language] ?? null;	

	$apiUrl = 'https://glodls.to/search_results.php?search=' . urlencode($searchQuery) . '&incldead=0&inclexternal=0&sort=id&order=desc';	
	

	if ($siteLanguage !== null) {
		$apiUrl .= '&lang=' . $siteLanguage;
	}
	if ($type == 'series') {
			
		$apiUrl .= '&cat=41';
	} elseif ($type == 'movies') {
			
		$apiUrl .= '&c52=1';
	}
	
	return $apiUrl;
}	

function glodls_to($response, $tvpack=false)
{
    global $timeOut, $maxResolution, $torrentData, $language, $languageMapping, $type, $season, $seasonNoPad, $episode;

    $tSite = 'glodls_to';	

    $data = $response;

    try {
        // Perform pattern matching to extract torrent data
        preg_match_all('/(?<=ttable_col1)[\s\S]*?(?=<\/tr>)/', $data, $matches);

        if (count($matches[0]) === 0) {
            throw new Exception("No links found on glodls_to."); // Throw a custom exception
        }
		$totalAdded = 0;
         // Loop through the 'matches' array
        for ($i = 0; $i < count($matches[0]); $i++) {
			
			// Extracted data from 'matches'
            $extractedData = $matches[0][$i];				
			
			// Replace '4K' or '4k' with '2160p' before extracting the resolution
			$extractedData = str_ireplace("4K", "2160p", $extractedData);

            // Apply the regex patterns to extract 'matchedRes' and 'matchedHash'
            preg_match('/(2160|1080|720|480|360|240)[pP]/i', $extractedData, $matchedRes);
            preg_match('/(?<=urn:btih:)([A-F|a-z\d]{40})/', $extractedData, $matchedHash);
			if(preg_match_all('/(?<=title=").*?(?=")/', $extractedData, $matchedTitle)){
				$extractedTitle = html_entity_decode($matchedTitle[0][0]);
				
			} else {
				 continue;
			}	
			
			// If matchedRes wasn't found, set it to 480p
			if ($tvpack) {
				$matchedRes[0] = 'unknown';
			} else if (!$matchedRes) {
				$matchedRes[0] = '480p';
			}
			
            // Check if both 'matchedRes' and 'matchedHash' were found
            if (($matchedRes && $matchedRes[0] && $matchedHash && $matchedHash[0]) || ($tvpack === true && $matchedHash[0])){
/*                 if ($GLOBALS['DEBUG']) {
                    echo "matchedRes: " . $matchedRes[0] . "</br></br>";
                    echo "matchedHash: " . $matchedHash[0] . "</br></br>";
                } */
				if ($tvpack) {           
					$title = preg_replace('/s\d{2}e\d{2}/i', 'Season ' . $GLOBALS['seasonNoPad'], $GLOBALS['globalTitle']);
				} else {
					$title = $GLOBALS['globalTitle'];
				}
				if(filterCompareTitles($extractedTitle, $title, $tvpack)){
					
					$torrentData[] = [
						'title_long' => $title, 
						'hash' => $matchedHash[0], 
						'quality' => $matchedRes[0],
						'extracted_title' => $extractedTitle,						
						'tvpack' => $tvpack
					];				
					$totalAdded++;
				}
            }
        }

		if ($GLOBALS['DEBUG']) {
			echo 'Finished running glodls_to (' .  $totalAdded . ') </br></br>';
		}

        return $totalAdded;
    }
    catch (exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
            echo 'Finished running glodls_to </br></br>';
        }

        return false;
    }
}

function initialize_MagnetDL_com($movieId, $imdbId, $title, $year, $tvpack=false)
{	
    global $timeOut, $maxResolution, $torrentData, $language, $languageMapping, $type, $season, $seasonNoPad, $episode;

    $tSite = 'magnetdl_com';
	
	if ($GLOBALS['DEBUG']) {
        echo 'Started running magnetdl_com </br></br>';
    }
	
    $key = $movieId . '_magnetdl_com';
	
	$titleNoPeriods = $title;
	$titleNoPeriods = str_replace('.', ' ', $titleNoPeriods);
	if($type == "movies"){
		$searchQuery = $title . ' (' . $year . ')';
	} else {
		$searchQuery = $title;
	}
	
	$apiUrl = 'https://www.magnetdl.com/search/?q=' . urlencode($searchQuery);	

	if ($type == 'series') {
			
		$apiUrl .= '&m=1&x=35&y=17';
	} elseif ($type == 'movies') {
			
		$apiUrl .= '&m=1&x=38&y=19';
	}
	
	return $apiUrl;
}	

function magnetdl_com($response, $tvpack=false)
{
    global $timeOut, $maxResolution, $torrentData, $language, $languageMapping, $type, $season, $seasonNoPad, $episode;

    $tSite = 'magnetdl_com';	
	
    try {

        if ($response === false) {
            throw new Exception('HTTP Error: magnetdl_com');
        }


    }
    catch (exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
        }

        return false;
    }

	$data = $response;
    try {
        // Perform pattern matching to extract torrent data
        preg_match_all('/(?<=class="m")[\s\S]*?(?=<\/tr>)/', $data, $matches);

        if (count($matches[0]) === 0) {
            throw new Exception("No links found on magnetdl_com."); // Throw a custom exception
        }
		$totalAdded = 0;
        // Loop through the 'matches' array
        for ($i = 0; $i < count($matches[0]); $i++) {

            // Extracted data from 'matches'
            $extractedData = $matches[0][$i];
			
			// Replace '4K' or '4k' with '2160p' before extracting the resolution
			$extractedData = str_ireplace("4K", "2160p", $extractedData);

            // Apply the regex patterns to extract 'matchedRes' and 'matchedHash'
			preg_match('/(2160|1080|720|480|360|240)[pP]/i', $extractedData, $matchedRes);
            preg_match('/(?<=urn:btih:)([A-F|a-z\d]{40})/', $extractedData, $matchedHash); 
			preg_match_all('/(?<=title=").*?(?=">)/', $extractedData, $matchedTitle); 			
			
			// If matchedRes wasn't found, set it to 480p
			if (!$matchedRes) {
				$matchedRes[0] = '480p';
			}		
			
            // Check if both 'matchedRes' and 'matchedHash' were found
            if ($matchedRes && $matchedRes[0] && $matchedHash && $matchedHash[0] && $matchedTitle[0][1]){
				$extractedTitle = html_entity_decode($matchedTitle[0][1]);
				
/*                 if ($GLOBALS['DEBUG']) {
                    echo "matchedRes: " . $matchedRes[0] . "</br></br>";
                    echo "matchedHash: " . $matchedHash[0] . "</br></br>";
                } */
                
				if ($tvpack) {           
					$title = preg_replace('/s\d{2}e\d{2}/i', 'Season ' . $GLOBALS['seasonNoPad'], $GLOBALS['globalTitle']);
				} else {
					$title = $GLOBALS['globalTitle'];
				}	

				if(filterCompareTitles($extractedTitle, $title, $tvpack)){
					$torrentData[] = [
						'title_long' => $title, 
						'hash' => $matchedHash[0], 
						'quality' => $matchedRes[0],
						'extracted_title' => $extractedTitle,			
						'tvpack' => $tvpack
					];
					
					$totalAdded++;
				}
            }
        }			

		if ($GLOBALS['DEBUG']) {
			echo 'Finished running magnetdl_com (' .  $totalAdded . ') </br></br>';
		}
        return $totalAdded;
    }
    catch (exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
            echo 'Finished running magnetdl_com </br></br>';
        }

        return false;
    }
}

function initialize_torrentDownload_info($movieId, $imdbId, $title, $year, $tvpack=false)
{
    global $timeOut, $maxResolution, $torrentData, $language, $languageMapping, $type, $season, $seasonNoPad, $episode;

    $tSite = 'torrentDownload_info';
	
	if ($GLOBALS['DEBUG']) {
        echo 'Started running torrentDownload_info </br></br>';
    }
	
    $key = $movieId . '_torrentDownload_info';
	
	if($type == "movies"){
		$searchQuery = preg_replace('/[^a-zA-Z0-9 ]/', '', $title . ' ' . $year);
	} else {

		$searchQuery = preg_replace('/[^a-zA-Z0-9 ]/', '', $title);
	}

	$apiUrl = 'https://www.torrentdownload.info/search?q=' . urlencode($searchQuery);	
	
	return $apiUrl;
}

function torrentDownload_info($response, $tvpack=false)
{
    global $timeOut, $maxResolution, $torrentData, $language, $languageMapping, $type, $season, $episode;

    $tSite = 'torrentDownload_info';
	
    try {

        if ($response === false) {
            throw new Exception('HTTP Error: torrentDownload_info');
        }


    }
    catch (exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
        }

        return false;
    }

    $data = $response;
	

    try {
        // Perform pattern matching to extract torrent data
        preg_match_all('/(?<=<td class="tdleft">)[\s\S]*?(?=<\/tr>)/', $data, $matches);

        if (count($matches[0]) === 0) {
            throw new Exception("No links found on torrentDownload_info."); // Throw a custom exception
        }

		$totalAdded = 0;
        // Loop through the 'matches' array
        for ($i = 3; $i < count($matches[0]); $i++) {

            // Extracted data from 'matches'
            $extractedData = $matches[0][$i];
			
			// Replace '4K' or '4k' with '2160p' before extracting the resolution
			$extractedData = str_ireplace("4K", "2160p", $extractedData);

            // Apply the regex patterns to extract 'matchedRes' and 'matchedHash'
            preg_match('/(2160|1080|720|480|360|240)[pP]/i', $extractedData, $matchedRes);
            preg_match('/(?<="\/)([A-F|a-z\d]{40})(?=\/)/', $extractedData, $matchedHash);
			$extractedTitle = strip_tags($extractedData);
			$extractedTitle = html_entity_decode($extractedTitle);
			$extractedTitle = preg_replace('/\s».*$/', '', $extractedTitle);

			
			// If matchedRes wasn't found, set it to 480p
			if (!$matchedRes) {
				$matchedRes[0] = '480p';
			}	
			
            // Check if both 'matchedRes' and 'matchedHash' were found
            if ($matchedRes && $matchedRes[0] && $matchedHash && $matchedHash[0] && $extractedTitle){
/*                 if ($GLOBALS['DEBUG']) {
                    echo "matchedRes: " . $matchedRes[0] . "</br></br>";
                    echo "matchedHash: " . $matchedHash[0] . "</br></br>";
                } */
				if ($tvpack) {           
					$title = preg_replace('/s\d{2}e\d{2}/i', 'Season ' . $GLOBALS['seasonNoPad'], $GLOBALS['globalTitle']);
				} else {
					$title = $GLOBALS['globalTitle'];
				}	
				if(filterCompareTitles($extractedTitle, $title, $tvpack)){	
					$torrentData[] = [
						'title_long' => $title, 
						'hash' => $matchedHash[0], 
						'quality' => $matchedRes[0],
						'extracted_title' => $extractedTitle,						
						'tvpack' => $tvpack
					];				
					$totalAdded++;
				}
            }
        }
		if ($GLOBALS['DEBUG']) {
			echo 'Finished running torrentDownload_info (' .  $totalAdded . ') </br></br>';
		}	
        return $totalAdded;
    }
    catch (exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
            echo 'Finished running torrentDownload_info </br></br>';
        }

        return false;
    }
}

function initialize_bitLordSearch_com($movieId, $imdbId, $title, $year, $tvpack=false)
{
	global $timeOut, $maxResolution, $torrentData, $type, $season, $seasonNoPad, $episode;
	$tSite = 'bitLordSearch_com';

	if ($GLOBALS['DEBUG']) {
		echo 'Started running bitLordSearch_com </br></br>';
	}

	 $apiUrl = "https://bitlordsearch.com/search";
	 if ($type == 'movies'){
		$apiUrl .= '?q='.urlencode($title . ' (' . $year . ')').'&offset=0&limit=50&filters[field]=seeds&filters[sort]=desc&filters[time]=0&filters[category]=3&filters[adult]=false&filters[risky]=false';		
	 } else {
		$apiUrl .= '?q='.urlencode($title).'&offset=0&limit=50&filters[field]=seeds&filters[sort]=desc&filters[time]=0&filters[category]=4&filters[adult]=false&filters[risky]=false';
	 }
	 
	 return $apiUrl;		 
}		 

function bitLordSearch_com($response, $tvpack=false)
{
    global $timeOut, $maxResolution, $torrentData, $type, $season, $episode;
    $tSite = 'bitLordSearch_com';

    try {		
		
		if ($response === false) {
            throw new Exception('HTTP Error: bitLordSearch');
        }
		
	} catch (exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
        }

        return false;
    }
		
    $data = $response;
	

    try {
        // Perform pattern matching to extract torrent data
        preg_match_all('/(?<=<tr class="bls-row")[\s\S]*?(?=<\/tr>)/', $data, $matches);

        if (count($matches[0]) === 0) {
            throw new Exception("No links found on bitLordSearch_com."); // Throw a custom exception
        }

		$totalAdded = 0;
        // Loop through the 'matches' array
        for ($i = 0; $i < count($matches[0]); $i++) {

            // Extracted data from 'matches'
            $extractedData = $matches[0][$i];

			// Replace '4K' or '4k' with '2160p' before extracting the resolution
			$extractedData = str_ireplace("4K", "2160p", $extractedData);
			
            // Apply the regex patterns to extract 'matchedRes' and 'matchedHash'
            preg_match('/(2160|1080|720|480|360|240)[pP]/i', $extractedData, $matchedRes);
            preg_match('/(?<=urn:btih:)([A-F|a-z\d]{40})/', $extractedData, $matchedHash);
			if(preg_match_all('/(?<="title">).*?(?=<)/', $extractedData, $matchedTitle)){
				$extractedTitle = html_entity_decode($matchedTitle[0][0]);
			} else {
				 continue;
			}
			
			// If matchedRes wasn't found, set it to 480p
			if (!$matchedRes) {
				$matchedRes[0] = '480p';
			}
			
            // Check if both 'matchedRes' and 'matchedHash' were found
            if ($matchedRes && $matchedRes[0] && $matchedHash && $matchedHash[0]){
/*                 if ($GLOBALS['DEBUG']) {
                    echo "matchedRes: " . $matchedRes[0] . "</br></br>";
                    echo "matchedHash: " . $matchedHash[0] . "</br></br>";
                } */				
				if ($tvpack) {           
					$title = preg_replace('/s\d{2}e\d{2}/i', 'Season ' . $GLOBALS['seasonNoPad'], $GLOBALS['globalTitle']);
				} else {
					$title = $GLOBALS['globalTitle'];
				}	
				if(filterCompareTitles($extractedTitle, $title, $tvpack)){				
					$torrentData[] = [
						'title_long' => $title, 
						'hash' => $matchedHash[0], 
						'quality' => $matchedRes[0],
						'extracted_title' => $extractedTitle,						
						'tvpack' => $tvpack
					];	
					$totalAdded++;
				}
            }
        }
		if ($GLOBALS['DEBUG']) {
            echo 'Finished running bitLordSearch_com (' .  $totalAdded . ') </br></br>';
        }
        return $totalAdded;
    }
    catch (exception $error) {
        if ($GLOBALS['DEBUG']) {
			echo 'Error: ' . $error->getMessage() . "</br></br>";
            echo 'Finished running bitLordSearch_com </br></br>';
        }
        return false;
    }		
}

function initialize_ezTV_re($movieId, $imdbId, $title, $tvpack=false)
{
    global $timeOut, $maxResolution, $torrentData, $language, $languageMapping, $type, $season, $seasonNoPad, $episode;

    $tSite = 'EZTV';
	
	if ($GLOBALS['DEBUG']) {
        echo 'Started running ezTV_re </br></br>';
    }
	
    $key = $movieId . '_ezTV_re';	
	
	$stripImdbId = str_replace("tt", "", $imdbId);
		
	$apiUrl = 'https://eztvx.to/api/get-torrents?imdb_id='.$stripImdbId.'&limit=100';

	return $apiUrl;

}

function ezTV_re($response, $tvpack=false)
{
    global $timeOut, $maxResolution, $torrentData, $type, $season, $episode;
    $tSite = 'ezTV_re';
    $totalAdded = 0;

    try {
        if ($response === false) {
            throw new Exception('HTTP Error: ezTV_re');
        }

        $data = json_decode($response, true);
		

        if (isset($data['torrents']) && is_array($data['torrents'])) {
			
			if(!$tvpack){
				$filtered = array_filter($data['torrents'], function ($torrent) use ($season, $episode) {
					return isset($torrent['season']) && $torrent['season'] == $season && isset($torrent['episode']) && $torrent['episode'] == $episode;
				});
			} else {
				$filtered = $data['torrents'];
			}	

            if (!empty($filtered)) {
                foreach ($filtered as $torrentInfo) {
                    $title = $GLOBALS['globalTitle'];

                    if ($tvpack) {
                        $title = preg_replace('/s\d{2}e\d{2}/i', 'Season ' . $GLOBALS['seasonNoPad'], $title);
                    }

                    $extractedTitle = $torrentInfo['title'];
                    preg_match('/(?<=urn:btih:)([A-Fa-f\d]{40})/', $torrentInfo['magnet_url'], $matchedHash);
                    preg_match('/(2160|1080|720|480|360|240)[pP]/i', $extractedTitle, $matchedRes);

                    if (!$matchedRes) {
                        $matchedRes[0] = '480p';
                    }

                    if (isset($matchedHash[0]) && isset($extractedTitle)) {
                        if (filterCompareTitles($extractedTitle, $title, $tvpack)) {
                            $torrentData[] = [
                                'title_long' => $title,
                                'hash' => $matchedHash[0],
                                'quality' => $matchedRes[0],
                                'extracted_title' => $extractedTitle,
                                'tvpack' => $tvpack
                            ];
                            $totalAdded++;
                        }
                    }
                }

            } else {
                throw new Exception('No matching torrents found');
            }
        } else {
            throw new Exception('Data does not meet criteria');
        }

        if ($GLOBALS['DEBUG']) {
            echo 'Finished running ezTV_re (' .  $totalAdded . ') </br></br>';
        }
        return $totalAdded;

    } catch (exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
            echo 'Finished running ezTV_re </br></br>';
        }
    
        return false;
    }
}

function initialize_yts_mx($movieId, $imdbId, $title)
{
    global $timeOut, $maxResolution, $torrentData;
    $tSite = 'Yts';

    if ($GLOBALS['DEBUG']) {
        echo 'Started running yts_mx </br></br>';
    }

    $apiUrl = 'https://yts.mx/api/v2/list_movies.json?query_term=' . $imdbId .
        '&sort_by=seeds&order_by=desc';
		
	return $apiUrl;
}

function yts_mx($response)
{
    global $timeOut, $maxResolution, $torrentData;
    $tSite = 'Yts';

    try {

        if ($response === false) {
            throw new Exception('HTTP Error: yts_mx');
        }

        $data = json_decode($response, true);

        if (!isset($data['status']) || $data['status'] !== 'ok' || !isset($data['data']) ||
            !isset($data['data']['movies']) || count($data['data']['movies']) === 0) {
            throw new Exception('Data does not meet criteria');
        }


    }
    catch (exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Finished running yts_mx </br></br>';
            echo 'Error: ' . $error->getMessage() . "</br></br>";
        }
        return false;
    }


	try {
		$movie = $data['data']['movies'][0];
		$torrents = $movie['torrents'];
		$totalAdded = 0;
		// Loop through each torrent entry
		foreach ($torrents as $torrent) {
			if (!isset($torrent['hash'])) {
				// Skip this torrent if it doesn't have a hash
				continue;
			}
			
			$quality = $torrent['quality'] ?? '1080';
			
			$torrentData[] = [
				'title_long' => $GLOBALS['globalTitle'],
				'hash' => $torrent['hash'],
				'quality' => $quality, 
				'tvpack' => false
			];
			$totalAdded++;
		}
/* 		if ($GLOBALS['DEBUG']) {
			echo 'Torrents: ' . json_encode($torrentData) . "</br></br>";
		} */

        if ($GLOBALS['DEBUG']) {;
            echo 'Finished running yts_mx (' .  $totalAdded . ') </br></br>';
        }		        
        return $totalAdded;

    }
    catch (exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
            echo 'Finished running yts_mx </br></br>';
        }
        return false;
    }
}

////////////////////////////// Video Link Extractors ///////////////////////////////

function FindVideoExtractor($urlToCheck, $tSite, $referer, $identifier = null, $test = null)
{
    global $type;

    // The identifiers and their corresponding function.	
    $extractFunctions = [
    'vidmoly' => 'vidmolyExtract',
		'habetar' => 'StreamwishExtract',
		'streamwish' => 'StreamwishExtract',
		'streamwish.com' => 'StreamwishExtract',
		'streamwish.to' => 'StreamwishExtract',
		'ajmidyad.sbs' => 'StreamwishExtract',
		'khadhnayad.sbs' => 'StreamwishExtract',
		'yadmalik.sbs' => 'StreamwishExtract',
		'hayaatieadhab.sbs' => 'StreamwishExtract',
		'kharabnahs.sbs' => 'StreamwishExtract',
		'atabkhha.sbs' => 'StreamwishExtract',
		'atabknha.sbs' => 'StreamwishExtract',
		'atabknhk.sbs' => 'StreamwishExtract',
		'atabknhs.sbs' => 'StreamwishExtract',
		'abkrzkr.sbs' => 'StreamwishExtract',
		'abkrzkz.sbs' => 'StreamwishExtract',
		'wishembed.pro' => 'StreamwishExtract',
		'mwish.pro' => 'StreamwishExtract',
		'strmwis.xyz' => 'StreamwishExtract',
		'awish.pro' => 'StreamwishExtract',
		'dwish.pro' => 'StreamwishExtract',
		'vidmoviesb.xyz' => 'StreamwishExtract',
		'embedwish.com' => 'StreamwishExtract',
		'cilootv.store' => 'StreamwishExtract',
		'uqloads.xyz' => 'StreamwishExtract',
		'tuktukcinema.store' => 'StreamwishExtract',
		'doodporn.xyz' => 'StreamwishExtract',
		'ankrzkz.sbs' => 'StreamwishExtract',
		'volvovideo.top' => 'StreamwishExtract',
		'streamwish.site' => 'StreamwishExtract',
		'wishfast.top' => 'StreamwishExtract',
		'ankrznm.sbs' => 'StreamwishExtract',
		'sfastwish.com' => 'StreamwishExtract',
		'eghjrutf.sbs' => 'StreamwishExtract',
		'eghzrutw.sbs' => 'StreamwishExtract',
		'playembed.online' => 'StreamwishExtract',
		'egsyxurh.sbs' => 'StreamwishExtract',
		'egtpgrvh.sbs' => 'StreamwishExtract',
		'flaswish.com' => 'StreamwishExtract',
		'obeywish.com' => 'StreamwishExtract',
		'cdnwish.com' => 'StreamwishExtract',
		'javsw.me' => 'StreamwishExtract',
		'cinemathek.online' => 'StreamwishExtract',
		'trgsfjll.sbs' => 'StreamwishExtract',
		'fsdcmo.sbs' => 'StreamwishExtract',
		'anime4low.sbs' => 'StreamwishExtract',
		'mohahhda.site' => 'StreamwishExtract',
		'ma2d.store' => 'StreamwishExtract',
		'dancima.shop' => 'StreamwishExtract',
		'swhoi.com' => 'StreamwishExtract',
		'gsfqzmqu.sbs' => 'StreamwishExtract',
		'jodwish.com' => 'StreamwishExtract',
		'swdyu.com' => 'StreamwishExtract',
		'mixdrop' => 'MixdropExtract',
    'mixdrop.cv' => 'MixdropExtract',
    'mixdrop.co' => 'MixdropExtract',
		'mixdrop.to' => 'MixdropExtract',
		'mixdrop.sx' => 'MixdropExtract',
		'mixdrop.bz' => 'MixdropExtract',
		'mixdrop.ch' => 'MixdropExtract',
		'mixdrp.co' => 'MixdropExtract',
		'mixdrp.to' => 'MixdropExtract',
		'mixdrop.gl' => 'MixdropExtract',
		'mixdrop.club' => 'MixdropExtract',
		'mixdroop.bz' => 'MixdropExtract',
		'mixdroop.co' => 'MixdropExtract',
		'mixdrop.vc' => 'MixdropExtract',
		'mixdrop.ag' => 'MixdropExtract',
		'mdy48tn97.com' => 'MixdropExtract',
		'md3b0j6hj.com' => 'MixdropExtract',
		'mdbekjwqa.pw' => 'MixdropExtract',
		'mdfx9dc8n.net' => 'MixdropExtract',
		'mixdropjmk.pw' => 'MixdropExtract',
		'mixdrop21.net' => 'MixdropExtract',
		'mixdrop.is' => 'MixdropExtract',
		'mixdrop.si' => 'MixdropExtract',
		'mixdrop23.net' => 'MixdropExtract',
		'mixdrop.nu' => 'MixdropExtract',
		'mixdrop.ms' => 'MixdropExtract',
		'mdzsmutpcvykb.net' => 'MixdropExtract',
		'streamvid' => 'StreamvidExtract',  
		'filelions' => 'FilelionsExtract', 		
		'dinisglows.com' => 'FilelionsExtract',
		'filelions.com' => 'FilelionsExtract',
		'filelions.to' => 'FilelionsExtract',
		'ajmidyadfihayh.sbs' => 'FilelionsExtract',
		'alhayabambi.sbs' => 'FilelionsExtract',
		'moflix-stream.click' => 'FilelionsExtract',
		'azipcdn.com' => 'FilelionsExtract',
		'mlions.pro' => 'FilelionsExtract',
		'alions.pro' => 'FilelionsExtract',
		'dlions.pro' => 'FilelionsExtract',
		'filelions.live' => 'FilelionsExtract',
		'motvy55.store' => 'FilelionsExtract',
		'filelions.xyz' => 'FilelionsExtract',
		'lumiawatch.top' => 'FilelionsExtract',
		'filelions.online' => 'FilelionsExtract',
		'javplaya.com' => 'FilelionsExtract',
		'fviplions.com' => 'FilelionsExtract',
		'egsyxutd.sbs' => 'FilelionsExtract',
		'filelions.site' => 'FilelionsExtract',
		'filelions.co' => 'FilelionsExtract',
		'vidhide.com' => 'FilelionsExtract',
		'vidhidepro.com' => 'FilelionsExtract',
		'vidhidevip.com' => 'FilelionsExtract',
		'javlion.xyz' => 'FilelionsExtract',
		'fdewsdc.sbs' => 'FilelionsExtract',
		'techradar.ink' => 'FilelionsExtract',
		'anime7u.com' => 'FilelionsExtract',
		'coolciima.online' => 'FilelionsExtract',
		'gsfomqu.sbs' => 'FilelionsExtract',
		'vidhidepre.com' => 'FilelionsExtract',
		'voe' => 'VoeExtract',
		'voe.sx' => 'VoeExtract',
		'voe-unblock.com' => 'VoeExtract',
		'voe-unblock.net' => 'VoeExtract',
		'voeunblock.com' => 'VoeExtract',
		'voeunbl0ck.com' => 'VoeExtract',
		'voeunblck.com' => 'VoeExtract',
		'voeunblk.com' => 'VoeExtract',
		'voe-un-block.com' => 'VoeExtract',
		'voeun-block.net' => 'VoeExtract',
		'un-block-voe.net' => 'VoeExtract',
		'v-o-e-unblock.com' => 'VoeExtract',
		'edwardarriveoften.com' => 'VoeExtract',
		'audaciousdefaulthouse.com' => 'VoeExtract',
		'launchreliantcleaverriver.com' => 'VoeExtract',
		'kennethofficialitem.com' => 'VoeExtract',
		'reputationsheriffkennethsand.com' => 'VoeExtract',
		'fittingcentermondaysunday.com' => 'VoeExtract',
		'lukecomparetwo.com' => 'VoeExtract',
		'housecardsummerbutton.com' => 'VoeExtract',
		'fraudclatterflyingcar.com' => 'VoeExtract',
		'wolfdyslectic.com' => 'VoeExtract',
		'bigclatterhomesguideservice.com' => 'VoeExtract',
		'uptodatefinishconferenceroom.com' => 'VoeExtract',
		'jayservicestuff.com' => 'VoeExtract',
		'realfinanceblogcenter.com' => 'VoeExtract',
		'tinycat-voe-fashion.com' => 'VoeExtract',
		'35volitantplimsoles5.com' => 'VoeExtract',
		'20demidistance9elongations.com' => 'VoeExtract',
		'telyn610zoanthropy.com' => 'VoeExtract',
		'toxitabellaeatrebates306.com' => 'VoeExtract',
		'greaseball6eventual20.com' => 'VoeExtract',
		'745mingiestblissfully.com' => 'VoeExtract',
		'19turanosephantasia.com' => 'VoeExtract',
		'30sensualizeexpression.com' => 'VoeExtract',
		'321naturelikefurfuroid.com' => 'VoeExtract',
		'449unceremoniousnasoseptal.com' => 'VoeExtract',
		'guidon40hyporadius9.com' => 'VoeExtract',
		'cyamidpulverulence530.com' => 'VoeExtract',
		'boonlessbestselling244.com' => 'VoeExtract',
		'antecoxalbobbing1010.com' => 'VoeExtract',
		'matriculant401merited.com' => 'VoeExtract',
		'scatch176duplicities.com' => 'VoeExtract',
		'availedsmallest.com' => 'VoeExtract',
		'counterclockwisejacky.com' => 'VoeExtract',
		'simpulumlamerop.com' => 'VoeExtract',
		'paulkitchendark.com' => 'VoeExtract',
		'metagnathtuggers.com' => 'VoeExtract',
		'gamoneinterrupted.com' => 'VoeExtract',
		'chromotypic.com' => 'VoeExtract',
		'crownmakermacaronicism.com' => 'VoeExtract',
		'generatesnitrosate.com' => 'VoeExtract',
		'yodelswartlike.com' => 'VoeExtract',
		'figeterpiazine.com' => 'VoeExtract',
		'strawberriesporail.com' => 'VoeExtract',
		'valeronevijao.com' => 'VoeExtract',
		'timberwoodanotia.com' => 'VoeExtract',
		'apinchcaseation.com' => 'VoeExtract',
		'nectareousoverelate.com' => 'VoeExtract',
		'nonesnanking.com' => 'VoeExtract',
		'kathleenmemberhistory.com' => 'VoeExtract',
		'stevenimaginelittle.com' => 'VoeExtract',
		'jamiesamewalk.com' => 'VoeExtract',
		'bradleyviewdoctor.com' => 'VoeExtract',
		'sandrataxeight.com' => 'VoeExtract',
		'graceaddresscommunity.com' => 'VoeExtract',
		'shannonpersonalcost.com' => 'VoeExtract',
		'cindyeyefinal.com' => 'VoeExtract',
		'michaelapplysome.com' => 'VoeExtract',
		'sethniceletter.com' => 'VoeExtract',
		'brucevotewithin.com' => 'VoeExtract',
		'rebeccaneverbase.com' => 'VoeExtract',
		'loriwithinfamily.com' => 'VoeExtract',
		'dood' => 'DoodExtract',
		'dood.watch' => 'DoodExtract',
		'doodstream.com' => 'DoodExtract',
		'dood.to' => 'DoodExtract',
		'dood.so' => 'DoodExtract',
		'dood.cx' => 'DoodExtract',
		'dood.la' => 'DoodExtract',
		'dood.ws' => 'DoodExtract',
		'dood.sh' => 'DoodExtract',
		'doodstream.co' => 'DoodExtract',
		'dood.pm' => 'DoodExtract',
		'dood.wf' => 'DoodExtract',
		'dood.re' => 'DoodExtract',
		'dood.yt' => 'DoodExtract',
		'dooood.com' => 'DoodExtract',
		'dood.stream' => 'DoodExtract',
		'd-s.io' => 'DoodExtract',
		'doply.net' => 'DoodExtract',
		'dood' => 'DoodExtract',
		'dood.li' => 'DoodExtract',
		'ds2play.com' => 'DoodExtract',
		'doods.pro' => 'DoodExtract',
		'ds2video.com' => 'DoodExtract',
		'd0o0d.com' => 'DoodExtract',
		'do0od.com' => 'DoodExtract',
		'd0000d.com' => 'DoodExtract',
		'd000d.com' => 'DoodExtract',
		'uqload' => 'UqloadExtract', 
		'brucevotewithin' => 'VoeExtract',   	
		'rabbitstream' => 'UpCloudExtract',
		'vidcloud' => 'UpCloudExtract',
		'upcloud' => 'UpCloudExtract',
		'upstream' => 'UpstreamExtract',
		'eplayvid' => 'ePlayVidExtract',
		'jwstream' => 'twoEmbedExtract',	
		'2embed' => 'twoEmbedExtract',	
		'vipstream' => 'superEmbedVipExtract',	
		'streambucket' => 'superEmbedVipExtract',
		'streamtape' => 'streamtapeExtract',
		'streamtape.com' => 'streamtapeExtract',
		'strtape.cloud' => 'streamtapeExtract',
		'streamtape.net' => 'streamtapeExtract',
		'streamta.pe' => 'streamtapeExtract',
		'streamtape.site' => 'streamtapeExtract',
		'strcloud.link' => 'streamtapeExtract',
		'strtpe.link' => 'streamtapeExtract',
		'streamtape.cc' => 'streamtapeExtract',
		'scloud.online' => 'streamtapeExtract',
		'stape.fun' => 'streamtapeExtract',
		'streamadblockplus.com' => 'streamtapeExtract',
		'shavetape.cash' => 'streamtapeExtract',
		'streamtape.to' => 'streamtapeExtract',
		'streamadblocker.xyz' => 'streamtapeExtract',
		'tapewithadblock.org' => 'streamtapeExtract',
		'adblocktape.wiki' => 'streamtapeExtract',
		'antiadtape.com' => 'streamtapeExtract',
		'streamtape.xyz' => 'streamtapeExtract',
		'tapeblocker.com' => 'streamtapeExtract',
		'streamnoads.com' => 'streamtapeExtract',
		'tapeadvertisement.com' => 'streamtapeExtract',
		'dropload' => 'droploadExtract',
		'vtube' => 'VTubeExtract',
		'vtube.to' => 'VTubeExtract',
		'vtplay.net' => 'VTubeExtract',
		'vtbe.net' => 'VTubeExtract',
		'vtbe.to' => 'VTubeExtract',
		'vtube.network' => 'VTubeExtract',
		'filemoon' => 'FileMoonExtract',
		'filemoon.sx' => 'FileMoonExtract',
		'filemoon.to' => 'FileMoonExtract',
		'filemoon.in' => 'FileMoonExtract',
		'filemoon.link' => 'FileMoonExtract',
		'filemoon.nl' => 'FileMoonExtract',
		'filemoon.wf' => 'FileMoonExtract',
		'cinegrab.com' => 'FileMoonExtract',
		'filemoon.eu' => 'FileMoonExtract',
		'filemoon.art' => 'FileMoonExtract',
		'moonmov.pro' => 'FileMoonExtract',
		'kerapoxy.cc' => 'FileMoonExtract',
		'furher.in' => 'FileMoonExtract',
		'1azayf9w.xyz' => 'FileMoonExtract',
		'closeload' => 'closeloadExtract',
		'closeload.top' => 'closeloadExtract',
		'embedpk.net' => 'EmbedpkExtract',
		'bigwarp' => 'bigwarpExtract',
		'bigwarp.pro' => 'bigwarpExtract',
		'luluvdoo' => 'luluvdooExtract',
		'luluvdoo.com' => 'luluvdooExtract',
		'lulu' => 'luluvdooExtract',
		'savefiles' => 'savefilesExtract',
		'savefiles.com' => 'savefilesExtract',
		'streamup' => 'strmupExtract',
		'streamup.ws' => 'strmupExtract',
		//'vidoza' => 'vidozaExtract',	// Video wouldnt load during testing.		
    ];

    // If identifier wasn't passed, derive it from URL
    if ($identifier === null) {
        $parsedUrl = parse_url($urlToCheck);
        $host = $parsedUrl['host'] ?? '';
        $parts = explode('.', $host);
        $identifier = (count($parts) >= 2)
            ? implode('.', array_slice($parts, -2))
            : $host;
    }

    // Find matching function by identifier
    $foundFunction = null;
    foreach ($extractFunctions as $key => $functionName) {
        if (stripos($identifier, $key) !== false) {
            $foundFunction = $functionName;
            break;
        }
    }

    // If just testing, return boolean result and skip actual extraction
    if ($test === true) {
        return $foundFunction !== null;
    }

    // If no extractor matched
    if ($foundFunction === null) {
        return false;
    }

    // If the URL is a primewire redirect, resolve it first
    if (stripos($urlToCheck, 'primewire') !== false) {
        $urlToCheck = getLastRedirectUrl($urlToCheck);
    }

    // Call the actual extractor function
    $funcReturn = $foundFunction($urlToCheck, $tSite, $referer);

    // Log result
    $logStatus = $funcReturn ? 'successful' : 'failed';
    logDetails(
        isset($tSite) ? $tSite : 'unknown',
        $foundFunction,
        $logStatus,
        $GLOBALS['logTitle'],
        $urlToCheck,
        $funcReturn === false ? 'n/a' : $funcReturn,
        $type,
        $GLOBALS['movieId'],
        $type === 'series' ? $GLOBALS['seriesCode'] : 'n/a'
    );

    return $funcReturn;
}

function savefilesExtract($url, $tSite, $referer)
{
	  global $timeOut;
	  
    try {

				$url = str_replace('/e/', '/', $url);
		
        $context = stream_context_create(['http' => ['timeout' => $timeOut, 'header' =>
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0\r\n" .
            "Referer: " . $referer, ], ]);

        $content = @file_get_contents($url, false, $context);

        if ($content === false) {
            throw new Exception('HTTP Error: savefilesExtract');
        }

        if (preg_match('/(?<=file:").*?(?=")/', $content, $matches)) {    
            $DirectLink = $matches[0];             

            $lCheck = checkLinkStatusCode($DirectLink);
            if ($lCheck == true) {

				if ($GLOBALS['DEBUG']) {
					echo "Video link: " . $DirectLink . "<br><br>";
				}
                return $DirectLink;

            } else {
                return false;
            }
		}
    } catch (Exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "<br><br>";
        }
        return false;
    }
	return false;
}

function strmupExtract($url, $tSite, $referer)
{
    global $timeOut;
		

    if ($GLOBALS['DEBUG']) {
        echo "Started strmupExtract for $tSite.<br><br>";
    }


    try {

        if (preg_match('#[^\/]+$#', $url, $videoID)) {    
            $url = "https://strmup.to/ajax/stream?filecode=$videoID[0]";
				} else {
						 throw new Exception('Couldn\'t locate the video id.');
				}

        $response = makeGetRequest($url, $referer);

        if ($response === false || trim($response) === "false") {
            throw new Exception('HTTP Error or no video link found in strmupExtract');
        }

				$responseArr = json_decode($response, true);

				if (empty($responseArr['streaming_url'])) {
						throw new Exception("No streaming_url found in response!");
				}

				$DirectLink = trim($responseArr['streaming_url']);

        $lCheck = checkLinkStatusCode($DirectLink);
        if ($lCheck === true) {
            if ($GLOBALS['DEBUG']) {
                echo "Video link: " . $DirectLink . "<br><br>";
            }
            return $DirectLink;
        } else {
            return false;
        }

    } catch (Exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "<br><br>";
            echo "Finished running strmupExtract.<br><br>";
        }
        return false;
    }

    if ($GLOBALS['DEBUG']) {
        echo "Finished running strmupExtract.<br><br>";
    }
    return false;
}

function bigwarpExtract($url, $tSite, $referer)
{
    global $timeOut;

		$url = str_replace('/e/', '/', $url);

    $workerBase64 = "aHR0cHM6Ly9iaWd3YXJwLWV4dHJhY3QuZGF0YS1zZWFyY2gud29ya2Vycy5kZXYvP3VybD0=";
    $workerUrl = base64_decode($workerBase64);

    if ($GLOBALS['DEBUG']) {
        echo "Started bigwarpExtract for $tSite.<br><br>";
    }

    try {
        $extractURL = $workerUrl . urlencode($url) . "&referer=" . urlencode($referer);

        $context = stream_context_create(['http' => ['timeout' => $timeOut]]);
        $response = @file_get_contents($extractURL, false, $context);

        if ($response === false || trim($response) === "false") {
            throw new Exception('HTTP Error or no video link found in bigwarpExtract');
        }

        $DirectLink = trim($response);

        $lCheck = checkLinkStatusCode($DirectLink);
        if ($lCheck === true) {
            if ($GLOBALS['DEBUG']) {
                echo "Video link: " . $DirectLink . "<br><br>";
            }
            return $DirectLink;
        } else {
            return false;
        }

    } catch (Exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "<br><br>";
            echo "Finished running bigwarpExtract.<br><br>";
        }
        return false;
    }

    if ($GLOBALS['DEBUG']) {
        echo "Finished running bigwarpExtract.<br><br>";
    }
    return false;
}

function closeloadExtract($url, $tSite, $referer)
{
	  global $timeOut;
	  
    try {
		
        $context = stream_context_create(['http' => ['timeout' => $timeOut, 'header' =>
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0\r\n" .
            "Referer: " . $referer, ], ]);

        $content = @file_get_contents($url, false, $context);

        if ($content === false) {
            throw new Exception('HTTP Error: closeloadExtract');
        }

        if (preg_match('/(?<=file:").*?(?=")/', $content, $matches)) {    
            $DirectLink = $matches[0];             

			$urlData = "|Origin='" . $referer . "'|Referer='" . $referer . "'|User-Agent='Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0'";
			
			$formedUrl = 'hls_proxy.php?url=' . urlencode($DirectLink) . '&data=' . base64_encode($urlData);

            $lCheck = checkLinkStatusCode($formedUrl);
            if ($lCheck == true) {

				if ($GLOBALS['DEBUG']) {
					echo "Video link: " . $DirectLink . "<br><br>";
				}
                return $formedUrl;

            } else {
                return false;
            }
		}
    } catch (Exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "<br><br>";
        }
        return false;
    }
	return false;
}

function EmbedpkExtract($url, $tSite, $referer)
{
    global $timeOut;

    if ($GLOBALS['DEBUG']) {
        echo "Started EmbedpkExtract for $tSite. </br></br>";
    }

    try {
        $contextOptions = ['http' => ['timeout' => $timeOut, 'header' =>
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0\r\n" .
            "Referer: " . $referer]];

        $context = stream_context_create($contextOptions);
        $response = @file_get_contents($url, false, $context);


        if ($response === false) {
            throw new Exception('HTTP Error: EmbedpkExtract');
        }


        if (preg_match('#eval\(function\(p,a,c,k,e,d\)[\s\S]*?(?=<\/script>)#', $response, $matches)) {
            $unpacker = new JavaScriptUnpacker();

            // Use the methods of the JavaScriptUnpacker class as needed
            $unpackedCode = $unpacker->unpack($matches[0]);

        } else {
            throw new Exception('Couldn\'t find javscript code for EmbedpkExtract');
        }

        if (!empty($unpackedCode) && preg_match('/[file|src]:"([^"]+)"/', $unpackedCode,
            $matches)) {

            $DirectLink = $matches[1];

            //Run link checker before returning.
            $urlData = $DirectLink . "|Referer='" . $referer .
                "'|User-Agent='Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0'";

            $lCheck = checkLinkStatusCode($urlData);
            if ($lCheck == true) {

            if ($GLOBALS['DEBUG']) {
                echo "Video link: " . $DirectLink . "<br><br>";
            }
                return 'video_proxy.php?data=' . base64_encode($urlData);

            } else {
                return false;
            }

        } else {
            throw new Exception('Couldn\'t extract the source links in EmbedpkExtract');
        }

    }
    catch (exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
            echo 'Finished running EmbedpkExtract. </br></br>';
        }
        return false;
    }
    if ($GLOBALS['DEBUG']) {
        echo 'Finished running EmbedpkExtract. </br></br>';
    }
    return false;

}

function FilemoonExtract($url, $tSite, $referer)
{
    global $timeOut;

    //$url = str_replace('/f/', '/e/', $url);

	//echo $url;

    if ($GLOBALS['DEBUG']) {
        echo "Started FilemoonExtract for $tSite. </br></br>";
    }

    try {
        $contextOptions = ['http' => ['timeout' => $timeOut, 'header' =>
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0\r\n" .
            "Referer: " . $referer]];

        $context = stream_context_create($contextOptions);
        $response = @file_get_contents($url, false, $context);


        if ($response === false) {
            throw new Exception('HTTP Error: FilemoonExtract');
        }

				if (preg_match('#(?<=<iframe src=").*?(?=")#', $response, $iframes)) {
           
					$response = @file_get_contents($iframes[0], false, $context);

        } else {
            throw new Exception('Couldn\'t find the iframe for FilemoonExtract');
        }

        if (preg_match('#eval\(function\(p,a,c,k,e,d\)[\s\S]*?(?=<\/script>)#', $response, $matches)) {
            $unpacker = new JavaScriptUnpacker();

            // Use the methods of the JavaScriptUnpacker class as needed
            $unpackedCode = $unpacker->unpack($matches[0]);
			

        } else {
            throw new Exception('Couldn\'t find javscript code for FilemoonExtract');
        }

        if (!empty($unpackedCode) && preg_match('/(?<=file:").*?(?=")/', $unpackedCode,
            $matches)) {

            $DirectLink = $matches[0];						
			
			$urlData = "|Origin='" . $referer . "'|Referer='" . $referer . "'|User-Agent='Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0'";
			
			$DirectLink = 'hls_proxy.php?url=' . urlencode($DirectLink) . '&data=' . base64_encode($urlData);
			
            $lCheck = checkLinkStatusCode($DirectLink);
            if ($lCheck == true) {

            if ($GLOBALS['DEBUG']) {
                echo "Video link: " . $DirectLink . "<br><br>";
            }
               return $DirectLink;

            } else {
                return false;
            }

        } else {
            throw new Exception('Couldn\'t extract the source links on Filemoon');
        }

    }
    catch (exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
            echo 'Finished running FilemoonExtract. </br></br>';
        }
        return false;
    }
    if ($GLOBALS['DEBUG']) {
        echo 'Finished running FilemoonExtract. </br></br>';
    }
    return false;

}

function VTubeExtract($url, $tSite, $subs = false) {
    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:123.0) Gecko/20100101 Firefox/126.0';
    
    if ($GLOBALS['DEBUG']) {
        echo "Started VTubeExtract for $tSite. </br></br>";
    }

    try {
        $html = makeGetRequest($url, $url);

		if (preg_match('eval\(function\(p,a,c,k,e,d\)[\s\S]*?(?=<\/script>)#', $html, $matches)) {
			$unpacker = new JavaScriptUnpacker();

			// Use the methods of the JavaScriptUnpacker class as needed
			$unpackedCode = $unpacker->unpack($matches[0]);

		} else {
			throw new Exception('Couldn\'t find javscript code for VTubeExtract');
		}
	
	} catch (Exception $e) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $e->getMessage() . "</br></br>";
            echo 'Finished running VTubeExtract. </br></br>';
        }
        return false;
    }


    if (preg_match("/sources:\s*\[{file:\s*['\"](?P<url>[^'\"]+)/", $unpackedCode, $match)) {
        $videoUrl = $match['url'];

        $lCheck = checkLinkStatusCode($videoUrl);
        if ($lCheck == true) {
            if ($GLOBALS['DEBUG']) {
                echo "Video link: " . $videoUrl . "<br><br>";
            }
            return $videoUrl;
        } else {
            return false;
        }
    }

    if ($GLOBALS['DEBUG']) {
        echo 'Finished running VTubeExtract. </br></br>';
    }
    return false;
}

function DoodExtract($url, $tSite, $subs = false) {
    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:123.0) Gecko/20100101 Firefox/126.0';
	
	$parsedUrl = parse_url($url);
	$oldHost = $parsedUrl['host'];
	$url = str_replace($oldHost, 'd000d.com', $url);
	
	if ($GLOBALS['DEBUG']) {
		echo "Started DoodExtract for $tSite. </br></br>";
	}

    try {
        $html = makeGetRequest($url, $url);
    } catch (Exception $e) {
		if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $e->getMessage() . "</br></br>";
            echo 'Finished running DoodExtract. </br></br>';
        }
        return false;
    }

    try {
        if (preg_match('/<iframe\s*src="([^"]+)/', $html, $match)) {
            $iframe_url = 'https://' . parse_url($url, PHP_URL_HOST) . $match[1];
            $html = makeGetRequest($iframe_url, $url);
        } else {
            $html = makeGetRequest($url, $url);
        }
    } catch (Exception $e) {
		if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $e->getMessage() . "</br></br>";
            echo 'Finished running DoodExtract. </br></br>';
        }
        return false;
    }

    try {
        if ($subs) {
            $subtitles = [];
            if (preg_match_all("/dsplayer\.addRemoteTextTrack\({src:'([^']+)',\s*label:'([^']*)',kind:'captions'/", $html, $matches)) {
                foreach ($matches[1] as $key => $src) {
                    $label = $matches[2][$key];
                    if (strlen($label) > 1) {
                        $subtitles[$label] = (strpos($src, '//') === 0 ? 'https:' : '') . $src;
                    }
                }
            }
        }

        if (preg_match("/dsplayer\.hotkeys[^']+'([^']+).+?function\s*makePlay.+?return[^?]+([^\"]+)/s", $html, $match)) {
            $token = $match[2];
            $play_url = 'https://' . parse_url($url, PHP_URL_HOST) . $match[1];
            $html = makeGetRequest($play_url, $url);
            if (strpos($html, 'cloudflarestorage.') !== false) {
                $vid_src = trim($html) . '&' . http_build_query(['headers' => $headers]);
            } else {
                $t = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
                $extra = '';
                for ($i = 0; $i < 10; $i++) {
                    $extra .= $t[random_int(0, strlen($t) - 1)];
                }
                $vid_src = $html . $extra . $token . round(microtime(true) * 1000);
            }
			
			$checkData = $vid_src . "|Referer='" . $url . "'|User-Agent='Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0'";
			
			$urlData = 'video_proxy.php?data=' . urlencode(base64_encode($checkData));
			
			
            $lCheck = checkLinkStatusCode($urlData);
            if ($lCheck == true) {

			if ($GLOBALS['DEBUG']) {
					echo "Video link: " . $urlData . "<br><br>";
				}
                return $urlData;

            } else {
                return false;
            }
        }
    } catch (Exception $e) {
		if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $e->getMessage() . "</br></br>";
            echo 'Finished running DoodExtract. </br></br>';
        }
        return false;
    }

		if ($GLOBALS['DEBUG']) {            
            echo 'Finished running DoodExtract. </br></br>';
        }
        return false;
}

function extractFebBox($url, $season = null, $episode = null) {
    global $DEBUG, $type, $HTTP_PROXY, $timeOut, $USE_HTTP_PROXY;

    try {
		$cookieFile = file_get_contents('sessions/showbox_media_cookies.txt');	
		
		$additionalHeaders = [
			'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:126.0) Gecko/20100101 Firefox/130.0',
			'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
			'Accept-Language: en-US,en;q=0.5',
			'DNT: 1',
			'Sec-GPC: 1',
			'Connection: keep-alive',		
			'Upgrade-Insecure-Requests: 1',
			'Sec-Fetch-Dest: document',
			'Sec-Fetch-Mode: navigate',
			'Sec-Fetch-Site: none',
			'Sec-Fetch-User: ?1',
			'Priority: u=1',
			'Pragma: no-cache',
			'Cache-Control: no-cache',
			'Cookie: ' . $cookieFile
		];

		$fid = '';
        $shareKey = explode('share/', $url)[1];
               
		$streamsResponse = makeGetRequest($url);
		
		if ($streamsResponse === false) {
			throw new Exception('HTTP Error: extractFebBox streams</br></br>');
		}
		
		if($type === 'movies'){		
			if (!preg_match_all('/(?<=div class="file " data-id=").*?(?=")/', $streamsResponse, $dataIds)) {
				throw new Exception('Couldn\'t locate the fid\'s on extractFebBox.');
			} 
			$fid = $dataIds[0][0];
		} else {
			if (!preg_match_all('/data-id="(\d+)"\s+data-path="([^"]*)"/', $streamsResponse, $dataIds)) {
				throw new Exception('Couldn\'t locate the fid\'s on extractFebBox.');
			} 
			

		}
				
		if ($type === 'series' && $season && $episode) {
			
			for ($i = 0; $i < count($dataIds[1]); $i++) {
				$id = $dataIds[1][$i];
				$path = $dataIds[2][$i];		
							
				if (strcasecmp($path, "season $season") == 0) {
					$parentId = $id;
					break;
				}
			}		
			
			if (empty($parentId)) {
				throw new Exception("Couldn't locate the seasons parent id.");
			}
			
			$streamsUrl = "https://www.febbox.com/file/file_share_list?share_key={$shareKey}&pwd=&parent_id={$parentId}&is_html=0";
			$streamsResponse = makeGetRequest($streamsUrl);
			
			if ($streamsResponse === false) {
				throw new Exception('HTTP Error: extractFebBox streams</br></br>');
			}

			$streamsData = json_decode($streamsResponse, true);

			if ($DEBUG) {
				echo 'Share List: ' . print_r($streamsData, true) . "</br></br>";
			}
			
			if (!isset($streamsData['data']['file_list']) || !is_array($streamsData['data']['file_list'])) {
				throw new Exception('Invalid file_list data structure');
			}

			// Ensure file_size_bytes exists and is numeric
			$showData = array_reduce($streamsData['data']['file_list'], function ($prev, $curr) {
				if (!isset($prev['file_size_bytes']) || !is_numeric($prev['file_size_bytes'])) {
					return $curr;
				}
				if (!isset($curr['file_size_bytes']) || !is_numeric($curr['file_size_bytes'])) {
					return $prev;
				}
				return $prev['file_size_bytes'] > $curr['file_size_bytes'] ? $prev : $curr;
			});

			function addLeadingZero($num) {
				return str_pad($num, 2, '0', STR_PAD_LEFT);
			}
			
			$showData = null; 
			foreach ($streamsData['data']['file_list'] as $file) {
				if (stripos($file['file_name'], "e" . addLeadingZero($episode)) !== false || stripos($file['file_name'], "episode $episode") !== false) {
					$showData = $file;
					break;
				}
			}

			if (!$showData) {
				throw new Exception('Episode file not found');
			}
			
			$fid = $showData['fid'];

		}
		
        $playerUrl = "https://www.febbox.com/file/player";
		$postData = "fid=$fid&share_key=$shareKey";
		
		$headers = [
			'Host: www.febbox.com',
			'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0',
			'Accept: text/plain, */*; q=0.01',
			'Accept-Language: en-US,en;q=0.5',
			'Accept-Encoding: gzip, deflate, br, zstd',
			'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
			'X-Requested-With: XMLHttpRequest',
			'Content-Length: ' . strlen($postData),
			'Origin: https://www.febbox.com',
			'Connection: keep-alive',
			'Referer: https://www.febbox.com/share/BsnQY1oN',
			'Cookie: ' . $cookieFile
		];
		
		
		$ch = curl_init($playerUrl);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); 
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_ENCODING, "");
		if (isset($HTTP_PROXY) && isset($USE_HTTP_PROXY) && $USE_HTTP_PROXY === true) {
			curl_setopt($ch, CURLOPT_PROXY, $HTTP_PROXY);       
		}

		$playerResponse = curl_exec($ch);
		curl_close($ch); 
	

        if ($DEBUG) {
            echo 'Post Data: ' . print_r($postData, true) . "</br></br>";
            echo 'Player Response: ' . $playerResponse . "</br></br>";
        }
		
		if (!file_exists('sessions/showbox_media_cookies.txt') || filesize('sessions/showbox_media_cookies.txt') == 0 || strpos($playerResponse, '"msg":"please login"') !== false) {
			$howToVurl = locateBaseURL() . 'videos/how_to_showbox_media_cookie.mp4';
			if ($DEBUG) {
				echo 'Login failed: '. $howToVurl . '</br></br>';
			}
			return $howToVurl;
		}

        if ($playerResponse === false) {
            throw new Exception('HTTP Error: extractFebBox player</br></br>');
        }		

        // Extract sources from the player response
        preg_match('/var\s+sources\s+=\s+(\[[^\]]*\])/', $playerResponse, $sourceMatches);
        if (!isset($sourceMatches[1])) {
            throw new Exception('Failed to extract sources from player response');
        }

        $sources = json_decode($sourceMatches[1], true);

        if (!is_array($sources)) {
            throw new Exception('Invalid sources data structure');
        }

		$result = array_map(function($source) {
			$quality = isset($source['label']) ? $source['label'] : '720P';
			if (stripos($quality, '4k') !== false) {
				$quality = '2160P';
			}
			return [
				'url' => $source['file'],
				'quality' => $quality
			];
		}, $sources);
		
		if ($DEBUG) {
            echo 'Sources: ';
			print_r($result);			
			echo "</br></br>";
        }

        return json_encode($result);

    } catch (Exception $e) {
        if ($DEBUG) {
            echo 'Error: ' . $e->getMessage() . "</br></br>";
        }
        return false;
    }
}

function superEmbedVipExtract($url, $tSite, $referer)
{
    global $timeOut;

    if (strpos($url, '/player/') === false) {   
    $url = str_replace('/movie/', '/player/movie/', $url);
	}

    if ($GLOBALS['DEBUG']) {
        echo "Started superEmbedVipExtract for $tSite. </br></br>";
    }

    try {
		$DirectLink = null;
		
		$context = stream_context_create(['http' => ['timeout' => $timeOut, 'header' =>
		"User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0\r\n" .
		"Referer: " . $referer, ], ]);

        $content = @file_get_contents($url, false, $context);
		
		if ($content === false) {
            throw new Exception('HTTP Error: superEmbedVipExtract');
        }
		
		if (preg_match('/(?<=decodeURIComponent\(escape\(r\)\))[\s\S]*?\)/', $content, $matches)) {
			
			$extractedString = $matches[0];
			
			if ($GLOBALS['DEBUG']) {
				echo 'Encrypted data extracted: ' . $extractedString . "</br></br>";
			}
			
			$extracted = str_getcsv($extractedString);
			

		if (preg_match('/\((.*)\)/', $extractedString, $matches)) {		


			$decryptedData = superEmbedDecodeString($extracted[0], $extracted[2], $extracted[1], $extracted[3], $extracted[4]);
			if (preg_match('/(?<=file:").*?(?=")/', $decryptedData, $matches)) {
				
				$DirectLink = $matches[0];

			} else {

				throw new Exception("Couldn't find the file link in superEmbedVipExtract for $tSite");
			}				
		
		} else {
			throw new Exception("Couldn't locate the encrypted host in superEmbedVipExtract for $tSite");
		}	   
		}

        if ($DirectLink) {

            $DirectLink = $matches[0];
			
			if ($GLOBALS['DEBUG']) {
                echo "Video link: " . $DirectLink . "<br><br>";
            }
			return $DirectLink;

        } else {
            throw new Exception("Couldn't extract the link in superEmbedVipExtract for $tSite");
        }

    }
    catch (exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
            echo 'Finished running superEmbedVipExtract. </br></br>';
        }
        return false;
    }
    if ($GLOBALS['DEBUG']) {
        echo 'Finished running superEmbedVipExtract. </br></br>';
    }
    return false;

}

function twoEmbedExtract($url, $tSite, $referer)
{
    global $timeOut;

    if (strpos($url, '/player/') === false) {   
    $url = str_replace('/movie/', '/player/movie/', $url);
	}

    if ($GLOBALS['DEBUG']) {
        echo "Started twoEmbedExtract for $tSite. </br></br>";
    }

    try {
		
		$context = stream_context_create(['http' => ['timeout' => $timeOut, 'header' =>
		"User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0\r\n" .
		"Referer: " . $referer, ], ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            throw new Exception('HTTP Error: twoEmbedExtract');
        }

        if (preg_match('/(?<=file":").*?(?=","type")/', $response, $matches)) {

            $DirectLink = $matches[0];

			$DirectLink = str_replace('\\', '', $DirectLink);
			
			if ($GLOBALS['DEBUG']) {
                echo "Video link: " . $DirectLink . "<br><br>";
            }
			return $DirectLink;

        } else {
            throw new Exception('Couldn\'t extract the source links on twoEmbed');
        }

    }
    catch (exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
            echo 'Finished running twoEmbedExtract. </br></br>';
        }
        return false;
    }
    if ($GLOBALS['DEBUG']) {
        echo 'Finished running twoEmbedExtract. </br></br>';
    }
    return false;

}

function luluvdooExtract($url, $tSite, $referer)
{
	  global $timeOut;
	  
    try {

		
        $context = stream_context_create(['http' => ['timeout' => $timeOut, 'header' =>
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0\r\n" .
            "Referer: " . $referer, ], ]);

        $content = @file_get_contents($url, false, $context);

        if ($content === false) {
            throw new Exception('HTTP Error: luluvdooExtract');
        }

        if (preg_match('/(?<=file:").*?(?=")/', $content, $matches)) {    
            $DirectLink = $matches[0];             

			$urlData = "|Origin='https://luluvdoo.com'|Referer='https://luluvdoo.com/'|User-Agent='Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0'";
			
			$checkData = $DirectLink . "|Referer='https://luluvdoo.to/'|User-Agent='Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0'";

            $lCheck = checkLinkStatusCode('video_proxy.php?data=' . base64_encode($checkData));
            if ($lCheck == true) {

				if ($GLOBALS['DEBUG']) {
					echo "Video link: " . $DirectLink . "<br><br>";
				}
                return 'hls_proxy.php?url=' . urlencode($DirectLink) . '&data=' . base64_encode($urlData);

            } else {
                return false;
            }
		}
    } catch (Exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "<br><br>";
        }
        return false;
    }
	return false;
}

function vidmolyExtract($url, $tSite, $referer)
{
	  global $timeOut;
	  
    try {


				if (preg_match('~https?://([^/]+)/w/([A-Za-z0-9]+)~',$url, $m)) {
						$host = $m[1];
						$videoId = $m[2];
						$url = "https://$host/embed-$videoId.html";
				} else {
					throw new Exception('Error: Unable to reformat the url. ');
				}
		
        $context = stream_context_create(['http' => ['timeout' => $timeOut, 'header' =>
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0\r\n" .
            "Referer: " . $referer, ], ]);

        $content = @file_get_contents($url, false, $context);

        if ($content === false) {
            throw new Exception('HTTP Error: vidmolyExtract');
        }

        if (preg_match('/(?<=file:").*?(?=")/', $content, $matches)) {    
            $DirectLink = $matches[0];             

			$urlData = "|Origin='https://vidmoly.to/'|Referer='https://vidmoly.to/'|User-Agent='Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0'";
			
			$checkData = $DirectLink . "|Referer='https://vidmoly.to/'|User-Agent='Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0'";

            $lCheck = checkLinkStatusCode($checkData);
            if ($lCheck == true) {

				if ($GLOBALS['DEBUG']) {
					echo "Video link: " . $DirectLink . "<br><br>";
				}
                return 'hls_proxy.php?url=' . urlencode($DirectLink) . '&data=' . base64_encode($urlData);

            } else {
                return false;
            }
		}
    } catch (Exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "<br><br>";
        }
        return false;
    }
	return false;
}

function StreamwishExtract($url, $tSite, $referer)
{
    global $timeOut;

    try {
        $context = stream_context_create(['http' => ['timeout' => $timeOut, 'header' =>
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0\r\n" .
            "Referer: " . $referer, ], ]);

        $content = @file_get_contents($url, false, $context);


        if ($content === false) {
            throw new Exception('HTTP Error: StreamwishExtract');      
        }
		
		
		if (preg_match("#eval\(function\(p,a,c,k,e,d\)[\s\S]*?(?=<\/script>)#", $content, $matches)) {


            $unpacker = new JavaScriptUnpacker();


            $unpackedCode = $unpacker->unpack($matches[0] . ";");

        } else {
            throw new Exception('Couldn\'t find javscript code for StreamwishExtract');
        }			

				// Extract 'links = {...};'
				if (preg_match('/var\s+links\s*=\s*({.*?});/s', $unpackedCode, $linkBlock)) {
						$linksJson = str_replace(["'", "\n", "\r"], ['"', '', ''], $linkBlock[1]);
						$linksJson = preg_replace('/,\s*}/', '}', $linksJson); // Remove trailing commas

						$links = json_decode($linksJson, true);

						// Get the site base URL
						$parsed = parse_url($url);
						$site = $parsed['scheme'] . '://' . $parsed['host'];

						foreach ($links as $val) {
								// Only accept .m3u8 and not .txt!
								if (is_string($val) && preg_match('/\.m3u8(\?|$)/i', $val)) {
										// Absolute URL
										if (stripos($val, 'http') === 0) {
												if ($GLOBALS['DEBUG']) echo "Video link: $val<br><br>";
												return $val;
										}
										// Relative URL
										else {
												$fullUrl = $site . $val;
												if ($GLOBALS['DEBUG']) echo "Video link: $fullUrl<br><br>";
												return $fullUrl;
										}
								}
						}
						throw new Exception('No usable .m3u8 playlist found in Streamwish links!');
				} else {
						throw new Exception('Couldn\'t extract links object from Streamwish unpacked JS.');
				}

    } catch (exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
            echo 'Finished running StreamwishExtract. </br></br>';
        }
        return false;
    }
    if ($GLOBALS['DEBUG']) {
        echo 'Finished running StreamwishExtract. </br></br>';
    }
    return false;

}

function UqloadExtract($url, $tSite, $referer)
{
    global $timeOut;

    if ($GLOBALS['DEBUG']) {
        echo "Started UqloadExtract for $tSite. </br></br>";
    }

    try {
        $contextOptions = ['http' => ['timeout' => $timeOut, 'header' =>
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0\r\n" .
            "Referer: " . $referer]];

        $context = stream_context_create($contextOptions);
        $response = @file_get_contents($url, false, $context);


        if ($response === false) {
            throw new Exception('HTTP Error: UqloadExtract');
        }


        if (preg_match('#(?<=sources: \[").*?(?=")#', $response, $matches)) {

            $DirectLink = $matches[0];

            //Run link checker before returning.
            $urlData = $DirectLink . "|Referer='" . $referer .
                "'|User-Agent='Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0'|Origin='https://hqq.to'";
				
			$urlData = 'video_proxy.php?data=' . base64_encode($urlData);

            $lCheck = checkLinkStatusCode($urlData);
            if ($lCheck == true) {

            if ($GLOBALS['DEBUG']) {
                echo "Video link: " . $urlData . "<br><br>";
            }
                return $urlData;

            } else {
                return false;
            }

        } else {
            throw new Exception('Couldn\'t extract the source links on Uqload');
        }

    }
    catch (exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
            echo 'Finished running UqloadExtract. </br></br>';
        }
        return false;
    }
    if ($GLOBALS['DEBUG']) {
        echo 'Finished running UqloadExtract. </br></br>';
    }
    return false;

}

function MixdropExtract($url, $tSite, $referer)
{
    global $timeOut;

		$unpacker = new JavaScriptUnpacker();

    // Use /e/ instead of /f/
    $url = str_replace('/f/', '/e/', $url);

    $parsedUrl = parse_url($url);
    $pReferer = $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . "/";

    if ($GLOBALS['DEBUG']) {
        echo "Started MixdropExtract for $tSite.<br><br>";
    }

    try {
        $contextOptions = ['http' => ['timeout' => $timeOut, 'header' =>
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0\r\n" .
            "Referer: " . $referer]];
        $context = stream_context_create($contextOptions);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            throw new Exception('HTTP Error: MixdropExtract');
        }

        // Unpack the JS
        if (preg_match('/eval\(function\(p,a,c,k,e,d\)[\s\S]*?(?=<\/script>)/s', $response, $matches)) {
            $unpackedCode = $unpacker->unpack($matches[0]);
        } else {
            throw new Exception('Couldn\'t find javascript code for MixdropExtract');
        }

        // Find MDCore.wurl (prefer double-quoted, fallback to single-quoted)
        $found = false;
        if (!empty($unpackedCode) && preg_match('/MDCore\.wurl\s*=\s*"([^"]+)"/', $unpackedCode, $wurlMatch)) {
            $DirectLink = 'https:' . $wurlMatch[1];
            $found = true;
        } elseif (!empty($unpackedCode) && preg_match("/MDCore\.wurl\s*=\s*'([^']+)'/", $unpackedCode, $wurlMatch)) {
            $DirectLink = 'https:' . $wurlMatch[1];
            $found = true;
        }

        if ($found) {
            // Compose the header pipe string for proxy

            $urlData = $DirectLink . "|Referer='" . $pReferer .
                "'|Origin='" . $pReferer .
                "'|User-Agent='Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0'";

            // Optional: Check if link works before returning
            $lCheck = checkLinkStatusCode('video_proxy.php?data=' . base64_encode($urlData));

            if ($lCheck == true) {
                if ($GLOBALS['DEBUG']) {
                    echo "Video link: " . $DirectLink . "<br><br>";
                }
                return 'video_proxy.php?data=' . base64_encode($urlData);
            } else {
                return false;
            }
        } else {
            throw new Exception('Couldn\'t extract the source links on Mixdrop');
        }

    } catch (Exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "<br><br>";
            echo 'Finished running MixdropExtract.<br><br>';
        }
        return false;
    }

    if ($GLOBALS['DEBUG']) {
        echo 'Finished running MixdropExtract.<br><br>';
    }

    return false;
}

function StreamvidExtract($url, $tSite, $referer)
{
    global $timeOut;

    $unpacker = new JavaScriptUnpacker();
    if ($GLOBALS['DEBUG']) {
        echo "Started StreamvidExtract for $tSite. </br></br>";
    }

    try {
		
        $context = stream_context_create(['http' => ['timeout' => $timeOut, 'header' =>
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0\r\n" .
            "Referer: " . $referer, ], ]);

        $response = @file_get_contents($url, false, $context);


        if ($response === false) {
            throw new Exception('HTTP Error: StreamvidExtract');      
        }

        if (preg_match('#eval\(function\(p,a,c,k,e,d\)[\s\S]*?(?=<\/script>)#', $response, $matches)) {

            // Use the methods of the JavaScriptUnpacker class as needed
            $unpackedCode = $unpacker->unpack($matches[0]);	
			
			if (!empty($unpackedCode) && preg_match('/(?<=src:").*?(?=")/', $unpackedCode, $matches)){
				
				$StreamvidDirect = $matches[0];				
			
				if ($GLOBALS['DEBUG']) {
					echo "Video link: " . $StreamvidDirect . "<br><br>";
				}				
									
				return $StreamvidDirect;
				
			} else {
				throw new Exception('Couldn\'t extract the unpackedCode on Streamvid');
			}

		} else {
			throw new Exception('Couldn\'t extract the source links on Streamvid');
		}

    } catch (exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
            echo 'Finished running StreamvidExtract. </br></br>';
        }
        return false;
    }
    if ($GLOBALS['DEBUG']) {
        echo 'Finished running StreamvidExtract. </br></br>';
    }
    return false;

}

function FilelionsExtract($url, $tSite, $referer)
{
    global $timeOut;

		$unpacker = new JavaScriptUnpacker();

    if ($GLOBALS['DEBUG']) {
        echo "Started FilelionsExtract for $tSite.<br><br>";
    }

    try {
        $context = stream_context_create([
            'http' => [
                'timeout' => $timeOut,
                'header'  => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0\r\n" .
                             "Referer: " . $referer,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            throw new Exception('HTTP Error: FilelionsExtract');
        }

        // Find packed JS
        if (preg_match('#eval\(function\(p,a,c,k,e,d\)[\s\S]*?(?=<\/script>)#s', $response, $matches)) {

            $unpackedCode = $unpacker->unpack($matches[0]); // use your unpacker instance

            // Extract 'links = {...};'
            if (preg_match('/var\s+links\s*=\s*({.*?});/s', $unpackedCode, $linkBlock)) {
                $linksJson = str_replace(["'", "\n", "\r"], ['"', '', ''], $linkBlock[1]);
                $linksJson = preg_replace('/,\s*}/', '}', $linksJson); // Remove trailing commas

                $links = json_decode($linksJson, true);

                // Get the site base URL
                $parsed = parse_url($url);
                $site = $parsed['scheme'] . '://' . $parsed['host'];

                foreach ($links as $val) {
                    // Only accept .m3u8 and not .txt!
                    if (is_string($val) && preg_match('/\.m3u8(\?|$)/i', $val)) {
                        // Absolute URL
                        if (stripos($val, 'http') === 0) {
                            if ($GLOBALS['DEBUG']) echo "Video link: $val<br><br>";
                            return $val;
                        }
                        // Relative URL
                        else {
                            $fullUrl = $site . $val;
                            if ($GLOBALS['DEBUG']) echo "Video link: $fullUrl<br><br>";
                            return $fullUrl;
                        }
                    }
                }
                throw new Exception('No usable .m3u8 playlist found in Filelions links!');
            } else {
                throw new Exception('Couldn\'t extract links object from Filelions unpacked JS.');
            }
        } else {
            throw new Exception('No packed JS found on Filelions');
        }

    } catch (Exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "<br><br>";
            echo 'Finished running FilelionsExtract.<br><br>';
        }
        return false;
    }

    if ($GLOBALS['DEBUG']) {
        echo 'Finished running FilelionsExtract.<br><br>';
    }

    return false;
}


function VoeExtract($url, $tSite, $referer)
{
    global $timeOut;

    if ($GLOBALS['DEBUG']) {
        echo "Started VoeExtract for $tSite. </br></br>";
    }

    try {
        $response = makeGetRequest($url, $referer);

        // Handle JS redirect, if present
        if (
            strpos($response, "typeof localStorage") !== false &&
            preg_match("/window\.location\.href\s*=\s*'([^']+)'/", $response, $matches)
        ) {
            $url = $matches[1];
            $response = makeGetRequest($url, $referer);
        }

        if ($response === false) {
            throw new Exception('HTTP Error: VoeExtract');
        }

        // --- New Logic Start ---
        // 1. Find the <script type="application/json">[ENCODED]</script> and the obfuscated script src
        if (
            preg_match('/<script type="application\/json">\s*(\[[^\]]+\])\s*<\/script>/', $response, $videoSrcs)
        ) {
            $json_encoded = $videoSrcs[1];
            $script_url = $videoSrcs[1];

            // 2. Fetch the obfuscated LUT pattern from the JS file
            $script_url = strpos($script_url, 'http') === 0 ? $script_url : dirname($url) . '/' . ltrim($script_url, '/');
            $js_data = makeGetRequest($script_url, $referer);
            if (preg_match("/(\[(?:'..'(?:,)?){1,9}\])/", $js_data, $lut_match)) {
                $luts = $lut_match[1];

                // 3. Decode using the voe_decode logic
                $result = voe_decode($json_encoded, $luts);

                // 4. Find and return any .m3u8 playlist link from result (may include direct mp4 too)
                foreach (['file', 'source', 'direct_access_url'] as $key) {
                    if (isset($result[$key]) && stripos($result[$key], '.m3u8') !== false) {
                        if ($GLOBALS['DEBUG']) {
                            echo "Video link: " . $result[$key] . "<br><br>";
                        }
                        return $result[$key];
                    }
                }
            }
        }

        throw new Exception('Could not extract the source links on Voe');

    } catch (Exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
            echo 'Finished running VoeExtract. </br></br>';
        }
        return false;
    }
    if ($GLOBALS['DEBUG']) {
        echo 'Finished running VoeExtract. </br></br>';
    }
    return false;
}

function voe_decode($ct, $luts) {
    // Clean and parse LUT
    $luts = trim($luts, "[]");
    $lut_arr = array_map(function($item) {
        $item = trim($item, "'");
        return preg_quote($item, '/');
    }, explode(',', $luts));

    // Step 1: Decode ct, which is a JSON array string
    $ct = json_decode($ct)[0];

    // Step 2: Shift letter ordinals as in Python code
    $txt = '';
    for ($i = 0; $i < strlen($ct); $i++) {
        $x = ord($ct[$i]);
        if ($x > 64 && $x < 91) { // A-Z
            $x = ($x - 52) % 26 + 65;
        } elseif ($x > 96 && $x < 123) { // a-z
            $x = ($x - 84) % 26 + 97;
        }
        $txt .= chr($x);
    }

    // Step 3: Strip LUT matches
    foreach ($lut_arr as $i) {
        $txt = preg_replace("/$i/", '', $txt);
    }

    // Step 4: Base64 decode, shift -3, reverse, decode again
    $ct2 = base64_decode($txt);
    $txt2 = '';
    for ($i = 0; $i < strlen($ct2); $i++) {
        $txt2 .= chr(ord($ct2[$i]) - 3);
    }
    $txt2 = base64_decode(strrev($txt2));

    // Step 5: Final JSON parse
    return json_decode($txt2, true);
}

function droploadExtract($url, $tSite, $referer)
{
    global $timeOut;

    $unpacker = new JavaScriptUnpacker();
    if ($GLOBALS['DEBUG']) {
        echo "Started droploadExtract for $tSite. </br></br>";
    }

    try {
        $context = stream_context_create(['http' => ['timeout' => $timeOut, 'header' =>
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0\r\n" .
            "Referer: https://" . str_replace('_', '.', strtolower($tSite)), ], ]);

        $response = @file_get_contents($url, false, $context);


        if ($response === false) {
            throw new Exception('HTTP Error: droploadExtract');
            if ($GLOBALS['DEBUG']) {
                echo 'Error: ' . $error->getMessage() . "</br></br>";
            }
        }

        if (preg_match('#eval\(function\(p,a,c,k,e,d\)[\s\S]*?(?=<\/script>)#', $response, $matches)) {

            // Use the methods of the JavaScriptUnpacker class as needed
            $unpackedCode = $unpacker->unpack($matches[0]);		

		if (preg_match('#(?<=sources:\[{file:").*?.*?(?="}])#', $unpackedCode, $matches)) {
			$sourceUrl = $matches[0];

			// Check if the URL starts with 'http' indicating it's a complete URL
			if (strpos($sourceUrl, 'http') !== 0) {  // It's not a full URL, so we will try to extract the domain
				if (preg_match('#image:"(https?://[^/]+)#', $unpackedCode, $imageMatches)) {
					$domain = $imageMatches[1];
					$sourceUrl = $domain . $sourceUrl;  // Append the relative URL to the domain to form the full URL
				} else {
					throw new Exception('Couldn\'t extract the domain from image URL on dropload');
				}
			}

			if ($GLOBALS['DEBUG']) {
				echo "Video link: " . $sourceUrl . "<br><br>";
			}
			return $sourceUrl;

		} else {
			throw new Exception('Couldn\'t extract the source links on dropload');
		}

    }
	}
    catch (exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
            echo 'Finished running droploadExtract. </br></br>';
        }
        return false;
    }
    if ($GLOBALS['DEBUG']) {
        echo 'Finished running droploadExtract. </br></br>';
    }
    return false;

}

function UpstreamExtract($url, $tSite, $referer)
{
    global $timeOut;

    $unpacker = new JavaScriptUnpacker();
    if ($GLOBALS['DEBUG']) {
        echo "Started UpstreamExtract for $tSite. </br></br>";
    }

    try {
        $context = stream_context_create(['http' => ['timeout' => $timeOut, 'header' =>
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0\r\n" .
            "Referer: https://" . str_replace('_', '.', strtolower($tSite)), ], ]);

        $response = @file_get_contents($url, false, $context);


        if ($response === false) {
            throw new Exception('HTTP Error: UpstreamExtract');
            if ($GLOBALS['DEBUG']) {
                echo 'Error: ' . $error->getMessage() . "</br></br>";
            }
        }

        if (preg_match('#eval\(function\(p,a,c,k,e,d\)[\s\S]*?(?=<\/script>)#', $response, $matches)) {

            // Use the methods of the JavaScriptUnpacker class as needed
            $unpackedCode = $unpacker->unpack($matches[0]);			


		if (preg_match('#(?<=sources:\[{file:").*?upstream.*?(?="}])#', $unpackedCode, $matches)) {
			$sourceUrl = $matches[0];

			// Check if the URL starts with 'http' indicating it's a complete URL
			if (strpos($sourceUrl, 'http') !== 0) {  // It's not a full URL, so we will try to extract the domain
				if (preg_match('#image:"(https?://[^/]+)#', $unpackedCode, $imageMatches)) {
					$domain = $imageMatches[1];
					$sourceUrl = $domain . $sourceUrl;  // Append the relative URL to the domain to form the full URL
				} else {
					throw new Exception('Couldn\'t extract the domain from image URL on Upstream');
				}
			}

			if ($GLOBALS['DEBUG']) {
				echo "Video link: " . $sourceUrl . "<br><br>";
			}
			return $sourceUrl;

		} else {
			throw new Exception('Couldn\'t extract the source links on Upstream');
		}

    }
	}
    catch (exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
            echo 'Finished running UpstreamExtract. </br></br>';
        }
        return false;
    }
    if ($GLOBALS['DEBUG']) {
        echo 'Finished running UpstreamExtract. </br></br>';
    }
    return false;

}

//Extractor for goMovies_sx
function UpCloudExtract($url, $tSite, $referer)
{
    global $timeOut;

    if ($GLOBALS['DEBUG']) {
        echo "Started UpCloudExtract for $tSite. </br></br>";
    }

    // Parse the URL
    $urlParts = parse_url($url);

    if ($urlParts !== false && isset($urlParts['path'])) {
        // Split the path into segments
        $pathSegments = explode('/', $urlParts['path']);

        // Get the last segment (id)
        $id = end($pathSegments);


        // Construct the new URL
        $outputUrl = "{$urlParts['scheme']}://{$urlParts['host']}/ajax/embed-4/getSources?id=$id";

        if ($GLOBALS['DEBUG']) {
            echo "UpCloudExtract - Output URL: $outputUrl </br></br>";
        }
    } else {

        if ($GLOBALS['DEBUG']) {
            echo "UpCloudExtract: Invalid URL for $tSite. </br></br>";
        }

        return false;
    }

    try {
        $context = stream_context_create(['http' => ['timeout' => $timeOut, 'header' =>
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0\r\n" .
            "X-Requested-With: XMLHttpRequest\r\n" . "Referer: $referer\r\n", ], ]);

        $response = @file_get_contents($outputUrl, false, $context);

        if ($response === false) {
            throw new Exception('HTTP Error: UpCloudExtract');
        }
        if ($GLOBALS['DEBUG']) {
            print_r('UpCloudExtract Json sources: ' . $response . "</br></br>");
        }

        // Decode the JSON response into an associative array
        $data = json_decode($response, true);

        // Decode the JSON response into an associative array
        $data = json_decode($response, true);

        if ($data !== null) {
            if (isset($data['sources'])) {
                if (is_array($data['sources'])) {
                    // Handle multiple sources (an array)
                    $firstSource = $data['sources'][0]; // Get the first source from the array
                } else {
                    // Handle a single source (a string)
                    $firstSource = $data['sources']; // The entire source is a single string
                }

                $getKeyset = extractUpCloudKey();				
				
				
				if ($response === false) {
					throw new Exception('HTTP Error: Couldn\'t get decryption key.');
				}

                return decryptUpcloudSource($firstSource, $getKeyset);
            } else {
                if ($GLOBALS['DEBUG']) {
                    echo "Error: 'sources' key not found. </br></br>";
                }
                return false;
            }
        } else {
            if ($GLOBALS['DEBUG']) {
                echo "Error: Invalid JSON. </br></br>";
            }
            return false;
        }
    }
    catch (exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
            echo 'Finished running UpCloudExtract. </br></br>';
        }
        return false;
    }

	return false;
}

//Extractor for upMovies_to
function ePlayVidExtract($url, $tSite, $referer)
{
    global $timeOut;

    try {
		$context = stream_context_create([
			'http' => [
				'timeout' => $timeOut,
				'header' =>
					"User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0\r\n" .
					"Referer: $referer",
			],
		]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            throw new Exception('HTTP Error: eplayvidExtact');
            if ($GLOBALS['DEBUG']) {
                echo 'Error: ' . $error->getMessage() . "</br></br>";
            }
        }

        if (preg_match('#(?<=<source src=")[\s\S]*?(?=")#', $response, $matches)) {

            if ($GLOBALS['DEBUG']) {
                echo "Video link: " . $matches[0] . "<br><br>";
            }
			//Run link checker before returning.
			$urlData = $matches[0] .
                "|Referer='https://eplayvid.net/'|User-Agent='Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0'|Origin='https://eplayvid.net/'";
				
			$lCheck = checkLinkStatusCode($urlData);
			if ($lCheck == true){
				return 'video_proxy.php?data=' . base64_encode($urlData);
			} else {
				return false;
			}
			
						
        }


    }
    catch (exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
            echo 'Finished running ePlayVid. </br></br>';
        }
        return false;
    }

    return false;

}

function streamtapeExtract($url, $tSite, $referer)
{
    global $timeOut;

    $url = str_replace('/v/', '/e/', $url);
	
    if ($GLOBALS['DEBUG']) {
        echo "Started streamtapeExtract for $tSite. </br></br>";
    }

    try {
        $context = stream_context_create(['http' => ['timeout' => $timeOut, 'header' =>
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0\r\n" .
            "Referer: " . $referer, ], ]);

        $response = @file_get_contents($url, false, $context);		

        if ($response === false) {
            throw new Exception('HTTP Error: streamtapeExtract');
        }

        $parsed_url = parse_url($url);
		

        if (preg_match_all('#(?<=innerHTML = ").*?(?=;)#', $response, $matches) && preg_match('#.*?(?=")#', $matches[0][0], $firstPart) && isset($parsed_url['scheme']) && isset($parsed_url['host'])) {			
		
			if (preg_match("/(?<=\(')(.*?)(?='\)).*?substring\((\d+)\).*?substring\((\d+)\)/", $matches[0][0], $urlMatches)) {				

				$urlPart = $urlMatches[1]; // The URL part
				$firstSubstrIndex = (int)$urlMatches[2]; // First substring index
				$secondSubstrIndex = (int)$urlMatches[3]+1; // Second substring index

				$finalIndex = $firstSubstrIndex + $secondSubstrIndex;
				$urlPart = substr($urlPart, $finalIndex);
				
			} else {
				throw new Exception("Couldn't form the streaming link in streamtapeExtract for $tSite");		
				
			}

            $sourceUrl = $firstPart[0]. $urlPart . '&stream=1';
			
			$parsedSource = parse_url($sourceUrl);
			
			if (!isset($parsedSource['scheme'])) {
				
				$sourceUrl = $parsed_url['scheme'] . '://' . ltrim($sourceUrl, '/');
			}

            if ($GLOBALS['DEBUG']) {
                echo "Video link: " . $sourceUrl . "<br><br>";
            }
            return $sourceUrl;

        } else {
            throw new Exception("Couldn't extract the source link in streamtapeExtract for $tSite");
        }

    }

    catch (exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
            echo 'Finished running streamtapeExtract. </br></br>';
        }
        return false;
    }
    if ($GLOBALS['DEBUG']) {
        echo 'Finished running streamtapeExtract. </br></br>';
    }
    return false;

}

function vidozaExtract($url, $tSite, $referer)
{
    global $timeOut;

    if ($GLOBALS['DEBUG']) {
        echo "Started vidozaExtract for $tSite. </br></br>";
    }

    try {
        $context = stream_context_create(['http' => ['timeout' => $timeOut, 'header' =>
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0\r\n" .
            "Referer: " . $referer, ], ]);

        $response = @file_get_contents($url, false, $context);		

        if ($response === false) {
            throw new Exception('HTTP Error: vidozaExtract');
        }		

        if (preg_match('#(?<=\[{ src: ").*?(?=")#', $response, $matches)) {			
		

            $DirectLink = $matches[0];			

            //Run link checker before returning.
            $urlData = $DirectLink . "|Referer='" . $referer .
                "'|User-Agent='Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0'";

            $lCheck = checkLinkStatusCode($urlData);
            if ($lCheck == true) {

            if ($GLOBALS['DEBUG']) {
                echo "Video link: " . $DirectLink . "<br><br>";
            }
                return 'video_proxy.php?data=' . base64_encode($urlData);

            } else {
                return false;
            }

        } else {
            throw new Exception("Couldn't extract the source link in vidozaExtract for $tSite");
        }

    }

    catch (exception $error) {
        if ($GLOBALS['DEBUG']) {
            echo 'Error: ' . $error->getMessage() . "</br></br>";
            echo 'Finished running vidozaExtract. </br></br>';
        }
        return false;
    }
    if ($GLOBALS['DEBUG']) {
        echo 'Finished running vidozaExtract. </br></br>';
    }
    return false;

}

//Decryption for UpCloudExtract
function decryptUpcloudSource($encryptedString, $keySet)
{

    try { 
		
        $ch = curl_init();

        $url = "https://script.google.com/macros/s/AKfycbx5yZILYCNrg2gHFtzHxryKXyr6OKoUWdAKeoqnAUKc4JUWwBvMm5ZsbluqdEOsBVnb9A/exec?keyset=" . urlencode($keySet) . "&text=" . urlencode($encryptedString);		


        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $response = curl_exec($ch);

        if ($response === false) {
            throw new Exception("Curl error: " . curl_error($ch));
        }

        curl_close($ch);

        // Parse the JSON response to extract the file URL
        $data = json_decode($response, true);

        if ($data !== null && isset($data[0]['file'])) {
            // Extract the 'file' URL from the first element of the array
            $fileURL = $data[0]['file'];

            return $fileURL;
        } else {
            throw new Exception("Invalid JSON or 'file' key not found in response.");
        }
    }
    catch (exception $error) {
        // Handle the exception here
        echo "Error: " . $error->getMessage();
        return false;
    }
}

function extractUpCloudKey($version = null) {
    $timeOut = 20;
    $context = stream_context_create(['http' => ['timeout' => $timeOut]]);
	$url = 'https://rabbitstream.net/js/player/e4-player-v2.min.js?v=0.1.8';
    $response = @file_get_contents($url, false, $context);
    
    if ($response === FALSE) {
        return json_encode(['error' => 'Could not retrieve the script.']);
    }

    $script = $response;

    $startOfSwitch = strrpos($script, "switch");
    $endOfCases = strpos($script, "partKeyStartPosition", $startOfSwitch);
    if ($startOfSwitch === false || $endOfCases === false) {
        return json_encode(['error' => 'Required patterns not found in the script.']); // Error in JSON format
    }
    $switchBody = substr($script, $startOfSwitch, $endOfCases - $startOfSwitch);

    $nums = [];
    preg_match_all('/:[a-zA-Z0-9]+=([a-zA-Z0-9]+),[a-zA-Z0-9]+=([a-zA-Z0-9]+);/', $switchBody, $matches, PREG_SET_ORDER);
    
    foreach ($matches as $match) {
        $innerNumbers = [];
        foreach (array_slice($match, 1) as $varMatch) {
            preg_match_all("/$varMatch=0x([a-zA-Z0-9]+)/", $script, $varMatches);
            $lastMatch = end($varMatches[1]);
            if (!$lastMatch) return json_encode(['error' => 'Failed to match the pattern in the script.']); // Error in JSON format
            $number = hexdec($lastMatch);
            $innerNumbers[] = $number;
        }

        $nums[] = $innerNumbers;
    }

    return json_encode($nums);
}

////////////////////////////// Caching ///////////////////////////////

function writeToCache($key, $value, $expires = null, $report=true)
{
    global $expirationDuration;	
	
	// Check if the value is a how to video.
    if (strpos($value, locateBaseURL() . 'videos/') !== false) {
        $expires = 60; // Set the expiration time to 60 seconds
    } elseif ($expires === null) {
        $expires = $expirationDuration;
    }

    // Specify the cache file path
    $cacheFilePath = 'cache.json';

    // Check if the cache file exists or create it if not
    if (!file_exists($cacheFilePath)) {
        file_put_contents($cacheFilePath, '{}');
    }

    // Get the current timestamp
    $now = time();
    $expirationTime = $now + $expires;

    // Serialize the value to a JSON string
    $serializedValue = json_encode($value);

    // Read existing cache data
    $cacheData = json_decode(file_get_contents($cacheFilePath), true) ? : [];

    // Update the cache data with the new value
    $cacheData[$key] = ['value' => $serializedValue, 'addedTime' => $now, 'expirationTime' => $expirationTime, ];

    // Write the updated cache data back to the file
    file_put_contents($cacheFilePath, json_encode($cacheData));

    if ($GLOBALS['DEBUG'] && $report == true) {
        echo 'Added to Cache - Key: ' . $key . ' Value: ' . json_encode($value) .
            "</br></br>";
    }
	
}

function readFromCache($key, $report=true)
{
    // Specify the cache file path
    $cacheFilePath = 'cache.json';

    // Check if the cache file exists or create it if not
    if (!file_exists($cacheFilePath)) {
        file_put_contents($cacheFilePath, '{}');
    }

    // Read existing cache data
    $cacheData = json_decode(file_get_contents($cacheFilePath), true) ? : [];

    if (isset($cacheData[$key])) {
        $parsedData = $cacheData[$key];

        // Get the current timestamp
        $now = time();

        // Check if the data has expired
        if ($now <= $parsedData['expirationTime']) {
            // Deserialize the JSON string back to an object
            $deserializedValue = json_decode($parsedData['value'], true);

            if ($GLOBALS['DEBUG'] && $report == true && $deserializedValue !== '_running_') {
                echo 'Read from Cache - Key: ' . $key . ' - Value: ' . json_encode($deserializedValue) .
                    "</br></br>";
            }

            return $deserializedValue;
        } else {
            // Data has expired, remove it from the cache
            unset($cacheData[$key]);

            // Write the updated cache data back to the file
            file_put_contents($cacheFilePath, json_encode($cacheData));
        }
    }

    // Cache miss or expired data, or the cache file doesn't exist
    return null;
}

function deleteFromCache($key) {
    $cacheFilePath = 'cache.json';
   
    if (!file_exists($cacheFilePath)) {
        return;
    }

    $cacheData = json_decode(file_get_contents($cacheFilePath), true) ?: [];

    if (isset($cacheData[$key])) {
        unset($cacheData[$key]);
        file_put_contents($cacheFilePath, json_encode($cacheData));

        if ($GLOBALS['DEBUG']) {
            echo 'Deleted from Cache - Key: ' . $key . "</br></br>";
        }
    }
}

function cleanupCacheFiles()
{
    global $cacheSize;
    // List of cache files to check
    $cacheFiles = ['html_cache.txt', 'cache.json', 'access.log'];

    foreach ($cacheFiles as $file) {
        // Check if file exists
        if (file_exists($file)) {
            // Get file size in bytes
            $fileSize = filesize($file);

            $maxSize = $cacheSize * 1024 * 1024;

            // If file size is greater than 30 MB, clear the file
            if ($fileSize > $maxSize) {
                if ($GLOBALS['DEBUG']) {
                    echo "Cache file $file is larger than " . $cacheSize .
                        "MB. Clearing the file.<br>";
                }

                // Clear the file contents
                file_put_contents($file, '');
            }
        }
    }
}

////////////////////////////// Logging ///////////////////////////////

function logDetails($siteFunction, $extractor, $status, $title, $pageUrl, $videoUrl, $type, $movieIds, $seriesCode = 'n/a', $logFilePath = 'detailed_log.html') {
	 $time = date('Y-m-d h:i:s A');	 
		
	// Create the access URL
	$accessUrl = locateBaseURL() . basename($_SERVER['SCRIPT_NAME']);

	// Append 'dev=true' only if it's not already in the query string
	if (!empty($_SERVER['QUERY_STRING'])) {
		// If the query string exists, append it, and add 'dev=true' if it's not part of the query string
		$accessUrl .= '?' . $_SERVER['QUERY_STRING'];
		if (strpos($_SERVER['QUERY_STRING'], 'dev=true') === false) {
			$accessUrl .= '&dev=true';
		}
	} else {
		// If there is no query string, just add '?dev=true'
		$accessUrl .= '?dev=true';
	}
	
	if ($videoUrl !== 'n/a' && !parse_url($videoUrl, PHP_URL_HOST)) {
		$videoUrl = locateBaseURL() . $videoUrl;
	}	
	 
    // Styles for the table
    $style = "<style>
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; overflow: hidden; text-overflow: ellipsis;}		
        th { background-color: #f2f2f2; }
        tr:nth-child(even) { background-color: #f9f9f9; }
        a { color: #0645ad; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .status-success { color: green; }
        .status-failed { color: red; }
		
		</style>";

    // DOMDocument setup
    $refreshInterval = 5000; // Refresh interval in milliseconds (5000ms = 5s)

    $doc = new DOMDocument();
    @$doc->loadHTMLFile($logFilePath) || @$doc->loadHTML('
    <html>
        <head>
            <title>Detailed Logs</title>
            ' . $style . '
		<script>
			setTimeout(function() {
				location.reload();
			}, ' . $refreshInterval . ');

			function openPopup(divId) {   
				var content = document.getElementById(divId).innerHTML;    
				var windowFeatures = "width=350,height=420,scrollbars=yes,resizable=no,toolbar=no,location=no,directories=no,status=no,menubar=no";    
				var popupWindow = window.open("", "_blank", windowFeatures);
				popupWindow.document.write("<html><head><title>Torrent Extractors<\/title><\/head><body>" + content + "<\/body><\/html>");
				popupWindow.document.close();
				if (window.focus) {
					popupWindow.focus();
				}
			}
		</script>

        </head>
        <body>
            <table>
                <thead></thead>
                <tbody></tbody>
            </table>
        </body>
    </html>');

    // Get or create the table and tbody
    $table = $doc->getElementsByTagName('table')->item(0);
    $tbody = $table->getElementsByTagName('tbody')->item(0);

    // Create header if it does not exist
    if ($table->getElementsByTagName('thead')->item(0)->childNodes->length === 0) {
        $headers = ['Time', 'Site Function', 'Extractor', 'Status', 'Title', 'Page Url', 'Video URL', 'Access URL', 'Type', 'TMDB', 'Series Code'];
        $headerRow = $doc->createElement('tr');
        foreach ($headers as $header) {
            $th = $doc->createElement('th', $header);
            $headerRow->appendChild($th);
        }
        $table->getElementsByTagName('thead')->item(0)->appendChild($headerRow);
    }

    // Create a new row
    $row = $doc->createElement('tr');
    $rowData = [$time, $siteFunction, $extractor, $status, $title, $pageUrl, $videoUrl, $accessUrl, $type, $movieIds, $seriesCode];
	

foreach ($rowData as $index => $data) {
    $td = $doc->createElement('td');
    $td->setAttribute('style', 'max-width: 220px; overflow: hidden; text-overflow: ellipsis;');

    // Check if the current cell should contain HTML content from $extractor
    if ($index == 2 && $data != 'n/a') { // Assuming $extractor content is at index 2
        // Create a DocumentFragment to hold the HTML content
        $fragment = $doc->createDocumentFragment();
        @$fragment->appendXML($data); // Suppress warnings for invalid HTML
        $td->appendChild($fragment);
    } else {
        // Correctly handle URLs; skip creating an anchor element if the data is 'n/a'
		if (in_array($index, [5, 6, 7]) && $data !== 'n/a') { // For Page URL, Video URL, and Access URL
			$parsed_url = parse_url($data);
			$current_host = $_SERVER['HTTP_HOST'];
			$href = $data;

			if (isset($parsed_url['host']) && $parsed_url['host'] !== $current_host) {
				$href = 'https://href.li/?' . $data;
			}

			$a = $doc->createElement('a');
			$a->setAttribute('href', $href);
			$a->setAttribute('target', '_blank');
			$a->appendChild($doc->createTextNode($data));
			$td->appendChild($a);
		} else { // For non-URLs or 'n/a', just set the text content
			if ($index == 3) { // Additional styling for Status column
				$tdColor = ($data === 'successful' ? 'green' : ($data === 'failed' ? 'red' : 'black'));
				$td->setAttribute('style', "max-width: 200px; overflow: hidden; text-overflow: ellipsis; color: $tdColor;");
				$td->textContent = $data; // Set text for status
			} else if ($index === 9) {
				// Create link for index 9
				$a = $doc->createElement('a');          
				
				if ($type === 'movies') {
					$url = 'https://www.themoviedb.org/movie/' . $data;
				} else {
					$url = 'https://www.themoviedb.org/tv/' . $data;
				}

				$a->setAttribute('href', $url);
				$a->setAttribute('target', '_blank');
				$a->appendChild($doc->createTextNode($data));
				$td->appendChild($a);
			} else {
				// For other indices, just set text
				$td->textContent = $data;
			}
				
        }
    }
    $row->appendChild($td);
}

    // Insert the new row at the top of the tbody
    if ($tbody->childNodes->length > 0) {
        $tbody->insertBefore($row, $tbody->childNodes->item(0));
    } else {
        $tbody->appendChild($row);
    }

    // Keep only the latest 300 rows in tbody
    while ($tbody->childNodes->length > 300) {
        $tbody->removeChild($tbody->lastChild);
    }

    // Save the updated HTML to the file
    $doc->saveHTMLFile($logFilePath);
}







?>
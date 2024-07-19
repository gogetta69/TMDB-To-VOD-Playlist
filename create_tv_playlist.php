<?php
// Created By gogetta.teams@gmail.com
// Please leave this in this script.
//https://github.com/gogetta69/TMDB-To-VOD-Playlist

require_once 'config.php';
set_time_limit(0); // Remove PHP's time restriction

if ($GLOBALS['DEBUG'] !== true) {
    error_reporting(0);	
} else {
	accessLog();
}	

if (!isset($userSetHost) || empty($userSetHost)){
	$domain = 'http://' . $_SERVER['HTTP_HOST'];	
} else {
	$domain = 'http://' . $userSetHost;
}
$basePath = '/'; 

// If the script is not running in CLI mode, set the base path
if (php_sapi_name() != 'cli') {
    $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';
}
$playVodUrl = $domain . $basePath . "/play.php";

//Set globals
$num = 0;
$outputData = []; // Initialize json content
$outputContent = "#EXTM3U\n"; // Initialize M3U8 content
$addedMovieIds = []; // Initialize to prevent duplicates


fetchSeries($playVodUrl, $language, $apiKey, $totalPages);

function fetchSeries($playVodUrl, $language, $apiKey, $totalPages)
{
    global $listType, $outputData, $outputContent, $num;	
	
	//Limit some categories to less items. (This allows the other categories to be populated)
	$limitTotalPages = ($totalPages > 15) ? 15 : $totalPages;
	
	// Call the function for on the air
    measureExecutionTime('fetchOnTheAirSeries', $playVodUrl, $language, $apiKey, $limitTotalPages);
	
	// Call the function for with networks
    measureExecutionTime('fetchSeriesWithNetwork', $playVodUrl, $language, $apiKey, $totalPages);

    // Call the function for top rated
    measureExecutionTime('fetchTopRatedSeries', $playVodUrl, $language, $apiKey, $limitTotalPages);

    // Call the function for popular
    measureExecutionTime('fetchPopularSeries', $playVodUrl, $language, $apiKey, $limitTotalPages);
	
	// Call the function for genres
    measureExecutionTime('fetchGenres', $playVodUrl, $language, $apiKey, $totalPages);

    //Save the Json and M3U8 Data (commented out since its not good with tv series).
    //file_put_contents('tv_playlist.m3u8', $outputContent);

    file_put_contents('tv_playlist.json', json_encode($outputData));

    return;
}

// Function to fetch and handle errors for a URL
function fetchAndHandleErrors($url, $errorMessage)
{
    try {
        $response = file_get_contents($url);

        if ($response !== false) {
            $data = json_decode($response, true);
            if ($data !== null) {
                return $data;
            } else {
                error_log($errorMessage . ' Invalid JSON format');
            }
        } else {
            error_log($errorMessage . ' Request failed');
        }
    }
    catch (exception $error) {
        error_log($errorMessage . ' ' . $error->getMessage());
    }
    return null;
}

// Fetch series by network.
function fetchSeriesWithNetwork($playVodUrl, $language, $apiKey, $totalPages)
{
    global $outputData, $outputContent, $addedMovieIds, $listType, $series_with_origin_country, $num;

    // Setup the network list to parse
    $tvNetworks = [
        "Apple Tv" => 2552,
        "Discovery" => 64,
        "Disney+" => 2739,
        "HBO" => 49,
        "History" => 65,
        "Hulu" => 453,
        "Investigation" => 244,
        "Lifetime" => 34,
        "Netflix" => 213,
        "Oxygen" => 132
    ];

    $baseUrl = 'https://api.themoviedb.org/3/discover/tv';

    $capturedTotalPages = null;

    // Iterate through each network
    foreach ($tvNetworks as $networkName => $networkId) {
        for ($page = 1; $page <= $totalPages; $page++) {
            $url = $baseUrl . "?api_key=$apiKey&include_adult=false&include_null_first_air_dates=false&sort_by=popularity.desc&with_origin_country=$series_with_origin_country&with_networks=$networkId&language=$language&page=$page";
            $data = fetchAndHandleErrors($url, 'Request for series with networks failed.');

            // Set the total pages after the first request
            if ($page == 1 && isset($data['total_pages'])) {
                $capturedTotalPages = $data['total_pages'];
            }

            if ($data !== null) {
                $series = $data['results'];

                foreach ($series as $show) {
                // JSON formatting for each show
				if (!isset($show['first_air_date']) || !isset($show['name']) || !isset($show['poster_path']) || !isset($show['id'])) {
					continue;
				}
                if (isset($show['first_air_date'])) {
                    $dateParts = explode("-", $show['first_air_date']);
                    $year = $dateParts[0];
					$date = $show['first_air_date'];
					$timestamp = strtotime($date);
                } else { 
					$date = '1970-01-01';
                    $year = '1970'; //Set to 1970 since its unknown.
					$timestamp = '24034884';
                }
					
				  $showData = [
					"num" => ++$num,
					"name" =>$show['name'] . ' (' . $year . ')',
					"series_id" => $show['id'],
					"cover" => 'https://image.tmdb.org/t/p/original' . $show['poster_path'],
					"plot" => $show['overview'],
					"cast" => "",
					"director" => "",
					"genre" => "",
					"releaseDate" => $date,
					"last_modified" => $timestamp,
					"rating" => isset($show['vote_average']) ? $show['vote_average'] : 0,
					"rating_5based" => isset($show['vote_average']) ? ($show['vote_average'] / 2) : 0,
					"backdrop_path" => [
					  'https://image.tmdb.org/t/p/original' . $show['backdrop_path']
					],
					"youtube_trailer" => "",
					"episode_run_time" => "",
					"category_id" => "99999".$networkId
			  ];

                $id = $show['id'];

                // Check if the movie ID has already been added
                if (!isset($addedMovieIds[$id])) {
                    // Mark the movie ID as added
                    $addedMovieIds[$id] = true;

                    // Add the movie data to the output array
                    $outputData[] = $showData;

                    // M3U8 formatting for each movie (inside the M3U8 block)
                    $title = $show['name'];
                    $year = isset($date) ? substr($date, 0, 4) :
                        'N/A';
                    $poster = 'https://image.tmdb.org/t/p/original' . $show['poster_path'];
                    $id = $show['id'];

                    // Concatenate to the existing $outputContent using .=
                    $outputContent .= "#EXTINF:-1 group-title=\"$networkName\" tvg-id=\"$title\" tvg-logo=\"$poster\",$title ($year)\n$playVodUrl?movieId=$id\n\n";

                }
            }
        }
		
            if ($capturedTotalPages !== null && $page >= $capturedTotalPages) {
                break; // break out of the loop
            }
        }
    }

    return;
}

// Fetch popular series
function fetchOnTheAirSeries($playVodUrl, $language, $apiKey, $totalPages)
{
    global $outputData, $outputContent, $addedMovieIds, $listType, $series_with_origin_country, $num;
    $baseUrl = 'https://api.themoviedb.org/3/tv/on_the_air';
	
	 $capturedTotalPages = null;

    for ($page = 1; $page <= $totalPages; $page++) {
        $url = $baseUrl . "?api_key=$apiKey&include_adult=false&include_null_first_air_dates=false&sort_by=popularity.desc&with_origin_country=$series_with_origin_country&language=$language&page=$page";
        $data = fetchAndHandleErrors($url, 'Request for popular series failed.');
		
                    // Set the total pages after the first request
            if ($page == 1 && isset($data['total_pages'])) {
                $capturedTotalPages = $data['total_pages'];
            }

            if ($data !== null) {
                $series = $data['results'];

                foreach ($series as $show) {
                // JSON formatting for each show
				if (!isset($show['first_air_date']) || !isset($show['name']) || !isset($show['poster_path']) || !isset($show['id'])) {
					continue;
				}
                if (isset($show['first_air_date'])) {
                    $dateParts = explode("-", $show['first_air_date']);
                    $year = $dateParts[0];
					$date = $show['first_air_date'];
					$timestamp = strtotime($date);
                } else { 
					$date = '1970-01-01';
                    $year = '1970'; //Set to 1970 since its unknown.
					$timestamp = '24034884';
                }
					
				  $showData = [
					"num" => ++$num,
					"name" =>$show['name'] . ' (' . $year . ')',
					"series_id" => $show['id'],
					"cover" => 'https://image.tmdb.org/t/p/original' . $show['poster_path'],
					"plot" => $show['overview'],
					"cast" => "",
					"director" => "",
					"genre" => "",
					"releaseDate" => $date,
					"last_modified" => $timestamp,
					"rating" => isset($show['vote_average']) ? $show['vote_average'] : 0,
					"rating_5based" => isset($show['vote_average']) ? ($show['vote_average'] / 2) : 0,
					"backdrop_path" => [
					  'https://image.tmdb.org/t/p/original' . $show['backdrop_path']
					],
					"youtube_trailer" => "",
					"episode_run_time" => "",
					"category_id" => "88883"
			  ];

                $id = $show['id'];

                // Check if the movie ID has already been added
                if (!isset($addedMovieIds[$id])) {
                    // Mark the movie ID as added
                    $addedMovieIds[$id] = true;

                    // Add the movie data to the output array
                    $outputData[] = $showData;

                    // M3U8 formatting for each movie (inside the M3U8 block)
                    $title = $show['name'];
                    $year = isset($date) ? substr($date, 0, 4) :
                        'N/A';
                    $poster = 'https://image.tmdb.org/t/p/original' . $show['poster_path'];
                    $id = $show['id'];

                    // Concatenate to the existing $outputContent using .=
                    $outputContent .= "#EXTINF:-1 group-title=\"On The Air\" tvg-id=\"$title\" tvg-logo=\"$poster\",$title ($year)\n$playVodUrl?movieId=$id\n\n";

                }
            }
        }
		if ($capturedTotalPages !== null && $page >= $capturedTotalPages) {
            break; // break out of the loop
        }
    }

    return;

}

// Fetch popular series
function fetchPopularSeries($playVodUrl, $language, $apiKey, $totalPages)
{
    global $outputData, $outputContent, $addedMovieIds, $listType, $series_with_origin_country, $num;
    $baseUrl = 'https://api.themoviedb.org/3/discover/tv';
	
	 $capturedTotalPages = null;

    for ($page = 1; $page <= $totalPages; $page++) {
        $url = $baseUrl . "?api_key=$apiKey&include_adult=false&include_null_first_air_dates=false&sort_by=popularity.desc&with_origin_country=$series_with_origin_country&language=$language&page=$page";
        $data = fetchAndHandleErrors($url, 'Request for popular series failed.');
		
                    // Set the total pages after the first request
            if ($page == 1 && isset($data['total_pages'])) {
                $capturedTotalPages = $data['total_pages'];
            }

            if ($data !== null) {
                $series = $data['results'];

                foreach ($series as $show) {
                // JSON formatting for each show
				if (!isset($show['first_air_date']) || !isset($show['name']) || !isset($show['poster_path']) || !isset($show['id'])) {
					continue;
				}
                if (isset($show['first_air_date'])) {
                    $dateParts = explode("-", $show['first_air_date']);
                    $year = $dateParts[0];
					$date = $show['first_air_date'];
					$timestamp = strtotime($date);
                } else { 
					$date = '1970-01-01';
                    $year = '1970'; //Set to 1970 since its unknown.
					$timestamp = '24034884';
                }
					
				  $showData = [
					"num" => ++$num,
					"name" =>$show['name'] . ' (' . $year . ')',
					"series_id" => $show['id'],
					"cover" => 'https://image.tmdb.org/t/p/original' . $show['poster_path'],
					"plot" => $show['overview'],
					"cast" => "",
					"director" => "",
					"genre" => "",
					"releaseDate" => $date,
					"last_modified" => $timestamp,
					"rating" => isset($show['vote_average']) ? $show['vote_average'] : 0,
					"rating_5based" => isset($show['vote_average']) ? ($show['vote_average'] / 2) : 0,
					"backdrop_path" => [
					  'https://image.tmdb.org/t/p/original' . $show['backdrop_path']
					],
					"youtube_trailer" => "",
					"episode_run_time" => "",
					"category_id" => "88881"
			  ];

                $id = $show['id'];

                // Check if the movie ID has already been added
                if (!isset($addedMovieIds[$id])) {
                    // Mark the movie ID as added
                    $addedMovieIds[$id] = true;

                    // Add the movie data to the output array
                    $outputData[] = $showData;

                    // M3U8 formatting for each movie (inside the M3U8 block)
                    $title = $show['name'];
                    $year = isset($date) ? substr($date, 0, 4) :
                        'N/A';
                    $poster = 'https://image.tmdb.org/t/p/original' . $show['poster_path'];
                    $id = $show['id'];

                    // Concatenate to the existing $outputContent using .=
                    $outputContent .= "#EXTINF:-1 group-title=\"Popular\" tvg-id=\"$title\" tvg-logo=\"$poster\",$title ($year)\n$playVodUrl?movieId=$id\n\n";

                }
            }
        }
		if ($capturedTotalPages !== null && $page >= $capturedTotalPages) {
            break; // break out of the loop
        }
    }

    return;

}

// Fetch top rated series
function fetchTopRatedSeries($playVodUrl, $language, $apiKey, $totalPages)
{
    global $outputData, $outputContent, $addedMovieIds, $listType, $series_with_origin_country, $num;
    $baseUrl = 'https://api.themoviedb.org/3/tv/top_rated';
	
	 $capturedTotalPages = null;

    for ($page = 1; $page <= $totalPages; $page++) {
        $url = $baseUrl . "?api_key=$apiKey&include_adult=false&include_null_first_air_dates=false&sort_by=popularity.desc&with_origin_country=$series_with_origin_country&language=$language&page=$page";
        $data = fetchAndHandleErrors($url, 'Request for popular series failed.');
		
            // Set the total pages after the first request
            if ($page == 1 && isset($data['total_pages'])) {
                $capturedTotalPages = $data['total_pages'];
            }

            if ($data !== null) {
                $series = $data['results'];

                foreach ($series as $show) {
                // JSON formatting for each show
				if (!isset($show['first_air_date']) || !isset($show['name']) || !isset($show['poster_path']) || !isset($show['id'])) {
					continue;
				}
                if (isset($show['first_air_date'])) {
                    $dateParts = explode("-", $show['first_air_date']);
                    $year = $dateParts[0];
					$date = $show['first_air_date'];
					$timestamp = strtotime($date);
                } else { 
					$date = '1970-01-01';
                    $year = '1970'; //Set to 1970 since its unknown.
					$timestamp = '24034884';
                }
					
				  $showData = [
					"num" => ++$num,
					"name" =>$show['name'] . ' (' . $year . ')',
					"series_id" => $show['id'],
					"cover" => 'https://image.tmdb.org/t/p/original' . $show['poster_path'],
					"plot" => $show['overview'],
					"cast" => "",
					"director" => "",
					"genre" => "",
					"releaseDate" => $date,
					"last_modified" => $timestamp,
					"rating" => isset($show['vote_average']) ? $show['vote_average'] : 0,
					"rating_5based" => isset($show['vote_average']) ? ($show['vote_average'] / 2) : 0,
					"backdrop_path" => [
					  'https://image.tmdb.org/t/p/original' . $show['backdrop_path']
					],
					"youtube_trailer" => "",
					"episode_run_time" => "",
					"category_id" => "88882"
			  ];

                $id = $show['id'];

                // Check if the movie ID has already been added
                if (!isset($addedMovieIds[$id])) {
                    // Mark the movie ID as added
                    $addedMovieIds[$id] = true;

                    // Add the movie data to the output array
                    $outputData[] = $showData;

                    // M3U8 formatting for each movie (inside the M3U8 block)
                    $title = $show['name'];
                    $year = isset($date) ? substr($date, 0, 4) :
                        'N/A';
                    $poster = 'https://image.tmdb.org/t/p/original' . $show['poster_path'];
                    $id = $show['id'];

                    // Concatenate to the existing $outputContent using .=
                    $outputContent .= "#EXTINF:-1 group-title=\"Top Rated\" tvg-id=\"$title\" tvg-logo=\"$poster\",$title ($year)\n$playVodUrl?movieId=$id\n\n";

                }
            }
        }
		if ($capturedTotalPages !== null && $page >= $capturedTotalPages) {
            break; // break out of the loop
        }
    }

    return;

}

// Fetch genres and series for each genre
function fetchSeriesByGenre($genreId, $genreName, $playVodUrl, $language, $apiKey,
    $totalPages)
{
    global $outputData, $outputContent, $addedMovieIds, $listType, $series_with_origin_country, $num;
    $baseUrl = 'https://api.themoviedb.org/3/discover/tv';
	
	$capturedTotalPages = null;

    for ($page = 1; $page <= $totalPages; $page++) {
        $url = $baseUrl . "?api_key=$apiKey&include_adult=false&language=$language&with_origin_country=$series_with_origin_country&with_genres=$genreId&page=$page";
        $data = fetchAndHandleErrors($url, "Request for $genreName series failed.");
		
        // Set the total pages after the first request
            if ($page == 1 && isset($data['total_pages'])) {
                $capturedTotalPages = $data['total_pages'];
            }

            if ($data !== null) {
                $series = $data['results'];

                foreach ($series as $show) {
                // JSON formatting for each show
				if (!isset($show['first_air_date']) || !isset($show['name']) || !isset($show['poster_path']) || !isset($show['id'])) {
					continue;
				}
                if (isset($show['first_air_date'])) {
                    $dateParts = explode("-", $show['first_air_date']);
                    $year = $dateParts[0];
					$date = $show['first_air_date'];
					$timestamp = strtotime($date);
                } else { 
					$date = '1970-01-01';
                    $year = '1970'; //Set to 1970 since its unknown.
					$timestamp = '24034884';
                }
					
				  $showData = [
					"num" => ++$num,
					"name" =>$show['name'] . ' (' . $year . ')',
					"series_id" => $show['id'],
					"cover" => 'https://image.tmdb.org/t/p/original' . $show['poster_path'],
					"plot" => $show['overview'],
					"cast" => "",
					"director" => "",
					"genre" => "",
					"releaseDate" => $date,
					"last_modified" => $timestamp,
					"rating" => isset($show['vote_average']) ? $show['vote_average'] : 0,
					"rating_5based" => isset($show['vote_average']) ? ($show['vote_average'] / 2) : 0,
					"backdrop_path" => [
					  'https://image.tmdb.org/t/p/original' . $show['backdrop_path']
					],
					"youtube_trailer" => "",
					"episode_run_time" => "",
					"category_id" => $genreId
			  ];

                $id = $show['id'];

                // Check if the movie ID has already been added
                if (!isset($addedMovieIds[$id])) {
                    // Mark the movie ID as added
                    $addedMovieIds[$id] = true;

                    // Add the movie data to the output array
                    $outputData[] = $showData;

                    // M3U8 formatting for each movie (inside the M3U8 block)
                    $title = $show['name'];
                    $year = isset($date) ? substr($date, 0, 4) :
                        'N/A';
                    $poster = 'https://image.tmdb.org/t/p/original' . $show['poster_path'];
                    $id = $show['id'];

                    // Concatenate to the existing $outputContent using .=
                    $outputContent .= "#EXTINF:-1 group-title=\"$genreName\" tvg-id=\"$title\" tvg-logo=\"$poster\",$title ($year)\n$playVodUrl?movieId=$id\n\n";

                }
            }
        }
		if ($capturedTotalPages !== null && $page >= $capturedTotalPages) {
            break; // break out of the loop
        }
    }

    return;
}

// Fetch genres dynamically
function fetchGenres($playVodUrl, $language, $apiKey, $totalPages)
{
    global $outputData, $outputContent, $listType, $num;

    $genresUrl = "https://api.themoviedb.org/3/genre/tv/list?api_key=$apiKey&include_adult=false&language=$language";
    $genreData = fetchAndHandleErrors($genresUrl, 'Request for genres failed.');
    if ($genreData !== null) {
        $genres = $genreData['genres'];

        foreach ($genres as $genre) {
            if ($listType == 'json') {
                fetchSeriesByGenre($genre['id'], $genre['name'], $playVodUrl, $language, $apiKey,
                    $totalPages);
            } else {
                fetchSeriesByGenre($genre['id'], $genre['name'], $playVodUrl, $language, $apiKey,
                    $totalPages);
            }
        }
    }

    return;
}

function measureExecutionTime($func, ...$params) {
    $start = microtime(true);

    call_user_func($func, ...$params);

    $end = microtime(true);
    $elapsedTime = $end - $start;

    $minutes = (int) ($elapsedTime / 60);
    $seconds = $elapsedTime % 60;
    $milliseconds = ($seconds - floor($seconds)) * 1000;

    echo "Total Execution Time for $func: " . $minutes . " minute(s) and " . floor($seconds) . "." . sprintf('%03d', $milliseconds) . " second(s)</br>";
}

?>

<?php
// Created By gogetta.teams@gmail.com
// Please leave this in this script.
// https://github.com/gogetta69/TMDB-To-VOD-Playlist/

require_once 'config.php';
set_time_limit(0); // Remove PHP's time restriction

if ($GLOBALS['DEBUG'] !== true) {
    error_reporting(0);	
} else {
	accessLog();
}	

$domain = 'http://localhost';

if (php_sapi_name() != 'cli') {
    
    if (isset($_SERVER['HTTP_HOST']) && !empty($_SERVER['HTTP_HOST'])) {
        $domain = 'http://' . $_SERVER['HTTP_HOST'];
    }
} else {
    
    if (isset($userSetHost) && !empty($userSetHost)) {
        $domain = 'http://' . $userSetHost;
    }
}

$basePath = '/'; 

// If the script is not running in CLI mode, set the base path
if (php_sapi_name() != 'cli') {
    $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';
}

$playVodUrl = $domain . $basePath . "play.php";

//Set globals
$num = 0;
$outputData = []; // Initialize json content
$outputContent = "#EXTM3U\n"; // Initialize M3U8 content
$addedMovieIds = []; // Initialize to prevent duplicates


fetchMovies($playVodUrl, $language, $apiKey, $totalPages);

function fetchMovies($playVodUrl, $language, $apiKey, $totalPages)
{
    global $listType, $outputData, $outputContent, $num;

	//Limit some categories to less items. (This allows the other categories to be populated)
	$limitTotalPages = ($totalPages > 15) ? 15 : $totalPages;
	
    // Call the function for now playing
    measureExecutionTime('fetchNowPlayingMovies', $playVodUrl, $language, $apiKey, $limitTotalPages);

    // Call the function for popular
    measureExecutionTime('fetchPopularMovies', $playVodUrl, $language, $apiKey, $limitTotalPages);

    // Call the function for genres
    measureExecutionTime('fetchGenres', $playVodUrl, $language, $apiKey, $totalPages);

    //Save the Json and M3U8 Data
    file_put_contents('playlist.m3u8', $outputContent);

    file_put_contents('playlist.json', json_encode($outputData));

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

// Fetch now playing movies
function fetchNowPlayingMovies($playVodUrl, $language, $apiKey, $totalPages)
{
    global $outputData, $outputContent, $addedMovieIds, $movies_with_origin_country, $listType, $num;
    $baseUrl = 'https://api.themoviedb.org/3/movie/now_playing';
	
     $capturedTotalPages = null; 
    //$pagesForCategory = ceil(0.20 * $totalPages); // Calculate 20% of $totalPages for this category
    for ($page = 1; $page <= $totalPages; $page++) {
        $url = $baseUrl . "?api_key=$apiKey&include_adult=false&with_origin_country=$movies_with_origin_country&language=$language&page=$page";
        $data = fetchAndHandleErrors($url, 'Request for now playing movies failed.');
		
        // Set the total pages after the first request
        if ($page == 1 && isset($data['total_pages'])) {
            $capturedTotalPages = $data['total_pages'];
        }

        if ($data !== null) {
            $movies = $data['results'];

            foreach ($movies as $movie) {
                // JSON formatting for each movie
                if (isset($movie['release_date'])) {
                    $dateParts = explode("-", $movie['release_date']);
                    $year = $dateParts[0];
					$date = $movie['release_date'];
					$timestamp = strtotime($date);
                } else { 
					$date = '1970-01-01';
                    $year = '1970'; //Set to 1970 since its unknown.
					$timestamp = '24034884';
                }
                $movieData = ["num" => ++$num, "name" => $movie['title'] . ' (' . $year . ')',
                    "stream_type" => "movie", "stream_id" => $movie['id'], "stream_icon" =>
                    'https://image.tmdb.org/t/p/original' . $movie['poster_path'], "rating" => isset($movie['vote_average']) ?
                    $movie['vote_average'] : 0, "rating_5based" => isset($movie['vote_average']) ? ($movie['vote_average'] /
                    2) : 0, "added" => $timestamp, "category_id" => 999992, "container_extension" =>
                    "mp4", // Use mp4 as a dummy value.
                    "custom_sid" => null, "direct_source" => $playVodUrl . '?movieId=' . $movie['id'],
                    "plot" => $movie['overview'], "backdrop_path" =>
                    'https://image.tmdb.org/t/p/original' . $movie['backdrop_path'], "group" =>
                    'Now Playing'];

                $id = $movie['id'];

                // Check if the movie ID has already been added
                if (!isset($addedMovieIds[$id])) {
                    // Mark the movie ID as added
                    $addedMovieIds[$id] = true;

                    // Add the movie data to the output array
                    $outputData[] = $movieData;

                    // M3U8 formatting for each movie (inside the M3U8 block)
                    $title = $movie['title'];
                    $year = isset($date) ? substr($date, 0, 4) :
                        'N/A';
                    $poster = 'https://image.tmdb.org/t/p/original' . $movie['poster_path'];
                    $id = $movie['id'];

                    // Concatenate to the existing $outputContent using .=
                    $outputContent .= "#EXTINF:-1 group-title=\"Now Playing\" tvg-id=\"$title\" tvg-logo=\"$poster\",$title ($year)\n$playVodUrl?movieId=$id\n\n";

                }
            }
        }
		
		if ($capturedTotalPages !== null && $page >= $capturedTotalPages) {
            break; // break out of the loop
        }
    }

    return;
}

// Fetch popular movies
function fetchPopularMovies($playVodUrl, $language, $apiKey, $totalPages)
{
    global $outputData, $outputContent, $addedMovieIds, $movies_with_origin_country, $listType, $num;
    $baseUrl = 'https://api.themoviedb.org/3/movie/popular';
	
	 $capturedTotalPages = null;

    for ($page = 1; $page <= $totalPages; $page++) {
        $url = $baseUrl . "?api_key=$apiKey&include_adult=false&with_origin_country=$movies_with_origin_country&language=$language&page=$page";
        $data = fetchAndHandleErrors($url, 'Request for popular movies failed.');
		
        // Set the total pages after the first request
        if ($page == 1 && isset($data['total_pages'])) {
            $capturedTotalPages = $data['total_pages'];
        }

        if ($data !== null) {
            $movies = $data['results'];


            foreach ($movies as $movie) {
                // JSON formatting for each movie
                if (isset($movie['release_date'])) {
                    $dateParts = explode("-", $movie['release_date']);
                    $year = $dateParts[0];
					$date = $movie['release_date'];
					$timestamp = strtotime($date);
                } else { 
					$date = '1970-01-01';
                    $year = '1970'; //Set to 1970 since its unknown.
					$timestamp = '24034884';
                }
                $movieData = ["num" => ++$num, "name" => $movie['title'] . ' (' . $year . ')',
                    "stream_type" => "movie", "stream_id" => $movie['id'], "stream_icon" =>
                    'https://image.tmdb.org/t/p/original' . $movie['poster_path'], "rating" => isset($movie['vote_average']) ?
                    $movie['vote_average'] : 0, "rating_5based" => isset($movie['vote_average']) ? ($movie['vote_average'] /
                    2) : 0, "added" => time(), "category_id" => 999991, "container_extension" =>
                    "mp4", // Use mp4 as a dummy value.
                    "custom_sid" => null, "direct_source" => $playVodUrl . '?movieId=' . $movie['id'],
                    "plot" => $movie['overview'], "backdrop_path" =>
                    'https://image.tmdb.org/t/p/original' . $movie['backdrop_path'], "group" =>
                    'Popular'];

                $id = $movie['id'];

                // Check if the movie ID has already been added
                if (!isset($addedMovieIds[$id])) {
                    // Mark the movie ID as added
                    $addedMovieIds[$id] = true;

                    // Add the movie data to the output array
                    $outputData[] = $movieData;

                    // M3U8 formatting for each movie (inside the M3U8 block)
                    $title = $movie['title'];
                    $year = isset($date) ? substr($date, 0, 4) :
                        'N/A';
                    $poster = 'https://image.tmdb.org/t/p/original' . $movie['poster_path'];

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

// Fetch genres and movies for each genre
function fetchMoviesByGenre($genreId, $genreName, $playVodUrl, $language, $apiKey,
    $totalPages)
{
    global $outputData, $outputContent, $addedMovieIds, $movies_with_origin_country, $listType, $num;
    $baseUrl = 'https://api.themoviedb.org/3/discover/movie';
	
	$capturedTotalPages = null;

    for ($page = 1; $page <= $totalPages; $page++) {
        $url = $baseUrl . "?api_key=$apiKey&include_adult=false&with_origin_country=$movies_with_origin_country&language=$language&with_genres=$genreId&page=$page";
        $data = fetchAndHandleErrors($url, "Request for $genreName movies failed.");
		
        // Set the total pages after the first request
        if ($page == 1 && isset($data['total_pages'])) {
            $capturedTotalPages = $data['total_pages'];
        }

        if ($data !== null) {
            $movies = $data['results'];

            foreach ($movies as $movie) {
                // JSON formatting for each movie
                if (isset($movie['release_date'])) {
                    $dateParts = explode("-", $movie['release_date']);
                    $year = $dateParts[0];
					$date = $movie['release_date'];
					$timestamp = strtotime($date);
                } else { 
					$date = '1970-01-01';
                    $year = '1970'; //Set to 1970 since its unknown.
					$timestamp = '24034884';
                }

                $movieData = ["num" => ++$num, "name" => $movie['title'] . ' (' . $year . ')',
                    "stream_type" => "movie", "stream_id" => $movie['id'], "stream_icon" =>
                    'https://image.tmdb.org/t/p/original' . $movie['poster_path'], "rating" => isset($movie['vote_average']) ?
                    $movie['vote_average'] : 0, "rating_5based" => isset($movie['vote_average']) ? ($movie['vote_average'] /
                    2) : 0, "added" => $timestamp, "category_id" => $movie['genre_ids'][0],
                    "container_extension" => "mp4", // Use mp4 as a dummy value.
                    "custom_sid" => null, "direct_source" => $playVodUrl . '?movieId=' . $movie['id'],
                    "plot" => $movie['overview'], "backdrop_path" =>
                    'https://image.tmdb.org/t/p/original' . $movie['backdrop_path'], "group" => $genreName];

                $id = $movie['id'];

                // Check if the movie ID has already been added
                if (!isset($addedMovieIds[$id])) {
                    // Mark the movie ID as added
                    $addedMovieIds[$id] = true;

                    // Add the movie data to the output array
                    $outputData[] = $movieData;


                    // M3U8 formatting for each movie (inside the M3U8 block)
                    $title = $movie['title'];
                    $year = isset($date) ? substr($date, 0, 4) :
                        'N/A';
                    $poster = 'https://image.tmdb.org/t/p/original' . $movie['poster_path'];
                    $id = $movie['id'];

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

    $genresUrl = "https://api.themoviedb.org/3/genre/movie/list?api_key=$apiKey&include_adult=false&language=$language";
    $genreData = fetchAndHandleErrors($genresUrl, 'Request for genres failed.');
    if ($genreData !== null) {
        $genres = $genreData['genres'];

        foreach ($genres as $genre) {
            if ($listType == 'json') {
                fetchMoviesByGenre($genre['id'], $genre['name'], $playVodUrl, $language, $apiKey,
                    $totalPages);
            } else {
                fetchMoviesByGenre($genre['id'], $genre['name'], $playVodUrl, $language, $apiKey,
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

<?php
// Created By gogetta.teams@gmail.com
// Please leave this in this script.
// https://github.com/gogetta69/TMDB-To-VOD-Playlist

set_time_limit(0); // Suppress the PHP timeout limit
error_reporting(0);

// CONFIGURATION
$categoryId = 37;
$baseUrl = 'https://watchpornx.net/wp-json/wp/v2/posts';
$perPage = 100;
$maxPages = 5;

$page = 1;
$counter = 999999999;
$counter++;
$moviesData = [];
$categories = []; // optional: id → name map

// Function to clean the description by removing URLs and stripping HTML tags
function cleanDescription($content) {
    // Remove URLs
    $content = preg_replace('/https?:\/\/[^\s"<]+/i', '', $content);
    // Decode HTML entities
    $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5);
    // Remove newlines
    $content = str_replace("\n", '', $content);
    // Strip HTML tags
    $content = strip_tags($content);
    return $content;
}

// FETCH & LOOP
do {
    $url = "$baseUrl?per_page=$perPage&categories=$categoryId&page=$page";
    $json = file_get_contents($url);
    if (!$json) {
        break;
    }

    $response = json_decode($json, true);
    if (empty($response)) {
        break;
    }

    foreach ($response as $item) {
        $title = html_entity_decode(strip_tags($item['title']['rendered']));
        $rawDesc = $item['excerpt']['rendered'] ?? '';
        $description = cleanDescription($rawDesc);

        $posterPath = $item['jetpack_featured_media_url'] ?? '';
        $streamUrls = [$item['link']];
        $addedTime = time();

        $categoryIds = $item['categories'] ?? [];
        $categoryNamesList = [];
        foreach ($categoryIds as $catId) {
            $categoryNamesList[] = $categories[$catId] ?? "Category $catId";
        }

				// Extract class_list from this $item
				$class_list = $item['class_list'] ?? [];

				// Genres
				$genres = [];
				foreach ($class_list as $class) {
						if (strpos($class, 'genres-') === 0) {
								$genres[] = ucwords(str_replace('-', ' ', substr($class, 7)));
						}
				}
				$genres = array_slice($genres, 0, 5);
				$genres_string = implode(', ', $genres);

				// Cast
				$cast = [];
				foreach ($class_list as $class) {
						if (strpos($class, 'cast-') === 0) {
								$cast[] = ucwords(str_replace('-', ' ', substr($class, 5)));
						}
				}
				$cast = array_slice($cast, 0, 5);
				$cast_string = implode(', ', $cast);

				// Director (first only)
				$director = '';
				foreach ($class_list as $class) {
						if (strpos($class, 'director-') === 0) {
								$director = ucwords(str_replace('-', ' ', substr($class, 9)));
								break;
						}
				}

				$plot_extra = '';

				if ($genres_string !== '') {
						$plot_extra .= "Genres: $genres_string.";
				}
				if ($director !== '') {
						$plot_extra .= " Director: $director.";
				}
				if ($cast_string !== '') {
						$plot_extra .= " Stars: $cast_string.";
				}

				if ($description !== '') {
						$full_description = $description . "\n\n" . ltrim($plot_extra);
				} else {
						$full_description = ltrim($plot_extra);
				}

				$obfuscated = base64_encode(json_encode($streamUrls));
        $moviesData[] = [
            'num' => $counter,
            'name' => $title,
            'stream_type' => 'adult',
            'stream_id' => $counter,
            'stream_icon' => $posterPath,
            'rating' => 0,
            'rating_5based' => 0,
            'added' => $addedTime,
            'category_id' => '999993',
            'parent_id' => 2,
            'container_extension' => "mp4",
            'custom_sid' => null,
            'direct_source' => '[[SERVER_URL]]/play.php?movieId=' . $counter,
            'plot' => $full_description,
            'genres' => $genres_string,
						'director' => $director,
						'cast' => $cast_string,
            'backdrop_path' => '',
            'group' => 'Adult Movies',
            'sub_group' => isset($categories[$categoryIds[0]]) ? $categories[$categoryIds[0]] : 'Unknown',
            'sources' => $obfuscated
        ];

        $counter++;
    }

    $page++;
} while (count($response) === $perPage && $page <= $maxPages);

// Save the data as JSON
file_put_contents('adult-movies.json', json_encode($moviesData, JSON_PRETTY_PRINT));

echo "Scraping complete.";

?>


<?php
// too heavy...
// include dirname(dirname(dirname(dirname(__FILE__)))).'/wp-blog-header.php';
// if ( ! is_user_logged_in()) die();

// tiny check
// $ok = false;
// if (isset($_COOKIE))
// {
// 	foreach (array_keys($_COOKIE) as $k)
// 	{
// 		if (substr($k, 0, 20) == 'wordpress_logged_in_')
// 		{
// 			$ok = TRUE;
// 			break;
// 		}
// 	}
// }

$ok = true;
$image_file = isset($_GET['path']) ? dirname(dirname(__DIR__)).'/dashi_uploads/'.$_GET['path'] : '';

if (
	$ok &&
	file_exists($image_file) &&
	in_array(substr(strtolower($image_file), -4), array('.jpg', 'jpeg', '.pdf'))
)
{
	$size = filesize($image_file);
	header("Content-Length: $size");
	if (substr(strtolower($image_file), -3) == 'pdf')
	{
		header("Content-type: application/pdf");
	}
	else
	{
		header("Content-type: image/jpeg");
	}
	readfile($image_file);
}
else
{
	header("HTTP/1.0 404 Not Found");
	die();
}

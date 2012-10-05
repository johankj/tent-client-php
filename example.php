<?php

// EXAMPLE

require_once 'tentapp.class.php';

echo "----------------------------------------------------------------/////\n";

$entityURL = 'https://jj.tent.is/';
$app = new TentApp($entityURL);

echo "Your profile without authentication:\n";

$posts = $app->getPosts();
foreach ($posts as $post) {
	if ($post['type'] == "https://tent.io/types/post/status/v0.1.0") {
		echo $post['content']['text']."\n";
	}
}

echo "----------------------------------------------------------------/////\n";


$keys = array(
	'mac_key_id' => 'u:d*****d0',
	'mac_key' => 'bcb2*****5*****7a7*****3f7eec70'
);

if (isset($keys['mac_key_id']) && isset($keys['mac_key'])) {

	echo "Your profile with authentication:\n";
	$app->authenticate($keys);

	$posts = $app->getPosts();
	foreach ($posts as $post) {
		if ($post['type'] == "https://tent.io/types/post/status/v0.1.0") {
			echo $post['content']['text']."\n";
		}
	}

	$followings = $app->getFollowings();
	foreach ($followings as $following) {
		if (isset($following['profile']['https://tent.io/types/info/basic/v0.1.0'])) {
			echo $following['profile']['https://tent.io/types/info/basic/v0.1.0']['name'];
		}
		echo " -> ".$following['entity']."\n";
	}

	echo "----------------------------------------------------------------/////\n";

}

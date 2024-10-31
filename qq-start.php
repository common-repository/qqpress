<?php
include "../../../wp-config.php";

if(!class_exists('MBOpenTOAuth')){
	include dirname(__FILE__).'/opent.php';
}

$to = new MBOpenTOAuth($qq_consumer_key, $qq_consumer_secret);

if($_GET['callback']) {
	$callback = $_GET['callback'];
} else{
	$callback = get_option('home');
}

$tok = $to->getRequestToken($callback);
$_SESSION["qq_oauth_token_secret"] = $tok['oauth_token_secret'];
$request_link = $to->getAuthorizeURL($tok['oauth_token'], false, '');

header('Location:'.$request_link);
?>

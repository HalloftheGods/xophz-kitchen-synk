<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php');
$api = new Kitchen_Synk_API();
$reflection = new ReflectionMethod($api, 'get_api_key');
$reflection->setAccessible(true);
$key = $reflection->invoke($api);
$url = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . $key;
$response = file_get_contents($url);
echo $response;

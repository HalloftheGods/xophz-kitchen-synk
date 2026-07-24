<?php
require_once('../../../wp-load.php');
$api = new Xophz_Kitchen_Synk_API();
$reflection = new ReflectionClass($api);
$method = $reflection->getMethod('get_api_key');
$method->setAccessible(true);
$api_key = $method->invoke($api);

$log = json_decode(file_get_contents('test_models.log'), true);
foreach ($log['models'] as $m) {
    $name = str_replace('models/', '', $m['name']);
    if (strpos($name, 'image') !== false || strpos(json_encode($m), 'image') !== false) {
        echo "Found candidate: " . $name . "\n";
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$name}:predict?key=" . $api_key;
        $body = ['instances' => [['prompt' => 'a strawberry']], 'parameters' => ['sampleCount' => 1]];
        $res = wp_remote_post($url, ['headers' => ['Content-Type' => 'application/json'], 'body' => json_encode($body), 'timeout' => 15]);
        $code = wp_remote_retrieve_response_code($res);
        $b = wp_remote_retrieve_body($res);
        echo "  :predict -> {$code}: " . substr($b, 0, 150) . "\n";
    }
}

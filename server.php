<?php

declare(strict_types=1);

/**
 * Development router for php artisan serve.
 * Place this file beside artisan, not inside public or vendor.
 * Existing files are served directly; directories such as public/rms
 * must continue through Laravel so the /rms dashboard route can run.
 */
$publicPath = __DIR__.'/public';

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/'
);

if ($uri !== '/' && is_file($publicPath.$uri)) {
    return false;
}

$formattedDateTime = date('D M j H:i:s Y');

$requestMethod = $_SERVER['REQUEST_METHOD'];
$remoteAddress = $_SERVER['REMOTE_ADDR'].':'.$_SERVER['REMOTE_PORT'];

file_put_contents('php://stdout', "[$formattedDateTime] $remoteAddress [$requestMethod] URI: $uri\n");

require_once $publicPath.'/index.php';

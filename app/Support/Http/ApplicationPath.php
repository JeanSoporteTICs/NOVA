<?php

namespace App\Support\Http;

final class ApplicationPath
{
    /** @param array<string,scalar> $query */
    public static function make(string $requestBaseUrl, string $path, array $query = []): string
    {
        $baseUrl = trim($requestBaseUrl);
        $baseUrl = $baseUrl === '' || $baseUrl === '/' ? '' : '/'.trim($baseUrl, '/');
        $url = $baseUrl.'/'.ltrim($path, '/');

        return $query === [] ? $url : $url.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}

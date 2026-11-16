<?php

namespace App\Services\Content;

/** Content types for package files (the proxy must send the right one, or browsers refuse scripts and styles). */
final class Mime
{
    private const MAP = [
        'html' => 'text/html; charset=utf-8', 'htm' => 'text/html; charset=utf-8', 'js' => 'application/javascript; charset=utf-8', 'mjs' => 'application/javascript; charset=utf-8', 'css' => 'text/css; charset=utf-8', 'json' => 'application/json; charset=utf-8',
        'xml' => 'application/xml; charset=utf-8', 'xsd' => 'application/xml', 'txt' => 'text/plain; charset=utf-8', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'webp' => 'image/webp',
        'ico' => 'image/x-icon', 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'm4a' => 'audio/mp4', 'pdf' => 'application/pdf', 'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
        'swf' => 'application/x-shockwave-flash', 'zip' => 'application/zip', 'vtt' => 'text/vtt', 'srt' => 'text/plain', 'wasm' => 'application/wasm', 'map' => 'application/json',
    ];

    public static function of(string $name): string
    {
        return self::MAP[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    }
}

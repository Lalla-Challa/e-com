<?php

declare(strict_types=1);

namespace AppCore;

/**
 * Frontend delivery for production (no Vite dev server):
 *   - site root "/"   → JSON status payload
 *   - /app and /app/* → the built SPA's index.html
 *   - /assets/*       → built SPA assets with proper MIME types
 * This is the ONLY sanctioned WP-facing rendering surface.
 */
final class Frontend
{
    public static function serveRootOrSpa(string $request, string $spaPath): never
    {
        if ($request === '') {
            // The acceptance payload for the WP site root.
            Http::send(200, ['ok' => true, 'service' => 'app-core', 'status' => 'passive']);
        }

        $dist = Env::projectRoot() . '/src/frontend/dist';
        $cleanPath = str_replace(['..', "\0"], '', ltrim($spaPath, '/'));

        if ($cleanPath === '' || $cleanPath === 'index.html') {
            self::streamHtml($dist . '/index.html');
        }

        $asset = $dist . '/' . $cleanPath;
        if (is_file($asset)) {
            self::streamFile($asset);
        }

        // SPA fallback: deep links stream index.html and react-router takes over.
        self::streamHtml($dist . '/index.html');
    }

    public static function serveAsset(string $filename): never
    {
        $dist = Env::projectRoot() . '/src/frontend/dist/assets';
        $clean = str_replace(['..', "\0", '/'], '', $filename);
        self::streamFile($dist . '/' . $clean);
    }

    private static function streamHtml(string $path): never
    {
        if (!is_file($path)) {
            Http::error(404, 'NOT_FOUND', 'SPA build not found. Run `npm run build` in src/frontend.');
        }
        // WP may already have queued a 404 status (no post matched) — the SPA
        // deep link is a real 200.
        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache');
        readfile($path);
        exit;
    }

    private static function streamFile(string $path): never
    {
        if (!is_file($path)) {
            Http::error(404, 'NOT_FOUND', 'Asset not found.');
        }
        // Never inherit a queued 404 status from the main query (see streamHtml).
        http_response_code(200);
        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'js', 'mjs' => 'text/javascript; charset=utf-8',
            'css' => 'text/css; charset=utf-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'json', 'map' => 'application/json',
            default => 'application/octet-stream',
        };
        header('Content-Type: ' . $mime);
        header('Cache-Control: public, max-age=31536000, immutable');
        readfile($path);
        exit;
    }
}

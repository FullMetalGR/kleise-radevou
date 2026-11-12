<?php

declare(strict_types=1);

namespace Tests\Browser\Support;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Tests\Support\TemporaryFiles;

/**
 * The browser plugin's in-process server builds each request with no files (its
 * LaravelHttpServer passes an empty array where the uploads go, marked as still to do), so a
 * file a page uploads never reaches the form request. This middleware, prepended by a browser
 * test that uploads, reads the multipart body the browser sent and hands the parts over as
 * the fields and files a real SAPI would have parsed. Test support only: production PHP does
 * this itself before the framework sees the request.
 */
final class MultipartBodies
{
    public function handle(Request $request, Closure $next): mixed
    {
        $type = (string) $request->headers->get('Content-Type', '');

        if ($request->files->count() === 0 && preg_match('/multipart\/form-data;.*boundary=("?)([^";]+)\1/i', $type, $match) === 1) {
            self::parse($request, $match[2]);
        }

        return $next($request);
    }

    /**
     * Each part sits between "--boundary" delimiters, its headers separated from its content
     * by a blank line; a part naming a file becomes an upload, any other a field.
     */
    private static function parse(Request $request, string $boundary): void
    {
        foreach (explode("\r\n--".$boundary, "\r\n".$request->getContent()) as $part) {
            if ($part === '' || str_starts_with($part, '--')) {
                continue;
            }

            [$head, $content] = array_pad(explode("\r\n\r\n", ltrim($part, "\r\n"), 2), 2, '');

            if (preg_match('/name="([^"]*)"/', $head, $name) !== 1) {
                continue;
            }

            if (preg_match('/filename="([^"]*)"/', $head, $file) !== 1) {
                $request->request->set($name[1], $content);

                continue;
            }

            $path = TemporaryFiles::make('kr-upload');
            file_put_contents($path, $content);
            $mime = preg_match('/Content-Type:\s*([^\r\n]+)/i', $head, $type) === 1 ? trim($type[1]) : null;
            $request->files->set($name[1], new UploadedFile($path, $file[1], $mime, null, true));
        }
    }
}

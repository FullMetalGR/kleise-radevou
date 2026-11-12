<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /embed.js on the tenant host: the one script a tenant pastes, whose URL names the tenant
 * and so is stable forever and cannot carry a content hash. The build writes the bundle to
 * public/build, outside version control, and this route streams it with a short cache lifetime
 * and two validators, an ETag over the content and the file's modification time, so a browser
 * that holds a copy asks once every few minutes and gets a 304 until a deploy changes the
 * bytes. Going through the application rather than the web server keeps those headers in this
 * repository, where the contract is, and keeps the route behind tenant resolution like every
 * other: an unknown label is a 404 and a suspended tenant gets the static page, script request
 * or not.
 *
 * A deployed tree without the build answers 404 and says so in the log, because a tenant page
 * that quietly shows nothing is the failure nobody reports.
 *
 * Public by design: no key and no token on the read surface.
 */
final class EmbedScriptController extends Controller
{
    public const string BUNDLE = 'build/embed.js';

    /**
     * Five minutes: long enough that a page reload costs no request, short enough that a deploy
     * reaches every tenant page before anyone notices the old one.
     */
    public const int MAX_AGE = 300;

    public function __invoke(Request $request, Application $app, LoggerInterface $log): Response
    {
        $path = $app->publicPath(self::BUNDLE);
        $modified = is_file($path) ? filemtime($path) : false;
        $contents = $modified === false ? false : file_get_contents($path);

        if ($modified === false || $contents === false) {
            $log->error(sprintf('The embed bundle is missing at %s; run npm run build before serving.', $path));

            throw new NotFoundHttpException;
        }

        $response = new Response($contents, Response::HTTP_OK, ['Content-Type' => 'text/javascript; charset=utf-8']);
        $response->setEtag(hash('xxh128', $contents));
        $response->setLastModified(new DateTimeImmutable('@'.$modified, new DateTimeZone('UTC')));
        $response->setPublic();
        $response->setMaxAge(self::MAX_AGE);
        $response->isNotModified($request);

        return $response;
    }
}

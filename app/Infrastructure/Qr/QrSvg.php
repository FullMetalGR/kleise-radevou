<?php

declare(strict_types=1);

namespace App\Infrastructure\Qr;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * A QR code as an SVG document, generated on our server by a pure-PHP library, the user's choice
 * at ticketing: no third party is asked and no image extension is needed. The text is encoded in
 * byte mode at level M, which survives a scuffed print, inside the standard quiet zone of four
 * modules; the document is plain ASCII.
 */
final readonly class QrSvg
{
    /** The drawing's side in pixels; an SVG scales, so this is the size a viewer opens it at. */
    private const int SIZE = 512;

    private const int QUIET_ZONE = 4;

    public static function of(string $text): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(self::SIZE, self::QUIET_ZONE), new SvgImageBackEnd));

        return $writer->writeString($text, Encoder::DEFAULT_BYTE_MODE_ENCODING, ErrorCorrectionLevel::M());
    }
}

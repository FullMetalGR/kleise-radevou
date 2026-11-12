<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use InvalidArgumentException;

/**
 * A file the image store will not keep: its bytes are not a PNG, a JPEG or a WebP, whatever its
 * name says, or they say so and no decoder reads them. The upload's own request refuses the
 * first case in words before the store is asked; this is what reaches a caller that did not.
 */
final class ImageRefused extends InvalidArgumentException {}

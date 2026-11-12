<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Infrastructure\Storage\TenantImages;
use Illuminate\Http\UploadedFile;
use LogicException;

/**
 * The body of POST /admin/settings/logo: one raster image of at most a megabyte, PNG, JPEG
 * or WebP and nothing else. The type is read from the bytes, not the name, so an SVG
 * called logo.png is refused in words: an SVG is a script host and never a logo here, and
 * a GIF is refused the same way. A file the engine itself refused, over its own upload
 * limit, is refused with the size sentence rather than the framework's.
 */
final class LogoRequest extends ImageUploadRequest
{
    public const string FIELD = 'logo';

    public const string TYPE = 'admin/shell.validation.upload.logo_type';

    /** Read as a choice by MAX_KILOBYTES' megabytes, :n the figure in words. */
    public const string SIZE = 'admin/shell.validation.upload.logo_size';

    /** The kilobytes the upload is refused above. */
    public const int MAX_KILOBYTES = 1024;

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            self::FIELD => ['required', 'file', 'mimes:'.TenantImages::TYPES, 'max:'.self::MAX_KILOBYTES],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            self::FIELD.'.required' => $this->line(self::MISSING),
            self::FIELD.'.file' => $this->line(self::MISSING),
            self::FIELD.'.uploaded' => $this->sizeLine(self::SIZE, self::MAX_KILOBYTES),
            self::FIELD.'.mimes' => $this->line(self::TYPE),
            self::FIELD.'.max' => $this->sizeLine(self::SIZE, self::MAX_KILOBYTES),
        ];
    }

    public function logo(): UploadedFile
    {
        $file = $this->file(self::FIELD);

        if (! $file instanceof UploadedFile) {
            throw new LogicException('The logo was validated as a file and is not one.');
        }

        return $file;
    }
}

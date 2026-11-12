<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Infrastructure\Storage\TenantImages;
use Illuminate\Http\UploadedFile;
use LogicException;

/**
 * The body of POST /admin/settings/cover: one raster image, PNG, JPEG or WebP as the logo's
 * is, typed from the bytes and never the name, of at most eight megabytes before the store
 * scales it down to the cover's own edge. A file the engine itself refused, over its own
 * upload limit, is refused with the size sentence rather than the framework's.
 */
final class CoverRequest extends ImageUploadRequest
{
    public const string FIELD = 'cover';

    public const string TYPE = 'admin/shell.validation.upload.cover_type';

    /** Read as a choice by MAX_KILOBYTES' megabytes, :n the figure in words. */
    public const string SIZE = 'admin/shell.validation.upload.cover_size';

    /** The kilobytes the upload is refused above, before the resize. */
    public const int MAX_KILOBYTES = 8192;

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

    public function cover(): UploadedFile
    {
        $file = $this->file(self::FIELD);

        if (! $file instanceof UploadedFile) {
            throw new LogicException('The cover was validated as a file and is not one.');
        }

        return $file;
    }
}

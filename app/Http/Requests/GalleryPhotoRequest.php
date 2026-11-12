<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Infrastructure\Storage\TenantImages;
use Illuminate\Http\UploadedFile;
use LogicException;

/**
 * The body of POST /admin/settings/gallery: one photo for the gallery, a raster image, PNG, JPEG
 * or WebP as the logo's is, typed from the bytes and never the name, of at most eight megabytes
 * before the store scales it down to the gallery's own edge. A file the engine itself refused,
 * over its own upload limit, is refused with the size sentence rather than the framework's.
 * Whether the gallery has room is the controller's question, under the row's lock.
 */
final class GalleryPhotoRequest extends ImageUploadRequest
{
    public const string FIELD = 'photo';

    public const string TYPE = 'admin/shell.validation.upload.gallery_type';

    /** Read as a choice by MAX_KILOBYTES' megabytes, :n the figure in words. */
    public const string SIZE = 'admin/shell.validation.upload.gallery_size';

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

    public function photo(): UploadedFile
    {
        $file = $this->file(self::FIELD);

        if (! $file instanceof UploadedFile) {
            throw new LogicException('The gallery photo was validated as a file and is not one.');
        }

        return $file;
    }
}

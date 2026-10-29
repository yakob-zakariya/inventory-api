<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Keeps one image per model row (a product photo, the shop logo) on the public disk.
 * The file it replaces is deleted only after the new path is saved.
 */
class ImageStore
{
    private const DISK = 'public';

    /**
     * Store the upload under the folder and point the column at it.
     */
    public function replace(Model $model, string $column, UploadedFile $file, string $folder): void
    {
        $path = $file->store($folder, self::DISK);

        try {
            $this->point($model, $column, $path);
        } catch (Throwable $exception) {
            Storage::disk(self::DISK)->delete($path);

            throw $exception;
        }
    }

    /**
     * Clear the column and delete its file.
     */
    public function remove(Model $model, string $column): void
    {
        $this->point($model, $column, null);
    }

    /**
     * The public URL of a stored image, or null when there is none.
     */
    public static function url(?string $path): ?string
    {
        return $path === null ? null : Storage::disk(self::DISK)->url($path);
    }

    /**
     * Save the new path under a row lock, so two uploads at once cannot both keep
     * the same old file, then delete the old file.
     */
    private function point(Model $model, string $column, ?string $path): void
    {
        $oldPath = DB::transaction(function () use ($model, $column, $path) {
            $locked = $model->newQuery()->lockForUpdate()->findOrFail($model->getKey());
            $oldPath = $locked->getAttribute($column);
            $locked->forceFill([$column => $path])->save();

            return $oldPath;
        });

        $model->setAttribute($column, $path)->syncOriginalAttribute($column);

        if ($oldPath !== null && $oldPath !== $path) {
            Storage::disk(self::DISK)->delete($oldPath);
        }
    }
}

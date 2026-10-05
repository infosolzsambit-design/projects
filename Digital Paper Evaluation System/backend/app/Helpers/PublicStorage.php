<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Storage;

/**
 * On the live server PHP runs as the web-server user, not the FTP account,
 * so a folder Laravel creates (answer-sheets/5/, ...) is owned by the web
 * server with 0755 — the FTP account can read it but not delete anything
 * inside it (deleting needs write permission on the *folder*). PHP can't
 * chown to the FTP user, so instead every upload folder it creates is
 * opened to 0777. Files themselves keep Laravel's 0644: they can be
 * deleted over FTP, but not altered.
 *
 * mkdir() is limited by the server umask (0777 → 0755), which is why this
 * is an explicit chmod rather than a filesystems.php permissions setting.
 */
class PublicStorage
{
    public const FOLDER_MODE = 0777;

    /**
     * Opens $relativeDir and every parent folder of it on the public disk
     * — e.g. 'answer-sheets/5' opens 'answer-sheets' and 'answer-sheets/5'.
     * Silently skips any folder PHP doesn't own (e.g. one created over FTP,
     * which the FTP account can already manage).
     */
    public static function openFolder(string $relativeDir): void
    {
        $path = '';
        foreach (explode('/', trim($relativeDir, '/')) as $part) {
            $path = ltrim("{$path}/{$part}", '/');
            self::chmod(Storage::disk('public')->path($path));
        }
    }

    /**
     * Opens every folder under the public disk, plus public/storage itself
     * (one-off fix for folders created before this existed). Returns how
     * many were changed.
     */
    public static function openAllFolders(): int
    {
        $changed = self::chmod(Storage::disk('public')->path('')) ? 1 : 0;
        foreach (Storage::disk('public')->allDirectories() as $dir) {
            $changed += self::chmod(Storage::disk('public')->path($dir)) ? 1 : 0;
        }

        return $changed;
    }

    private static function chmod(string $absolute): bool
    {
        if (! is_dir($absolute) || (fileperms($absolute) & 0777) === self::FOLDER_MODE) {
            return false;
        }

        return @chmod($absolute, self::FOLDER_MODE);
    }
}

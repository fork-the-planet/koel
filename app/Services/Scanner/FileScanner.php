<?php

namespace App\Services\Scanner;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Song;
use App\Models\User;
use App\Repositories\SongRepository;
use App\Services\MediaBrowser;
use App\Services\MediaMetadataService;
use App\Services\Scanner\Contracts\ScannerCacheStrategy as CacheStrategy;
use App\Services\SimpleLrcReader;
use App\Values\Scanning\ScanConfiguration;
use App\Values\Scanning\ScanResult;
use App\Values\Scanning\SongScanInformation;
use getID3;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Throwable;

class FileScanner
{
    private ?int $fileModifiedTime = null;
    private ?string $filePath = null;

    /**
     * The song model that's associated with the current file.
     */
    private ?Song $song;

    private ?string $syncError = null;

    public function __construct(
        private readonly getID3 $getID3,
        private readonly MediaMetadataService $mediaMetadataService,
        private readonly MediaBrowser $browser,
        private readonly SongRepository $songRepository,
        private readonly SimpleLrcReader $lrcReader,
        private readonly Finder $finder,
        private readonly CacheStrategy $cache,
    ) {
    }

    public function setFile(string|SplFileInfo $path): static
    {
        $file = $path instanceof SplFileInfo ? $path : new SplFileInfo($path);

        $this->filePath = $file->getRealPath();
        $this->song = $this->songRepository->findOneByPath($this->filePath);
        $this->fileModifiedTime = get_mtime($file);

        return $this;
    }

    public function getScanInformation(): ?SongScanInformation
    {
        $raw = $this->getID3->analyze($this->filePath);

        if (Arr::get($raw, 'playtime_seconds')) {
            $this->syncError = Arr::get($raw, 'error.0') ?: (null);
        } else {
            $this->syncError = Arr::get($raw, 'error.0') ?: 'Empty file';
        }

        if ($this->syncError) {
            return null;
        }

        $this->getID3->CopyTagsToComments($raw);
        $info = SongScanInformation::fromGetId3Info($raw, $this->filePath);

        $info->lyrics = $info->lyrics ?: $this->lrcReader->tryReadForMediaFile($this->filePath);

        return $info;
    }

    private function resolveArtist(User $user, ?string $name): Artist
    {
        $name = trim($name);

        return $this->cache->remember(
            key: simple_hash("{$user->id}_{$name}"),
            ttl: now()->addMinutes(30),
            callback: static fn () => Artist::getOrCreate($user, $name)
        );
    }

    private function resolveAlbum(Artist $artist, ?string $name): Album
    {
        $name = trim($name);

        return $this->cache->remember(
            key: simple_hash("{$artist->id}_{$name}"),
            ttl: now()->addMinutes(30),
            callback: static fn () => Album::getOrCreate($artist, $name)
        );
    }

    public function scan(ScanConfiguration $config): ScanResult
    {
        try {
            if (!$config->force && !$this->isFileNewOrChanged()) {
                return ScanResult::skipped($this->filePath);
            }

            $info = $this->getScanInformation()?->toArray();

            if (!$info) {
                return ScanResult::error($this->filePath, $this->syncError);
            }

            if (!$this->isFileNew()) {
                Arr::forget($info, $config->ignores);
            }

            /** @var Artist $artist */
            $artist = Arr::get($info, 'artist')
                ? $this->resolveArtist($config->owner, $info['artist'])
                : $this->song->artist;

            $albumArtist = Arr::get($info, 'albumartist')
                ? $this->resolveArtist($config->owner, $info['albumartist'])
                : $artist;

            /** @var Album $album */
            $album = Arr::get($info, 'album')
                ? $this->resolveAlbum($albumArtist, $info['album'])
                : $this->song->album;

            if (!$album->has_cover && !in_array('cover', $config->ignores, true)) {
                $this->tryGenerateAlbumCover($album, Arr::get($info, 'cover', []));
            }

            $data = Arr::except($info, ['album', 'artist', 'albumartist', 'cover']);
            $data['album_id'] = $album->id;
            $data['artist_id'] = $artist->id;
            $data['is_public'] = $config->makePublic;

            if ($this->isFileNew()) {
                // Only set the owner if the song is new, i.e., don't override the owner if the song is being updated.
                $data['owner_id'] = $config->owner->id;
            }

            // @todo Decouple song creation from scanning.
            $this->song = Song::query()->updateOrCreate(['path' => $this->filePath], $data); // @phpstan-ignore-line

            if (!$album->year && $this->song->year) {
                $album->update(['year' => $this->song->year]);
            }

            if ($config->extractFolderStructure && $this->song->storage->supportsFolderStructureExtraction()) {
                $this->browser->maybeCreateFolderStructureForSong($this->song);
            }

            return ScanResult::success($this->filePath);
        } catch (Throwable $e) {
            Log::error('Error scanning file', [
                'file' => $this->filePath,
                'error' => $e,
            ]);

            return ScanResult::error($this->filePath, 'Possible invalid file');
        }
    }

    /**
     * Try to generate a cover for an album based on extracted data, or use the cover file under the directory.
     *
     * @param ?array<mixed> $coverData
     */
    private function tryGenerateAlbumCover(Album $album, ?array $coverData): void
    {
        rescue(function () use ($album, $coverData): void {
            // If the album has no cover, we try to get the cover image from existing tag data
            if ($coverData) {
                $this->mediaMetadataService->writeAlbumCover($album, $coverData['data']);

                return;
            }

            // Or, if there's a cover image under the same directory, use it.
            optional($this->getCoverFileUnderSameDirectory(), function (string $cover) use ($album): void {
                $this->mediaMetadataService->writeAlbumCover($album, $cover);
            });
        });
    }

    /**
     * Issue #380.
     * Some albums have its own cover image under the same directory as cover|folder.jpg/png.
     * We'll check if such a cover file is found, and use it if positive.
     */
    private function getCoverFileUnderSameDirectory(): ?string
    {
        // As directory scanning can be expensive, we cache and reuse the result.
        return Cache::remember(md5($this->filePath . '_cover'), now()->addDay(), function (): ?string {
            $matches = array_keys(
                iterator_to_array(
                    $this->finder::create()
                        ->depth(0)
                        ->ignoreUnreadableDirs()
                        ->files()
                        ->followLinks()
                        ->name('/(cov|fold)er\.(jpe?g|png)$/i')
                        ->in(dirname($this->filePath))
                )
            );

            $cover = $matches[0] ?? null;

            return $cover && self::isImage($cover) ? $cover : null;
        });
    }

    private static function isImage(string $path): bool
    {
        return rescue(static fn () => (bool) exif_imagetype($path)) ?? false;
    }

    /**
     * Determine if the file is new (its Song record can't be found in the database).
     */
    public function isFileNew(): bool
    {
        return !$this->song;
    }

    /**
     * Determine if the file is changed (its Song record is found, but the timestamp is different).
     */
    public function isFileChanged(): bool
    {
        return !$this->isFileNew() && $this->song->mtime !== $this->fileModifiedTime;
    }

    public function isFileNewOrChanged(): bool
    {
        return $this->isFileNew() || $this->isFileChanged();
    }

    public function getSong(): Song
    {
        if (!$this->song) {
            throw new RuntimeException('No song model is available.');
        }

        return $this->song;
    }
}

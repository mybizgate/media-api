<?php

namespace App\Http\Controllers\Api;

use App\DTO\Media\MediaData;
use App\Exceptions\MediaException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveTenant;
use App\Http\Requests\ReplaceMediaRequest;
use App\Http\Requests\UploadMediaRequest;
use App\Http\Resources\MediaResource;
use App\Repositories\Media\MediaRepositoryInterface;
use App\Services\Media\MediaPathService;
use App\Services\Media\MediaStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MediaController extends Controller
{
    /** Route constraint for {mediaId}: lowercase ULID. */
    public const ID_PATTERN = '[0-9a-z]{26}';

    public function __construct(
        private readonly MediaStorageService $storage,
        private readonly MediaRepositoryInterface $repository,
        private readonly MediaPathService $paths,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'module' => ['nullable', 'string', 'regex:'.config('media.module.pattern')],
            'entity_id' => ['nullable', 'string', 'regex:'.config('media.entity_id.pattern')],
            'media_type' => ['nullable', 'in:image,file'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('media.listing.max_per_page')],
        ]);

        $result = $this->repository->list(
            $this->tenant($request),
            [
                'module' => $validated['module'] ?? null,
                'entity_id' => $validated['entity_id'] ?? null,
                'media_type' => $validated['media_type'] ?? null,
                'year' => isset($validated['year']) ? (int) $validated['year'] : null,
                'month' => isset($validated['month']) ? (int) $validated['month'] : null,
            ],
            (int) ($validated['page'] ?? 1),
            (int) ($validated['per_page'] ?? config('media.listing.per_page')),
        );

        return response()->json([
            'success' => true,
            'data' => MediaResource::collection($result['items'])->resolve($request),
            'meta' => [
                'page' => $result['page'],
                'per_page' => $result['per_page'],
                'total' => $result['total'],
                'total_pages' => (int) ceil($result['total'] / $result['per_page']),
            ],
        ]);
    }

    public function store(UploadMediaRequest $request): JsonResponse
    {
        $media = $this->storage->upload(
            $this->tenant($request),
            $request->file('file'),
            (string) $request->input('module'),
            $request->input('entity_id'),
            $request->input('visibility'),
        );

        return (new MediaResource($media))->response($request)->setStatusCode(201);
    }

    public function show(Request $request, string $mediaId): BinaryFileResponse
    {
        $media = $this->readable($request, $mediaId);
        $path = $this->existingPath($this->storage->contentPath($media));

        if ($media->isImage() || in_array($media->mimeType, config('media.file.inline', []), true)) {
            return $this->inline($media, $path, $media->mimeType);
        }

        return $this->attachment($media, $path);
    }

    public function thumbnail(Request $request, string $mediaId): BinaryFileResponse
    {
        $media = $this->readable($request, $mediaId);
        $thumbnail = $this->storage->thumbnailPath($media);

        if ($thumbnail === null) {
            throw MediaException::notFound('This media has no thumbnail.');
        }

        return $this->inline($media, $this->existingPath($thumbnail), 'image/webp');
    }

    public function metadata(Request $request, string $mediaId): JsonResponse
    {
        return (new MediaResource($this->readable($request, $mediaId)))->response($request);
    }

    public function download(Request $request, string $mediaId): BinaryFileResponse
    {
        $media = $this->readable($request, $mediaId);

        return $this->attachment($media, $this->existingPath($this->storage->contentPath($media)));
    }

    public function update(ReplaceMediaRequest $request, string $mediaId): JsonResponse
    {
        $media = $this->owned($request, $mediaId);

        $media = $this->storage->replace($media, $request->file('file'), $request->input('visibility'));

        return (new MediaResource($media))->response($request);
    }

    public function destroy(Request $request, string $mediaId): JsonResponse
    {
        $media = $this->owned($request, $mediaId);

        $this->storage->delete($media);

        return response()->json([
            'success' => true,
            'data' => ['id' => $media->id, 'deleted' => true],
        ]);
    }

    private function tenant(Request $request): string
    {
        return (string) $request->attributes->get(ResolveTenant::ATTRIBUTE);
    }

    /** Media that must belong to the tenant in X-Client-Code (write operations). */
    private function owned(Request $request, string $mediaId): MediaData
    {
        return $this->repository->find($this->tenant($request), $mediaId) ?? throw MediaException::notFound();
    }

    /**
     * Media for read operations. With a header the lookup is scoped to that
     * tenant; without one only public media can be resolved.
     */
    private function readable(Request $request, string $mediaId): MediaData
    {
        $tenant = ResolveTenant::optional($request, $this->paths);

        if ($tenant !== null) {
            return $this->repository->find($tenant, $mediaId) ?? throw MediaException::notFound();
        }

        $media = $this->repository->findAnyTenant($mediaId) ?? throw MediaException::notFound();

        if (! $media->isPublic()) {
            throw MediaException::forbidden('This media is private; send the X-Client-Code header of its owner.');
        }

        return $media;
    }

    private function existingPath(string $path): string
    {
        if (! is_file($path)) {
            throw MediaException::notFound('The media file is missing.');
        }

        return $path;
    }

    private function inline(MediaData $media, string $path, string $contentType): BinaryFileResponse
    {
        return $this->withCacheHeaders($media, response()->file($path, ['Content-Type' => $contentType]))->setAutoLastModified();
    }

    private function attachment(MediaData $media, string $path): BinaryFileResponse
    {
        return $this->withCacheHeaders($media, response()->download($path, $this->storage->downloadFilename($media), ['Content-Type' => $media->mimeType]));
    }

    /**
     * BinaryFileResponse marks itself public by default, so the cache policy
     * is applied after construction: a day for public media, no-store for private.
     */
    private function withCacheHeaders(MediaData $media, BinaryFileResponse $response): BinaryFileResponse
    {
        $response->headers->set('Cache-Control', (string) config($media->isPublic() ? 'media.cache.public' : 'media.cache.private'));
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}

<?php

namespace App\Services;

use App\Exceptions\PlatformUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class KnowledgePlatform
{
    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.platform.base_url'), '/').'/api/v1')
            ->withToken((string) config('services.platform.token'))
            ->acceptJson()
            ->timeout(10)
            ->connectTimeout(5);
    }

    private function send(callable $call): Response
    {
        try {
            return $call($this->client());
        } catch (ConnectionException $e) {
            // The real cause is logged for us; the client sees a single sentence.
            Log::warning('Knowledge platform unreachable: '.$e->getMessage());

            throw new PlatformUnavailableException;
        }
    }

    public function listArticles(array $query = []): Response
    {
        return $this->send(fn (PendingRequest $http) => $http->get('/posts', $query));
    }

    /**
     * Categories from the knowledge platform.
     *
     * Read only, never stored: the platform is the source of truth for
     * categories, and any copy we hold goes stale the moment a category is added
     * over there. That is why there is no categories table in this database at
     * all.
     */
    public function listTags(): Response
    {
        return $this->send(fn (PendingRequest $http) => $http->get('/tags'));
    }

    public function getArticle(string $slug): Response
    {
        return $this->send(fn (PendingRequest $http) => $http->get("/posts/{$slug}"));
    }

    public function createArticle(array $data, ?UploadedFile $image = null): Response
    {
        if ($image === null) {
            return $this->send(fn (PendingRequest $http) => $http->post('/posts', $data));
        }

        return $this->send(fn (PendingRequest $http) => $this->withImage($http, $image)
            ->post('/posts', $this->asFormFields($data)));
    }

    public function updateArticle(string $slug, array $data, ?UploadedFile $image = null): Response
    {
        if ($image === null) {
            return $this->send(fn (PendingRequest $http) => $http->put("/posts/{$slug}", $data));
        }

        /*
         * With an image we send a POST, not a PUT.
         *
         * PHP only decodes multipart bodies on POST — not PUT, not PATCH — so the
         * file reaches the platform empty no matter what we send. And
         * `_method=PUT` is native spoofing in Laravel: the router over there sees
         * the request as a PUT and matches the existing route, so we need no new
         * route in the first project.
         */
        return $this->send(fn (PendingRequest $http) => $this->withImage($http, $image)
            ->post("/posts/{$slug}", $this->asFormFields($data + ['_method' => 'PUT'])));
    }

    /** Attach the image with its content, name and type as the user uploaded it. */
    private function withImage(PendingRequest $http, UploadedFile $image): PendingRequest
    {
        return $http->attach(
            'image',
            $image->getContent(),
            $image->getClientOriginalName(),
            ['Content-Type' => $image->getMimeType()],
        );
    }

    /**
     * Flatten the data so that it works as form fields.
     *
     * multipart knows nothing of nested arrays: an array value makes Guzzle
     * fail. So we write `tags[0]` and `tags[1]` — the form PHP reassembles back
     * into an array on the receiving end. Empty values are dropped, because
     * multipart cannot carry a null.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, scalar>
     */
    private function asFormFields(array $data): array
    {
        $fields = [];

        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_array($value)) {
                foreach (array_values($value) as $index => $item) {
                    $fields["{$key}[{$index}]"] = $item;
                }

                continue;
            }

            $fields[$key] = $value;
        }

        return $fields;
    }

    public function deleteArticle(string $slug): Response
    {
        return $this->send(fn (PendingRequest $http) => $http->delete("/posts/{$slug}"));
    }
}

<?php

namespace App\Services;

use App\Exceptions\PlatformUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
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
            // السبب الحقيقي يُسجَّل لنا؛ والعميل يرى جملة واحدة.
            Log::warning('Knowledge platform unreachable: '.$e->getMessage());

            throw new PlatformUnavailableException();
        }
    }

    public function listArticles(array $query = []): Response
    {
        return $this->send(fn (PendingRequest $http) => $http->get('/posts', $query));
    }

    public function getArticle(string $slug): Response
    {
        return $this->send(fn (PendingRequest $http) => $http->get("/posts/{$slug}"));
    }

    public function createArticle(array $data): Response
    {
        return $this->send(fn (PendingRequest $http) => $http->post('/posts', $data));
    }

    public function updateArticle(string $slug, array $data): Response
    {
        return $this->send(fn (PendingRequest $http) => $http->put("/posts/{$slug}", $data));
    }

    public function deleteArticle(string $slug): Response
    {
        return $this->send(fn (PendingRequest $http) => $http->delete("/posts/{$slug}"));
    }
}
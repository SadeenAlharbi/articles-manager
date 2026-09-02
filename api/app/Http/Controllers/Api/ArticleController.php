<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\KnowledgePlatform;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ArticleController extends Controller
{
    public function __construct(
        private readonly KnowledgePlatform $platform,
        private readonly AuditLogger $audit,
    ) {
    }

    /* ------------------------------- قراءة ------------------------------- */

    public function index(Request $request): JsonResponse
    {
        return $this->forward(
            $this->platform->listArticles(
                $request->only(['search', 'tag', 'page', 'per_page', 'sort'])
            )
        );
    }

    public function show(string $slug): JsonResponse
    {
        return $this->forward($this->platform->getArticle($slug));
    }

    /* ------------------------------- كتابة ------------------------------- */

    public function store(Request $request): JsonResponse
    {
    
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'status' => ['nullable', 'string'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
        ]);

        $response = $this->platform->createArticle($data);

        $this->audit->record(
            $request,
            'articles.create',
            data_get($response->json(), 'data.slug'),
            ['title' => $data['title'], 'status' => $response->status()],
            $response->successful(),
        );

        return $this->forward($response);
    }

    public function update(Request $request, string $slug): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'content' => ['sometimes', 'required', 'string'],
            'status' => ['nullable', 'string'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
        ]);

        $response = $this->platform->updateArticle($slug, $data);

        $this->audit->record(
            $request,
            'articles.update',
            $slug,
            ['fields' => array_keys($data), 'status' => $response->status()],
            $response->successful(),
        );

        return $this->forward($response);
    }

    public function destroy(Request $request, string $slug): JsonResponse
    {
        $response = $this->platform->deleteArticle($slug);

        $this->audit->record(
            $request,
            'articles.delete',
            $slug,
            ['status' => $response->status()],
            $response->successful(),
        );

        return $this->forward($response);
    }

    private function forward(Response $response): JsonResponse
    {
        return response()->json($response->json() ?? [], $response->status());
    }
}
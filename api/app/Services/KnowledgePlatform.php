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
            // السبب الحقيقي يُسجَّل لنا؛ والعميل يرى جملة واحدة.
            Log::warning('Knowledge platform unreachable: '.$e->getMessage());

            throw new PlatformUnavailableException;
        }
    }

    public function listArticles(array $query = []): Response
    {
        return $this->send(fn (PendingRequest $http) => $http->get('/posts', $query));
    }

    /**
     * تصنيفات منصّة المعرفة.
     *
     * قراءة فقط ولا تخزين: المنصّة هي مصدر الحقيقة للتصنيفات، وأي نسخة عندنا
     * تصير قديمة لحظة إضافة تصنيف هناك. ولهذا لا يوجد جدول تصنيفات في هذي
     * القاعدة إطلاقاً.
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
         * مع صورة نرسل POST لا PUT.
         *
         * PHP لا يفكّ ترميز multipart إلا في POST — لا PUT ولا PATCH — فيصل
         * الملف إلى المنصّة فارغاً مهما أرسلنا. و`_method=PUT` انتحالٌ أصلي في
         * Laravel: الموجّه هناك يرى الطلب PUT فيطابق المسار القائم، فلا نحتاج
         * مساراً جديداً في المشروع الأول.
         */
        return $this->send(fn (PendingRequest $http) => $this->withImage($http, $image)
            ->post("/posts/{$slug}", $this->asFormFields($data + ['_method' => 'PUT'])));
    }

    /** إرفاق الصورة بمحتواها واسمها ونوعها كما رفعها المستخدم. */
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
     * تسطيح البيانات لتصلح حقولَ نموذج.
     *
     * multipart لا يعرف المصفوفات المتداخلة: قيمة مصفوفة تُفشل Guzzle. فنكتب
     * `tags[0]` و`tags[1]` — وهي الصيغة التي يعيد PHP تجميعها مصفوفةً عند
     * الاستقبال. والقيم الفارغة تُسقَط لأن multipart لا يحمل null.
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

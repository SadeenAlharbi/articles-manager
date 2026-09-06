<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesKnowledgePlatform;
use Tests\TestCase;

/**
 * رفع صورة المقال.
 *
 * أهشّ ما في المشروع، لأن كل ما فيه يفشل **بصمت**: multipart لا يمرّ عبر PUT
 * فيصل الملف فارغاً بلا خطأ، والمصفوفة غير المسطّحة تُفشل Guzzle برسالة غامضة،
 * وترويسة Content-Type مكتوبة يدوياً تُنتج طلباً بلا حدّ فاصل.
 *
 * فالاختبارات هنا تفحص **شكل الطلب الصادر** لا الاستجابة: هذي هي الطبقة التي
 * نملكها، وفيها تقع الأخطاء الثلاثة.
 */
class ArticleImageTest extends TestCase
{
    use FakesKnowledgePlatform, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->fakePlatform([], Http::response(
            ['data' => ['slug' => 'article-slug', 'title' => 'عنوان'], 'message' => 'ok'],
            200
        ));

        $this->actingAsAccount('admin@demo.test');
    }

    private function image(string $name = 'cover.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 800, 450);
    }

    /* ------------------------------- الإنشاء ------------------------------- */

    public function test_creating_with_an_image_sends_multipart(): void
    {
        $this->post('/api/v1/articles', [
            'title' => 'مقال بصورة',
            'content' => 'نصّ.',
            'image' => $this->image(),
        ])->assertSuccessful();

        Http::assertSent(fn ($request) => $request->isMultipart()
            && $request->hasFile('image'));
    }

    public function test_creating_without_an_image_stays_json(): void
    {
        $this->postJson('/api/v1/articles', [
            'title' => 'مقال بلا صورة',
            'content' => 'نصّ.',
        ])->assertSuccessful();

        // لا نُعقّد الحالة الشائعة من أجل الاستثناء
        Http::assertSent(fn ($request) => ! $request->isMultipart());
    }

    /* ------------------------------- التعديل ------------------------------- */

    /**
     * التعديل مع صورة يخرج POST لا PUT.
     *
     * PHP لا يفكّ ترميز multipart إلا في POST. و`_method=PUT` انتحالٌ أصلي في
     * Laravel يجعل موجّه المنصّة يرى الطلب PUT فيطابق مسارها القائم — فلا مسار
     * جديد هناك ولا تغيير في العقد.
     */
    public function test_updating_with_an_image_spoofs_the_method(): void
    {
        $this->post('/api/v1/articles/article-slug', [
            '_method' => 'PUT',
            'title' => 'عنوان معدَّل',
            'content' => 'نصّ.',
            'image' => $this->image(),
        ])->assertSuccessful();

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->isMultipart()
            && $request->hasFile('image')
            && collect($request->data())->contains(
                fn ($part) => $part['name'] === '_method' && $part['contents'] === 'PUT'
            ));
    }

    public function test_updating_without_an_image_stays_a_plain_put(): void
    {
        $this->putJson('/api/v1/articles/article-slug', [
            'title' => 'عنوان معدَّل',
            'content' => 'نصّ.',
        ])->assertSuccessful();

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && ! $request->isMultipart());
    }

    /* ------------------------- تسطيح المصفوفات ------------------------- */

    /**
     * التصنيفات تُكتب tags[0] و tags[1].
     *
     * multipart لا يعرف المصفوفات المتداخلة: قيمة مصفوفة تُفشل Guzzle. وهذي
     * الصيغة يعيد PHP تجميعها مصفوفةً عند الاستقبال، فتصل المنصّةَ تصنيفاتٍ.
     */
    public function test_tags_are_flattened_for_multipart(): void
    {
        $this->post('/api/v1/articles', [
            'title' => 'مقال بتصنيفات',
            'content' => 'نصّ.',
            'tags' => ['heritage', 'vision-2030'],
            'image' => $this->image(),
        ])->assertSuccessful();

        Http::assertSent(function ($request) {
            $names = collect($request->data())->pluck('name');

            return $names->contains('tags[0]') && $names->contains('tags[1]');
        });
    }

    /* -------------------------------- الرفض -------------------------------- */

    /** ملف ليس صورة يُرفض عندنا قبل أن يُرفع عبر الشبكة. */
    public function test_a_non_image_file_is_rejected_before_the_network(): void
    {
        $this->post('/api/v1/articles', [
            'title' => 'مقال',
            'content' => 'نصّ.',
            'image' => UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('image');

        Http::assertNothingSent();
    }

    /** والصورة الضخمة كذلك — طبقتان لا واحدة. */
    public function test_an_oversized_image_is_rejected_before_the_network(): void
    {
        $this->post('/api/v1/articles', [
            'title' => 'مقال',
            'content' => 'نصّ.',
            // الحدّ 5 ميجابايت في الطرفين
            'image' => UploadedFile::fake()->create('huge.jpg', 6000, 'image/jpeg'),
        ])->assertStatus(422)->assertJsonValidationErrors('image');

        Http::assertNothingSent();
    }

    /* ------------------------------- السجلّ ------------------------------- */

    public function test_the_audit_records_that_an_image_was_attached(): void
    {
        $this->post('/api/v1/articles', [
            'title' => 'مقال بصورة',
            'content' => 'نصّ.',
            'image' => $this->image(),
        ])->assertSuccessful();

        $this->assertDatabaseHas('audit_logs', ['action' => 'articles.create']);

        $log = AuditLog::where('action', 'articles.create')->latest('id')->firstOrFail();

        $this->assertTrue($log->payload['has_image']);
    }
}

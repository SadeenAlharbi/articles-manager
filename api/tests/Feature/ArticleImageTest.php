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
 * Uploading the article image.
 *
 * The most fragile thing in the project, because everything in it fails
 * **silently**: multipart does not survive a PUT, so the file arrives empty
 * with no error; an unflattened array makes Guzzle fail with an opaque message;
 * and a hand-written Content-Type header produces a request with no boundary.
 *
 * So the tests here inspect the **shape of the outgoing request** rather than
 * the response: that is the layer we own, and it is where all three bugs live.
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

    /* ------------------------------ Creating ----------------------------- */

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

        // We do not complicate the common case for the sake of the exception
        Http::assertSent(fn ($request) => ! $request->isMultipart());
    }

    /* ------------------------------ Updating ----------------------------- */

    /**
     * An update carrying an image goes out as a POST, not a PUT.
     *
     * PHP only decodes multipart on POST. And `_method=PUT` is Laravel's own
     * method spoofing, which makes the platform's router see the request as a
     * PUT and match its existing route — so there is no new route on that side
     * and no change to the contract.
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

    /* ------------------------- Flattening arrays ------------------------- */

    /**
     * Tags are written out as tags[0] and tags[1].
     *
     * multipart knows nothing about nested arrays: an array value makes Guzzle
     * fail. PHP reassembles this notation back into an array on receipt, so the
     * platform receives them as tags.
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

    /* ----------------------------- Rejection ----------------------------- */

    /** A non-image file is rejected on our side before it goes over the network. */
    public function test_a_non_image_file_is_rejected_before_the_network(): void
    {
        $this->post('/api/v1/articles', [
            'title' => 'مقال',
            'content' => 'نصّ.',
            'image' => UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('image');

        Http::assertNothingSent();
    }

    /** And so is an oversized image — two layers of defence, not one. */
    public function test_an_oversized_image_is_rejected_before_the_network(): void
    {
        $this->post('/api/v1/articles', [
            'title' => 'مقال',
            'content' => 'نصّ.',
            // The limit is 5 MB on both ends
            'image' => UploadedFile::fake()->create('huge.jpg', 6000, 'image/jpeg'),
        ])->assertStatus(422)->assertJsonValidationErrors('image');

        Http::assertNothingSent();
    }

    /* --------------------------- The audit log --------------------------- */

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

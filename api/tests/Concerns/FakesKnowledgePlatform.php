<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use LogicException;

/**
 * محاكاة منصّة المعرفة في الاختبارات.
 *
 * سبب وجود هذي السمة خطأ وقع ثلاث مرات في هذا المشروع:
 *
 * Http::fake **يُلحِق** المحاكاة ولا يستبدلها، و PendingRequest يبني معالجه
 * بـ ->map->__invoke(...)->filter()->first() — أي أن **أول** ردّ غير فارغ هو
 * الذي يفوز. فقاعدة '*' لو سُجِّلت قبل قاعدة مخصّصة ابتلعتها بلا خطأ ظاهر:
 * يعود ردّ لا علاقة له بالمسار، ويسقط الاختبار في موضع بعيد عن السبب.
 *
 * fakePlatform تجعل ذلك مستحيلاً بنيوياً لا اتفاقياً: القواعد المخصّصة أولاً
 * دائماً، والشاملة أخيراً دائماً، ولا يملك الاختبار خيار عكسهما.
 */
trait FakesKnowledgePlatform
{
    /**
     * تسجّل محاكاة المنصّة بالترتيب الصحيح المضمون.
     *
     * @param  array<string, mixed>  $routes  قواعد مخصّصة بأنماط عناوين
     * @param  mixed  $fallback  القاعدة الشاملة لما لا ينطبق عليه شيء
     */
    protected function fakePlatform(array $routes, mixed $fallback): void
    {
        if (array_key_exists('*', $routes)) {
            throw new LogicException(
                'القاعدة الشاملة تُمرَّر في $fallback لا في $routes: ترتيبها هو ما تحرسه هذي السمة.'
            );
        }

        // اتحاد المصفوفات يحفظ ترتيب المفاتيح: المخصّص أولاً ثم '*'
        Http::fake($routes + ['*' => $fallback]);
    }

    /**
     * تجعل كل طلب لاحق يفشل اتصالاً — تُستدعى داخل الاختبار لا في setUp.
     *
     * وهي تعمل رغم أن Http::fake يُلحِق ولا يستبدل: buildStubHandler يستدعي
     * **كل** المحاكيات (map متعجّلة) قبل أن يختار أولها غير فارغ، فالاستثناء
     * يُرمى أثناء ذلك الاستدعاء لا بعد الاختيار.
     *
     * الاعتماد على تفصيلة داخلية كهذي دقيق، فحُصر في موضع واحد موثّق بدل أن
     * يتكرّر في الاختبارات بلا تفسير.
     */
    protected function fakePlatformUnreachable(): void
    {
        Http::fake(fn () => throw new ConnectionException('unreachable'));
    }

    /** يدخل بحساب من بيانات البذرة، ويعيده. */
    protected function actingAsAccount(string $email): User
    {
        $user = User::where('email', $email)->firstOrFail();

        Sanctum::actingAs($user);

        return $user;
    }
}

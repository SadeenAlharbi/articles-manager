# لوحة إدارة المقالات — Articles Manager

لوحة تحكّم تدير محتوى **منصّة المعرفة السعودية** عبر واجهتها البرمجية (REST API)،
ولها نظام صلاحيات دقيق خاص بها وسجلّ تدقيق كامل.

---

## ١. الفكرة المعمارية

```
   المتصفّح
   React + Tailwind  (منفذ 5173)
        │
        │  REST + توكن Sanctum خاص باللوحة
        ▼
   لوحة الإدارة — Laravel  (منفذ 8001)
        │                    │
        │                    └──►  PostgreSQL
        │                          المستخدمون · الأدوار · الصلاحيات · سجلّ التدقيق
        │
        │  REST + توكن Sanctum لحساب خدمة  (يعيش في .env على الخادم)
        ▼
   منصّة المعرفة — Laravel  (منفذ 8000)
        │
        └──►  MySQL — المقالات والتعليقات والتصنيفات
```

### ثلاثة مبادئ تحكم التصميم

**١. المقالات لها مالك واحد.**
لا تُخزَّن نسخة منها هنا. مالكها منصّة المعرفة، وهذي اللوحة تتحكّم بها عبر الـAPI
فقط — **لا يوجد أي اتصال مباشر بقاعدة بيانات المشروع الأول**. لهذا لا يمكن أن
تتعارض نسختان من الحقيقة.

**٢. قاعدة بيانات هذي اللوحة لها عمل مختلف تماماً.**
لا تحوي مقالاً واحداً. تحوي: من المستخدمون، وما أدوارهم، وما صلاحياتهم، وماذا فعلوا.

**٣. المتصفّح لا يعرف بوجود منصّة المعرفة.**
توكن المنصّة يعيش في `api/.env` على الخادم. لو وُضع في React لقرأه أي زائر.

---

## ٢. الحزمة التقنية

| الطبقة | المستخدم | لماذا |
|---|---|---|
| الواجهة | React 19 + Vite + Tailwind CSS v4 | لوحة إدارة تفاعلية بالكامل |
| الخادم | Laravel 13 | نفس إطار المشروع الأول |
| قاعدة البيانات | PostgreSQL 17 (في Docker) | تنويع عن MySQL + مزايا تُستخدم فعلاً |
| المصادقة | Laravel Sanctum | توكنات Bearer للـAPI |
| الصلاحيات | spatie/laravel-permission | التطبيق المعياري لنموذج RBAC |

### لماذا PostgreSQL تحديداً؟

ليس لمجرد اختلاف الاسم — بل لمزايا مستخدَمة فعلاً:

- **`JSONB` + فهرس GIN** على حمولة سجلّ التدقيق: نوع ثنائي **قابل للفهرسة**،
  فيمكن الاستعلام داخل محتوى JSON نفسه (`payload @> '{"status":201}'`) — وهذا
  غير متاح في MySQL.
- الفهرس يُنشأ بشرط `DB::getDriverName() === 'pgsql'` حتى لا تنكسر الاختبارات
  التي تعمل على SQLite.

---

## ٣. نظام الصلاحيات

### الصلاحيات — بنمط `مورد.فعل`

```
articles.view    articles.create    articles.update
articles.delete  articles.publish   users.manage      roles.manage
```

التسمية النصّية مقصودة: إضافة صلاحية جديدة تصبح **صفّاً في جدول**، لا تعديلاً على
بنية قاعدة البيانات ولا على الكود.

### الأدوار

| الدور | صلاحياته |
|---|---|
| `viewer` | view |
| `author` | view · create |
| `editor` | view · create · update |
| `moderator` | view · create · update · delete · publish |
| `admin` | كل شيء |

### المنح المباشر (Direct Grant)

الصلاحية قد تأتي من مصدرين مستقلّين: **دور المستخدم**، أو **منح شخصي له وحده**
يُخزَّن في `model_has_permissions`.

مثال حيّ في بيانات العرض: `author.plus@demo.test` دورها `author` (لا تملك الحذف)،
لكنها مُنحت `articles.delete` شخصياً — فتحذف بينما بقية الكُتّاب لا يستطيعون.

### أين يقع الفحص

```php
Route::delete('/articles/{slug}', [ArticleController::class, 'destroy'])
    ->middleware('permission:articles.delete');
```

طبقتان متتاليتان: `auth:sanctum` تسأل **مَن أنت؟**، و`permission:` تسأل **هل يُسمح لك؟**
المصادقة والتفويض مفهومان مختلفان.

> **إخفاء الأزرار في React ليس أمانًا.** الواجهة تُخفي ما لا يُسمح به تحسيناً
> للتجربة فقط، والفحص الحقيقي في الخادم. وللبرهنة على ذلك يوجد في الواجهة زر
> **«اختبار الحماية: حاول الحذف»** يستدعي المسار فعلاً ويُظهر رفض الخادم بـ403.

---

## ٤. المسارات

| الطريقة | المسار | الحماية |
|---|---|---|
| POST | `/api/v1/login` | مفتوح · `throttle:5,1` |
| GET | `/api/v1/me` | `auth:sanctum` |
| POST | `/api/v1/logout` | `auth:sanctum` |
| GET | `/api/v1/articles` | `permission:articles.view` |
| GET | `/api/v1/articles/{slug}` | `permission:articles.view` |
| POST | `/api/v1/articles` | `permission:articles.create` |
| PUT | `/api/v1/articles/{slug}` | `permission:articles.update` |
| DELETE | `/api/v1/articles/{slug}` | `permission:articles.delete` |
| GET | `/api/v1/audit-logs` | `role:admin` |

**رموز الحالة مستخدمة بدقّة:** `401` بلا توكن، `403` بلا صلاحية، `422` بيانات غير
صالحة، **`502`** حين تكون منصّة المعرفة غير متاحة (وهو الرمز الصحيح لخادم وسيط
يعجز عن الوصول لخادم خلفه).

---

## ٥. سجلّ التدقيق

جدول `audit_logs` يسجّل كل عملية تغيير — **الناجحة والمرفوضة معاً**:

| العمود | المحتوى |
|---|---|
| `user_id` | مَن (يبقى السطر بلا صاحب إن حُذف المستخدم، فالسجلّ لا يُمحى) |
| `action` | نفس أسماء الصلاحيات: `articles.delete` |
| `subject_id` | slug المقال في منصّة المعرفة |
| `payload` | **JSONB** — تفاصيل متغيّرة الشكل، مفهرسة بـGIN |
| `succeeded` | نجحت أم رُفضت |
| `created_at` | **لا يوجد `updated_at`** — سجلّ يمكن تعديله ليس سجلّ تدقيق |

---

## ٦. Running From Scratch

The whole stack (PostgreSQL, the Laravel API, and the React UI) runs through Docker Compose — no
local PHP, Composer, or Node installation is required.

```bash
cd articles-manager
cp .env.example .env
# set DB_PASSWORD in .env

cd api
cp .env.example .env
# set DB_* to match docker-compose.yml, and PLATFORM_BASE_URL / PLATFORM_TOKEN

cd ..
docker compose up -d --build
```

This starts PostgreSQL, builds and starts the API container (running `composer install`, then
`php artisan migrate`, then serving on port 8001), and starts the UI container (`npm install`
followed by the Vite dev server on port 5173).

Seed roles, permissions, and demo accounts:

```bash
docker compose exec api php artisan db:seed
```

The knowledge platform (mywebsite) must be running on port 8000 for article operations to succeed.

### Service Account Token

Issued from the knowledge platform for an admin account:

```bash
php artisan tinker --execute="echo App\Models\User::where('email','...')->first()->createToken('articles-manager')->plainTextToken;"
```

Set it in `api/.env` under `PLATFORM_TOKEN`.
**Never write it into the code, commit it to Git, or expose it to the browser.**

### Restoring an Existing Database

To load real content instead of an empty database:

```bash
docker exec -i <postgres-container-name> psql -U articles_app -d articles_manager < articles_manager.sql
```

---

## ٧. حسابات العرض

كلمة المرور للجميع: `password`

| البريد | الدور | ما يستطيعه |
|---|---|---|
| `admin@demo.test` | admin | كل شيء + سجلّ التدقيق |
| `moderator@demo.test` | moderator | يحذف وينشر |
| `editor@demo.test` | editor | يعدّل ولا يحذف |
| `author@demo.test` | author | يضيف فقط |
| `author.plus@demo.test` | author **+ منح مباشر** | يضيف **ويحذف** |
| `viewer@demo.test` | viewer | عرض فقط |

---

## ٨. الاختبارات

```bash
php artisan test
```

٩ اختبارات تغطّي المصادقة والتفويض وسجلّ التدقيق والتحقّق من المدخلات.

منصّة المعرفة **مُزيَّفة بـ`Http::fake()`**: الاختبارات تعمل بلا إنترنت وبلا تشغيل
المشروع الأول، لأن المقيس هو سلوك هذا النظام لا سلوك خدمة خارجية.

الاختبارات تعمل على **SQLite في الذاكرة** ولا تلمس بيانات PostgreSQL.

---

## ٩. سيناريو العرض

1. ادخل بـ`viewer@demo.test` → شريط الصلاحيات: وسم واحد. لا نموذج إضافة.
2. اضغط **«اختبار الحماية: حاول الحذف»** → الخادم يرفض بـ403.
3. ادخل بـ`author@demo.test` → يظهر نموذج الإضافة. أضف مقالاً.
4. افتح منصّة المعرفة على المنفذ 8000 → **المقال هناك فعلاً**.
5. ادخل بـ`author.plus@demo.test` → **نفس الدور**، لكن زر الحذف ظاهر. احذف.
6. ادخل بـ`admin@demo.test` → تبويب سجلّ التدقيق يعرض كل ما جرى.
7. أوقف منصّة المعرفة → الواجهة تقول «تعذّر الوصول» بلا انهيار (502).

---

## ١٠. حدود معروفة

مذكورة بوعي، لا منسيّة:

- **لا واجهة لإدارة الأدوار** — تُدار عبر البذور و Tinker. الصلاحيات `users.manage`
  و`roles.manage` معرَّفة ومحجوزة لهذي الشاشة مستقبلاً.
- **لا تحديث لحظي** — قائمة المقالات تُحدَّث عند التحميل أو البحث.
- **مستخدم قاعدة البيانات صاحب امتيازات كاملة** — لأن صورة `postgres` في Docker
  تجعل `POSTGRES_USER` مالكاً للنظام. مقبول في التطوير، ويجب استبداله بمستخدم
  محدود الصلاحيات في الإنتاج.
- **`articles.publish` معرَّفة ولم تُستخدم بعد** — المنصّة تُدير النشر عبر واجهتها.

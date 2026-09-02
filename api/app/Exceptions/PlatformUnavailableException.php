<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * منصّة المعرفة لا تستجيب: متوقّفة، أو الشبكة منقطعة، أو تجاوزت المهلة.
 *
 * وجود دالة render() يجعل Laravel يستخدمها تلقائياً لأي مكان يُرمى فيه
 * هذا الاستثناء — فلا يتكرّر معالجة الخطأ في كل متحكّم، ولا يتسرّب
 * أثر الخطأ إلى العميل.
 */
class PlatformUnavailableException extends Exception
{
    public function render(): JsonResponse
    {
        return response()->json([
            'message' => 'تعذّر الوصول إلى منصّة المعرفة حالياً. حاول بعد قليل.',
        ], 502);
    }
}

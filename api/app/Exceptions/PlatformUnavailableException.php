<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * The knowledge platform is not answering: it is down, the network is
 * unreachable, or the request ran past its timeout.
 *
 * Because a render() method exists, Laravel calls it automatically anywhere
 * this exception is thrown — so the error handling is not repeated in every
 * controller, and no trace of the underlying failure leaks to the client.
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

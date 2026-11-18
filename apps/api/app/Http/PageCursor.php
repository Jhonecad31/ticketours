<?php

namespace App\Http;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

final class PageCursor
{
    public static function read(Request $request, ?string $token): ?Cursor
    {
        if ($token === null) {
            return null;
        }

        try {
            $data = Crypt::decryptString($token);
            $prefix = self::scope($request);
            if (str_starts_with($data, $prefix)) {
                return Cursor::fromEncoded(substr($data, strlen($prefix)));
            }
        } catch (DecryptException) {
            // A modified or invalid token is handled as a validation error.
        }

        throw ValidationException::withMessages(['cursor' => 'Usa el cursor devuelto para esta consulta.']);
    }

    public static function next(Request $request, ?Cursor $cursor): ?string
    {
        return $cursor ? Crypt::encryptString(self::scope($request).$cursor->encode()) : null;
    }

    private static function scope(Request $request): string
    {
        return $request->user()->id.':'.$request->path()."\n";
    }
}

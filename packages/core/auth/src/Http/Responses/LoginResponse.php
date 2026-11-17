<?php

namespace MasterCore\Auth\Http\Responses;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request)
    {
        $destination = $request->session()->pull('url.intended');
        $path = $this->localPath($destination, $request);

        return $request->wantsJson()
            ? response()->json(['redirect' => $path])
            : redirect($path);
    }

    private function localPath(mixed $destination, Request $request): string
    {
        $fallback = (string) config('master-core-auth.application_path', '/app');

        if (! is_string($destination) || $destination === '') {
            return $fallback;
        }

        if (Str::startsWith($destination, '/') && ! Str::startsWith($destination, '//')) {
            return $destination;
        }

        $urlHost = parse_url($destination, PHP_URL_HOST);
        if ($urlHost !== null && strcasecmp($urlHost, $request->getHost()) === 0) {
            return (string) (parse_url($destination, PHP_URL_PATH) ?: '/').
                (parse_url($destination, PHP_URL_QUERY) ? '?'.parse_url($destination, PHP_URL_QUERY) : '');
        }

        return $fallback;
    }
}

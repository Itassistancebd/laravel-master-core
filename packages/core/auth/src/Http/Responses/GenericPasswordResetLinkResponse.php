<?php

namespace MasterCore\Auth\Http\Responses;

use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;

class GenericPasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    public function __construct(private readonly string $status = '') {}

    public function toResponse($request)
    {
        // Do not reveal whether an email exists or whether a recent request was throttled.
        $message = 'If the address is eligible, a password reset link has been sent.';

        return $request->wantsJson()
            ? response()->json(['message' => $message])
            : back()->with('status', $message);
    }
}

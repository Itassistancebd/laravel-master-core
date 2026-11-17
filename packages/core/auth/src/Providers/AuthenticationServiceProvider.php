<?php

namespace MasterCore\Auth\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;
use LogicException;
use MasterCore\Auth\Actions\ResetUserPassword;
use MasterCore\Auth\Contracts\LoginEligibility;
use MasterCore\Auth\Http\Responses\GenericPasswordResetLinkResponse;
use MasterCore\Auth\Http\Responses\LoginResponse as CoreLoginResponse;
use MasterCore\Auth\Support\AllowAllLoginEligibility;

class AuthenticationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/master-core-auth.php', 'master-core-auth');
        $this->app->singleton(LoginEligibility::class, AllowAllLoginEligibility::class);

        // Set the approved feature list before Fortify boots and registers its routes.
        $fortifyConfig = require __DIR__.'/../../config/fortify.php';
        $this->mergeConfigFrom(__DIR__.'/../../config/fortify.php', 'fortify');
        config(['fortify.features' => $fortifyConfig['features']]);

    }

    public function boot(): void
    {
        // Fortify registers its defaults during provider registration; replace those bindings after registration.
        $this->app->bind(LoginResponse::class, CoreLoginResponse::class);
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, GenericPasswordResetLinkResponse::class);
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponse::class, GenericPasswordResetLinkResponse::class);

        if (! config('master-core-auth.register_fortify_routes', true)) {
            Fortify::ignoreRoutes();
        }

        if (! $this->app->routesAreCached()) {
            $this->assertRoutePathsAvailable();
        }

        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'master-core-auth');
        $this->loadRoutesFrom(__DIR__.'/../../routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        Fortify::loginView(fn () => view('master-core-auth::app', ['page' => 'login']));
        Fortify::requestPasswordResetLinkView(fn () => view('master-core-auth::app', ['page' => 'forgot-password']));
        Fortify::resetPasswordView(fn ($request) => view('master-core-auth::app', [
            'page' => 'reset-password',
            'token' => $request->route('token'),
            'email' => $request->email,
        ]));
        Fortify::verifyEmailView(fn () => view('master-core-auth::app', ['page' => 'verify-email']));
        Fortify::confirmPasswordView(fn () => view('master-core-auth::app', ['page' => 'confirm-password']));
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        // Keep Fortify's guard, session rotation, throttling, and generic failures while allowing
        // an optional account module to reject credentials for an inactive host user.
        $hostAuthenticateUsing = Fortify::$authenticateUsingCallback;
        Fortify::authenticateUsing(function (Request $request) use ($hostAuthenticateUsing) {
            if ($hostAuthenticateUsing !== null) {
                $user = call_user_func($hostAuthenticateUsing, $request);
            } else {
                $guard = Auth::guard(config('fortify.guard', 'web'));
                $provider = $guard->getProvider();
                $credentials = $request->only(Fortify::username(), 'password');
                $user = $provider->retrieveByCredentials($credentials);

                if (! $user || ! $provider->validateCredentials($user, $credentials)) {
                    return null;
                }

                if (method_exists($provider, 'rehashPasswordIfRequired')) {
                    $provider->rehashPasswordIfRequired($user, $credentials);
                }
            }

            return $user && app(LoginEligibility::class)->allows($user) ? $user : null;
        });

        RateLimiter::for('master-core-auth-login', function ($request): array {
            $username = mb_strtolower(trim((string) $request->input(Fortify::username())));
            $identity = hash('sha256', $username);
            $limitResponse = fn () => response()->json([
                'message' => 'Too many sign-in attempts. Please try again shortly.',
            ], 429);

            return [
                Limit::perMinute(5)->by('login:'.$identity.'|'.$request->ip())->response($limitResponse),
                Limit::perMinute(50)->by('login-ip:'.$request->ip())->response($limitResponse),
            ];
        });
    }

    /** Fail early instead of letting duplicate host and package paths shadow each other. */
    private function assertRoutePathsAvailable(): void
    {
        $paths = [];

        if (config('master-core-auth.register_fortify_routes', true)) {
            $prefix = trim((string) config('fortify.prefix', ''), '/');
            $fortifyPaths = [
                ['GET', config('fortify.paths.login') ?: '/login'],
                ['POST', config('fortify.paths.login') ?: '/login'],
                ['POST', config('fortify.paths.logout') ?: '/logout'],
                ['GET', config('fortify.paths.password.request') ?: '/forgot-password'],
                ['POST', config('fortify.paths.password.email') ?: '/forgot-password'],
                ['GET', config('fortify.paths.password.reset') ?: '/reset-password/{token}'],
                ['POST', config('fortify.paths.password.update') ?: '/reset-password'],
                ['GET', config('fortify.paths.verification.notice') ?: '/email/verify'],
                ['GET', config('fortify.paths.verification.verify') ?: '/email/verify/{id}/{hash}'],
                ['POST', config('fortify.paths.verification.send') ?: '/email/verification-notification'],
                ['GET', config('fortify.paths.password.confirm') ?: '/user/confirm-password'],
                ['POST', config('fortify.paths.password.confirm') ?: '/user/confirm-password'],
                ['GET', config('fortify.paths.password.confirmation') ?: '/user/confirmed-password-status'],
            ];

            foreach ($fortifyPaths as [$method, $path]) {
                $paths[] = [$method, trim($prefix.'/'.trim((string) $path, '/'), '/')];
            }
        }

        if (config('master-core-auth.register_application_route', true)) {
            $appPath = trim((string) config('master-core-auth.application_path', '/app'), '/');
            $paths[] = ['GET', $appPath.'/{path?}'];
        }

        $apiPrefix = trim((string) config('master-core-auth.api_prefix', 'api/v1/auth'), '/');
        array_push($paths,
            ['GET', $apiPrefix.'/user'],
            ['GET', $apiPrefix.'/sessions'],
            ['DELETE', $apiPrefix.'/sessions/{session}'],
        );

        foreach (Route::getRoutes()->getRoutes() as $route) {
            foreach ($paths as [$method, $path]) {
                $samePath = $route->uri() === $path;
                if (config('master-core-auth.register_application_route', true) && $method === 'GET') {
                    $appPath = trim((string) config('master-core-auth.application_path', '/app'), '/');
                    if ($path === $appPath.'/{path?}') {
                        $samePath = $samePath || $route->uri() === $appPath || str_starts_with($route->uri(), $appPath.'/');
                    }
                }

                if ($samePath && in_array($method, $route->methods(), true)) {
                    throw new LogicException("Master Core Authentication route '{$method} /{$path}' conflicts with an existing host route.");
                }
            }
        }
    }
}

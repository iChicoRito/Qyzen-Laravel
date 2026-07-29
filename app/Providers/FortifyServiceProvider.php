<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        // C2: bind identity by lowercased email; block inactive and password-less
        // (imported, not-yet-reset) accounts at the credential check.
        Fortify::authenticateUsing(function (Request $request) {
            $user = User::where('email', Str::lower((string) $request->email))->first();

            if ($user && $user->is_active && $user->password && Hash::check($request->password, $user->password)) {
                return $user;
            }

            return null;
        });

        // C1: Blade views built from the Tabler template/*.html auth pages.
        Fortify::loginView(fn () => view('auth.login'));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.reset-password', ['request' => $request]));
        Fortify::verifyEmailView(fn () => view('auth.verify-email'));

        // C4: non-enumerating reset request is Fortify default; rate-limit per email+IP.
        RateLimiter::for('login', function (Request $request) {
            $key = Str::transliterate(Str::lower((string) $request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($key);
        });

        RateLimiter::for('verification-codes', function (Request $request) {
            return Limit::perMinute(5)->by((string) optional($request->route('user'))->id.'|'.$request->ip());
        });

        // J3: throttle quiz writes (autosave/submit) per authenticated user — autosave is
        // debounced ~800ms client-side, so 60/min is generous headroom while capping abuse.
        RateLimiter::for('quiz-writes', function (Request $request) {
            return Limit::perMinute(60)->by((string) optional($request->user())->id ?: $request->ip());
        });
    }
}

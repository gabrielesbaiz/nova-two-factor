<?php

namespace Gabrielesbaiz\NovaTwoFactor\Http\Controller;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FAQRCode\Google2FA;
use PragmaRX\Google2FA\Google2FA as G2fa;
use Gabrielesbaiz\NovaTwoFactor\Helpers\NovaUser;
use Gabrielesbaiz\NovaTwoFactor\TwoFaAuthenticator;

class TwoFactorController
{
    use NovaUser;

    public static function generateGoogleQRCodeUrl($domain, $page, $queryParameters, $qrCodeUrl)
    {
        $url = $domain .
            rawurlencode($page) .
            '?' . $queryParameters .
            urlencode($qrCodeUrl);

        return $url;
    }

    public function register()
    {
        if ($this->novaUser()->hasTwoFactorAuthenticationConfirmed()) {
            return $this->settings();
        }

        return inertia('NovaTwoFactor.Register', [
            ...$this->registerUser(),
        ]);
    }

    public function settings()
    {
        return inertia('NovaTwoFactor.Settings', [
            'enabled' => $this->novaUser()->hasTwoFactorAuthenticationEnable(),
        ]);
    }

    public function verifyOtp(Request $request)
    {
        $request->validate([
            'otp' => 'required',
        ]);

        $otp = request()->get('otp');
        request()->merge(['one_time_password' => $otp]);

        $authenticator = app(TwoFaAuthenticator::class)->boot(request());

        if ($authenticator->isAuthenticated()) {
            // otp auth success!

            $this->novaUser()->twoFa()->update([
                'confirmed' => true,
                'google2fa_enable' => true,
            ]);

            return response()->json([
                'message' => __('2FA security successfully activated !'),
                'url' => '/nova-two-factor/settings',
            ]);
        }

        // auth fail
        return response()->json([
            'message' => __('Invalid OTP !. Please try again'),
        ], 422);
    }

    public function toggle2Fa(Request $request)
    {
        $status = $request->get('status', false);

        $this->novaUser()->twoFa()->update([
            'google2fa_enable' => $status,
        ]);

        return response()->json([
            'message' => $status ? __('2FA feature enabled!') : __('2FA feature disabled !'),
        ]);
    }

    public function getQRCodeUsingGoogle($company, $holder, $secret, $size = 500)
    {
        $url = (new Google2FA())->getQRCodeUrl($company, $holder, $secret);

        return "https://api.qrserver.com/v1/create-qr-code/?size={$size}x{$size}&data={$url}";
    }

    public function authenticate(Request $request)
    {
        $authenticator = app(TwoFaAuthenticator::class)->boot(request());

        if ($authenticator->isAuthenticated()) {
            session()->put('2fa.logged_at', now());
            session()->put('2fa.prompt', false);

            return redirect()->intended(config('nova.path'));
        }

        return back()->withErrors([__('Incorrect OTP !')]);
    }

    public function recover(Request $request)
    {
        if ($request->isMethod('get')) {
            return view('nova-two-factor::recover');
        }

        if (Hash::check($request->get('recovery_code'), $this->novaUser()->twoFa->recovery)) {
            // reset 2fa
            $this->novaUser()->twoFa()->delete();

            return redirect()->to(config('nova.path'));
        }

        return back()->withErrors([__('Incorrect recovery code !')]);
    }

    public function validatePrompt(Request $request)
    {
        $authenticator = app(TwoFaAuthenticator::class)->boot($request);

        if ($authenticator->isValidOtp()) {
            session()->put('2fa.prompt_at', now());

            return response()->json([
                // 'goto' => session()->get('url.intended')
            ]);
        }

        return response()->json([
            'message' => __('Incorrect OTP'),
        ], 422);
    }

    public function clear(Request $request)
    {
        if ($request->isMethod('get')) {
            return inertia('NovaTwoFactor.Clear');
        }

        $request->validate([
            'password' => 'required|current_password:' . config('nova.guard'),
        ]);

        app(TwoFaAuthenticator::class)->logout();

        $this->novaUser()->twoFa()->delete();

        return response()->json(['message' => __('Two FA settings has been cleared')]);
    }

    private function generateRecoveryCode(): string
    {
        $recoveryKey = strtoupper(Str::random(16));
        $recoveryKey = str_split($recoveryKey, 4);
        $recoveryKey = implode('-', $recoveryKey);

        return $recoveryKey;
    }

    private function registerUser()
    {
        $google2fa = new G2fa();
        $secretKey = $google2fa->generateSecretKey();

        $recovery = $this->generateRecoveryCode();
        $recoveryKeyHashed = bcrypt($recovery);

        if ($this->novaUser()->twofa) {
            $this->novaUser()->twofa->update([
                'recovery' => $recoveryKeyHashed,
            ]);
        } else {
            $this->novaUser()->twofa()->create([
                'google2fa_secret' => $secretKey,
                'recovery' => $recoveryKeyHashed,
            ]);
        }

        $this->novaUser()->refresh();

        $url = null;
        $company = config('app.name');
        $email = $this->novaUser()->email;
        $secretKey = $this->novaUser()->twofa->google2fa_secret;

        if (config('nova-two-factor.use_google_qr_code_api')) {
            $url = $this->getQRCodeUsingGoogle($company, $email, $secretKey);
        } else {
            $url = (new Google2FA())->getQRCodeInline($company, $email, $secretKey, 500);
        }

        $data = [
            'qr_url' => $url,
            'recovery' => $recovery,
        ];

        return $data;
    }
}

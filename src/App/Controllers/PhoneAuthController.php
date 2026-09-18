<?php

namespace Keel\App\Controllers;

use Keel\App\Models\User;
use Keel\App\Services\PhoneOtpService;
use Keel\Core\Activity;
use Keel\Core\Controller;
use Keel\Core\Request;
use Keel\Core\Session;

/**
 * Phone OTP sign-in. Keel's email OTP and magic link stay where they are; this
 * is the door FairPlate customers, kitchens and drivers actually use.
 */
class PhoneAuthController extends Controller
{
    public function showLogin(Request $request): void
    {
        $this->view('auth.phone-login', ['title' => 'Sign in to FairPlate']);
    }

    public function requestOtp(Request $request): void
    {
        $phone = trim((string) $request->input('phone'));

        if ($phone === '') {
            $this->json(['success' => false, 'message' => 'Enter your phone number.'], 422);
        }

        $result = (new PhoneOtpService())->requestCode($phone);

        $this->json($result, $result['success'] ? 200 : 422);
    }

    public function verifyOtp(Request $request): void
    {
        $phone = trim((string) $request->input('phone'));
        $code = trim((string) $request->input('code'));

        if ($phone === '' || $code === '') {
            $this->json(['success' => false, 'message' => 'Phone and code are required.'], 422);
        }

        $result = (new PhoneOtpService())->verifyCode($phone, $code);

        if (!($result['success'] ?? false)) {
            $this->json($result, 422);
        }

        $this->loginUser($result['user']);

        $this->json([
            'success' => true,
            'redirect' => User::homePath($result['user']),
        ]);
    }

    private function loginUser(array $user): void
    {
        Session::regenerate();
        Session::put('user_id', (int) $user['id']);
        Session::put('user_phone', (string) ($user['phone'] ?? ''));
        Session::put('user_role', (string) ($user['role'] ?? ''));
        Session::put('theme_preference', $user['theme_preference'] ?? null);
        Activity::log('user.login');
    }
}

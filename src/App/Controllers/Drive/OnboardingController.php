<?php

namespace Keel\App\Controllers\Drive;

use Keel\App\Models\Driver;
use Keel\App\Models\User;
use Keel\App\Services\StripeConnectService;
use Keel\Core\Activity;
use Keel\Core\Request;
use Keel\Core\Response;

/**
 * Signing up to drive: the profile, the vehicle, and the bank details.
 *
 * Three steps, in an order chosen so that nobody is asked for anything before
 * they have a reason to give it. Name and car first, because that is two
 * minutes and it is what a customer will see in the street. Stripe second,
 * because handing a tax identifier and a bank account to a company you have not
 * finished signing up to is a reasonable thing to hesitate over. Approval last,
 * and it is a person's decision.
 *
 * The screen after that is the honest part. FairPlate cannot promise a new
 * driver when they will be approved, so the pending state says what has been
 * done, what is left and who is holding it, rather than a spinner.
 */
class OnboardingController extends DriverController
{
    /** Long enough for "Volkswagen", short enough to fit the column. */
    private const MAX_FIELD = 80;

    public function show(Request $request): void
    {
        $driver = $this->driver();

        $this->view('drive.onboarding', array_merge(
            $this->shell($driver, 'account', 'Set up'),
            [
                'connect' => $this->connectStatus($driver),
                'values' => $this->formValues($driver),
            ]
        ));
    }

    /**
     * Saves the profile and the vehicle, creating the driver record the first
     * time round.
     *
     * A new record is created not approved, which is the whole of the pending
     * state, and offline, so that finishing this form does not put somebody on
     * the dispatch list before an admin has looked at them.
     */
    public function save(Request $request): void
    {
        $driver = $this->driver();

        $name = $this->text($request->input('name', ''), self::MAX_FIELD);
        $make = $this->text($request->input('vehicle_make', ''), self::MAX_FIELD);
        $model = $this->text($request->input('vehicle_model', ''), self::MAX_FIELD);
        $color = $this->text($request->input('vehicle_color', ''), 40);
        $plate = strtoupper($this->text($request->input('plate', ''), 20));

        if ($name === '' || $make === '' || $model === '') {
            $this->back('/drive/onboarding', 'Your name and what you drive are both needed.', 'bad');
        }

        User::updateName($this->userId(), $name);

        $attributes = [
            'vehicle_make' => $make,
            'vehicle_model' => $model,
            'vehicle_color' => $color === '' ? null : $color,
            'plate' => $plate === '' ? null : $plate,
        ];

        if ($driver === null) {
            $driverId = Driver::create($attributes + [
                'user_id' => $this->userId(),
                'approved' => 0,
                'online' => 0,
                'idle' => 1,
            ]);

            Activity::log('driver.applied', 'Driver', $driverId, ['vehicle' => $make . ' ' . $model]);

            $this->back('/drive/onboarding', 'Saved. Connect your payouts next.');
        }

        Driver::update((int) $driver['id'], $attributes);

        $this->back('/drive/onboarding', 'Saved.');
    }

    /**
     * Starts Stripe onboarding.
     *
     * A POST because the first call creates a connected account, and a GET that
     * creates things is a GET a browser will helpfully repeat.
     */
    public function startConnect(Request $request): void
    {
        $driver = $this->requireDriverProfile();

        try {
            $url = (new StripeConnectService())->driverOnboardingUrl($driver);
        } catch (\Throwable $exception) {
            error_log('[FairPlate] Driver Stripe onboarding failed: ' . $exception->getMessage());

            $this->back('/drive/onboarding', 'Stripe could not start. Try again in a moment.', 'bad');
        }

        Response::redirect($url);
    }

    /**
     * Stripe's link expired before it was used. Mint another and bounce.
     */
    public function refreshConnect(Request $request): void
    {
        $driver = $this->requireDriverProfile();

        try {
            $url = (new StripeConnectService())->driverRefreshUrl($driver);
        } catch (\Throwable $exception) {
            error_log('[FairPlate] Driver Stripe refresh failed: ' . $exception->getMessage());

            $this->back('/drive/onboarding', 'That Stripe link expired. Start payouts setup again.', 'bad');
        }

        Response::redirect($url);
    }

    /**
     * Back from Stripe. Asks Stripe what happened rather than assuming that
     * arriving here means the form was finished.
     */
    public function returnFromConnect(Request $request): void
    {
        $driver = $this->requireDriverProfile();
        $status = (new StripeConnectService())->status($driver);

        Activity::log('driver.stripe_onboarding_returned', 'Driver', (int) $driver['id'], $status);

        if (!$status['details_submitted']) {
            $this->back('/drive/onboarding', 'Stripe still needs a few details before you can be paid.', 'warn');
        }

        // Finished with Stripe is not the same as cleared to drive. The spec has
        // an admin approve, so nothing is flipped here and the message says so
        // rather than implying the driver can start taking offers.
        $this->back(
            '/drive/onboarding',
            Driver::isApproved($driver)
                ? 'Payouts are connected. You are good to go.'
                : 'Payouts are connected. FairPlate will review your application next.'
        );
    }

    /**
     * What Stripe says, without letting a Stripe outage take the screen down.
     *
     * @return array<string, mixed>
     */
    private function connectStatus(?array $driver): array
    {
        if ($driver === null) {
            return [
                'connected' => false,
                'details_submitted' => false,
                'charges_enabled' => false,
                'payouts_enabled' => false,
                'requirements' => [],
            ];
        }

        return (new StripeConnectService())->status($driver);
    }

    /**
     * @return array<string, string>
     */
    private function formValues(?array $driver): array
    {
        $user = $this->user();

        return [
            'name' => (string) ($user['name'] ?? ''),
            'vehicle_make' => (string) ($driver['vehicle_make'] ?? ''),
            'vehicle_model' => (string) ($driver['vehicle_model'] ?? ''),
            'vehicle_color' => (string) ($driver['vehicle_color'] ?? ''),
            'plate' => (string) ($driver['plate'] ?? ''),
        ];
    }

    private function text(mixed $value, int $max): string
    {
        return mb_substr(trim((string) $value), 0, $max);
    }
}

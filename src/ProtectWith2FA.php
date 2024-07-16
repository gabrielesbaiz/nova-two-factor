<?php

namespace Visanduma\NovaTwoFactor;

use Visanduma\NovaTwoFactor\Models\TwoFa;

trait ProtectWith2FA
{
    public function twoFa()
    {
        return $this->hasOne(TwoFa::class, 'user_id', config('nova-two-factor.user_id_column'));
    }

    /**
     * Check if user has two factor authentication.
     *
     * @return bool
     */
    public function hasTwoFactorAuthentication(): bool
    {
        return $this->twoFa
            ? true
            : false;
    }

    /**
     * Check if user has not two factor authentication.
     *
     * @return bool
     */
    public function hasNotTwoFactorAuthentication(): bool
    {
        return ! $this->hasTwoFactorAuthentication();
    }

    /**
     * Check if user has two factor authentication enable.
     *
     * @return bool
     */
    public function hasTwoFactorAuthenticationEnable(): bool
    {
        return $this->hasTwoFactorAuthentication() && $this->twoFa->google2fa_enable
            ? true
            : false;
    }

    /**
     * Check if user has two factor authentication not enable.
     *
     * @return bool
     */
    public function hasTwoFactorAuthenticationNotEnable(): bool
    {
        return ! $this->hasTwoFactorAuthenticationEnable();
    }

    /**
     * Check if user has two factor authentication confirmed.
     *
     * @return bool
     */
    public function hasTwoFactorAuthenticationConfirmed(): bool
    {
        return $this->hasTwoFactorAuthentication() && $this->twoFa->confirmed == 1
            ? true
            : false;
    }
}

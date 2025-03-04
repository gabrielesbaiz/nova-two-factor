<?php

namespace Gabrielesbaiz\NovaTwoFactor;

use Exception;
use Gabrielesbaiz\NovaTwoFactor\Helpers\NovaUser;
use PragmaRX\Google2FALaravel\Support\Authenticator;

class TwoFaAuthenticator extends Authenticator
{
    use NovaUser;

    public function isValidOtp(): bool
    {
        return $this->checkOTP() == 'valid';
    }

    protected function canPassWithoutCheckingOTP()
    {
        return
            ! $this->isEnabled() ||
            $this->noUserIsAuthenticated() ||
            $this->twoFactorAuthStillValid();
    }

    /**
     * @return mixed
     * @throws Exception
     */
    protected function getGoogle2FASecretKey()
    {
        $secret = $this->getUser()->twoFa->google2fa_secret;

        if (is_null($secret) || empty($secret)) {
            throw new Exception('Secret key cannot be empty.');
        }

        return $secret;
    }

    protected function getUser()
    {
        return $this->novaUser();
    }
}

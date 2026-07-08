<?php

namespace App\Support\Eloquent;

use Illuminate\Contracts\Encryption\DecryptException;

trait IgnoresInvalidEncryptionInLocal
{
    /**
     * @param  string  $value
     * @return mixed
     */
    public function fromEncryptedString($value)
    {
        try {
            return parent::fromEncryptedString($value);
        } catch (DecryptException $exception) {
            if ($this->shouldIgnoreInvalidEncryption()) {
                return null;
            }

            throw $exception;
        }
    }

    protected function shouldIgnoreInvalidEncryption(): bool
    {
        return app()->environment(['local', 'development']);
    }
}

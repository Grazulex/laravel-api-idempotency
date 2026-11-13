<?php

declare(strict_types=1);

namespace Grazulex\ApiIdempotency\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\StringEncrypter;

/**
 * Encrypts stored response bodies with the application encrypter (APP_KEY).
 *
 * Encrypted bodies carry a prefix, so a record is decrypted on read whatever
 * the current `storage.encrypt` setting is.
 */
class ResponseBodyCipher
{
    public const PREFIX = 'api-idempotency:encrypted:v1:';

    public function __construct(
        protected ?StringEncrypter $encrypter = null,
    ) {}

    public function encrypt(string $body): string
    {
        return self::PREFIX.$this->encrypter()->encryptString($body);
    }

    /**
     * @throws DecryptException when the body was tampered with or the key that encrypted it is gone
     */
    public function decrypt(string $body): string
    {
        if (! self::isEncrypted($body)) {
            return $body;
        }

        return $this->encrypter()->decryptString(substr($body, strlen(self::PREFIX)));
    }

    public static function isEncrypted(?string $body): bool
    {
        return $body !== null && str_starts_with($body, self::PREFIX);
    }

    /**
     * Resolved on first use, so applications without APP_KEY only need one when encryption is used.
     */
    protected function encrypter(): StringEncrypter
    {
        return $this->encrypter ??= app(StringEncrypter::class);
    }
}

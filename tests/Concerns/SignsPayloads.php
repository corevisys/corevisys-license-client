<?php

namespace CoreVisys\License\Tests\Concerns;

/**
 * Produces CoreVisys-style signed response envelopes for use in tests,
 * without ever calling a live server.
 *
 * Deliberately does NOT generate a key pair at runtime via
 * openssl_pkey_new() — RSA key *generation* frequently fails on Windows
 * PHP builds unless OPENSSL_CONF / php.ini's openssl.cnf is configured
 * just right, throwing an opaque "Cannot get key from parameter 1".
 * Loading and using an *existing* key (openssl_sign/openssl_verify via
 * openssl_pkey_get_private/openssl_pkey_get_public) does not have that
 * dependency, so a pair of static, test-only keys sidesteps the problem
 * entirely. These keys are used for tests only and are never shipped in
 * any production configuration.
 */
trait SignsPayloads
{
    protected function keyPair(): array
    {
        return [
            'private' => self::TEST_PRIVATE_KEY,
            'public' => self::TEST_PUBLIC_KEY,
        ];
    }

    /**
     * A second, distinct key pair — used by the public-key-rotation test
     * to prove the client fetches the *new* key by key_id rather than
     * reusing whatever it already has cached.
     */
    protected function secondaryKeyPair(): array
    {
        return [
            'private' => self::TEST_PRIVATE_KEY_2,
            'public' => self::TEST_PUBLIC_KEY_2,
        ];
    }

    protected function signedEnvelope(
        array $data,
        string $keyId = 'test-key-1',
        bool $corruptSignature = false,
        bool $useSecondaryKey = false,
    ): array {
        ksort($data);
        $canonical = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $privatePem = $useSecondaryKey ? $this->secondaryKeyPair()['private'] : $this->keyPair()['private'];
        $privateKey = openssl_pkey_get_private($privatePem);

        if ($privateKey === false) {
            $this->fail('Could not load the static test RSA private key: '.(openssl_error_string() ?: 'unknown error'));
        }

        openssl_sign($canonical, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        $encoded = base64_encode($signature);

        if ($corruptSignature) {
            $encoded = base64_encode($signature.'x');
        }

        return [
            'success' => true,
            'status' => 'success',
            'message' => 'ok',
            'data' => $data,
            'signature' => $encoded,
            'key_id' => $keyId,
            'algorithm' => 'RSA-SHA256',
        ];
    }

    protected function publicKeyResponse(string $keyId = 'test-key-1', bool $useSecondaryKey = false): array
    {
        return [
            'public_key' => $useSecondaryKey ? $this->secondaryKeyPair()['public'] : $this->keyPair()['public'],
            'key_id' => $keyId,
        ];
    }

    private const TEST_PRIVATE_KEY = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQDSXMfLVcFQgK7e
9W0MQtLLUEB6oe0VHIqZejJR3VpB39CYQPCDPeQuuytt+ff+nFjCRMIHOwHoIgWW
ow93U7q01x5yIv55b/ATZdHLjZ98gh+CjTrc3fWfzsaxFEKA3YOshU0o/EyawkOs
SKhFvSXUFA39KIzCtbijuS/wSyCnCu9W4tq3gYCmV4HdUOj46FieTNJ9lMrdStXq
sOnCZzBnSLqhwrlRS4tbGGlSAMPfAmpEMT/TjW2MZ1/Lf95/agmqaC9AdHb0f3IS
TwKB9uDjN3LoUBmC6YPJ/l3NN88Q4nfu/v5YynN3+rfB6eekbBIp+C4QsOpmvBnV
Jft4LIl3AgMBAAECggEAAX70aLWogYJ9jiTvw1cO0yylZNnbKSl8jdwTqwsqMhqc
4ABMu/Lf4V68T7Z79unZZfaMqp4upZbKD7LXdsHIbX+AsWxgT6kiZClhCqYUgGs0
wN8ZWVwFSOP4eElbNdN43Wxc2WfdNtmbWOBObG4id3W3vCYXivW4+WyfqZrUa6gB
3YPs6r3DcW6fc4OihnAWsOyxpaQ5QVakH2pWyCA0jtcpSrvjVWW3cKM+aSum2PoM
7+7k3wqDTtuFSOuYYIWrzXL45JzLtip//mJP8ez8vJ5RYgiyD4FvNXGfH+8pjCO9
4DxyCWYqIUuLc+rhDCVW10CcjBVTolIIe16NBymVVQKBgQD92YQ7UrQSgLl9n9Oq
SexBYMp7yv8iCk6iToKNNp9jIdu7hL6AQ5W4MbNkg67FLBEiinj4lhoebwjdu1Q7
qJBI/WO4ZLDK67vWCBEtP1h5ZYP0jwK7EFZ9AaWCHVQeYdSWJrBpABqQmEqsh9rq
5/2f5ZEm5vKUWKNgYpW8t9TWVQKBgQDUJPXEFwgSxLkFKVhfZ9+a6tLDxzEbYA6F
zWF01DesRaKJOiQFn3/06gmEXNCnpldFTUEHiF5ki8mTbBqR4TS+S32EFuQ5imrd
Jjy168uH25IsaThpeOblyAI58ttd0uwJRCPew57XfG8i/kTKmCMy48g/Wh7K49l1
czBcgi+0mwKBgBxPfvTSw2xw2L8O7R9HwUaFUe++cvfL6HsngF3ZYqs+om/mXQyW
/QKe4F/sY7hvsrWEdftbWixcu8Nm2f0RTo4lXFK7QBBRfBBhs/C06NwZGz9SF77f
EpY8ccXyGWiOBpR8Wh5Luaq4oVNej2a1Ws7TXn3VMeajgA0G0aZLZjxFAoGAaqZm
E32MpnrVlR2y+suqoyQYbyoNqviAdI3Kx8QEdQvQ6XIcN+N2nXam8C8FCrNaPlHX
NmU9JwkLfpyjQuFX9a7X2/byJ2dJ0AHwFXkEKjmdY8xF+ug4FB6X1/AajjGCTio+
ajgn+6bn7Eyt4rfXQjc3LXot7svbP+t3zZn5R68CgYEAhTcmh3IgoRdUJg3mro/9
33WggwPDRA4j7gvUZla64LV5S523nPB3o1bhz7RUSXLMw5s15iH1yu04Vhoa39vD
bzWBqi/ZAjloKr2OY5Wk5hlMl6u27PWZI6E1GyMCAYTujVUNtFCddQ5b2nJh1LAo
4N01AmzuhGFRKCsJ0CvVXjQ=
-----END PRIVATE KEY-----
PEM;

    private const TEST_PUBLIC_KEY = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA0lzHy1XBUICu3vVtDELS
y1BAeqHtFRyKmXoyUd1aQd/QmEDwgz3kLrsrbfn3/pxYwkTCBzsB6CIFlqMPd1O6
tNceciL+eW/wE2XRy42ffIIfgo063N31n87GsRRCgN2DrIVNKPxMmsJDrEioRb0l
1BQN/SiMwrW4o7kv8EsgpwrvVuLat4GApleB3VDo+OhYnkzSfZTK3UrV6rDpwmcw
Z0i6ocK5UUuLWxhpUgDD3wJqRDE/041tjGdfy3/ef2oJqmgvQHR29H9yEk8Cgfbg
4zdy6FAZgumDyf5dzTfPEOJ37v7+WMpzd/q3wennpGwSKfguELDqZrwZ1SX7eCyJ
dwIDAQAB
-----END PUBLIC KEY-----
PEM;

    // A second, distinct key pair for the public-key-rotation test.
    private const TEST_PRIVATE_KEY_2 = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQC9qOuvdDmm6AyQ
MA3pDufnp6EnQhj/Cd6T6sLEI+jZoWkwstcoop5ZEewAtwrZj/GPrfyXpkiuwFj2
vH4JFjS6hJVTNOSvpYhJST3bix1eOYlUp6HIzIwoDnlcr9AiBH6dc3iwQxEJ/I43
ncciSmx2nMpOpLxx0PQTjkeDdb3zQc9ldT8+3YGHEHqoenw4ATeQHvcf+IJvVRr/
vK8mqQxMkUnrdo12KFQxwipH8fcQYsZsO4k8lJ6rcEJyrNfdrr0yuQvZb0WDeDM3
ccpV1pulzkEs+f9wSbrzn0iqpoCMQhzlhNxrA1asbbxhnq5ZvCcqdu5brt0oY9Uw
CCgkppArAgMBAAECggEACrgZWfk/qOdEjoGox5tIEBexS/64WvG72rBSAbPl3Sb6
Qv5YmrtWJ8KTjBbMTi+Mf4pd1FRZl0bXwFo25VyT7la/+cvrgOHiKgIxtM7QAhtO
X7J5uleVNE5dHZfyM3n9jfiQwaWIuP/FKe+I6a87IhkKdhdpbyVYJiLMd+mXqr/c
gOhlfHFb5gwK3h4JGIfVabXBGYzGWbL766KRgGHixgEAMYFHthcehURwGldBi8zF
WAjVcD9SSYA8XMqKMGqHzE6Ejy4nN88SNBw8T8BnN9BpMm/Pdr5J8rEAXlgbtAeD
0AZX7wRwp4JvZTu01521oYyMPU1aboTDMeZE7EoVQQKBgQDncYP2Goa7C4C+MVLj
LDYwjUJjAvkNCgfDeMwMstZqJ3Mz+147TBNMTBySkdl2KBgAs/cT6ejgAzaiEnxo
ZsV9MFatArWgV/8SyDVAnBMQEiuQR/+8yQeB+Z5o5Ov2wkzxynQx7F0eJMWYNF18
P2o8nhl/9IcQK4ryHjeIVMh6FQKBgQDRyHn3h2QFjl1bjlCRZ4NKXDcQA7Xte+Ei
iuoyjzwFrW1G+WSKDWlO64ZCGAGQuxG+MyQCy24yKOFOv+tkM66gTAnYFh9+Dbvj
8mKo911Tt5nGQudUclWG3rTpxSSOeqsWrf3eprW5o6RYuginpOzsQh+oVMyWiMpk
i1rvQUixPwKBgQDQNMl/A1wDNpTqBJtJbMOPJ/T593mvJj/XtHr0TYogUz8LG24p
MAYIVEw7+uNDrUvyjfOPQZVSuPFUGgc7MIEnXu4KlG5qQd9guSVW61Em2wG/uVWy
MrMDVVkRiidQhHkN55BiPP2EGZZ8l1cmaDIdOCk+d+9tN462w0I37fWwBQKBgQCk
xIMXaZ2jx4eH66VYLyctdnRA/ckcd9oCGX2MrHeGNgrIXgUbcSEvPUm8C8Le/C8Z
Zm14THOGrhkYkyC9GOKlQFPTBr1BcmQKy0u2TmNc5629zLqI1yxZu/34RkFKLwrF
y27EO8grwF3K2oMFuUHk5qKawc/WxCXDBrkrhekkXQKBgCJNUw2fgf1MSGKlkz+z
4M7eX6xw8WFyBGkmPfTTCOXJCS5JO9eB/iXovuqolTSKkPLYVgXP64bZKjjTShEt
xw48Tbq24qTOznvvuc1O3ouLJVwwP92NebHxOpLVzgFCHAUHUM4gEYVIP3GdPRGp
iVNOpoVRygBHmWzpLtbBIzm+
-----END PRIVATE KEY-----
PEM;

    private const TEST_PUBLIC_KEY_2 = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAvajrr3Q5pugMkDAN6Q7n
56ehJ0IY/wnek+rCxCPo2aFpMLLXKKKeWRHsALcK2Y/xj638l6ZIrsBY9rx+CRY0
uoSVUzTkr6WISUk924sdXjmJVKehyMyMKA55XK/QIgR+nXN4sEMRCfyON53HIkps
dpzKTqS8cdD0E45Hg3W980HPZXU/Pt2BhxB6qHp8OAE3kB73H/iCb1Ua/7yvJqkM
TJFJ63aNdihUMcIqR/H3EGLGbDuJPJSeq3BCcqzX3a69MrkL2W9Fg3gzN3HKVdab
pc5BLPn/cEm6859IqqaAjEIc5YTcawNWrG28YZ6uWbwnKnbuW67dKGPVMAgoJKaQ
KwIDAQAB
-----END PUBLIC KEY-----
PEM;
}

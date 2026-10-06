# CoreVisys Laravel License Client - Gap Analysis

## 1. Current assessment

The previously identified client/server interoperability gaps are resolved and verified. The evidence standard is three-part: current source, both suites asserting the shared contract, and a real local HTTP round trip. Independent suite success alone is not treated as integration proof.

## 2. Resolved - verified end-to-end

### 2.1 Envelope and field contract

The server's shared response builder emits `success`, `status`, `message`, `data`, `signature`, `key_id`, and `algorithm` at [LicenseController.php](../server/app/Http/Controllers/Api/V1/LicenseController.php#L226-L237). The signed license payload explicitly adds `license_id`, `product_code`, and `features`, together with the remaining canonical fields, at [LicenseController.php](../server/app/Http/Controllers/Api/V1/LicenseController.php#L240-L258). The client strictly requires boolean `success`, string `status`, and array `data` in [LicenseResponse.php](../src/DTOs/LicenseResponse.php#L26-L49).

The exact envelope/data field lists are shared by [license-response-contract.json](../tests/Fixtures/license-response-contract.json), asserted by [LicenseActivationTest.php](../tests/Feature/LicenseActivationTest.php#L14-L22) and [OfflinePolicyTest.php](../server/tests/Feature/OfflinePolicyTest.php#L16-L24).

### 2.2 Semantic API version header

The client default is `client_version: 1.0.0` in [corevisys-license.php](../config/corevisys-license.php). `ApiRequestHandler` sends `X-API-Version` in [ApiRequestHandler.php](../src/Services/ApiRequestHandler.php#L40-L50), and the server compares it semantically in [CheckClientVersion.php](../server/app/Http/Middleware/CheckClientVersion.php#L20-L31).

### 2.3 Recursive canonicalization

The server recursively sorts associative arrays and uses the three canonical JSON flags in [OfflineLicenseVerification.php](../server/app/Support/OfflineLicenseVerification.php#L7-L18) and [OfflineLicenseVerification.php](../server/app/Support/OfflineLicenseVerification.php#L103-L125). The client performs the same recursive sort and JSON serialization in [LicenseResponse.php](../src/DTOs/LicenseResponse.php#L61-L64) and [LicenseResponse.php](../src/DTOs/LicenseResponse.php#L98-L115).

This closes the former shallow-sort mismatch.

### 2.4 Rotation and revocation

The server publishes `available_keys`, `active_key_id`, and `revoked_key_ids` at [LicenseController.php](../server/app/Http/Controllers/Api/V1/LicenseController.php#L158-L176). The client refreshes metadata, resolves a specific response `key_id`, and rejects revoked IDs before using cached public keys in [SignedPayloadVerifier.php](../src/Services/SignedPayloadVerifier.php#L145-L187). Previously cached keys are checked against refreshed revocation metadata.

### 2.5 Offline expiry

The server issues `offline_valid_until` using the seven-day default in [license.php](../server/config/license.php#L1-L6). The client persists the server-issued boundary and requires both it and `expires_at` to pass before accepting offline cache in [LicenseVerifier.php](../src/Services/LicenseVerifier.php#L139-L156) and [LicenseVerifier.php](../src/Services/LicenseVerifier.php#L200-L236). The local grace setting can shorten but never extend the server boundary.

### 2.6 Algorithm negotiation and crypto scope

Only RSA-SHA256 is supported. The server signs with `OPENSSL_ALGO_SHA256` at [LicenseController.php](../server/app/Http/Controllers/Api/V1/LicenseController.php#L260-L283) and reports the fixed constant from [OfflineLicenseVerification.php](../server/app/Support/OfflineLicenseVerification.php#L5-L25). The client accepts only `rsa`; an unsupported value fails during provider registration with `InvalidArgumentException` in [CoreVisysServiceProvider.php](../src/CoreVisysServiceProvider.php#L27-L36) and [SignedPayloadVerifier.php](../src/Services/SignedPayloadVerifier.php#L25-L32).

Ed25519 was evaluated, implemented, tested with a real Sodium round trip, and then deliberately removed. This is a closed design decision, not a future TODO. `ext-sodium` is not present in [composer.json](../composer.json).

## 3. Remaining non-contract operational items

The following are outside the resolved client/server wire-contract scope:

- package version metadata is still repeated in activation/heartbeat paths,
- scheduler interval mapping is intentionally coarse,
- external payment-provider sandbox verification requires provider credentials,
- production deployment must provide the RSA private/public key configuration.

These items must not be described as license-contract interoperability gaps.

## 4. Verification baseline

Rerun on 2026-09-20:

```text
Client: 45 tests passed, 77 assertions
Server: 110 tests passed, 398 assertions
```

The counts supersede the stale pre-fix values of 41/71 and 90/281. The increase is from added contract, rotation, revocation, offline-policy, and algorithm-validation tests.

The remediation also included a real local server/client run covering activate -> check -> pulse, suspended state, unknown and revoked key IDs, tampered signed data, and an expired offline boundary. Future changes to this contract require the same live evidence before documentation says resolved.

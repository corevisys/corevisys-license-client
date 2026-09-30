# CoreVisys Laravel License Client - Package Knowledge Base

## 1. Scope and source of truth

This package is the CoreVisys Laravel license client SDK. It consumes signed responses from the reference server in `server/`. The client and server are separate codebases with one runtime contract; passing each project's own test suite is not, by itself, proof of interoperability.

The current contract is defined by:

- [LicenseController.php](../server/app/Http/Controllers/Api/V1/LicenseController.php)
- [OfflineLicenseVerification.php](../server/app/Support/OfflineLicenseVerification.php)
- [LicenseResponse.php](../src/DTOs/LicenseResponse.php)
- [SignedPayloadVerifier.php](../src/Services/SignedPayloadVerifier.php)
- [license-response-contract.json](../tests/Fixtures/license-response-contract.json)
- [LicenseActivationTest.php](../tests/Feature/LicenseActivationTest.php)
- [OfflinePolicyTest.php](../server/tests/Feature/OfflinePolicyTest.php)

## 2. Cross-project contract note

The client and server MUST evolve together. The shared fixture `tests/Fixtures/license-response-contract.json` is read and asserted by both the client suite and the server suite. Any future change to response shape, field names, signing algorithm, key metadata, or offline semantics MUST update both implementations, the shared fixture, and both test suites in the same change. CI must pass on both sides before the change is considered complete.

The contract fixture asserts this envelope field set:

```text
success, status, message, data, signature, key_id, algorithm
```

For activate, check, and pulse signed license payloads, the data field set is:

```text
status, license_id, product_code, license_type, expires_at,
features, issued_at, offline_valid_until, is_grace_period
```

The server builds that payload in [LicenseController.php](../server/app/Http/Controllers/Api/V1/LicenseController.php#L240-L258) and wraps/signs it in [LicenseController.php](../server/app/Http/Controllers/Api/V1/LicenseController.php#L226-L237). The client strictly parses the envelope in [LicenseResponse.php](../src/DTOs/LicenseResponse.php#L26-L50).

## 3. API version negotiation

The client default is `1.0.0`, configured as `client_version` in [corevisys-license.php](../config/corevisys-license.php). The shared HTTP client sends it as `X-API-Version` in [ApiRequestHandler.php](../src/Services/ApiRequestHandler.php#L40-L50), and the public-key resolver sends the same header in [SignedPayloadVerifier.php](../src/Services/SignedPayloadVerifier.php#L200-L204).

The server middleware compares this semantic version with its configured minimum using `version_compare()` in [CheckClientVersion.php](../server/app/Http/Middleware/CheckClientVersion.php#L20-L31).

## 4. Canonicalization and signatures

The server recursively normalizes associative arrays, preserves list order, sorts associative keys, and serializes with:

```text
JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
```

This is implemented in [OfflineLicenseVerification.php](../server/app/Support/OfflineLicenseVerification.php#L7-L18) and its recursive helper at [OfflineLicenseVerification.php](../server/app/Support/OfflineLicenseVerification.php#L103-L125).

The client performs the same recursive normalization and uses the same JSON flags in [LicenseResponse.php](../src/DTOs/LicenseResponse.php#L61-L64) and [LicenseResponse.php](../src/DTOs/LicenseResponse.php#L98-L115).

This resolved the former shallow-client-sort versus recursive-server-sort interoperability defect. The canonical bytes must remain identical on both sides.

Only RSA-SHA256 signing and verification is supported. The server signs with `openssl_sign(..., OPENSSL_ALGO_SHA256)` in [LicenseController.php](../server/app/Http/Controllers/Api/V1/LicenseController.php#L260-L283). The server reports the fixed `RSA-SHA256` label from [OfflineLicenseVerification.php](../server/app/Support/OfflineLicenseVerification.php#L5-L25). The client accepts only `rsa`; invalid configuration fails during provider registration.

Ed25519 support was evaluated, implemented, and tested during the remediation cycle, then deliberately removed. The trust boundary is intentionally limited to one production-verified RSA-SHA256 path. `ext-sodium` is not a Composer dependency.

## 5. Key rotation and revocation

The server public-key endpoint publishes `key_id`, `active_key_id`, `available_keys`, `rotation_overlap_days`, and `revoked_key_ids` in [LicenseController.php](../server/app/Http/Controllers/Api/V1/LicenseController.php#L158-L176).

The client parses and caches the complete key set, resolves the response's specific `key_id`, and checks fresh revocation metadata before trusting a cached key in [SignedPayloadVerifier.php](../src/Services/SignedPayloadVerifier.php#L145-L187) and [SignedPayloadVerifier.php](../src/Services/SignedPayloadVerifier.php#L213-L224). An older overlapping key is accepted only when published and not revoked. Unknown and revoked key IDs fail closed, including keys previously cached as trusted.

## 6. Offline validity

The server default offline window is seven days from issuance, configured in [license.php](../server/config/license.php#L1-L6). Successful active payloads carry `issued_at` and `offline_valid_until`; suspended pulse payloads carry a null offline boundary.

The client persists these fields through activation, check, and pulse in [LicenseActivator.php](../src/Services/LicenseActivator.php#L55-L70), [LicenseVerifier.php](../src/Services/LicenseVerifier.php#L112-L130), and [LicenseHeartbeat.php](../src/Services/LicenseHeartbeat.php#L45-L61).

Cache fallback requires all of the following:

- `offline_valid_until` exists and is in the future,
- `expires_at` is absent or in the future,
- the cached signed payload still verifies,
- the local `grace_period` window has not expired.

The local grace setting can only shorten the server-issued boundary; it cannot extend it. The checks are implemented in [LicenseVerifier.php](../src/Services/LicenseVerifier.php#L139-L156), [LicenseVerifier.php](../src/Services/LicenseVerifier.php#L200-L208), and [LicenseVerifier.php](../src/Services/LicenseVerifier.php#L221-L236). A cached `status: active` value alone is never sufficient.

## 7. Verification evidence

Fresh post-fix automated baselines, rerun on 2026-09-20:

```text
Client: vendor\bin\phpunit
119 tests passed, 276 assertions   (2026-09-29, after the config/storage-safety phases;
                                    previously 84/205, then 63/106, then 46/81)

Server: php artisan test
110 tests passed, 398 assertions
```

The higher post-fix counts replace the historical pre-fix baselines of 41/71 client tests/assertions and 90/281 server tests/assertions. The increase comes from contract, rotation, revocation, offline-policy, and algorithm validation coverage; it is not scope creep.

A real locally booted server run was also executed during the remediation on 2026-09-20. It exercised activate -> check -> pulse with real RSA signatures, then exercised suspended-license rejection, unknown-key rejection, previously-cached revoked-key rejection, tampered cached payload rejection, and an expired offline boundary. Future contract changes should meet this same live round-trip evidence standard; isolated unit or suite-only results must not be described as end-to-end proof.

## 8. Historical clarification

Earlier documentation treated independent client and server test success as if it proved interoperability. That was incorrect and is historical only. The current status distinguishes:

- each side's own full suite passing,
- shared contract fixture assertions on both sides,
- live local HTTP round-trip evidence between the real client and server.

## 9. Runtime entry points

The public client entry point is `CoreVisysLicense`. Main operations are `activate()`, `check()`, `pulse()`, `isValid()`, `isActive()`, `isExpired()`, `isRevoked()`, `isSuspended()`, `feature()`, `features()`, `deactivate()`, `clearCache()`, `status()`, and `licenseKey()`.

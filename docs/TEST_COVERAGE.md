# Test Coverage Map

This map ties each required licensing scenario to the test that proves it. It is
a convenience index for reviewers; the tests themselves are the source of
truth. All tests below run in the default suite (the `live` group is excluded in
`phpunit.xml`).

| # | Scenario | Proving test (file:line) |
|---|----------|--------------------------|
| 1 | valid | `tests/Feature/LicenseActivationTest.php:25` (`test_valid_license_activation_succeeds`); `tests/Feature/ArtisanCommandsTest.php:67` (`test_status_command_shows_cached_status`) |
| 2 | invalid | `tests/Feature/LicenseActivationTest.php:83` (`test_invalid_license_activation_is_rejected`); `tests/Feature/ArtisanCommandsTest.php:31` (`test_activate_command_reports_failure`) |
| 3 | bad signature | `tests/Feature/LicenseCheckTest.php:94` (`test_invalid_signature_is_rejected`); `tests/Feature/LicenseCheckTest.php:113` (`test_missing_signature_is_rejected`) |
| 4 | expired | `tests/Feature/LicenseCheckTest.php:55` (`test_expired_license_is_invalid`) |
| 5 | grace active | `tests/Feature/LicenseCheckTest.php:131` (`test_offline_grace_period_keeps_license_valid`) |
| 6 | grace expired | `tests/Feature/LicenseCheckTest.php:146` (`test_offline_grace_period_expired_invalidates_license`) |
| 7 | suspended | `tests/Feature/LicenseCheckTest.php:198` (`test_suspended_license_is_invalid`) |
| 8 | unknown key_id | `tests/Feature/FastPathKeyRevocationTest.php:112` (`test_unknown_key_id_is_not_served_valid_on_the_fast_path`); `tests/Feature/ColdKeyCacheCharacterizationTest.php:36` (`test_cold_key_cache_is_labelled_unknown_key_id_known_limitation`) |
| 9 | revoked key_id | `tests/Feature/FastPathKeyRevocationTest.php:92` (`test_revoked_signing_key_is_not_served_valid_on_the_fast_path`) |
| 10 | route deny | `tests/Feature/MiddlewareExcludedRoutesTest.php:119` (`test_invalid_license_redirects_a_web_request_to_the_activation_route`); `tests/Feature/MiddlewareExcludedRoutesTest.php:144` (`test_expired_signed_license_denies_a_web_request`); `tests/Feature/MiddlewareExcludedRoutesTest.php:127` (`test_invalid_license_returns_a_generic_json_403_without_any_secret`) |
| 11 | open routes | `tests/Feature/MiddlewareExcludedRoutesTest.php:164` (`test_default_excluded_auth_and_health_routes_are_reachable_while_invalid`); `tests/Feature/MiddlewareExcludedRoutesTest.php:195` (custom excluded by name); `tests/Feature/MiddlewareExcludedRoutesTest.php:205` (custom excluded by path) |
| 12 | activation form — success | `tests/Feature/LicenseActivationScreenTest.php:26` (`test_submitting_a_valid_key_activates_and_redirects_back`) |
| 13 | activation form — failure | `tests/Feature/LicenseActivationScreenTest.php:47` (`test_submitting_an_invalid_key_shows_a_validation_error`) |
| 14 | activation form — validation | `tests/Feature/LicenseActivationScreenTest.php:60` (`test_submitting_without_a_key_fails_validation`) |
| 15 | activation form — key non-disclosure | `tests/Feature/LicenseKeyNonDisclosureTest.php:95` (`test_submitted_key_is_not_echoed_back_after_a_failed_activation`); `tests/Feature/LicenseKeyNonDisclosureTest.php:117` (`test_session_flash_never_contains_the_raw_key`) |
| 16 | server unavailable + fallback | `tests/Feature/LicenseCheckTest.php:185` (`test_api_timeout_falls_back_to_cache_within_grace`); `tests/Feature/LicenseVerifierRedactionTest.php:60` (`test_unavailable_server_never_surfaces_the_key`); `tests/Feature/LicenseStorageFallbackTest.php:143` (`test_primary_db_failure_uses_valid_fallback_payload`) |
| 17 | DB failure fallback | `tests/Feature/LicenseStorageFallbackTest.php:143` (`test_primary_db_failure_uses_valid_fallback_payload`); `tests/Feature/LicenseStorageFallbackTest.php:165` (`test_primary_db_failure_with_tampered_fallback_payload_is_rejected`) |

No scenario in the required list was missing a test, so no new test was added
for this item.

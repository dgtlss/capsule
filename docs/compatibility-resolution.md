# Compatibility dependency-resolution evidence

Checked 2026-10-10 using Composer 2.10.3. Framework versions below are released packages (`minimum-stability: stable`); no advisory ignores or security-check bypasses were configured.

| Requested combination | Result |
| --- | --- |
| Laravel 10.*, Testbench ^8, PHPUnit ^10.5, platform PHP 8.1.0 | Blocked by direct `laravel/framework` security advisories |
| Laravel 11.*, Testbench ^9, PHPUnit ^11.5, platform PHP 8.2.0 | Blocked by direct `laravel/framework` security advisories |
| Laravel 12.*, Testbench ^10, PHPUnit ^11.5, platform PHP 8.2.0 | Dependency resolution succeeds |
| Laravel 13.*, Testbench ^11, PHPUnit ^11.5, platform PHP 8.3.0 | Dependency resolution succeeds |

A platform override checks the solver; it does not execute tests on that PHP version. The local runtime was PHP 8.4.24. The baseline's existing `minimum-stability: dev` selected Laravel's `11.x-dev` branch, so a successful old baseline did not establish a safe stable Laravel 11 install.

## Direct Laravel 10 blocker

Composer found `laravel/framework` releases `v10.0.0` through `v10.50.3`, but rejected the candidate range because of these advisory IDs:

- `PKSA-d5tc-s1qs-h781`
- `PKSA-m5cs-t1y6-qpcs`
- `PKSA-3r5d-mb8f-1qw9`
- `PKSA-mdq4-51ck-6kdq`
- `PKSA-8qx3-n5y5-vvnd`
- `PKSA-w7xr-vk7n-rstm`

The additional `illuminate/mail` / PHP constraint error follows after the compatible older candidates have been filtered; it is not evidence that raising the PHP requirement fixes the framework advisories.

## Direct Laravel 11 blocker

Composer found `laravel/framework` releases `v11.0.0` through `v11.57.0`, but rejected the candidate range for the six IDs above plus:

- `PKSA-q46n-4fdk-zjr4`
- `PKSA-qzrn-rnz3-85w1`

The latest resolved Testbench candidate, `orchestra/testbench v9.18.0`, requires Laravel `^11.56.1`. Both framework candidates `v11.56.1` and `v11.57.0` are blocked by the first four IDs in the Laravel 10 list. This is a framework advisory blocker, not a claim that Testbench itself is vulnerable. IDs apply across a candidate range; the list does not mean every older release is affected by every listed advisory.

## Reproducing the check

In an isolated checkout, select the framework, Testbench and PHPUnit versions used by the CI row, set Composer's `config.platform.php` to that row's PHP version, then run `composer update --dry-run --prefer-dist --no-interaction --no-progress`. Keep Composer's default security policy enabled. The result can change as package versions or advisory data change.

Decision: this PR drops Laravel 10/11 and PHP 8.1 support as an explicit breaking compatibility change. It targets Laravel 12/13, keeps Composer security checks enabled, and removes the blocked legacy rows rather than installing known-vulnerable dependencies or reporting unresolved compatibility as verified. Upgrade the host application before adopting this change. The package version is 3.0.0 to reflect these breaking requirements; this PR does not publish a release or create a version tag. See [Packagist advisory data](https://packagist.org/security-advisories/) and [Laravel's support policy](https://laravel.com/docs/13.x/releases).

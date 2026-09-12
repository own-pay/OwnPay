# Pull Request Template

> [!IMPORTANT]
> **Branch Rule**
> Please open this Pull Request against the **`dev` branch**. PRs targeting `main` directly are not accepted.

## Summary

Provide a short summary of what this PR changes.

## Related Issue

Closes #

## What Changed

Describe the main changes included in this PR.

*
*
*

## Why This Change

Explain the problem this solves or the feature it adds.

## Type of Change

* [ ] Bug fix
* [ ] New feature
* [ ] Breaking change
* [ ] Documentation update
* [ ] Refactor / cleanup

## Screenshots / UI Changes

If applicable, attach screenshots, recordings, or before/after comparisons.

## How to Test

List the steps someone can follow to verify this PR.

1.
2.
3.

## Test Checklist

* [ ] `composer test` passes
* [ ] `composer analyse` passes
* [ ] `composer lint` passes
* [ ] Manual testing completed
* [ ] Relevant docs updated
* [ ] Existing behavior still works as expected

## OwnPay-Specific Checklist

* [ ] This PR targets `dev`, not `main`
* [ ] New PHP files use `declare(strict_types=1);`
* [ ] Code follows PSR-12
* [ ] Tenant-scoped reads/writes are handled correctly
* [ ] CSRF tokens use `SecurityHelpers::csrfToken()`
* [ ] New database tables/columns use the `op_` prefix and follow the column naming conventions
* [ ] Any gateway/plugin changes were tested end-to-end
* [ ] I reviewed the diff carefully for mistakes or typos

## Notes

Add anything reviewers should know before merging.

## License

* [ ] I agree that my contribution will be licensed under the **AGPL-3.0 License**.

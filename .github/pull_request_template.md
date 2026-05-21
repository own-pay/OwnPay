# Pull Request Template

> [!IMPORTANT]
> **Branch Rule**:
> Ensure that your Pull Request targets the **`dev` branch**. Pull requests targeting the `main` branch directly **will be automatically closed and rejected**.

## Description
Please include a summary of the changes and the related issue. Please also include relevant motivation and context. List any dependencies that are required for this change.

Fixes # (issue)

## Type of Change
Please check options that are relevant.
- [ ] Bug fix (non-breaking change which fixes an issue)
- [ ] New feature (non-breaking change which adds functionality)
- [ ] Breaking change (fix or feature that would cause existing functionality to not work as expected)
- [ ] Documentation Update

## 🎨 Design / UI Changes
*(If applicable, please attach screenshots or screen recordings showcasing the visual differences)*

## 🧪 How Has This Been Tested?
Please describe the tests that you ran to verify your changes. Provide instructions so we can reproduce.
- [ ] Automated Unit Tests (`vendor/bin/phpunit`)
- [ ] Static Analysis Check (`vendor/bin/phpstan analyse`)
- [ ] Manual verification in local environment

**Test Configuration**:
* OwnPay Version:
* PHP Version:
* Database:
* Environment:

## Checklist:
- [ ] My PR targets the **`dev` branch** and not the `main` branch.
- [ ] My code follows the [PSR-12 coding standards](https://github.com/own-pay/OwnPay/blob/dev/CONTRIBUTING.md).
- [ ] I have declared `declare(strict_types=1);` at the top of all new PHP files.
- [ ] I have resolved dependencies via the DI container where applicable.
- [ ] I have scoped brand-specific data queries via `TenantScope` repository methods.
- [ ] My forms use the canonical `_csrf_token` retrieved via `\OwnPay\Security\SecurityHelpers::csrfToken()`.
- [ ] I have performed a self-review of my code and run linting checks (`php -l`).
- [ ] I have commented my code, particularly in hard-to-understand areas.
- [ ] I have made corresponding changes to the documentation.
- [ ] New and existing unit tests pass locally with my changes.
- [ ] I have checked my code and corrected any misspellings.

## ⚖️ License
- [ ] I agree that my contributions will be licensed under the **AGPL-3.0 License**.
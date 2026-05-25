# Employee Profile Templates — Manual Test Report

## Automated suite (run locally)

```bash
php artisan test --compact \
  tests/Unit/EmployeeProfileTemplateResolverTest.php \
  tests/Feature/Organization/EmployeeProfileTemplateTest.php \
  tests/Feature/Organization/EmployeeEnsureTest.php \
  tests/Feature/Organization/EmployeeCreateProfileTest.php
```

## Manual checklist

| Scenario | Expected | Pass |
|----------|----------|------|
| Create employee without template | All tabs and personal fields visible | |
| Create with template hiding bank | Bank tab not in tab list | |
| Save contract with name only (no id yet) | Ensure creates DRAFT employee, contract saves | |
| Save with empty name on non-personal tab | Blocked with clear error | |
| Edit employee without template | Same as before (all tabs) | |
| Edit with template hiding fields | Hidden rows not shown in personal/header | |
| Template builder | Personal tab toggle disabled, always on | |
| `/onboarding/templates` | 404 | |
| Organization → Templates → Employee profile templates | CRUD works | |
| Import without template | Preview succeeds | |
| Import with template | Columns match template fields | |

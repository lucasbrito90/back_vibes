# Quality harness — back_vibes

Local validation before PR → `develop`:

```bash
composer test
composer lint:pint    # Pint dry-run; may fail until formatted
# composer format:pint  # apply fixes when intentional
```

**PHPStan / Larastan:** not installed — see central doc.

**Source of truth:** [ixora-infra/docs/quality-harness.md](../../ixora-infra/docs/quality-harness.md)

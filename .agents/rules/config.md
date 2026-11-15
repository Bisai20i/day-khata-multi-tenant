---
paths:
  - config/permissions.php
---

# Config

## Permission keys are permanent; owner-only list is fixed
Keys are `<resource>.<action>` with actions view, create, edit, delete, cancel, print, export, import, manage; they are stored in roles.permissions JSON, so renaming one needs a data migration. Owner-only keys are exactly roles.manage, backups.manage, fiscal_year.close_archive, ownership.transfer (never grantable to a role). A new module is OFF for existing tenants unless its release adds a data migration granting it. Formula (one place, EffectivePermissions): entitled module AND active AND (owner OR role grant, not owner-only).

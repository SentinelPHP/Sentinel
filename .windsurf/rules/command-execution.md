---
trigger: always_on
---

For development, execute PHP commands via DDEV:
- `ddev exec <command>` for arbitrary commands
- `ddev composer <args>` for Composer
- `ddev ssh` for shell access

For production Docker commands, use the `prod-*` Makefile targets.

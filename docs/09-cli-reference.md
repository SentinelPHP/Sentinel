# CLI Reference

All commands are Symfony console commands. Use DDEV in development.

Prefix commands with:

```bash
ddev exec php bin/console
```

## Token Commands

```bash
sentinel:token:create <name>
sentinel:token:update <identifier>
```

## Schema Commands

```bash
sentinel:schema:list
sentinel:schema:show <schema-id>
sentinel:schema:import <file>
sentinel:schema:export
sentinel:schema:promote <schema-id>
```

## Drift Commands

```bash
sentinel:drift:list
```

## DTO Commands

```bash
sentinel:dto:generate
sentinel:dto:export
sentinel:dto:list
sentinel:dto:show <dto-id>
sentinel:dto:diff <dto-id>
```

## Security And Maintenance Commands

```bash
sentinel:encryption:generate-key
sentinel:retention:purge
sentinel:user:create <email>
```

## Common Development Usage

```bash
# Run a command
ddev exec php bin/console sentinel:schema:list --limit=20

# Run tests
ddev exec bin/phpunit

# Run static analysis
ddev exec vendor/bin/phpstan analyse
```

For option-level details, run `--help` on each command.

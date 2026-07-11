# Schema Learning And Management

This chapter covers schema capture, inspection, promotion, import, and export.

## Learning Workflow

1. Create token in learning mode.
2. Send real traffic through Sentinel.
3. Inspect learned schemas.
4. Promote a schema to master.
5. Switch token to validating mode (manual or auto).

```bash
ddev exec php bin/console sentinel:token:create "My API" \
  --mode=learning \
  --targets=api.example.com \
  --learning-threshold=10 \
  --auto-switch
```

## Inspect Learned Schemas

```bash
ddev exec php bin/console sentinel:schema:list
ddev exec php bin/console sentinel:schema:list --token="My API" --master-only
ddev exec php bin/console sentinel:schema:show <schema-id>
ddev exec php bin/console sentinel:schema:show <schema-id> --diff=previous
```

## Promote Schema

```bash
ddev exec php bin/console sentinel:schema:promote <schema-id> --switch-mode
```

## Import Schema

```bash
ddev exec php bin/console sentinel:schema:import schema.json \
  --token="My API" \
  --host=https://api.example.com \
  --endpoint=/users/{id} \
  --method=GET \
  --type=response \
  --master
```

Important: the import command expects `--endpoint` (not `--path`).

## Export Schema

```bash
# Export by schema id
ddev exec php bin/console sentinel:schema:export --id=<schema-uuid> --format=json-schema --output=schema.json

# Export master schema by filters
ddev exec php bin/console sentinel:schema:export \
  --token="My API" \
  --host=https://api.example.com \
  --endpoint=/users/{id} \
  --method=GET \
  --type=response \
  --format=openapi
```

Important: export formats are `json-schema` and `openapi`.

## Related Chapters

- Drift handling: [05-drift-and-alerts.md](05-drift-and-alerts.md)
- DTO generation: [07-dto-generation.md](07-dto-generation.md)
- CLI details: [09-cli-reference.md](09-cli-reference.md)

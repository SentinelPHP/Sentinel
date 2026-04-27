Before reporting any task as complete, you MUST:

1. Run the full test suite:
   ```bash
   ddev exec bin/phpunit
   ```

2. Run PHPStan static analysis:
   ```bash
   ddev exec vendor/bin/phpstan analyse
   ```

3. Fix ALL errors, warnings, and notices from both tools before marking the task done.

4. If fixes introduce new issues, repeat the verification cycle until both commands pass cleanly.

Do not consider a task complete until both verification steps pass with zero issues.

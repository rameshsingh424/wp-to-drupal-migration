# Contributing

## Guidelines

1. Fork the repository.
2. Create a feature branch from `main`.
3. Follow Drupal coding standards.
4. Keep migration logic inside services.
5. Add comments for new field, taxonomy, or post type mappings.
6. Test migrations on cloned WordPress and Drupal databases.
7. Submit a pull request with a clear summary and test notes.

## Local Validation

```bash
php -l src/Commands/WordPressToDrupalMigrationCommands.php
php -l src/Service/WordPressMigrationService.php
drush cr
```

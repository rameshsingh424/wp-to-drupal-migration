# WordPress to Drupal Migration

## Overview

WordPress to Drupal Migration is a Drupal custom module that imports WordPress content into Drupal using Drupal entity APIs instead of direct SQL writes.

The module migrates:

- WordPress posts
- WordPress pages
- WordPress custom post types
- WordPress custom fields and ACF-style post meta
- WordPress categories, tags, and custom taxonomies
- WordPress featured images
- WordPress child attachments
- WordPress inline images and inline file links inside body HTML
- WordPress slugs into Drupal path aliases
- WordPress author ownership by matching author email to an existing Drupal user

## Requirements

- PHP 8.1 or higher
- Drupal 10 or Drupal 11
- Drush 12 or Drush 13
- Drupal modules: `node`, `file`, `image`, `taxonomy`, `path_alias`
- Source WordPress MySQL/MariaDB database access
- Local filesystem or HTTP access to `wp-content/uploads`

## Repository Structure

```text
wp-to-drupal-migration/
├── README.md
├── LICENSE
├── composer.json
├── .gitignore
├── wp_to_drupal_migration.info.yml
├── wp_to_drupal_migration.services.yml
├── drush.services.yml
├── config/
│   ├── install/
│   │   └── wp_to_drupal_migration.settings.yml
│   └── schema/
│       └── wp_to_drupal_migration.schema.yml
└── src/
    ├── Commands/
    │   └── WordPressToDrupalMigrationCommands.php
    └── Service/
        └── WordPressMigrationService.php
```

## Installation

### 1. Copy the module into Drupal

Place the module directory here:

```bash
web/modules/custom/wp_to_drupal_migration
```

### 2. Configure WordPress database connection

Add the WordPress database connection to `web/sites/default/settings.php`:

```php
$databases['wordpress']['default'] = [
  'database' => 'wordpress_database_name',
  'username' => 'wordpress_database_user',
  'password' => 'wordpress_database_password',
  'prefix' => '',
  'host' => '127.0.0.1',
  'port' => '3306',
  'namespace' => 'Drupal\\mysql\\Driver\\Database\\mysql',
  'driver' => 'mysql',
];
```

### 3. Configure module settings

Edit:

```text
web/modules/custom/wp_to_drupal_migration/config/install/wp_to_drupal_migration.settings.yml
```

Set the correct WordPress uploads URL and local path:

```yaml
wordpress_uploads_url: 'https://example.com/wp-content/uploads'
wordpress_uploads_path: '/var/www/wordpress/wp-content/uploads'
```

Map WordPress post types to Drupal content types:

```yaml
post_type_bundle_map:
  post: article
  page: page
  product: product
```

Map WordPress taxonomies to Drupal vocabularies:

```yaml
taxonomy_vocabulary_map:
  category: categories
  post_tag: tags
  product_cat: product_categories
```

Map WordPress taxonomies to Drupal entity reference fields:

```yaml
taxonomy_field_map:
  category: field_categories
  post_tag: field_tags
  product_cat: field_product_categories
```

Map WordPress custom fields/post meta to Drupal fields:

```yaml
custom_field_map:
  _yoast_wpseo_title: field_meta_title
  _yoast_wpseo_metadesc: field_meta_description
  subtitle: field_subtitle
  hero_title: field_hero_title
```

### 4. Create Drupal fields before migration

Create these fields on target Drupal content types as needed:

```text
field_wp_id           Integer
field_wp_raw_meta     Text formatted, long
field_image           Image
field_attachments     File
field_categories      Taxonomy term reference
field_tags            Taxonomy term reference
```

Create additional fields that are listed in `custom_field_map`.

### 5. Enable the module

```bash
drush en node file image taxonomy path_alias wp_to_drupal_migration -y
drush cr
```

## Usage Examples

### Run full migration

```bash
drush wp-to-drupal:migrate --update=1
```

### Run migration without pre-indexing all attachments

```bash
drush wp-to-drupal:migrate --update=1 --skip-attachments=1
```

### Run only WordPress posts

```bash
drush wp-to-drupal:migrate --post-type=post --update=1
```

### Run only WordPress pages

```bash
drush wp-to-drupal:migrate --post-type=page --update=1
```

### Run a custom post type

```bash
drush wp-to-drupal:migrate --post-type=product --update=1
```

### Run in batches

```bash
drush wp-to-drupal:migrate --post-type=post --limit=100 --offset=0 --update=1
drush wp-to-drupal:migrate --post-type=post --limit=100 --offset=100 --update=1
drush wp-to-drupal:migrate --post-type=post --limit=100 --offset=200 --update=1
```

### Reset migration tracking state

```bash
drush wp-to-drupal:reset-state
```

### Reset only migrated post tracking state

```bash
drush wp-to-drupal:reset-state --type=post
```

### Reset only migrated attachment tracking state

```bash
drush wp-to-drupal:reset-state --type=attachment
```

## Custom Post Types

Add custom post type mappings in `post_type_bundle_map`:

```yaml
post_type_bundle_map:
  portfolio: portfolio
  case_study: case_study
  product: product
```

Each mapped Drupal bundle must exist before running migration.

## Custom Taxonomies

Add custom taxonomy mappings in `taxonomy_vocabulary_map` and `taxonomy_field_map`:

```yaml
taxonomy_vocabulary_map:
  industry: industries
  location: locations

taxonomy_field_map:
  industry: field_industries
  location: field_locations
```

Each mapped Drupal vocabulary and entity reference field must exist before running migration.

## Custom Fields and ACF Fields

Add WordPress meta keys to `custom_field_map`:

```yaml
custom_field_map:
  hero_title: field_hero_title
  hero_image: field_hero_image
  gallery: field_gallery
  cta_link: field_cta_link
```

Supported Drupal field types include:

- `string`
- `string_long`
- `text`
- `text_long`
- `text_with_summary`
- `boolean`
- `integer`
- `decimal`
- `float`
- `link`
- `file`
- `image`

## Contribution Guidelines

1. Fork the repository.
2. Create a feature branch.
3. Follow Drupal coding standards.
4. Keep migration logic inside services where possible.
5. Add clear comments for new migration mappings.
6. Test on a copy of the Drupal database and WordPress database.
7. Submit a pull request with a clear description of the change.

## License

This project is licensed under the MIT License.

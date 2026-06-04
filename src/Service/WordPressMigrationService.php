<?php

namespace Drupal\wp_to_drupal_migration\Service;

use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\file\Entity\File;
use Drupal\file\FileRepositoryInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use GuzzleHttp\ClientInterface;

/**
 * Imports WordPress content into Drupal entities.
 */
final class WordPressMigrationService {

  /**
   * WordPress database connection.
   */
  private Connection $wpDb;

  /**
   * Module settings.
   */
  private array $settings;

  /**
   * Runtime cache for imported files.
   */
  private array $fileCache = [];

  /**
   * Runtime cache for imported taxonomy terms.
   */
  private array $termCache = [];

  /**
   * Constructs the migration service.
   */
  public function __construct(
    ConfigFactoryInterface $configFactory,
    private readonly Connection $drupalDb,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly FileSystemInterface $fileSystem,
    private readonly FileRepositoryInterface $fileRepository,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly StateInterface $state,
    private readonly ClientInterface $httpClient,
    private readonly LoggerChannelInterface $logger,
  ) {
    $this->settings = $configFactory
      ->get('wp_to_drupal_migration.settings')
      ->getRawData();

    $connectionKey = $this->settings['wordpress_connection_key'] ?? 'wordpress';
    $this->wpDb = Database::getConnection('default', $connectionKey);
  }

  /**
   * Migrates WordPress posts, pages, custom post types, files, and metadata.
   */
  public function migrate(array $options = []): array {
    $stats = [
      'created' => 0,
      'updated' => 0,
      'skipped' => 0,
      'failed' => 0,
      'attachments_indexed' => 0,
    ];

    if (empty($options['skip_attachments'])) {
      $stats['attachments_indexed'] = $this->migrateAllAttachments();
    }

    $posts = $this->fetchWordPressContentPosts(
      $options['post_type'] ?? NULL,
      $options['limit'] ?? NULL,
      (int) ($options['offset'] ?? 0),
    );

    foreach ($posts as $post) {
      try {
        $result = $this->migrateSinglePost($post, (bool) ($options['update'] ?? FALSE));
        $stats[$result]++;
      }
      catch (\Throwable $e) {
        $stats['failed']++;
        $this->logger->error('Failed to migrate WordPress post ID @id: @message', [
          '@id' => $post['ID'] ?? 'unknown',
          '@message' => $e->getMessage(),
        ]);
      }
    }

    return $stats;
  }

  /**
   * Clears migration tracking state keys.
   */
  public function resetState(string $type = 'all'): int {
    $prefixes = match ($type) {
      'post' => ['wp_to_drupal_migration.post.'],
      'attachment' => ['wp_to_drupal_migration.attachment.'],
      default => ['wp_to_drupal_migration.post.', 'wp_to_drupal_migration.attachment.'],
    };

    $deleted = 0;

    foreach ($prefixes as $prefix) {
      $keys = $this->drupalDb->select('key_value', 'kv')
        ->fields('kv', ['name'])
        ->condition('kv.collection', 'state')
        ->condition('kv.name', $prefix . '%', 'LIKE')
        ->execute()
        ->fetchCol();

      foreach ($keys as $key) {
        $this->state->delete($key);
        $deleted++;
      }
    }

    return $deleted;
  }

  /**
   * Imports all WordPress attachment posts into Drupal file entities.
   */
  private function migrateAllAttachments(): int {
    $prefix = $this->wpPrefix();

    $attachments = $this->wpDb->select($prefix . 'posts', 'p')
      ->fields('p')
      ->condition('p.post_type', 'attachment')
      ->orderBy('p.ID', 'ASC')
      ->execute()
      ->fetchAllAssoc('ID', \PDO::FETCH_ASSOC);

    $count = 0;

    foreach ($attachments as $attachment) {
      try {
        $file = $this->importWordPressAttachmentFile((int) $attachment['ID'], $attachment);

        if ($file) {
          $count++;
        }
      }
      catch (\Throwable $e) {
        $this->logger->warning('Attachment ID @id failed: @message', [
          '@id' => $attachment['ID'],
          '@message' => $e->getMessage(),
        ]);
      }
    }

    return $count;
  }

  /**
   * Fetches WordPress posts, pages, and custom post types.
   */
  private function fetchWordPressContentPosts(?string $postType = NULL, ?int $limit = NULL, int $offset = 0): array {
    $prefix = $this->wpPrefix();

    $excludedTypes = [
      'attachment',
      'revision',
      'nav_menu_item',
      'custom_css',
      'customize_changeset',
      'oembed_cache',
      'user_request',
      'wp_block',
      'wp_template',
      'wp_template_part',
      'wp_global_styles',
      'wp_navigation',
    ];

    $query = $this->wpDb->select($prefix . 'posts', 'p')
      ->fields('p')
      ->condition('p.post_type', $excludedTypes, 'NOT IN')
      ->condition('p.post_status', array_keys($this->settings['post_status_map'] ?? ['publish' => 1]), 'IN')
      ->orderBy('p.ID', 'ASC');

    if ($postType) {
      $query->condition('p.post_type', $postType);
    }

    if ($limit !== NULL) {
      $query->range($offset, $limit);
    }

    return $query->execute()->fetchAllAssoc('ID', \PDO::FETCH_ASSOC);
  }

  /**
   * Migrates one WordPress post, page, or custom post type into a Drupal node.
   */
  private function migrateSinglePost(array $post, bool $updateExisting): string {
    $wpPostId = (int) $post['ID'];
    $wpType = (string) $post['post_type'];
    $bundle = $this->mapWordPressPostTypeToDrupalBundle($wpType);

    if (!$this->drupalBundleExists($bundle)) {
      $this->logger->warning('Skipped WordPress post ID @id because Drupal bundle @bundle does not exist.', [
        '@id' => $wpPostId,
        '@bundle' => $bundle,
      ]);
      return 'skipped';
    }

    $existing = $this->loadExistingNodeForWordPressPost($wpPostId, $bundle);

    if ($existing && !$updateExisting) {
      return 'skipped';
    }

    $meta = $this->fetchPostMeta($wpPostId);
    $bodyHtml = (string) ($post['post_content'] ?? '');
    $bodyHtml = $this->replaceInlineWordPressFilesWithDrupalFiles($bodyHtml);

    $values = [
      'type' => $bundle,
      'title' => Html::decodeEntities((string) ($post['post_title'] ?: '(Untitled)')),
      'uid' => $this->resolveDrupalUidFromWordPressAuthor((int) ($post['post_author'] ?? 0)),
      'status' => $this->mapWordPressStatusToDrupalStatus((string) ($post['post_status'] ?? 'draft')),
      'created' => $this->convertWordPressDateToTimestamp($post['post_date_gmt'] ?: $post['post_date']),
      'changed' => $this->convertWordPressDateToTimestamp($post['post_modified_gmt'] ?: $post['post_modified']),
    ];

    $node = $existing ?: Node::create($values);

    foreach ($values as $fieldName => $value) {
      $node->set($fieldName, $value);
    }

    if ($node->hasField('body')) {
      $node->set('body', [
        'value' => $bodyHtml,
        'format' => $this->settings['default_body_format'] ?? 'full_html',
      ]);
    }

    $this->setTrackingFields($node, $wpPostId, $meta);
    $this->setFeaturedImageField($node, $meta);
    $this->setAttachmentField($node, $wpPostId);
    $this->setTaxonomyFields($node, $wpPostId);
    $this->setMappedCustomFields($node, $meta);

    $node->save();

    $this->state->set($this->postStateKey($wpPostId), (int) $node->id());
    $this->upsertPathAlias($node, $post);

    return $existing ? 'updated' : 'created';
  }

  /**
   * Adds WordPress ID and raw WordPress meta into tracking fields when configured.
   */
  private function setTrackingFields(NodeInterface $node, int $wpPostId, array $meta): void {
    $wpIdField = $this->settings['wp_id_field'] ?? 'field_wp_id';

    if ($node->hasField($wpIdField)) {
      $node->set($wpIdField, $wpPostId);
    }

    $rawMetaField = $this->settings['raw_meta_field'] ?? 'field_wp_raw_meta';

    if ($node->hasField($rawMetaField)) {
      $node->set($rawMetaField, [
        'value' => json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'format' => 'plain_text',
      ]);
    }
  }

  /**
   * Sets the Drupal image field from WordPress _thumbnail_id meta.
   */
  private function setFeaturedImageField(NodeInterface $node, array $meta): void {
    $field = $this->settings['featured_image_field'] ?? 'field_image';

    if (!$node->hasField($field)) {
      return;
    }

    $thumbnailId = $this->firstMetaValue($meta, '_thumbnail_id');

    if (!$thumbnailId || !is_numeric($thumbnailId)) {
      return;
    }

    $attachmentPost = $this->fetchWordPressPostById((int) $thumbnailId);

    if (!$attachmentPost) {
      return;
    }

    $file = $this->importWordPressAttachmentFile((int) $thumbnailId, $attachmentPost);

    if (!$file) {
      return;
    }

    $node->set($field, [
      [
        'target_id' => $file->id(),
        'alt' => $attachmentPost['post_title'] ?: $node->label(),
        'title' => $attachmentPost['post_title'] ?: '',
      ],
    ]);
  }

  /**
   * Sets the Drupal file field from WordPress child attachment posts.
   */
  private function setAttachmentField(NodeInterface $node, int $wpPostId): void {
    $field = $this->settings['attachment_file_field'] ?? 'field_attachments';

    if (!$node->hasField($field)) {
      return;
    }

    $attachments = $this->fetchAttachmentsForPost($wpPostId);
    $values = [];

    foreach ($attachments as $attachment) {
      $file = $this->importWordPressAttachmentFile((int) $attachment['ID'], $attachment);

      if ($file) {
        $values[] = [
          'target_id' => $file->id(),
          'description' => $attachment['post_title'] ?: $file->getFilename(),
        ];
      }
    }

    if ($values) {
      $node->set($field, $values);
    }
  }

  /**
   * Sets Drupal taxonomy reference fields from WordPress taxonomy terms.
   */
  private function setTaxonomyFields(NodeInterface $node, int $wpPostId): void {
    $terms = $this->fetchWordPressTermsForPost($wpPostId);

    if (!$terms) {
      return;
    }

    $grouped = [];

    foreach ($terms as $term) {
      $grouped[(string) $term['taxonomy']][] = $term;
    }

    foreach ($grouped as $taxonomy => $taxonomyTerms) {
      $field = $this->settings['taxonomy_field_map'][$taxonomy] ?? NULL;
      $vocabulary = $this->settings['taxonomy_vocabulary_map'][$taxonomy] ?? $taxonomy;

      if (!$field || !$node->hasField($field)) {
        continue;
      }

      $values = [];

      foreach ($taxonomyTerms as $wpTerm) {
        $termEntity = $this->ensureDrupalTaxonomyTerm(
          $vocabulary,
          (string) $wpTerm['name'],
          (string) ($wpTerm['slug'] ?? ''),
          (string) ($wpTerm['description'] ?? ''),
        );

        if ($termEntity) {
          $values[] = ['target_id' => $termEntity->id()];
        }
      }

      if ($values) {
        $node->set($field, $values);
      }
    }
  }

  /**
   * Sets mapped Drupal custom fields from WordPress post meta.
   */
  private function setMappedCustomFields(NodeInterface $node, array $meta): void {
    $map = $this->settings['custom_field_map'] ?? [];

    foreach ($map as $wpMetaKey => $drupalFieldName) {
      if (!$node->hasField($drupalFieldName) || empty($meta[$wpMetaKey])) {
        continue;
      }

      $definition = $node->getFieldDefinition($drupalFieldName);
      $fieldType = $definition->getType();
      $isMultiple = $definition->getFieldStorageDefinition()->isMultiple();
      $values = [];

      foreach ($meta[$wpMetaKey] as $rawValue) {
        $value = $this->maybeUnserialize($rawValue);

        foreach ($this->normalizeMetaValueList($value) as $singleValue) {
          $mapped = $this->mapMetaValueForDrupalField($fieldType, $singleValue);

          if ($mapped !== NULL) {
            $values[] = $mapped;
          }
        }
      }

      if ($values) {
        $node->set($drupalFieldName, $isMultiple ? $values : reset($values));
      }
    }
  }

  /**
   * Normalizes scalar, array, and nested WordPress meta into a flat list.
   */
  private function normalizeMetaValueList(mixed $value): array {
    if ($value === NULL || $value === '') {
      return [];
    }

    if (!is_array($value)) {
      return [$value];
    }

    $values = [];

    foreach ($value as $item) {
      if (is_array($item)) {
        $values[] = json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
      }
      else {
        $values[] = $item;
      }
    }

    return $values;
  }

  /**
   * Converts a WordPress meta value into a Drupal field item value.
   */
  private function mapMetaValueForDrupalField(string $fieldType, mixed $value): mixed {
    if (is_array($value)) {
      $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    $value = trim((string) $value);

    if ($value === '') {
      return NULL;
    }

    return match ($fieldType) {
      'boolean' => (bool) $value,
      'integer' => (int) $value,
      'decimal', 'float' => (float) $value,
      'string', 'string_long' => ['value' => $value],
      'text', 'text_long', 'text_with_summary' => [
        'value' => $value,
        'format' => $this->settings['default_body_format'] ?? 'full_html',
      ],
      'link' => [
        'uri' => preg_match('/^https?:\/\//i', $value) ? $value : 'internal:/' . ltrim($value, '/'),
        'title' => '',
      ],
      'image', 'file' => $this->mapMetaValueToFileField($value),
      default => $value,
    };
  }

  /**
   * Converts a WordPress attachment ID or URL into a Drupal file/image field item.
   */
  private function mapMetaValueToFileField(string $value): ?array {
    $file = NULL;

    if (is_numeric($value)) {
      $attachmentPost = $this->fetchWordPressPostById((int) $value);

      if ($attachmentPost) {
        $file = $this->importWordPressAttachmentFile((int) $value, $attachmentPost);
      }
    }
    elseif ($this->looksLikeWordPressUpload($value) || preg_match('/^https?:\/\//i', $value)) {
      $file = $this->importFileFromWordPressSource($value);
    }

    if (!$file) {
      return NULL;
    }

    return [
      'target_id' => $file->id(),
      'alt' => $file->getFilename(),
      'title' => $file->getFilename(),
    ];
  }

  /**
   * Replaces inline WordPress upload URLs in body HTML with Drupal file URLs.
   */
  private function replaceInlineWordPressFilesWithDrupalFiles(string $html): string {
    if (trim($html) === '') {
      return '';
    }

    $dom = Html::load('<div data-wp-inline-root="1">' . $html . '</div>');
    $xpath = new \DOMXPath($dom);

    foreach ($xpath->query('//*[@src]') as $element) {
      if (!$element instanceof \DOMElement) {
        continue;
      }

      $src = $element->getAttribute('src');

      if (!$this->looksLikeWordPressUpload($src)) {
        continue;
      }

      $file = $this->importFileFromWordPressSource($src);

      if ($file) {
        $element->setAttribute('src', $this->fileUrlGenerator->generateString($file->getFileUri()));
      }
    }

    foreach ($xpath->query('//*[@href]') as $element) {
      if (!$element instanceof \DOMElement) {
        continue;
      }

      $href = $element->getAttribute('href');

      if (!$this->looksLikeWordPressUpload($href)) {
        continue;
      }

      $file = $this->importFileFromWordPressSource($href);

      if ($file) {
        $element->setAttribute('href', $this->fileUrlGenerator->generateString($file->getFileUri()));
      }
    }

    $root = $xpath->query('//*[@data-wp-inline-root="1"]')->item(0);

    return $root ? $this->innerHtml($root) : $html;
  }

  /**
   * Imports a WordPress attachment post as a Drupal managed file.
   */
  private function importWordPressAttachmentFile(int $attachmentId, array $attachmentPost): ?File {
    $existingFid = $this->state->get($this->attachmentStateKey($attachmentId));

    if ($existingFid) {
      $existing = File::load((int) $existingFid);

      if ($existing) {
        return $existing;
      }
    }

    $meta = $this->fetchPostMeta($attachmentId);
    $attachedFile = $this->firstMetaValue($meta, '_wp_attached_file');
    $source = $attachedFile ?: ($attachmentPost['guid'] ?? '');

    if (!$source) {
      return NULL;
    }

    $file = $this->importFileFromWordPressSource((string) $source);

    if ($file) {
      $this->state->set($this->attachmentStateKey($attachmentId), (int) $file->id());
    }

    return $file;
  }

  /**
   * Imports a file from a WordPress URL, relative uploads path, or local path.
   */
  private function importFileFromWordPressSource(string $source): ?File {
    $source = trim(Html::decodeEntities($source));

    if ($source === '') {
      return NULL;
    }

    $cacheKey = sha1($source);

    if (isset($this->fileCache[$cacheKey])) {
      return $this->fileCache[$cacheKey];
    }

    $localPath = $this->resolveWordPressLocalFilePath($source);
    $sourceUrl = $this->resolveWordPressSourceUrl($source);
    $relativePath = $this->extractUploadsRelativePath($source);
    $binary = NULL;

    if ($localPath && is_file($localPath)) {
      $binary = file_get_contents($localPath);
    }
    elseif ($sourceUrl) {
      try {
        $response = $this->httpClient->request('GET', $sourceUrl, [
          'timeout' => 30,
          'http_errors' => FALSE,
          'headers' => [
            'User-Agent' => 'Drupal WordPress Migration Bot',
          ],
        ]);

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
          $binary = (string) $response->getBody();
        }
      }
      catch (\Throwable $e) {
        $this->logger->warning('Download failed for @source: @message', [
          '@source' => $source,
          '@message' => $e->getMessage(),
        ]);
      }
    }

    if ($binary === NULL || $binary === '') {
      return NULL;
    }

    $filename = $this->sanitizeFilename(basename((string) (parse_url($source, PHP_URL_PATH) ?: $source)));

    if ($filename === '') {
      return NULL;
    }

    $directory = $this->buildDrupalTargetDirectory($relativePath);
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    $destination = $directory . '/' . $filename;
    $existing = $this->loadFileByUri($destination);

    if ($existing) {
      $this->fileCache[$cacheKey] = $existing;
      return $existing;
    }

    $replaceMode = class_exists(FileExists::class)
      ? FileExists::Rename
      : FileSystemInterface::EXISTS_RENAME;

    $file = $this->fileRepository->writeData($binary, $destination, $replaceMode);

    if (!$file instanceof File) {
      return NULL;
    }

    $file->setPermanent();
    $file->save();

    $this->fileCache[$cacheKey] = $file;

    return $file;
  }

  /**
   * Resolves a WordPress file source into a local file path when possible.
   */
  private function resolveWordPressLocalFilePath(string $source): ?string {
    $uploadsPath = rtrim((string) ($this->settings['wordpress_uploads_path'] ?? ''), '/');

    if ($uploadsPath === '') {
      return NULL;
    }

    if (is_file($source)) {
      return $source;
    }

    $relative = $this->extractUploadsRelativePath($source);

    if ($relative) {
      return $uploadsPath . '/' . ltrim($relative, '/');
    }

    return NULL;
  }

  /**
   * Resolves a WordPress file source into an absolute URL when possible.
   */
  private function resolveWordPressSourceUrl(string $source): ?string {
    $uploadsUrl = rtrim((string) ($this->settings['wordpress_uploads_url'] ?? ''), '/');

    if (preg_match('/^\/\//', $source)) {
      return 'https:' . $source;
    }

    if (preg_match('/^https?:\/\//i', $source)) {
      return $source;
    }

    $relative = $this->extractUploadsRelativePath($source);

    if ($uploadsUrl && $relative) {
      return $uploadsUrl . '/' . ltrim($relative, '/');
    }

    return NULL;
  }

  /**
   * Extracts a relative path below wp-content/uploads.
   */
  private function extractUploadsRelativePath(string $source): string {
    $uploadsUrl = rtrim((string) ($this->settings['wordpress_uploads_url'] ?? ''), '/');

    if ($uploadsUrl && str_starts_with($source, $uploadsUrl)) {
      return ltrim(substr($source, strlen($uploadsUrl)), '/');
    }

    $path = (string) (parse_url($source, PHP_URL_PATH) ?: $source);
    $needle = '/wp-content/uploads/';

    if (str_contains($path, $needle)) {
      return ltrim(substr($path, strpos($path, $needle) + strlen($needle)), '/');
    }

    if (!preg_match('/^https?:\/\//i', $source) && !str_starts_with($source, '/')) {
      return ltrim($source, '/');
    }

    return '';
  }

  /**
   * Builds the Drupal target file directory while preserving year/month folders.
   */
  private function buildDrupalTargetDirectory(string $relativePath): string {
    $base = rtrim((string) ($this->settings['target_file_directory'] ?? 'public://wp-migration'), '/');
    $dir = trim(dirname($relativePath), '.\\/');

    if ($dir !== '') {
      return $base . '/' . $dir;
    }

    return $base;
  }

  /**
   * Fetches WordPress post meta as meta_key => [values].
   */
  private function fetchPostMeta(int $postId): array {
    $prefix = $this->wpPrefix();

    $rows = $this->wpDb->select($prefix . 'postmeta', 'pm')
      ->fields('pm', ['meta_key', 'meta_value'])
      ->condition('pm.post_id', $postId)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    $meta = [];

    foreach ($rows as $row) {
      $meta[(string) $row['meta_key']][] = $row['meta_value'];
    }

    return $meta;
  }

  /**
   * Fetches a single WordPress post row by ID.
   */
  private function fetchWordPressPostById(int $postId): ?array {
    $prefix = $this->wpPrefix();

    $post = $this->wpDb->select($prefix . 'posts', 'p')
      ->fields('p')
      ->condition('p.ID', $postId)
      ->execute()
      ->fetchAssoc();

    return $post ?: NULL;
  }

  /**
   * Fetches child WordPress attachment posts for a WordPress content post.
   */
  private function fetchAttachmentsForPost(int $postId): array {
    $prefix = $this->wpPrefix();

    return $this->wpDb->select($prefix . 'posts', 'p')
      ->fields('p')
      ->condition('p.post_parent', $postId)
      ->condition('p.post_type', 'attachment')
      ->orderBy('p.menu_order', 'ASC')
      ->orderBy('p.ID', 'ASC')
      ->execute()
      ->fetchAllAssoc('ID', \PDO::FETCH_ASSOC);
  }

  /**
   * Fetches WordPress taxonomy terms assigned to a post.
   */
  private function fetchWordPressTermsForPost(int $postId): array {
    $prefix = $this->wpPrefix();

    $query = $this->wpDb->select($prefix . 'term_relationships', 'tr');
    $query->join($prefix . 'term_taxonomy', 'tt', 'tt.term_taxonomy_id = tr.term_taxonomy_id');
    $query->join($prefix . 'terms', 't', 't.term_id = tt.term_id');

    $query
      ->fields('t', ['term_id', 'name', 'slug'])
      ->fields('tt', ['taxonomy', 'description'])
      ->condition('tr.object_id', $postId)
      ->orderBy('t.name', 'ASC');

    return $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Creates or loads a Drupal taxonomy term.
   */
  private function ensureDrupalTaxonomyTerm(string $vid, string $name, string $slug = '', string $description = ''): ?Term {
    $cacheKey = $vid . ':' . mb_strtolower($name);

    if (isset($this->termCache[$cacheKey])) {
      return $this->termCache[$cacheKey];
    }

    $vocabulary = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->load($vid);

    if (!$vocabulary) {
      $this->logger->warning('Vocabulary @vid does not exist. Term @term skipped.', [
        '@vid' => $vid,
        '@term' => $name,
      ]);
      return NULL;
    }

    $existing = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties([
      'vid' => $vid,
      'name' => $name,
    ]);

    if ($existing) {
      $term = reset($existing);
      $this->termCache[$cacheKey] = $term;
      return $term;
    }

    $term = Term::create([
      'vid' => $vid,
      'name' => $name,
      'description' => [
        'value' => $description,
        'format' => 'basic_html',
      ],
    ]);

    if ($slug && $term->hasField('field_wp_slug')) {
      $term->set('field_wp_slug', $slug);
    }

    $term->save();
    $this->termCache[$cacheKey] = $term;

    return $term;
  }

  /**
   * Resolves Drupal UID by matching WordPress author email to a Drupal user.
   */
  private function resolveDrupalUidFromWordPressAuthor(int $wpUserId): int {
    $defaultUid = (int) ($this->settings['default_drupal_uid'] ?? 1);

    if (!$wpUserId) {
      return $defaultUid;
    }

    $prefix = $this->wpPrefix();

    $wpUser = $this->wpDb->select($prefix . 'users', 'u')
      ->fields('u', ['user_email'])
      ->condition('u.ID', $wpUserId)
      ->execute()
      ->fetchAssoc();

    if (!$wpUser || empty($wpUser['user_email'])) {
      return $defaultUid;
    }

    $users = $this->entityTypeManager->getStorage('user')->loadByProperties([
      'mail' => $wpUser['user_email'],
    ]);

    if ($users) {
      $user = reset($users);
      return (int) $user->id();
    }

    return $defaultUid;
  }

  /**
   * Loads an existing Drupal node created from the same WordPress post ID.
   */
  private function loadExistingNodeForWordPressPost(int $wpPostId, string $bundle): ?NodeInterface {
    $stateNid = $this->state->get($this->postStateKey($wpPostId));

    if ($stateNid) {
      $node = $this->entityTypeManager->getStorage('node')->load((int) $stateNid);

      if ($node instanceof NodeInterface) {
        return $node;
      }
    }

    $wpIdField = $this->settings['wp_id_field'] ?? 'field_wp_id';

    if (!$this->bundleHasField($bundle, $wpIdField)) {
      return NULL;
    }

    $nids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $bundle)
      ->condition($wpIdField, $wpPostId)
      ->range(0, 1)
      ->execute();

    if (!$nids) {
      return NULL;
    }

    $node = $this->entityTypeManager->getStorage('node')->load((int) reset($nids));

    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * Creates or replaces a Drupal path alias for the migrated node.
   */
  private function upsertPathAlias(NodeInterface $node, array $post): void {
    $alias = $this->buildDrupalAliasFromWordPressPost($post);

    if (!$alias) {
      return;
    }

    $path = '/node/' . $node->id();
    $storage = $this->entityTypeManager->getStorage('path_alias');

    $existing = $storage->loadByProperties(['path' => $path]);

    foreach ($existing as $aliasEntity) {
      $aliasEntity->delete();
    }

    $storage->create([
      'path' => $path,
      'alias' => $alias,
      'langcode' => $node->language()->getId(),
    ])->save();
  }

  /**
   * Builds a Drupal path alias from WordPress post type and post slug.
   */
  private function buildDrupalAliasFromWordPressPost(array $post): string {
    $wpType = (string) ($post['post_type'] ?? 'post');
    $slug = (string) ($post['post_name'] ?? '');

    if ($slug === '') {
      $slug = Html::getClass((string) ($post['post_title'] ?? 'node'));
    }

    if ($wpType === 'page') {
      $segments = $this->buildWordPressPageParentSlugs((int) ($post['post_parent'] ?? 0));
      $segments[] = $slug;
      return '/' . implode('/', array_filter($segments));
    }

    $prefixMap = $this->settings['post_alias_prefix_map'] ?? [];
    $prefix = trim((string) ($prefixMap[$wpType] ?? '/' . $wpType), '/');

    return '/' . trim($prefix . '/' . $slug, '/');
  }

  /**
   * Builds parent slugs for hierarchical WordPress pages.
   */
  private function buildWordPressPageParentSlugs(int $parentId): array {
    if (!$parentId) {
      return [];
    }

    $segments = [];
    $guard = 0;

    while ($parentId && $guard < 20) {
      $guard++;
      $parent = $this->fetchWordPressPostById($parentId);

      if (!$parent) {
        break;
      }

      array_unshift($segments, (string) ($parent['post_name'] ?: Html::getClass($parent['post_title'] ?? 'page')));
      $parentId = (int) ($parent['post_parent'] ?? 0);
    }

    return $segments;
  }

  /**
   * Maps WordPress status to Drupal node status.
   */
  private function mapWordPressStatusToDrupalStatus(string $status): int {
    $map = $this->settings['post_status_map'] ?? ['publish' => 1];

    return (int) ($map[$status] ?? 0);
  }

  /**
   * Maps WordPress post type to Drupal node bundle.
   */
  private function mapWordPressPostTypeToDrupalBundle(string $postType): string {
    $map = $this->settings['post_type_bundle_map'] ?? [];

    return (string) ($map[$postType] ?? $postType);
  }

  /**
   * Checks whether the target Drupal node bundle exists.
   */
  private function drupalBundleExists(string $bundle): bool {
    return (bool) $this->entityTypeManager->getStorage('node_type')->load($bundle);
  }

  /**
   * Checks whether a field exists on a Drupal node bundle.
   */
  private function bundleHasField(string $bundle, string $fieldName): bool {
    $definitions = $this->entityFieldManager->getFieldDefinitions('node', $bundle);

    return isset($definitions[$fieldName]);
  }

  /**
   * Loads a Drupal file entity by file URI.
   */
  private function loadFileByUri(string $uri): ?File {
    $files = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $uri]);

    if (!$files) {
      return NULL;
    }

    $file = reset($files);

    return $file instanceof File ? $file : NULL;
  }

  /**
   * Converts a WordPress date string to a Unix timestamp.
   */
  private function convertWordPressDateToTimestamp(?string $date): int {
    if (!$date || str_starts_with($date, '0000-00-00')) {
      return time();
    }

    $timestamp = strtotime($date . ' UTC');

    return $timestamp ?: time();
  }

  /**
   * Returns the first WordPress meta value for a meta key.
   */
  private function firstMetaValue(array $meta, string $key): mixed {
    return $meta[$key][0] ?? NULL;
  }

  /**
   * Unserializes WordPress serialized meta values when needed.
   */
  private function maybeUnserialize(mixed $value): mixed {
    if (!is_string($value)) {
      return $value;
    }

    $trimmed = trim($value);

    if ($trimmed === '') {
      return $trimmed;
    }

    if ($trimmed === 'b:0;' || preg_match('/^[aOsibd]:/', $trimmed)) {
      $unserialized = @unserialize($trimmed, ['allowed_classes' => FALSE]);

      if ($unserialized !== FALSE || $trimmed === 'b:0;') {
        return $unserialized;
      }
    }

    $json = json_decode($trimmed, TRUE);

    if (json_last_error() === JSON_ERROR_NONE) {
      return $json;
    }

    return $value;
  }

  /**
   * Checks whether a URL or path looks like a WordPress uploaded file.
   */
  private function looksLikeWordPressUpload(string $source): bool {
    $uploadsUrl = (string) ($this->settings['wordpress_uploads_url'] ?? '');

    return str_contains($source, '/wp-content/uploads/') || ($uploadsUrl && str_starts_with($source, $uploadsUrl));
  }

  /**
   * Sanitizes imported filenames.
   */
  private function sanitizeFilename(string $filename): string {
    $filename = rawurldecode($filename);
    $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename);
    $filename = trim((string) $filename, '.-');

    return $filename;
  }

  /**
   * Returns inner HTML for a DOM node.
   */
  private function innerHtml(\DOMNode $node): string {
    $html = '';

    foreach ($node->childNodes as $child) {
      $html .= $node->ownerDocument?->saveHTML($child) ?: '';
    }

    return $html;
  }

  /**
   * Returns WordPress database table prefix.
   */
  private function wpPrefix(): string {
    return (string) ($this->settings['wordpress_table_prefix'] ?? 'wp_');
  }

  /**
   * State key for migrated WordPress posts.
   */
  private function postStateKey(int $wpPostId): string {
    return 'wp_to_drupal_migration.post.' . $wpPostId;
  }

  /**
   * State key for migrated WordPress attachments.
   */
  private function attachmentStateKey(int $wpAttachmentId): string {
    return 'wp_to_drupal_migration.attachment.' . $wpAttachmentId;
  }

}

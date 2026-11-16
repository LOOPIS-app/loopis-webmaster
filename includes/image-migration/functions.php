<?php
/**
 * Scan and process image migrations for the current site.
 *
 * Maintains the queue and activity log, converts supported images to WebP,
 * quarantines orphaned files, and handles scan and processing requests.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Return the queue table name for the current site. */
function loopis_image_migration_tables() {
    global $wpdb;

    return array(
        'items' => $wpdb->prefix . 'loopis_image_migration',
    );
}

/** Create this site's migration queue table when its schema is not installed. */
function loopis_image_migration_install() {
    if (get_option('loopis_image_migration_db_version') === '1') {
        return;
    }

    global $wpdb;
    $tables = loopis_image_migration_tables();
    $charset_collate = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    dbDelta("CREATE TABLE {$tables['items']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        item_type varchar(20) NOT NULL,
        attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
        source_path text NOT NULL,
        target_path text NOT NULL,
        payload longtext NOT NULL,
        status varchar(24) NOT NULL DEFAULT 'pending',
        message text NOT NULL,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY status (status),
        KEY attachment_id (attachment_id)
    ) $charset_collate;");

    update_option('loopis_image_migration_db_version', '1', false);
}
add_action('admin_init', 'loopis_image_migration_install');

/** Save a timestamped activity entry for the current site's migration. */
function loopis_image_migration_log($item_id, $status, $message) {
    $entry = array(
        'item_id'    => (int) $item_id,
        'status'     => sanitize_key($status),
        'message'    => sanitize_textarea_field($message),
        'created_at' => current_time('mysql', true),
    );
    $log_file = loopis_image_migration_log_file();
    $line = wp_json_encode($entry);
    $written = !is_wp_error($log_file) && false !== $line
        ? file_put_contents($log_file, $line . "\n", FILE_APPEND | LOCK_EX)
        : false;
    if (is_wp_error($log_file) || false === $line || $written !== strlen($line . "\n")) {
        $error = is_wp_error($log_file) ? $log_file->get_error_message() : 'Could not append to the activity log file.';
        error_log('LOOPIS Image Migration: ' . $error);
        set_transient('loopis_image_migration_log_error', $error, DAY_IN_SECONDS);
        return new WP_Error('log_write_failed', $error);
    }

    return true;
}

/** Prepare a site-specific log file outside the known web root. */
function loopis_image_migration_log_file() {
    $wordpress_root = realpath(ABSPATH);
    $web_root = !empty($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : $wordpress_root;
    if (!$wordpress_root || !$web_root) {
        return new WP_Error('log_root_unavailable', 'Cannot verify the web root for the activity log.');
    }
    $root = defined('LOOPIS_IMAGE_MIGRATION_LOG_DIR')
        ? LOOPIS_IMAGE_MIGRATION_LOG_DIR
        : dirname($wordpress_root) . '/loopis-image-migration-logs';
    if (!is_dir($root)) {
        if (!wp_mkdir_p($root) || !chmod($root, 0700)) {
            return new WP_Error('log_directory_failed', 'Cannot create a private activity log directory: ' . $root);
        }
    }
    $real_root = realpath($root);
    if (!$real_root || is_link($root) || $real_root === $web_root || loopis_image_migration_path_is_inside($real_root, $web_root) || $real_root === $wordpress_root || loopis_image_migration_path_is_inside($real_root, $wordpress_root)) {
        return new WP_Error('unsafe_log_directory', 'The activity log directory must be outside the web root and must not be a symlink.');
    }
    $directory_permissions = fileperms($real_root);
    if (false === $directory_permissions || ($directory_permissions & 0077)) {
        return new WP_Error('unsafe_log_permissions', 'The activity log directory must not be accessible to group or other users: ' . $real_root);
    }
    $file = trailingslashit($real_root) . 'site-' . get_current_blog_id() . '.jsonl';
    if (is_link($file)) {
        return new WP_Error('unsafe_log_file', 'The activity log file must not be a symlink.');
    }
    if (!file_exists($file)) {
        $handle = @fopen($file, 'x');
        if (!$handle) {
            return new WP_Error('log_file_create_failed', 'Cannot create the activity log file: ' . $file);
        }
        fclose($handle);
        if (!chmod($file, 0600)) {
            return new WP_Error('log_file_permissions_failed', 'Cannot set private permissions on the activity log file: ' . $file);
        }
    }
    if (!is_file($file) || !is_writable($file)) {
        return new WP_Error('log_file_unwritable', 'The activity log file is not writable: ' . $file);
    }
    $file_permissions = fileperms($file);
    if (false === $file_permissions || ($file_permissions & 0077)) {
        return new WP_Error('unsafe_log_permissions', 'The activity log file must not be accessible to group or other users: ' . $file);
    }
    return $file;
}

/** Read the most recent activity entries without loading the entire log. */
function loopis_image_migration_recent_activity($log_file, $limit = 50) {
    $handle = @fopen($log_file, 'rb');
    if (!$handle) {
        return new WP_Error('log_read_failed', 'Cannot open the activity log file for reading.');
    }
    if (!flock($handle, LOCK_SH)) {
        fclose($handle);
        return new WP_Error('log_read_failed', 'Cannot lock the activity log file for reading.');
    }

    $error = false;
    $buffer = '';
    if (fseek($handle, 0, SEEK_END) !== 0 || false === ($position = ftell($handle))) {
        $error = true;
    } else {
        while ($position > 0 && substr_count($buffer, "\n") <= $limit) {
            $size = min(8192, $position);
            $position -= $size;
            if (fseek($handle, $position) !== 0 || false === ($chunk = fread($handle, $size)) || strlen($chunk) !== $size) {
                $error = true;
                break;
            }
            $buffer = $chunk . $buffer;
        }
    }
    flock($handle, LOCK_UN);
    fclose($handle);
    if ($error) {
        return new WP_Error('log_read_failed', 'Cannot read the activity log file.');
    }

    $lines = explode("\n", $buffer);
    if (end($lines) === '') {
        array_pop($lines);
    }
    if ($position > 0) {
        array_shift($lines);
    }
    $entries = array();
    foreach (array_reverse(array_slice($lines, -$limit)) as $line) {
        $entry = json_decode($line);
        if (!is_object($entry) || !isset($entry->item_id, $entry->status, $entry->message, $entry->created_at)
            || !is_int($entry->item_id) || !is_string($entry->status) || !is_string($entry->message) || !is_string($entry->created_at)) {
            return new WP_Error('log_invalid_entry', 'The activity log contains an invalid entry; inspect the log file.');
        }
        $entries[] = $entry;
    }
    return $entries;
}

/** Normalize path separators for consistent filesystem comparisons. */
function loopis_image_migration_normalize_path($path) {
    return wp_normalize_path((string) $path);
}

/** Check that a path resolves within the site's uploads directory. */
function loopis_image_migration_path_is_inside($path, $base_dir) {
    $base_real = realpath($base_dir);
    $path_real = realpath($path);
    if (!$path_real) {
        $parent_real = realpath(dirname($path));
        if ($parent_real) {
            $path = trailingslashit($parent_real) . wp_basename($path);
        }
    } else {
        $path = $path_real;
    }

    $path = loopis_image_migration_normalize_path($path);
    $base_dir = trailingslashit(loopis_image_migration_normalize_path($base_real ?: $base_dir));

    return 0 === strpos($path, $base_dir);
}

/** Exclude subsite uploads and directories owned by other tools or this migration. */
function loopis_image_migration_path_is_in_scope($path, $base_dir) {
    $base_real = realpath($base_dir);
    $path_real = realpath($path);
    if (!$base_real || !$path_real || !loopis_image_migration_path_is_inside($path_real, $base_real)) {
        return false;
    }

    $relative = substr(loopis_image_migration_normalize_path($path_real), strlen(trailingslashit(loopis_image_migration_normalize_path($base_real))));
    $top_level = explode('/', $relative, 2)[0];
    return !in_array($top_level, array('sites', 'wp-statistics', 'migration', '.loopis-image-migration-staging'), true);
}

/** Traverse the current site's uploads while excluding protected trees and symlinks. */
function loopis_image_migration_upload_iterator($base_dir, $mode) {
    $directory = new RecursiveDirectoryIterator($base_dir, FilesystemIterator::SKIP_DOTS);
    $filtered = new RecursiveCallbackFilterIterator($directory, static function($entry) use ($base_dir) {
        return !$entry->isLink() && loopis_image_migration_path_is_in_scope($entry->getPathname(), $base_dir);
    });

    return new RecursiveIteratorIterator($filtered, $mode);
}

/** Report whether the migration supports converting this image MIME type. */
function loopis_image_migration_supported_mime($mime_type) {
    return in_array(
        $mime_type,
        array('image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'),
        true
    );
}

/** Collect the original image, WordPress sizes, and any pre-scaled original. */
function loopis_image_migration_attachment_files($attachment_id, $source_file = '') {
    $files = array();
    $source_file = $source_file ?: get_attached_file($attachment_id);

    if ($source_file) {
        $files[] = $source_file;
    }

    $metadata = wp_get_attachment_metadata($attachment_id);
    if ($source_file) {
        if (!empty($metadata['original_image'])) {
            $files[] = trailingslashit(dirname($source_file)) . wp_basename($metadata['original_image']);
        }
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size) {
                if (!empty($size['file'])) {
                    $files[] = trailingslashit(dirname($source_file)) . wp_basename($size['file']);
                }
            }
        }
    }

    return array_values(array_unique(array_filter($files, 'is_file')));
}

/** Map this post's featured and secondary gift images to their filename slots. */
function loopis_image_migration_slot_map($post_id) {
    static $cache = array();

    if (isset($cache[$post_id])) {
        return $cache[$post_id];
    }

    $map = array();
    $thumbnail_id = (int) get_post_thumbnail_id($post_id);
    $image_2 = (int) get_post_meta($post_id, 'image_2', true);
    $image_3 = (int) get_post_meta($post_id, 'image_3', true);

    if ($thumbnail_id > 0) {
        $map[$thumbnail_id] = 1;
    }
    if ($image_2 > 0 && !isset($map[$image_2])) {
        $map[$image_2] = 2;
    }
    if ($image_3 > 0 && !isset($map[$image_3])) {
        $map[$image_3] = 3;
    }

    $cache[$post_id] = $map;
    return $map;
}

/** Build a WebP filename, avoiding both existing files and planned targets. */
function loopis_image_migration_target_path($attachment_id, $post_id, $source_file, &$reserved_paths = null) {
    $post = get_post($post_id);
    if (!$post) {
        return new WP_Error('missing_parent', 'The attachment parent post no longer exists.');
    }

    $slots = 'post' === $post->post_type ? loopis_image_migration_slot_map($post_id) : array();
    if ('post' !== $post->post_type) {
        $title = sanitize_title($post->post_title);
        $filename = ($title ?: 'post-' . $post_id) . '.webp';
    } elseif (isset($slots[$attachment_id])) {
        $filename = 'p' . $post_id . '-' . $slots[$attachment_id] . '.webp';
    } else {
        $title = sanitize_title($post->post_title);
        $filename = ($title ?: 'post-' . $post_id) . '-' . $attachment_id . '.webp';
    }

    $directory = dirname($source_file);
    $base_name = pathinfo($filename, PATHINFO_FILENAME);
    $suffix = 'post' === $post->post_type ? '(?:-[1-9][0-9]*)?' : '(?:-(?:[2-9]|[1-9][0-9]+))?';
    $same_file = (bool) preg_match('/^' . preg_quote($base_name, '/') . $suffix . '\.webp$/', wp_basename($source_file));
    $directory_key = loopis_image_migration_normalize_path(realpath($directory) ?: $directory);
    if ($same_file && !isset($reserved_paths[$directory_key . '/' . wp_basename($source_file)])) {
        $candidate = wp_basename($source_file);
    } elseif ('post' !== $post->post_type) {
        $candidate = $filename;
        $number = 2;
        while (
            isset($reserved_paths[$directory_key . '/' . $candidate])
            || wp_unique_filename($directory, $candidate) !== $candidate
        ) {
            $candidate = $base_name . '-' . $number++ . '.webp';
        }
    } else {
        $candidate = $same_file ? $filename : wp_unique_filename($directory, $filename);
        $number = 1;
        while (isset($reserved_paths[$directory_key . '/' . $candidate])) {
            $candidate = wp_unique_filename($directory, $base_name . '-' . $number++ . '.webp');
        }
    }

    $reserved_paths[$directory_key . '/' . $candidate] = true;
    return trailingslashit($directory) . $candidate;
}

/** Check the actual image format and dimensions before skipping a matching WebP. */
function loopis_image_migration_webp_is_within_limits($source, $mime_type, $max_width, $max_height) {
    if ('image/webp' !== $mime_type || $max_width < 1 || $max_height < 1) {
        return false;
    }

    $dimensions = @getimagesize($source);
    return is_array($dimensions)
        && isset($dimensions['mime'], $dimensions[0], $dimensions[1])
        && 'image/webp' === $dimensions['mime']
        && $dimensions[0] > 0 && $dimensions[0] <= $max_width
        && $dimensions[1] > 0 && $dimensions[1] <= $max_height;
}

/** Add one planned conversion or quarantine action to the site's queue. */
function loopis_image_migration_insert_item($item_type, $attachment_id, $source_path, $target_path, $payload, $status = 'pending', $message = '') {
    global $wpdb;
    $tables = loopis_image_migration_tables();

    $result = $wpdb->insert(
        $tables['items'],
        array(
            'item_type'     => sanitize_key($item_type),
            'attachment_id' => (int) $attachment_id,
            'source_path'   => (string) $source_path,
            'target_path'   => (string) $target_path,
            'payload'       => wp_json_encode($payload),
            'status'        => sanitize_key($status),
            'message'       => sanitize_textarea_field($message),
            'created_at'    => current_time('mysql', true),
            'updated_at'    => current_time('mysql', true),
        ),
        array('%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
    );

    if (false === $result) {
        error_log('LOOPIS Image Migration: could not add a scan item: ' . $wpdb->last_error);
        $GLOBALS['loopis_image_migration_scan_write_failed'] = true;
        return 0;
    }

    return (int) $wpdb->insert_id;
}

/** Inventory attachments and unregistered image files without changing media. */
function loopis_image_migration_scan() {
    $lock = loopis_image_migration_acquire_lock();
    if (is_wp_error($lock)) {
        loopis_image_migration_log(0, 'warning', $lock->get_error_message());
        return;
    }

    try {
        loopis_image_migration_scan_locked();
    } finally {
        loopis_image_migration_release_lock($lock);
    }
}

/** Build the queue while holding the current site's migration lock. */
function loopis_image_migration_scan_locked() {
    global $wpdb;
    $tables = loopis_image_migration_tables();
    $processing = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['items']} WHERE status = 'processing'");
    if ($processing > 0) {
        loopis_image_migration_log(0, 'warning', 'Scan was not started because a migration item is still processing. Refresh and retry after it finishes.');
        return;
    }

    $GLOBALS['loopis_image_migration_scan_write_failed'] = false;
    if (false === $wpdb->query("UPDATE {$tables['items']} SET status = 'superseded'")) {
        error_log('LOOPIS Image Migration: could not archive the previous scan queue: ' . $wpdb->last_error);
        loopis_image_migration_log(0, 'failed', 'Could not archive the previous scan queue; no new scan was started.');
        return;
    }

    $upload = wp_upload_dir();
    if (!empty($upload['error']) || empty($upload['basedir']) || !is_dir($upload['basedir'])) {
        loopis_image_migration_log(0, 'failed', 'Could not access this site’s uploads directory.');
        return;
    }

    $base_dir = trailingslashit(realpath($upload['basedir']));
    $known_files = array();
    $reserved_paths = array();
    $page = 1;
    $per_page = 200;
    $attachment_count = 0;
    $quarantine_count = 0;
    $skipped_count = 0;
    $already_matching_count = 0;
    $orphan_count = 0;
    $empty_folder_count = 0;
    $max_width = absint(get_option('large_size_w'));
    $max_height = absint(get_option('large_size_h'));
    $webp_supported = wp_image_editor_supports(array('mime_type' => 'image/webp'));

    do {
        $attachments = get_posts(array(
            'post_type'      => 'attachment',
            'post_status'    => 'any',
            'post_mime_type' => 'image',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ));

        foreach ($attachments as $attachment) {
            $attachment_id = (int) $attachment->ID;
            $source = get_attached_file($attachment_id);
            $old_files = loopis_image_migration_attachment_files($attachment_id, $source);
            foreach ($old_files as $old_file) {
                $known_files[loopis_image_migration_normalize_path(realpath($old_file))] = true;
            }

            if ((int) $attachment->post_parent === 0) {
                $outside_scope = false;
                foreach ($old_files as $old_file) {
                    if (!loopis_image_migration_path_is_in_scope($old_file, $base_dir)) {
                        $outside_scope = true;
                        break;
                    }
                }
                $id = loopis_image_migration_insert_item(
                    'attachment_quarantine',
                    $attachment_id,
                    $source ?: '',
                    '',
                    array('files' => $old_files),
                    empty($old_files) || $outside_scope ? 'skipped' : 'pending',
                    $outside_scope ? 'Attachment files are outside this site’s migration scope.' : (empty($old_files) ? 'No existing attachment files were found.' : '')
                );
                if (!empty($old_files) && !$outside_scope) {
                    $quarantine_count++;
                } else {
                    $skipped_count++;
                }
                if ($id && !empty($old_files) && !$outside_scope) {
                    loopis_image_migration_log($id, 'planned', 'Registered image attachment without a post parent will be quarantined and its attachment record deleted.');
                }
                continue;
            }

            $attachment_count++;
            if (!$source || !is_file($source) || !loopis_image_migration_path_is_in_scope($source, $base_dir)) {
                loopis_image_migration_insert_item('convert', $attachment_id, $source ?: '', '', array(), 'skipped', 'Attachment file is missing or outside this site’s uploads directory.');
                $skipped_count++;
                continue;
            }

            if (!loopis_image_migration_supported_mime($attachment->post_mime_type)) {
                loopis_image_migration_insert_item('convert', $attachment_id, $source, '', array(), 'skipped', 'Image format is not supported by this migration; no conversion was attempted.');
                $skipped_count++;
                continue;
            }

            $target = loopis_image_migration_target_path($attachment_id, (int) $attachment->post_parent, $source, $reserved_paths);
            if (is_wp_error($target)) {
                loopis_image_migration_insert_item('convert', $attachment_id, $source, '', array(), 'skipped', $target->get_error_message());
                $skipped_count++;
                continue;
            }

            if (
                loopis_image_migration_normalize_path($source) === loopis_image_migration_normalize_path($target)
                && loopis_image_migration_webp_is_within_limits($source, $attachment->post_mime_type, $max_width, $max_height)
            ) {
                loopis_image_migration_insert_item('convert', $attachment_id, $source, $target, array(), 'skipped', 'Already a correctly named WebP within the configured dimensions; no conversion needed.');
                $already_matching_count++;
                continue;
            }

            if (!$webp_supported) {
                loopis_image_migration_insert_item('convert', $attachment_id, $source, $target, array(), 'skipped', 'The active image editor cannot encode WebP.');
                $skipped_count++;
                continue;
            }

            loopis_image_migration_insert_item('convert', $attachment_id, $source, $target, array(), 'pending');
        }

        $page++;
    } while (count($attachments) === $per_page);

    $image_extensions = array('jpg', 'jpeg', 'png', 'webp', 'heic', 'heif', 'heics', 'heifs', 'gif', 'avif', 'bmp', 'tif', 'tiff', 'svg');

    $scan_failed = false;
    try {
        $iterator = loopis_image_migration_upload_iterator($base_dir, RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file_info) {
            if ($file_info->isDir()) {
                $contents = new FilesystemIterator($file_info->getPathname(), FilesystemIterator::SKIP_DOTS);
                if (!$contents->valid()) {
                    $empty_folder_count++;
                }
                continue;
            }
            if (!$file_info->isFile() || !in_array(strtolower($file_info->getExtension()), $image_extensions, true)) {
                continue;
            }

            $path = loopis_image_migration_normalize_path($file_info->getPathname());
            if (isset($known_files[$path])) {
                continue;
            }

            loopis_image_migration_insert_item('orphan', 0, $path, '', array(), 'pending');
            $orphan_count++;
        }
    } catch (UnexpectedValueException $error) {
        $scan_failed = true;
        loopis_image_migration_log(0, 'failed', 'Could not scan every uploads subdirectory: ' . $error->getMessage());
    }

    if (!$scan_failed && !$GLOBALS['loopis_image_migration_scan_write_failed']) {
        loopis_image_migration_insert_item('cleanup', 0, $base_dir, '', array(), 'pending', 'Remove empty in-scope upload folders after all image items finish.');
    }

    if ($scan_failed || $GLOBALS['loopis_image_migration_scan_write_failed']) {
        $wpdb->query("UPDATE {$tables['items']} SET status = 'failed', message = 'Scan incomplete; rerun the scan before processing.' WHERE status = 'pending'");
        loopis_image_migration_log(0, 'failed', 'The scan was incomplete, so its queued items are disabled. Correct the error and scan again before processing.');
        return;
    }

    loopis_image_migration_log(
        0,
        'scanned',
        sprintf(
            "Scan complete for site %d:\n%d post-attached images found.\n%d already-matching WebP images skipped.\n%d parentless attachments queued for quarantine.\n%d unregistered image files queued for quarantine.\n%d unsupported or unavailable attachments skipped.\n%d empty upload folders found; cleanup queued last as one item.",
            get_current_blog_id(),
            $attachment_count,
            $already_matching_count,
            $quarantine_count,
            $orphan_count,
            $skipped_count,
            $empty_folder_count
        )
    );
}

/** Serialize scans and queue processing for the current site. */
function loopis_image_migration_acquire_lock() {
    global $wpdb;
    $tables = loopis_image_migration_tables();
    $name = 'loopis-img-' . md5($wpdb->dbname . ':' . $tables['items']);
    $result = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name));
    if ('1' === (string) $result) {
        return $name;
    }
    if ('0' === (string) $result) {
        return new WP_Error('migration_busy', 'Another scan or migration request is running for this site.');
    }
    return new WP_Error('lock_failed', 'Could not acquire the migration database lock: ' . $wpdb->last_error);
}

/** Release the site lock after a scan or queue request. */
function loopis_image_migration_release_lock($name) {
    global $wpdb;
    if ('1' !== (string) $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name))) {
        error_log('LOOPIS Image Migration: could not release the migration database lock: ' . $wpdb->last_error);
    }
}

/** Remove empty in-scope directories, working from the leaves upwards. */
function loopis_image_migration_cleanup_directories($item_id) {
    $upload = wp_upload_dir();
    if (!empty($upload['error']) || empty($upload['basedir']) || !is_dir($upload['basedir']) || !realpath($upload['basedir'])) {
        return new WP_Error('cleanup_directory_unavailable', 'Could not access this site’s uploads directory for empty-folder cleanup.');
    }

    $base_dir = realpath($upload['basedir']);
    $removed = 0;
    $failures = array();
    try {
        $iterator = loopis_image_migration_upload_iterator($base_dir, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if (!$entry->isDir() || $entry->isLink()) {
                continue;
            }
            $path = $entry->getPathname();
            $contents = new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS);
            if ($contents->valid()) {
                continue;
            }
            if (!loopis_image_migration_path_is_in_scope($path, $base_dir) || is_link($path)) {
                $failures[] = $path . ' (path changed)';
            } elseif (@rmdir($path)) {
                $removed++;
                loopis_image_migration_log($item_id, 'cleanup', 'Removed empty directory: ' . $path);
            } elseif (is_dir($path)) {
                $failures[] = $path;
            }
        }
    } catch (UnexpectedValueException $error) {
        return new WP_Error('cleanup_read_failed', 'Empty-folder cleanup could not read a directory: ' . $error->getMessage());
    }

    if ($failures) {
        return new WP_Error('cleanup_directories_failed', 'Removed ' . $removed . ' empty folder(s), but could not remove: ' . implode(', ', $failures));
    }
    return 'Removed ' . $removed . ' empty upload folder(s).';
}

/** Move a file only when both paths are contained within the uploads directory. */
function loopis_image_migration_safe_move($source, $destination, $base_dir) {
    $source_real = realpath($source);
    if (!$source_real || !is_file($source_real) || !loopis_image_migration_path_is_in_scope($source_real, $base_dir)) {
        return new WP_Error('unsafe_source', 'Source file is missing or outside this site’s migration scope.');
    }

    $destination_dir = dirname($destination);
    if (!wp_mkdir_p($destination_dir)) {
        return new WP_Error('mkdir_failed', 'Could not create the destination directory.');
    }

    $destination_dir_real = realpath($destination_dir);
    if (!$destination_dir_real || !loopis_image_migration_path_is_inside($destination_dir_real, $base_dir)) {
        return new WP_Error('unsafe_destination', 'Destination is outside this site’s uploads directory.');
    }

    $quarantine_root = trailingslashit(loopis_image_migration_normalize_path($base_dir)) . 'migration/quarantine/';
    if (0 === strpos(loopis_image_migration_normalize_path($destination), $quarantine_root)) {
        $protection = loopis_image_migration_protect_quarantine($base_dir);
        if (is_wp_error($protection)) {
            return $protection;
        }
    }

    if (file_exists($destination) || is_link($destination)) {
        return new WP_Error('destination_exists', 'Destination file already exists; no files were overwritten.');
    }

    if (!rename($source_real, $destination)) {
        return new WP_Error('move_failed', 'Could not move the file.');
    }

    return true;
}

/** Create the migration directory and add Apache-compatible access restrictions. */
function loopis_image_migration_protect_quarantine($base_dir) {
    $root = trailingslashit($base_dir) . 'migration';
    if (is_link($root) || !wp_mkdir_p($root)) {
        return new WP_Error('quarantine_directory_failed', 'Could not create the quarantine directory.');
    }
    if (!loopis_image_migration_path_is_inside(realpath($root), $base_dir)) {
        return new WP_Error('unsafe_quarantine_directory', 'Migration directory is outside this site’s uploads directory.');
    }

    $protection_files = array(
        $root . '/index.php' => "<?php\n",
        $root . '/.htaccess' => "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
    );
    foreach ($protection_files as $path => $contents) {
        if (!is_file($path) && false === file_put_contents($path, $contents, LOCK_EX)) {
            return new WP_Error('quarantine_protection_failed', 'Could not protect the quarantine directory from web access.');
        }
    }

    return true;
}

/** Prefix the original filename with the queue item ID in the shared quarantine directory. */
function loopis_image_migration_quarantine_path($source, $base_dir, $item_id) {
    return trailingslashit($base_dir) . 'migration/quarantine/' . (int) $item_id . '-' . wp_basename($source);
}

/** Quarantine an orphan file or a parentless attachment and its files. */
function loopis_image_migration_quarantine_item($item) {
    $upload = wp_upload_dir();
    $base_dir = trailingslashit(realpath($upload['basedir']));
    $moved = array();

    if ('orphan' === $item->item_type) {
        global $wpdb;
        $relative_path = _wp_relative_upload_path($item->source_path);
        $registered = $wpdb->get_var($wpdb->prepare(
            "SELECT posts.ID FROM {$wpdb->postmeta} AS meta INNER JOIN {$wpdb->posts} AS posts ON posts.ID = meta.post_id WHERE meta.meta_key = '_wp_attached_file' AND meta.meta_value IN (%s, %s) LIMIT 1",
            $relative_path,
            $item->source_path
        ));
        if ('' !== $wpdb->last_error) {
            return new WP_Error('file_lookup_failed', 'Could not check whether the file belongs to an existing post; it was not moved.');
        }
        if ($registered) {
            return new WP_Error('file_registered', 'This file is referenced by metadata for an existing post or attachment; it was not moved. Rescan before processing.');
        }
        $files = array($item->source_path);
    } else {
        $attachment = get_post((int) $item->attachment_id);
        if (!$attachment || 'attachment' !== $attachment->post_type || (int) $attachment->post_parent !== 0) {
            return new WP_Error('attachment_changed', 'The attachment is no longer a parentless image; rescan before processing.');
        }
        $files = loopis_image_migration_attachment_files((int) $item->attachment_id, $item->source_path);
        if (empty($files)) {
            return new WP_Error('no_files', 'No attachment files remain to quarantine.');
        }
    }

    foreach ($files as $source) {
        if (!is_file($source)) {
            continue;
        }

        $destination = loopis_image_migration_quarantine_path($source, $base_dir, (int) $item->id);
        $result = loopis_image_migration_safe_move($source, $destination, $base_dir);
        if (is_wp_error($result)) {
            loopis_image_migration_restore_files($moved);
            return $result;
        }
        $moved[] = array('source' => $source, 'destination' => $destination);
    }

    if (empty($moved)) {
        return new WP_Error('no_files', 'No files remain to quarantine.');
    }

    if ('attachment_quarantine' === $item->item_type && !wp_delete_attachment((int) $item->attachment_id, true)) {
        loopis_image_migration_restore_files($moved);
        return new WP_Error('delete_failed', 'Could not delete the parentless attachment record; quarantined files were restored.');
    }

    $moves = array_map(static function($move) {
        return $move['source'] . ' → ' . $move['destination'];
    }, $moved);

    return 'Quarantined ' . count($moved) . ' file(s): ' . implode('; ', $moves);
}

/** Attempt to return staged files to their original paths after a failure. */
function loopis_image_migration_restore_files($moved) {
    foreach (array_reverse($moved) as $move) {
        if (file_exists($move['destination']) && !rename($move['destination'], $move['source'])) {
            error_log('LOOPIS Image Migration: could not restore a staged original image.');
        }
    }
}

/** Replace old image URLs in post content, rolling back partial replacements. */
function loopis_image_migration_update_content_urls($replacements) {
    global $wpdb;
    $updated = array();

    foreach ($replacements as $old_url => $new_url) {
        if ('' === $old_url || $old_url === $new_url) {
            continue;
        }

        $like = '%' . $wpdb->esc_like($old_url) . '%';
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s",
            $old_url,
            $new_url,
            $like
        ));

        if (false === $result) {
            foreach (array_reverse($updated, true) as $replaced_url => $replacement_url) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s",
                    $replacement_url,
                    $replaced_url,
                    '%' . $wpdb->esc_like($replacement_url) . '%'
                ));
            }
            return new WP_Error('content_update_failed', 'Could not update image URLs embedded in post content.');
        }

        $updated[$old_url] = $new_url;
    }

    return true;
}

/** Convert one attachment to WebP and update its metadata and embedded URLs. */
function loopis_image_migration_convert_item($item) {
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $attachment_id = (int) $item->attachment_id;
    $source = get_attached_file($attachment_id);
    $attachment = get_post($attachment_id);
    $upload = wp_upload_dir();
    $base_dir = trailingslashit(realpath($upload['basedir']));

    if (
        !$attachment
        || 'attachment' !== $attachment->post_type
        || (int) $attachment->post_parent < 1
        || !loopis_image_migration_supported_mime($attachment->post_mime_type)
        || !$source
        || loopis_image_migration_normalize_path($source) !== loopis_image_migration_normalize_path($item->source_path)
        || !is_file($source)
        || !loopis_image_migration_path_is_in_scope($source, $base_dir)
    ) {
        return new WP_Error('missing_source', 'Attachment source is missing or outside this site’s uploads directory.');
    }
    if (file_exists($item->target_path) && loopis_image_migration_normalize_path($item->target_path) !== loopis_image_migration_normalize_path($source)) {
        return new WP_Error('target_exists', 'The planned target now exists; rescan before processing.');
    }

    $editor = wp_get_image_editor($source);
    if (is_wp_error($editor)) {
        return $editor;
    }

    if (!call_user_func(array(get_class($editor), 'supports_mime_type'), 'image/webp')) {
        return new WP_Error('webp_unsupported', 'The active image editor cannot encode WebP.');
    }

    $result = $editor->maybe_exif_rotate();
    if (is_wp_error($result)) {
        return $result;
    }

    $dimensions = $editor->get_size();
    $max_width = absint(get_option('large_size_w'));
    $max_height = absint(get_option('large_size_h'));
    if ($max_width < 1 || $max_height < 1) {
        return new WP_Error('invalid_dimensions', 'The large image dimensions must be set to positive values before migration.');
    }
    if ($dimensions['width'] > $max_width || $dimensions['height'] > $max_height) {
        $result = $editor->resize($max_width, $max_height, false);
        if (is_wp_error($result)) {
            return $result;
        }
    }

    $result = $editor->set_quality(75);
    if (is_wp_error($result)) {
        return $result;
    }

    $target = $item->target_path;
    $target_dir = realpath(dirname($target));
    if (
        !$target_dir
        || !loopis_image_migration_path_is_inside($target_dir, $base_dir)
        || dirname($target) !== dirname($source)
        || sanitize_file_name(wp_basename($target)) !== wp_basename($target)
        || 'webp' !== strtolower(pathinfo($target, PATHINFO_EXTENSION))
    ) {
        return new WP_Error('unsafe_target', 'Target is outside this site’s uploads directory.');
    }

    $temp_name = wp_unique_filename(dirname($target), '.loopis-migration-' . wp_generate_uuid4() . '.webp');
    $temp_path = trailingslashit(dirname($target)) . $temp_name;
    $saved = $editor->save($temp_path, 'image/webp');
    if (is_wp_error($saved)) {
        return $saved;
    }

    $temp_path = $saved['path'];
    if (!function_exists('loopis_media_strip_webp_metadata')) {
        if (!wp_delete_file($temp_path)) {
            error_log('LOOPIS Image Migration: could not remove a temporary WebP after metadata removal failed.');
        }
        return new WP_Error('metadata_removal_unavailable', 'WebP metadata removal is unavailable; ensure the LOOPIS Content plugin is active on this site.');
    }
    $stripped = loopis_media_strip_webp_metadata($temp_path);
    if (is_wp_error($stripped)) {
        if (!wp_delete_file($temp_path)) {
            error_log('LOOPIS Image Migration: could not remove a temporary WebP after metadata removal failed.');
        }
        return $stripped;
    }
    $old_meta = wp_get_attachment_metadata($attachment_id);
    $old_mime = get_post_mime_type($attachment_id);
    $old_url = wp_get_attachment_url($attachment_id);
    $old_files = loopis_image_migration_attachment_files($attachment_id, $source);
    $stage_dir = trailingslashit($base_dir) . '.loopis-image-migration-staging/site-' . get_current_blog_id() . '/' . (int) $item->id;
    $moved = array();

    foreach ($old_files as $index => $old_file) {
        $relative = ltrim(substr(loopis_image_migration_normalize_path($old_file), strlen(trailingslashit(loopis_image_migration_normalize_path($base_dir)))), '/');
        $backup_path = trailingslashit($stage_dir) . sprintf('%03d-', $index) . wp_basename($relative);
        $result = loopis_image_migration_safe_move($old_file, $backup_path, $base_dir);
        if (is_wp_error($result)) {
            loopis_image_migration_restore_files($moved);
            wp_delete_file($temp_path);
            return $result;
        }
        $moved[] = array('source' => $old_file, 'destination' => $backup_path);
    }

    if (!rename($temp_path, $target)) {
        loopis_image_migration_restore_files($moved);
        wp_delete_file($temp_path);
        return new WP_Error('target_move_failed', 'Could not place the converted image at its final path.');
    }

    $new_meta = wp_generate_attachment_metadata($attachment_id, $target);
    if (!is_array($new_meta)) {
        loopis_image_migration_remove_generated_files($target, array());
        loopis_image_migration_restore_files($moved);
        return new WP_Error('metadata_failed', 'Could not generate WordPress metadata for the converted image.');
    }

    update_attached_file($attachment_id, $target);
    $post_update = wp_update_post(array('ID' => $attachment_id, 'post_mime_type' => 'image/webp'), true);
    wp_update_attachment_metadata($attachment_id, $new_meta);
    $stored_meta = get_post_meta($attachment_id, '_wp_attachment_metadata', true);

    if (
        is_wp_error($post_update)
        || !$post_update
        || loopis_image_migration_normalize_path(get_attached_file($attachment_id)) !== loopis_image_migration_normalize_path($target)
        || 'image/webp' !== get_post_mime_type($attachment_id)
        || !is_array($stored_meta)
        || ($stored_meta['file'] ?? '') !== _wp_relative_upload_path($target)
    ) {
        update_attached_file($attachment_id, $source);
        wp_update_attachment_metadata($attachment_id, $old_meta);
        wp_update_post(array('ID' => $attachment_id, 'post_mime_type' => $old_mime));
        loopis_image_migration_remove_generated_files($target, $new_meta);
        loopis_image_migration_restore_files($moved);
        return new WP_Error('metadata_update_failed', 'Could not update attachment metadata; original files were restored.');
    }

    $new_url = wp_get_attachment_url($attachment_id);
    if (!$new_url) {
        update_attached_file($attachment_id, $source);
        wp_update_attachment_metadata($attachment_id, $old_meta);
        wp_update_post(array('ID' => $attachment_id, 'post_mime_type' => $old_mime));
        loopis_image_migration_remove_generated_files($target, $new_meta);
        loopis_image_migration_restore_files($moved);
        return new WP_Error('attachment_url_failed', 'Could not generate the WebP attachment URL; original files were restored.');
    }
    $url_replacements = array();
    $new_url_path = wp_parse_url($new_url, PHP_URL_PATH);
    foreach ($old_files as $old_file) {
        $relative_path = ltrim(substr(loopis_image_migration_normalize_path($old_file), strlen(trailingslashit(loopis_image_migration_normalize_path($base_dir)))), '/');
        $encoded_path = implode('/', array_map('rawurlencode', explode('/', $relative_path)));
        $old_file_url = trailingslashit($upload['baseurl']) . $encoded_path;
        if ($new_url_path) {
            $url_replacements[$old_file_url] = $new_url;
            $old_url_path = wp_parse_url($old_file_url, PHP_URL_PATH);
            if ($old_url_path) {
                $url_replacements[$old_url_path] = $new_url_path;
            }
        }
    }
    if ($old_url && $new_url) {
        $url_replacements[$old_url] = $new_url;
        $old_url_path = wp_parse_url($old_url, PHP_URL_PATH);
        if ($old_url_path && $new_url_path) {
            $url_replacements[$old_url_path] = $new_url_path;
        }
    }

    $content_update = loopis_image_migration_update_content_urls($url_replacements);
    if (is_wp_error($content_update)) {
        update_attached_file($attachment_id, $source);
        wp_update_attachment_metadata($attachment_id, $old_meta);
        wp_update_post(array('ID' => $attachment_id, 'post_mime_type' => $old_mime));
        loopis_image_migration_remove_generated_files($target, $new_meta);
        loopis_image_migration_restore_files($moved);
        return $content_update;
    }

    $cleanup_failures = array();
    foreach ($moved as $move) {
        wp_delete_file($move['destination']);
        if (is_file($move['destination'])) {
            $cleanup_failures[] = wp_basename($move['source']);
        }
    }

    if (!empty($cleanup_failures)) {
        return new WP_Error('cleanup_failed', 'Converted to WebP, but could not delete staged originals: ' . implode(', ', $cleanup_failures));
    }

    return 'Converted ' . $source . ' to ' . $target . '; updated attachment metadata and removed previous image files.';
}

/** Remove the converted file and generated sizes when conversion must roll back. */
function loopis_image_migration_remove_generated_files($target, $metadata) {
    $files = array($target);
    if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
        foreach ($metadata['sizes'] as $size) {
            if (!empty($size['file'])) {
                $files[] = trailingslashit(dirname($target)) . wp_basename($size['file']);
            }
        }
    }

    $filename = pathinfo($target, PATHINFO_FILENAME);
    foreach (glob(trailingslashit(dirname($target)) . $filename . '-*.webp') ?: array() as $file) {
        if (preg_match('/^' . preg_quote($filename, '/') . '-(?:\d+x\d+|scaled|rotated)\.webp$/i', wp_basename($file))) {
            $files[] = $file;
        }
    }

    foreach (array_unique($files) as $file) {
        if (is_file($file)) {
            wp_delete_file($file);
            if (is_file($file)) {
                error_log('LOOPIS Image Migration: could not remove a generated image file during rollback.');
            }
        }
    }
}

/** Validate and handle the admin request to create a fresh scan queue. */
function loopis_image_migration_handle_scan() {
    if (!current_user_can('manage_options')) {
        wp_die('You are not allowed to run this action.');
    }
    check_admin_referer('loopis_image_migration_scan');
    loopis_image_migration_scan();
    wp_safe_redirect(admin_url('admin.php?page=loopis-image-migration&scan=complete'));
    exit;
}
add_action('admin_post_loopis_image_migration_scan', 'loopis_image_migration_handle_scan');

/** Validate the backup confirmation before starting queued processing. */
function loopis_image_migration_handle_process() {
    if (!current_user_can('manage_options')) {
        wp_die('You are not allowed to run this action.');
    }
    check_admin_referer('loopis_image_migration_process');
    if (empty($_POST['confirm_backup'])) {
        wp_die('Confirm that a current database and uploads backup exists before proceeding.');
    }

    $result = loopis_image_migration_process_next();
    if (is_wp_error($result)) {
        wp_die(esc_html($result->get_error_message()));
    }
    wp_safe_redirect(admin_url('admin.php?page=loopis-image-migration&processed=1'));
    exit;
}
add_action('admin_post_loopis_image_migration_process', 'loopis_image_migration_handle_process');

/** Claim and process one queue item, then record its outcome. */
function loopis_image_migration_process_next() {
    $lock = loopis_image_migration_acquire_lock();
    if (is_wp_error($lock)) {
        return $lock;
    }

    try {
        return loopis_image_migration_process_next_locked();
    } finally {
        loopis_image_migration_release_lock($lock);
    }
}

/** Add the affected source path to failure and warning details. */
function loopis_image_migration_error_details($message, $source_path) {
    if ('' === $source_path) {
        return $message;
    }

    $source_line = 'Source: ' . $source_path;
    return false !== strpos($message, $source_line) ? $message : $message . "\n" . $source_line;
}

/** Process one item while holding the current site's migration lock. */
function loopis_image_migration_process_next_locked() {
    global $wpdb;
    $tables = loopis_image_migration_tables();
    $item = $wpdb->get_row("SELECT * FROM {$tables['items']} WHERE status = 'pending' ORDER BY id ASC LIMIT 1");

    if (!$item) {
        return array('pending' => 0, 'message' => 'No pending items remain.');
    }

    if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['items']} WHERE status = 'processing'") > 0) {
        return new WP_Error('processing_incomplete', 'Processing cannot continue while an item is marked processing. Inspect that item and its staged files first.');
    }

    $log_file = loopis_image_migration_log_file();
    if (is_wp_error($log_file)) {
        return $log_file;
    }
    if (false === file_put_contents($log_file, '', FILE_APPEND | LOCK_EX)) {
        return new WP_Error('log_write_failed', 'Cannot write the activity log file; no queue item was processed.');
    }

    $upload = wp_upload_dir();
    if (!empty($upload['error']) || empty($upload['basedir']) || !is_dir($upload['basedir']) || !realpath($upload['basedir'])) {
        return new WP_Error('upload_directory_unavailable', 'Cannot access this site’s uploads directory; no queue item was processed.');
    }
    $claimed = $wpdb->update(
        $tables['items'],
        array('status' => 'processing', 'updated_at' => current_time('mysql', true)),
        array('id' => (int) $item->id, 'status' => 'pending'),
        array('%s', '%s'),
        array('%d', '%s')
    );
    if (1 !== $claimed) {
        return array('pending' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['items']} WHERE status = 'pending'"), 'message' => 'Another request claimed the next item; continuing.');
    }

    if ('convert' === $item->item_type) {
        $result = loopis_image_migration_convert_item($item);
    } elseif (in_array($item->item_type, array('attachment_quarantine', 'orphan'), true)) {
        $result = loopis_image_migration_quarantine_item($item);
    } elseif ('cleanup' === $item->item_type) {
        $result = loopis_image_migration_cleanup_directories((int) $item->id);
    } else {
        $result = new WP_Error('unknown_item_type', 'The queue item has an unknown action.');
    }
    $status = is_wp_error($result)
        ? ('cleanup_failed' === $result->get_error_code() ? 'warning' : 'failed')
        : (in_array($item->item_type, array('attachment_quarantine', 'orphan'), true) ? 'quarantined' : 'completed');
    $message = is_wp_error($result) ? $result->get_error_message() : $result;
    if (is_wp_error($result)) {
        $message = loopis_image_migration_error_details($message, $item->source_path);
    }

    $updated = $wpdb->update(
        $tables['items'],
        array('status' => $status, 'message' => $message, 'updated_at' => current_time('mysql', true)),
        array('id' => (int) $item->id),
        array('%s', '%s', '%s'),
        array('%d')
    );
    $logged = loopis_image_migration_log((int) $item->id, $status, $message);

    if (false === $updated) {
        error_log('LOOPIS Image Migration: could not update a processed queue item: ' . $wpdb->last_error);
        return new WP_Error('queue_update_failed', 'The item was processed, but its queue status could not be saved. Inspect the activity log before continuing.');
    }
    if (is_wp_error($logged)) {
        return new WP_Error('activity_log_failed', 'The item was processed, but its activity could not be fully logged: ' . $logged->get_error_message() . ' Inspect the queue before continuing.');
    }

    return array(
        'pending' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['items']} WHERE status = 'pending'"),
        'item_id' => (int) $item->id,
        'status'  => $status,
        'message' => $message,
    );
}

/** Authorize an AJAX request to process the next queue item. */
function loopis_image_migration_ajax_process() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'You are not allowed to run this action.'), 403);
    }
    check_ajax_referer('loopis_image_migration_process');
    if (empty($_POST['confirm_backup'])) {
        wp_send_json_error(array('message' => 'Confirm that a current database and uploads backup exists before proceeding.'), 400);
    }

    $result = loopis_image_migration_process_next();
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()), 409);
    }
    wp_send_json_success($result);
}
add_action('wp_ajax_loopis_image_migration_process_ajax', 'loopis_image_migration_ajax_process');

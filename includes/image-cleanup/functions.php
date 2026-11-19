<?php
/**
 * Review and quarantine image files not referenced by this site's content.
 */

if (!defined('ABSPATH')) {
    exit;
}

function loopis_image_cleanup_table() {
    global $wpdb;
    return $wpdb->prefix . 'loopis_image_cleanup';
}

function loopis_image_cleanup_install() {
    if (get_option('loopis_image_cleanup_db_version') === '1') {
        return;
    }
    global $wpdb;
    $table = loopis_image_cleanup_table();
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta("CREATE TABLE $table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        item_type varchar(20) NOT NULL,
        attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
        source_path text NOT NULL,
        payload longtext NOT NULL,
        status varchar(24) NOT NULL,
        message text NOT NULL,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY status (status)
    ) $charset;");
    $installed = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
    if ('' !== $wpdb->last_error || $installed !== $table) {
        wp_die(esc_html('Could not install the image cleanup queue: ' . $wpdb->last_error));
    }
    update_option('loopis_image_cleanup_db_version', '1', false);
}
add_action('admin_init', 'loopis_image_cleanup_install');

/** Search conservatively: any matching ID or filename in content or metadata protects the image. */
function loopis_image_cleanup_is_used($attachment_id, $files) {
    global $wpdb;
    $conditions = array();
    $args = array();
    if ($attachment_id) {
        $conditions[] = 'value REGEXP %s';
        $args[] = '(^|[^0-9])' . (int) $attachment_id . '([^0-9]|$)';
    }
    foreach ($files as $file) {
        $name = wp_basename($file);
        foreach (array_unique(array($name, rawurlencode($name))) as $variant) {
            $conditions[] = 'value LIKE %s';
            $args[] = '%' . $wpdb->esc_like($variant) . '%';
        }
    }
    if (!$conditions) {
        return new WP_Error('missing_reference', 'Cannot check usage without an attachment ID or filename.');
    }
    $where = implode(' OR ', $conditions);
    $sql = "SELECT 1 FROM (
        SELECT post_content AS value FROM {$wpdb->posts} WHERE post_type <> 'attachment'
        UNION ALL
        SELECT m.meta_value AS value FROM {$wpdb->postmeta} m
        INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.post_type <> 'attachment'
    ) content WHERE $where LIMIT 1";
    $found = $wpdb->get_var($wpdb->prepare($sql, $args));
    if ('' !== $wpdb->last_error) {
        return new WP_Error('usage_lookup_failed', 'Could not check content and metadata for image references: ' . $wpdb->last_error);
    }
    return null !== $found;
}

/** Check all attachment metadata, not just the full-size file, for overlapping files. */
function loopis_image_cleanup_files_registered_elsewhere($attachment_id, $files) {
    global $wpdb;
    foreach ($files as $file) {
        foreach (array_unique(array(wp_basename($file), rawurlencode(wp_basename($file)))) as $name) {
            $found = $wpdb->get_var($wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
                WHERE p.post_type = 'attachment' AND p.ID <> %d
                AND m.meta_key IN ('_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes')
                AND m.meta_value LIKE %s LIMIT 1",
                $attachment_id,
                '%' . $wpdb->esc_like($name) . '%'
            ));
            if ('' !== $wpdb->last_error) {
                return new WP_Error('registration_lookup_failed', 'Could not check image registration: ' . $wpdb->last_error);
            }
            if (null !== $found) {
                return true;
            }
        }
    }
    return false;
}

/** Include every file WordPress may delete with the attachment record. */
function loopis_image_cleanup_attachment_files($attachment_id, $source, $base_dir) {
    global $wpdb;
    $metadata = wp_get_attachment_metadata($attachment_id);
    $backups = get_post_meta($attachment_id, '_wp_attachment_backup_sizes', true);
    if ('' !== $wpdb->last_error) {
        return new WP_Error('attachment_lookup_failed', 'Could not read attachment metadata: ' . $wpdb->last_error);
    }
    if (!$source) {
        return array();
    }
    $metadata = is_array($metadata) ? $metadata : array();
    $names = array();
    foreach (array('thumb', 'original_image') as $key) {
        if (!empty($metadata[$key])) {
            $names[] = $metadata[$key];
        }
    }
    if (isset($metadata['sizes'])) {
        if (!is_array($metadata['sizes'])) {
            return new WP_Error('attachment_metadata_invalid', 'Attachment sizes metadata is invalid for attachment ' . $attachment_id . '.');
        }
        foreach ($metadata['sizes'] as $size) {
            if (!is_array($size) || empty($size['file'])) {
                return new WP_Error('attachment_metadata_invalid', 'Attachment size filename is invalid for attachment ' . $attachment_id . '.');
            }
            $names[] = $size['file'];
        }
    }
    $files = array($source);
    foreach ($names as $name) {
        if (!is_string($name) || wp_basename($name) !== $name) {
            return new WP_Error('attachment_metadata_invalid', 'An attachment filename is unsafe for attachment ' . $attachment_id . '.');
        }
        $files[] = trailingslashit(dirname($source)) . $name;
    }
    if (!empty($backups)) {
        if (!is_array($backups) || empty($metadata['file']) || !is_string($metadata['file'])) {
            return new WP_Error('attachment_metadata_invalid', 'Attachment backup metadata is invalid for attachment ' . $attachment_id . '.');
        }
        foreach ($backups as $size) {
            if (!is_array($size) || empty($size['file']) || !is_string($size['file']) || wp_basename($size['file']) !== $size['file']) {
                return new WP_Error('attachment_metadata_invalid', 'Attachment backup filename is invalid for attachment ' . $attachment_id . '.');
            }
            $files[] = trailingslashit($base_dir) . dirname($metadata['file']) . '/' . $size['file'];
        }
    }
    return array_values(array_unique(array_filter($files, 'is_file')));
}

/** Capture the exact files and contents reviewed; changes require another scan. */
function loopis_image_cleanup_fingerprint($files, $base_dir) {
    $fingerprints = array();
    foreach ($files as $file) {
        if (is_link($file) || !is_file($file) || !loopis_image_conversion_path_is_in_scope($file, $base_dir)) {
            return new WP_Error('unsafe_file', 'An image file is missing, a symlink, or outside this site’s uploads scope: ' . $file);
        }
        $hash = hash_file('sha256', $file);
        if (false === $hash) {
            return new WP_Error('hash_failed', 'Could not read image file: ' . $file);
        }
        $fingerprints[loopis_image_conversion_normalize_path($file)] = $hash;
    }
    ksort($fingerprints);
    return $fingerprints;
}

function loopis_image_cleanup_add_candidate($type, $attachment_id, $source, $files, $base_dir) {
    global $wpdb;
    $used = loopis_image_cleanup_is_used($attachment_id, $files);
    if (is_wp_error($used)) {
        return $used;
    }
    if ($used) {
        return false;
    }
    $registered = loopis_image_cleanup_files_registered_elsewhere($attachment_id, $files);
    if (is_wp_error($registered)) {
        return $registered;
    }
    if ($registered) {
        return false;
    }
    $fingerprints = loopis_image_cleanup_fingerprint($files, $base_dir);
    if (is_wp_error($fingerprints)) {
        return $fingerprints;
    }
    $now = current_time('mysql', true);
    $inserted = $wpdb->insert(loopis_image_cleanup_table(), array(
        'item_type' => $type,
        'attachment_id' => $attachment_id,
        'source_path' => $source,
        'payload' => wp_json_encode($fingerprints),
        'status' => 'review',
        'message' => '',
        'created_at' => $now,
        'updated_at' => $now,
    ), array('%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s'));
    if (false === $inserted) {
        return new WP_Error('queue_write_failed', 'Could not save a cleanup candidate: ' . $wpdb->last_error);
    }
    return true;
}

function loopis_image_cleanup_scan() {
    global $wpdb;
    $lock = loopis_image_conversion_acquire_lock();
    if (is_wp_error($lock)) {
        return $lock;
    }
    try {
        $table = loopis_image_cleanup_table();
        $processing = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'processing'");
        if ('' !== $wpdb->last_error) {
            return new WP_Error('queue_read_failed', 'Could not read the cleanup queue: ' . $wpdb->last_error);
        }
        if ((int) $processing) {
            return new WP_Error('processing_incomplete', 'An item is still marked processing. Inspect it before rescanning.');
        }
        update_option('loopis_image_cleanup_scan_ready', '0', false);
        if ('0' !== get_option('loopis_image_cleanup_scan_ready')) {
            return new WP_Error('scan_state_failed', 'Could not disable the old cleanup scan.');
        }
        if (false === $wpdb->query("UPDATE $table SET status = 'superseded' WHERE status IN ('review', 'skipped', 'pending')")) {
            return new WP_Error('queue_write_failed', 'Could not archive the previous cleanup queue: ' . $wpdb->last_error);
        }
        $upload = wp_upload_dir();
        if (!empty($upload['error']) || empty($upload['basedir']) || !is_dir($upload['basedir']) || !realpath($upload['basedir'])) {
            return new WP_Error('upload_unavailable', 'Could not access this site’s uploads directory.');
        }
        $base = trailingslashit(realpath($upload['basedir']));
        $known = array();
        $count = 0;
        $page = 1;
        do {
            $attachments = get_posts(array(
                'post_type' => 'attachment', 'post_status' => 'any', 'post_mime_type' => 'image',
                'posts_per_page' => 200, 'paged' => $page++, 'orderby' => 'ID', 'order' => 'ASC',
            ));
            if ('' !== $wpdb->last_error) {
                $error = new WP_Error('attachments_lookup_failed', 'Could not inventory attachments: ' . $wpdb->last_error);
                break;
            }
            foreach ($attachments as $attachment) {
                $source = get_attached_file($attachment->ID);
                $files = loopis_image_cleanup_attachment_files($attachment->ID, $source, $base);
                if (is_wp_error($files)) {
                    $error = $files;
                    break 2;
                }
                foreach ($files as $file) {
                    $known[loopis_image_conversion_normalize_path(realpath($file))] = true;
                }
                if (!$source || !is_file($source) || is_link($source) || !$files) {
                    continue;
                }
                $in_scope = true;
                foreach ($files as $file) {
                    if (is_link($file) || !loopis_image_conversion_path_is_in_scope($file, $base)) {
                        $in_scope = false;
                        break;
                    }
                }
                if (!$in_scope) {
                    continue;
                }
                $candidate = loopis_image_cleanup_add_candidate('attachment', (int) $attachment->ID, $source, $files, $base);
                if (is_wp_error($candidate)) {
                    $error = $candidate;
                    break 2;
                }
                $count += (int) $candidate;
            }
        } while (count($attachments) === 200);

        if (!isset($error)) {
            try {
                $extensions = array('jpg', 'jpeg', 'png', 'webp', 'heic', 'heif', 'heics', 'heifs', 'gif', 'avif', 'bmp', 'tif', 'tiff', 'svg');
                foreach (loopis_image_conversion_upload_iterator($base, RecursiveIteratorIterator::SELF_FIRST) as $entry) {
                    if (!$entry->isFile() || !in_array(strtolower($entry->getExtension()), $extensions, true)) {
                        continue;
                    }
                    $path = loopis_image_conversion_normalize_path(realpath($entry->getPathname()));
                    if (isset($known[$path])) {
                        continue;
                    }
                    $candidate = loopis_image_cleanup_add_candidate('file', 0, $path, array($path), $base);
                    if (is_wp_error($candidate)) {
                        $error = $candidate;
                        break;
                    }
                    $count += (int) $candidate;
                }
            } catch (UnexpectedValueException $exception) {
                $error = new WP_Error('scan_failed', 'Could not scan uploads: ' . $exception->getMessage());
            }
        }
        if (isset($error)) {
            if (false === $wpdb->query("UPDATE $table SET status = 'failed', message = 'Incomplete scan; rescan before processing.' WHERE status = 'review'")) {
                error_log('LOOPIS Image Cleanup: failed to disable an incomplete scan: ' . $wpdb->last_error);
            }
            return $error;
        }
        $logged = loopis_image_conversion_log(0, 'cleanup_scan', "Cleanup scan complete: $count candidates for review.", 'cleanup');
        if (is_wp_error($logged)) {
            return $logged;
        }
        update_option('loopis_image_cleanup_scan_ready', '1', false);
        if ('1' !== get_option('loopis_image_cleanup_scan_ready')) {
            return new WP_Error('scan_state_failed', 'Could not enable the completed cleanup scan.');
        }
        return $count;
    } finally {
        loopis_image_conversion_release_lock($lock);
    }
}

function loopis_image_cleanup_quarantine($item) {
    global $wpdb;
    $upload = wp_upload_dir();
    if (!empty($upload['error']) || empty($upload['basedir']) || !realpath($upload['basedir'])) {
        return new WP_Error('upload_unavailable', 'Could not access uploads.');
    }
    $base = trailingslashit(realpath($upload['basedir']));
    $expected = json_decode($item->payload, true);
    if (!is_array($expected) || !$expected) {
        return new WP_Error('invalid_snapshot', 'The reviewed file snapshot is invalid; rescan.');
    }
    if ('attachment' === $item->item_type) {
        $attachment = get_post((int) $item->attachment_id);
        $source = get_attached_file((int) $item->attachment_id);
        if (!$attachment || 'attachment' !== $attachment->post_type || $source !== $item->source_path || !is_file($source)) {
            return new WP_Error('attachment_changed', 'The attachment changed; rescan.');
        }
        $files = loopis_image_cleanup_attachment_files((int) $item->attachment_id, $source, $base);
        if (is_wp_error($files)) {
            return $files;
        }
    } elseif ('file' === $item->item_type && !(int) $item->attachment_id) {
        $files = array($item->source_path);
    } else {
        return new WP_Error('invalid_item', 'Unknown cleanup item.');
    }
    $scope = loopis_image_tools_check_item_scope('cleanup', $files);
    if (is_wp_error($scope)) {
        return $scope;
    }
    $current = loopis_image_cleanup_fingerprint($files, $base);
    if (is_wp_error($current)) {
        return $current;
    }
    if ($current !== $expected) {
        return new WP_Error('files_changed', 'Image files changed since the scan; rescan.');
    }
    $used = loopis_image_cleanup_is_used((int) $item->attachment_id, $files);
    if (is_wp_error($used)) {
        return $used;
    }
    if ($used) {
        return new WP_Error('now_used', 'The image is now referenced by content or metadata; it was not moved.');
    }
    $registered = loopis_image_cleanup_files_registered_elsewhere((int) $item->attachment_id, $files);
    if (is_wp_error($registered)) {
        return $registered;
    }
    if ($registered) {
        return new WP_Error('now_registered', 'A different attachment references an image file; it was not moved.');
    }
    $moved = array();
    foreach ($files as $file) {
        $destination = trailingslashit($base) . 'image-cleanup/quarantine/cleanup-' . (int) $item->id . '-' . wp_basename($file);
        $result = loopis_image_conversion_safe_move($file, $destination, $base);
        if (is_wp_error($result)) {
            $restored = loopis_image_cleanup_restore_files($moved);
            return is_wp_error($restored) ? $restored : $result;
        }
        $moved[] = array('source' => $file, 'destination' => $destination);
    }
    $used = loopis_image_cleanup_is_used((int) $item->attachment_id, $files);
    $registered = is_wp_error($used) || $used ? false : loopis_image_cleanup_files_registered_elsewhere((int) $item->attachment_id, $files);
    if (is_wp_error($used) || $used || is_wp_error($registered) || $registered) {
        $restored = loopis_image_cleanup_restore_files($moved);
        if (is_wp_error($restored)) {
            return $restored;
        }
        if (is_wp_error($used)) {
            return $used;
        }
        if (is_wp_error($registered)) {
            return $registered;
        }
        return new WP_Error('reference_changed', 'A new image reference or registration appeared during quarantine; the files were restored.');
    }
    if ('attachment' === $item->item_type && !wp_delete_attachment((int) $item->attachment_id, true)) {
        $restored = loopis_image_cleanup_restore_files($moved);
        return is_wp_error($restored) ? $restored : new WP_Error('delete_failed', 'Could not delete the attachment record; its files were restored.');
    }
    return 'Quarantined ' . count($moved) . ' file(s): ' . $item->source_path;
}

function loopis_image_cleanup_restore_files($moved) {
    $failed = array();
    foreach (array_reverse($moved) as $move) {
        if (file_exists($move['source']) || is_link($move['source']) || !rename($move['destination'], $move['source'])) {
            $failed[] = $move['destination'];
        }
    }
    if ($failed) {
        return new WP_Error('restore_failed', 'Could not restore quarantined files; inspect these paths before continuing: ' . implode(', ', $failed));
    }
    return true;
}

function loopis_image_cleanup_mutate_queue($action, $id = 0) {
    global $wpdb;
    $lock = loopis_image_conversion_acquire_lock();
    if (is_wp_error($lock)) {
        return $lock;
    }
    try {
        $scan_guard = loopis_image_tools_processing_guard('cleanup');
        if (is_wp_error($scan_guard)) {
            return $scan_guard;
        }
        if ('1' !== get_option('loopis_image_cleanup_scan_ready')) {
            return new WP_Error('scan_not_ready', 'Run a complete cleanup scan before changing the queue.');
        }
        $table = loopis_image_cleanup_table();
        if ('skip' === $action && $id > 0) {
            $changed = $wpdb->update($table, array('status' => 'skipped'), array('id' => $id, 'status' => 'review'), array('%s'), array('%d', '%s'));
        } elseif ('accept' === $action) {
            $changed = $wpdb->query("UPDATE $table SET status = 'pending' WHERE status = 'review'");
        } else {
            return new WP_Error('invalid_action', 'Invalid cleanup queue action.');
        }
        if (false === $changed) {
            return new WP_Error('queue_write_failed', 'Could not update the cleanup queue: ' . $wpdb->last_error);
        }
        if ('skip' === $action && 1 !== $changed) {
            return new WP_Error('item_changed', 'This item is no longer awaiting review.');
        }
        return $changed;
    } finally {
        loopis_image_conversion_release_lock($lock);
    }
}

function loopis_image_cleanup_process_next() {
    global $wpdb;
    $lock = loopis_image_conversion_acquire_lock();
    if (is_wp_error($lock)) {
        return $lock;
    }
    try {
        $scan_guard = loopis_image_tools_processing_guard('cleanup');
        if (is_wp_error($scan_guard)) {
            return $scan_guard;
        }
        if ('1' !== get_option('loopis_image_cleanup_scan_ready')) {
            return new WP_Error('scan_not_ready', 'Run a complete cleanup scan before processing.');
        }
        $table = loopis_image_cleanup_table();
        $processing = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'processing'");
        if ('' !== $wpdb->last_error) {
            return new WP_Error('queue_read_failed', 'Could not read the cleanup queue: ' . $wpdb->last_error);
        }
        if ((int) $processing) {
            return new WP_Error('processing_incomplete', 'An item is still marked processing. Inspect its files and activity before continuing.');
        }
        $item = $wpdb->get_row("SELECT * FROM $table WHERE status = 'pending' ORDER BY id ASC LIMIT 1");
        if ('' !== $wpdb->last_error) {
            return new WP_Error('queue_read_failed', 'Could not read the cleanup queue: ' . $wpdb->last_error);
        }
        if (!$item) {
            return array('pending' => 0, 'message' => 'No queued cleanup items remain.');
        }
        $log = loopis_image_conversion_log_file('cleanup');
        if (is_wp_error($log)) {
            return $log;
        }
        if (false === file_put_contents($log, '', FILE_APPEND | LOCK_EX)) {
            return new WP_Error('log_write_failed', 'Cannot write the activity log; no item was processed.');
        }
        $claimed = $wpdb->update($table, array('status' => 'processing'), array('id' => (int) $item->id, 'status' => 'pending'), array('%s'), array('%d', '%s'));
        if (1 !== $claimed) {
            return new WP_Error('queue_claim_failed', 'Could not claim the cleanup item: ' . $wpdb->last_error);
        }
        $result = loopis_image_cleanup_quarantine($item);
        $status = is_wp_error($result) ? 'failed' : 'quarantined';
        $message = is_wp_error($result) ? $result->get_error_message() : $result;
        $updated = $wpdb->update($table, array('status' => $status, 'message' => $message, 'updated_at' => current_time('mysql', true)), array('id' => (int) $item->id), array('%s', '%s', '%s'), array('%d'));
        $logged = loopis_image_conversion_log((int) $item->id, 'cleanup_' . $status, $message, 'cleanup');
        if (false === $updated || is_wp_error($logged)) {
            return new WP_Error('record_failed', 'The item was processed but its status or activity could not be saved; inspect the queue and files before continuing.');
        }
        if (is_wp_error($result)) {
            return $result;
        }
        $pending = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'pending'");
        if ('' !== $wpdb->last_error) {
            return new WP_Error('queue_read_failed', 'The item was processed, but the remaining queue could not be read: ' . $wpdb->last_error);
        }
        return array('pending' => (int) $pending, 'item_id' => (int) $item->id, 'message' => $message);
    } finally {
        loopis_image_conversion_release_lock($lock);
    }
}

function loopis_image_cleanup_empty_directories() {
    global $wpdb;
    $lock = loopis_image_conversion_acquire_lock();
    if (is_wp_error($lock)) {
        return $lock;
    }
    try {
        foreach (array('conversion', 'cleanup') as $tool) {
            $state = get_option(loopis_image_tools_scan_option($tool));
            if (is_array($state) && in_array($state['status'], array('active', 'batch'), true)) {
                return new WP_Error('scan_in_progress', 'Complete or reset the active image scan before removing directories.');
            }
        }
        $table = loopis_image_cleanup_table();
        $conversion = loopis_image_conversion_tables();
        $processing = $wpdb->get_var("SELECT (SELECT COUNT(*) FROM $table WHERE status = 'processing') + (SELECT COUNT(*) FROM {$conversion['items']} WHERE status = 'processing')");
        if ('' !== $wpdb->last_error) {
            return new WP_Error('queue_read_failed', 'Could not check the queues: ' . $wpdb->last_error);
        }
        if ((int) $processing) {
            return new WP_Error('processing_incomplete', 'An image item is still marked processing. Inspect it before removing directories.');
        }
        $log = loopis_image_conversion_log_file('cleanup');
        if (is_wp_error($log)) {
            return $log;
        }
        if (false === file_put_contents($log, '', FILE_APPEND | LOCK_EX)) {
            return new WP_Error('log_write_failed', 'Cannot write the activity log; no directories were removed.');
        }
        $result = loopis_image_conversion_cleanup_directories(0);
        $logged = loopis_image_conversion_log(0, is_wp_error($result) ? 'cleanup_failed' : 'cleanup_directories', is_wp_error($result) ? $result->get_error_message() : $result, 'cleanup');
        return is_wp_error($logged) ? $logged : $result;
    } finally {
        loopis_image_conversion_release_lock($lock);
    }
}

function loopis_image_cleanup_authorize($nonce) {
    if (!is_super_admin() || !current_user_can('manage_options')) {
        wp_die('You are not allowed to run this action.');
    }
    check_admin_referer($nonce);
}

function loopis_image_cleanup_handle_scan() {
    loopis_image_cleanup_authorize('loopis_image_cleanup_scan');
    wp_die('Use the year selector and enable JavaScript to run the batched scan.');
}
add_action('admin_post_loopis_image_cleanup_scan', 'loopis_image_cleanup_handle_scan');

function loopis_image_cleanup_handle_review() {
    loopis_image_cleanup_authorize('loopis_image_cleanup_review');
    $action = isset($_POST['decision']) ? sanitize_key(wp_unslash($_POST['decision'])) : '';
    $id = isset($_POST['item_id']) ? absint($_POST['item_id']) : 0;
    $result = loopis_image_cleanup_mutate_queue($action, $id);
    if (is_wp_error($result)) {
        wp_die(esc_html($result->get_error_message()));
    }
    $page = isset($_POST['review_page']) ? max(1, absint($_POST['review_page'])) : 1;
    wp_safe_redirect(admin_url('admin.php?page=loopis-image-cleanup&review_page=' . $page));
    exit;
}
add_action('admin_post_loopis_image_cleanup_review', 'loopis_image_cleanup_handle_review');

function loopis_image_cleanup_handle_process() {
    loopis_image_cleanup_authorize('loopis_image_cleanup_process');
    if (empty($_POST['confirm_backup'])) {
        wp_die('Confirm a current database and uploads backup before processing.');
    }
    $result = loopis_image_cleanup_process_next();
    if (is_wp_error($result)) {
        wp_die(esc_html($result->get_error_message()));
    }
    wp_safe_redirect(admin_url('admin.php?page=loopis-image-cleanup'));
    exit;
}
add_action('admin_post_loopis_image_cleanup_process', 'loopis_image_cleanup_handle_process');

function loopis_image_cleanup_handle_directories() {
    loopis_image_cleanup_authorize('loopis_image_cleanup_directories');
    if (empty($_POST['confirm_backup'])) {
        wp_die('Confirm a current database and uploads backup before processing.');
    }
    $result = loopis_image_cleanup_empty_directories();
    if (is_wp_error($result)) {
        wp_die(esc_html($result->get_error_message()));
    }
    wp_safe_redirect(admin_url('admin.php?page=loopis-image-cleanup'));
    exit;
}
add_action('admin_post_loopis_image_cleanup_directories', 'loopis_image_cleanup_handle_directories');

function loopis_image_cleanup_ajax_process() {
    if (!is_super_admin() || !current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'You are not allowed to run this action.'), 403);
    }
    check_ajax_referer('loopis_image_cleanup_process');
    if (empty($_POST['confirm_backup'])) {
        wp_send_json_error(array('message' => 'Confirm a current database and uploads backup before processing.'), 400);
    }
    $result = loopis_image_cleanup_process_next();
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()), 409);
    }
    wp_send_json_success($result);
}
add_action('wp_ajax_loopis_image_cleanup_process_ajax', 'loopis_image_cleanup_ajax_process');

<?php
/**
 * Render the Image Migration administration page for the current site.
 *
 * Provides scanning and processing controls, a queue preview, backup
 * confirmation, and recent activity from the migration log.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Escape a file path and emphasize its filename for the queue preview. */
function loopis_image_migration_format_path($path) {
    if ('' === $path) {
        return '';
    }

    $filename = wp_basename($path);
    $directory = substr($path, 0, strlen($path) - strlen($filename));
    return esc_html($directory) . '<strong>' . esc_html($filename) . '</strong>';
}

/** Render the current site's scan, queue, backup confirmation, and activity log. */
function loopis_image_migration_render_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    global $wpdb;
    $tables = loopis_image_migration_tables();
    $counts = $wpdb->get_results("SELECT status, COUNT(*) AS total FROM {$tables['items']} GROUP BY status", OBJECT_K);
    $pending = isset($counts['pending']) ? (int) $counts['pending']->total : 0;
    $processing = isset($counts['processing']) ? (int) $counts['processing']->total : 0;
    $upload = wp_upload_dir();
    $log_error = get_transient('loopis_image_migration_log_error');
    $log_file = loopis_image_migration_log_file();
    $logs = is_wp_error($log_file) ? $log_file : loopis_image_migration_recent_activity($log_file);
    ?>
    <div class="wrap">
        <h1>LOOPIS Image Migration</h1>
        <?php if ($log_error || is_wp_error($log_file)) : ?>
            <div class="notice notice-error"><p>Activity file logging error: <?php echo esc_html(is_wp_error($log_file) ? $log_file->get_error_message() : $log_error); ?></p></div>
        <?php endif; ?>
        <?php if (is_wp_error($logs) && !is_wp_error($log_file)) : ?>
            <div class="notice notice-error"><p>Activity file reading error: <?php echo esc_html($logs->get_error_message()); ?></p></div>
        <?php endif; ?>
        <p>This tool operates on the current site only: <strong><?php echo esc_html(get_bloginfo('name')); ?></strong> (site ID <?php echo esc_html((string) get_current_blog_id()); ?>).</p>
        <h2>Description</h2>
        <p>Images in the sites uploads directory will be conversed to WebP and processed according to the settings below!</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="loopis_image_migration_scan">
            <?php wp_nonce_field('loopis_image_migration_scan'); ?>
            <?php submit_button('Scan this site (no media changes)', 'secondary', 'submit', false); ?>
        </form>

        <h2>Scan queue</h2>
        <?php if ($processing > 0) : ?>
            <div class="notice notice-warning"><p><?php echo esc_html((string) $processing); ?> item(s) are marked as processing. Do not rescan or retry blindly; check the latest activity and staged files before taking recovery action.</p></div>
        <?php endif; ?>
        <ul>
            <?php foreach (array('pending', 'processing', 'completed', 'quarantined', 'skipped', 'failed', 'warning', 'superseded') as $status) : ?>
                <li><?php echo esc_html(ucfirst($status)); ?>: <?php echo esc_html(isset($counts[$status]) ? (string) $counts[$status]->total : '0'); ?></li>
            <?php endforeach; ?>
        </ul>

        <?php if ($pending > 0) : ?>
            <?php $next_item = $wpdb->get_row("SELECT * FROM {$tables['items']} WHERE status = 'pending' ORDER BY id ASC LIMIT 1"); ?>
            <?php if ($next_item) : ?>
                <p>
                    <strong>Next item:</strong> <?php echo esc_html($next_item->item_type); ?><br>
                    <?php echo 'cleanup' === $next_item->item_type ? esc_html($next_item->source_path) : loopis_image_migration_format_path($next_item->source_path); ?>
                    <?php if ('convert' === $next_item->item_type) : ?>
                        →<br><?php echo loopis_image_migration_format_path($next_item->target_path); ?>
                    <?php elseif ('orphan' === $next_item->item_type) : ?>
                        <br>Will be moved to quarantine for deletion.
                    <?php endif; ?>
                </p>
            <?php endif; ?>
            <form id="loopis-image-migration-process-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="loopis_image_migration_process">
                <?php wp_nonce_field('loopis_image_migration_process'); ?>
                <p><label><input type="checkbox" name="confirm_backup" value="1" required> I have verified a current database and uploads backup for this site, and authorize the selected processing action. The queue includes deleting parentless attachment records and empty upload folders.</label></p>
                <?php submit_button('Start processing queue', 'primary', 'submit', false); ?>
                <button type="submit" class="button" name="process_one" value="1">Process one item</button>
                <button type="button" class="button hidden" id="loopis-image-migration-stop" hidden>Stop after current item</button>
                <p id="loopis-image-migration-progress" aria-live="polite"></p>
            </form>
        <?php endif; ?>

        <h2>Recent activity</h2>
        <table class="widefat striped">
            <thead><tr><th>Time (UTC)</th><th>Item</th><th>Status</th><th>Details</th></tr></thead>
            <tbody>
                <?php if (is_wp_error($logs)) : ?>
                    <tr><td colspan="4">Activity could not be loaded from the log file.</td></tr>
                <?php elseif (empty($logs)) : ?>
                    <tr><td colspan="4">No scan or migration activity yet.</td></tr>
                <?php else : ?>
                    <?php foreach ($logs as $log) : ?>
                        <tr>
                            <td><?php echo esc_html($log->created_at); ?></td>
                            <td><?php echo esc_html((string) $log->item_id); ?></td>
                            <td><?php echo esc_html($log->status); ?></td>
                            <td><?php echo nl2br(esc_html($log->message)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <h2>Settings</h2>
    <p><strong>Image format:</strong><br>WebP (quality 75) with maximum dimensions using the site’s <code>large_size_w</code> and <code>large_size_h</code> options (<?php echo esc_html((string) get_option('large_size_w')); ?> × <?php echo esc_html((string) get_option('large_size_h')); ?>).</p>
        <p><strong>File naming:</strong><br>Featured and secondary images for regular posts (gifts) are named <code>p{post-id}-{slot}.webp</code>, with slots 1–3.</p>
        <p>Other regular post attachments use <code>{post-title}-{attachment-id}.webp</code>; custom post type images use <code>{post-title}.webp</code>. If the title is empty, <code>post-{post-id}</code> is used instead. A numeric suffix may be added to full-size filenames to avoid collisions.</p>
        <p>Thumbnails are named by adding dimensions from <code>thumbnail_size_w</code> and <code>thumbnail_size_h</code> options (<?php echo esc_html((string) get_option('thumbnail_size_w')); ?>x<?php echo esc_html((string) get_option('thumbnail_size_h')); ?>) to the filename of the original image.</p>
        <p><strong>Upload directory:</strong><br><code><?php echo esc_html($upload['basedir']); ?></code></p>
        <p><strong>Quarantine:</strong><br> <code><?php echo esc_html(trailingslashit($upload['basedir']) . 'migration/quarantine/'); ?></code>.</p>
        <?php if (!is_wp_error($log_file)) : ?>
            <p><strong>Activity log file:</strong><br> <code><?php echo esc_html($log_file); ?></code>.</p>
        <?php endif; ?>
        <p><strong>Before using:</strong><br>
        Take and verify a backup of this site’s database and uploads directory! Conversion deletes previous image files after WordPress metadata is updated. Parentless attachment records will be deleted and their files moved to quarantine; this can break pages or settings that reference those images. Files without attachment records will also be moved to quarantine. After the queue finishes, empty folders in this site’s uploads will be removed. The <code>sites</code>, <code>wp-statistics</code>, migration and staging directories are excluded from scanning and cleanup.</p>

    <script>
    (function() {
        const form = document.getElementById('loopis-image-migration-process-form');
        if (!form) {
            return;
        }

        const progress = document.getElementById('loopis-image-migration-progress');
        const stopButton = document.getElementById('loopis-image-migration-stop');
        const startButton = form.querySelector('input[type="submit"]');
        const oneButton = form.querySelector('button[name="process_one"]');
        let stopRequested = false;

        stopButton.addEventListener('click', function() {
            stopRequested = true;
            progress.textContent = 'Stopping after the current item...';
            stopButton.disabled = true;
        });

        form.addEventListener('submit', async function(event) {
            event.preventDefault();
            if (!form.reportValidity()) {
                return;
            }

            const processOne = event.submitter === oneButton;
            stopRequested = false;
            startButton.disabled = true;
            oneButton.disabled = true;
            stopButton.hidden = processOne;
            stopButton.classList.toggle('hidden', processOne);
            progress.textContent = 'Processing the current item...';
            let finished = false;
            let failed = false;

            try {
                while (!stopRequested) {
                    const body = new FormData(form);
                    body.set('action', 'loopis_image_migration_process_ajax');
                    const response = await fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body });
                    const result = await response.json();

                    if (!response.ok || !result.success) {
                        throw new Error(result.data && result.data.message ? result.data.message : 'Migration request failed.');
                    }

                    const data = result.data;
                    progress.textContent = (data.item_id ? 'Item ' + data.item_id + ' (' + data.status + '): ' + data.message + ' ' : '') + data.pending + ' item(s) remain.';
                    if (processOne || !data.pending) {
                        finished = true;
                        break;
                    }
                }
            } catch (error) {
                failed = true;
                progress.textContent = 'Stopped: ' + error.message;
            } finally {
                startButton.disabled = false;
                oneButton.disabled = false;
                stopButton.hidden = true;
                stopButton.classList.add('hidden');
                stopButton.disabled = false;
                if (!failed && (finished || stopRequested)) {
                    window.location.reload();
                }
            }
        });
    })();
    </script>
    <?php
}

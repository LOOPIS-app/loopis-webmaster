<?php
/**
 * Render the Image Conversion administration page for the current site.
 *
 * Provides scanning and processing controls, a queue preview, backup
 * confirmation, and recent activity from the conversion log.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Escape a file path and emphasize its filename for the queue preview. */
function loopis_image_conversion_format_path($path) {
    if ('' === $path) {
        return '';
    }

    $filename = wp_basename($path);
    $directory = substr($path, 0, strlen($path) - strlen($filename));
    return esc_html($directory) . '<strong>' . esc_html($filename) . '</strong>';
}

/** Render the current site's scan, queue, backup confirmation, and activity log. */
function loopis_image_conversion_render_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    global $wpdb;
    $tables = loopis_image_conversion_tables();
    $counts = $wpdb->get_results("SELECT status, COUNT(*) AS total FROM {$tables['items']} WHERE item_type = 'convert' GROUP BY status", OBJECT_K);
    $pending = isset($counts['pending']) ? (int) $counts['pending']->total : 0;
    $scan_ready = '1' === get_option('loopis_image_conversion_scan_ready');
    $processing = isset($counts['processing']) ? (int) $counts['processing']->total : 0;
    $upload = wp_upload_dir();
    $log_error = get_transient('loopis_image_conversion_log_error');
    $log_file = loopis_image_conversion_log_file();
    $logs = is_wp_error($log_file) ? $log_file : loopis_image_conversion_recent_activity($log_file);
    ?>
    <div class="wrap">
        <h1>LOOPIS Image Conversion</h1>
        <?php if ($log_error || is_wp_error($log_file)) : ?>
            <div class="notice notice-error"><p>Activity file logging error: <?php echo esc_html(is_wp_error($log_file) ? $log_file->get_error_message() : $log_error); ?></p></div>
        <?php endif; ?>
        <?php if (is_wp_error($logs) && !is_wp_error($log_file)) : ?>
            <div class="notice notice-error"><p>Activity file reading error: <?php echo esc_html($logs->get_error_message()); ?></p></div>
        <?php endif; ?>
        <p><strong>💡 This tool will convert eligible image attachments to WebP according to the information below.</strong></p>
        <p>This tool operates on the current site only: <strong><?php echo esc_html(get_bloginfo('name')); ?></strong> (site ID <?php echo esc_html((string) get_current_blog_id()); ?>).</p>
        <?php loopis_image_tools_render_scan('conversion'); ?>

        <h2>Conversion queue</h2>
        <?php if (!$scan_ready) : ?>
            <div class="notice notice-warning"><p>The conversion scan is incomplete or unverified. Processing is disabled until a scan completes.</p></div>
        <?php endif; ?>
        <?php if ($processing > 0) : ?>
            <div class="notice notice-warning"><p><?php echo esc_html((string) $processing); ?> item(s) are marked as processing. Do not rescan or retry blindly; check the latest activity and staged files before taking recovery action.</p></div>
        <?php endif; ?>
        <ul>
            <?php foreach (array('pending', 'processing', 'completed', 'quarantined', 'skipped', 'failed', 'warning', 'superseded') as $status) : ?>
                <li><?php echo esc_html(ucfirst($status)); ?>: <?php echo esc_html(isset($counts[$status]) ? (string) $counts[$status]->total : '0'); ?></li>
            <?php endforeach; ?>
        </ul>

        <?php if ($pending > 0 && $scan_ready) : ?>
            <?php $next_item = $wpdb->get_row("SELECT * FROM {$tables['items']} WHERE status = 'pending' AND item_type = 'convert' ORDER BY id ASC LIMIT 1"); ?>
            <?php if ($next_item) : ?>
                <p>
                    <strong>Next item:</strong> <?php echo esc_html($next_item->item_type); ?><br>
                    <?php echo loopis_image_conversion_format_path($next_item->source_path); ?>
                        →<br><?php echo loopis_image_conversion_format_path($next_item->target_path); ?>
                </p>
            <?php endif; ?>
            <form id="loopis-image-conversion-process-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="loopis_image_conversion_process">
                <?php wp_nonce_field('loopis_image_conversion_process'); ?>
                <p><label><input type="checkbox" name="confirm_backup" value="1" required> I have verified a current database and uploads backup for this site, and authorize conversion.</label></p>
                <?php submit_button('Start processing queue', 'primary', 'submit', false); ?>
                <button type="submit" class="button" name="process_one" value="1">Process one item</button>
                <button type="button" class="button hidden" id="loopis-image-conversion-stop" hidden>Stop after current item</button>
                <p id="loopis-image-conversion-progress" aria-live="polite"></p>
            </form>
        <?php endif; ?>

        <h2>Recent activity</h2>
        <table class="widefat striped">
            <thead><tr><th>Time (UTC)</th><th>Item</th><th>Status</th><th>Details</th></tr></thead>
            <tbody>
                <?php if (is_wp_error($logs)) : ?>
                    <tr><td colspan="4">Activity could not be loaded from the log file.</td></tr>
                <?php elseif (empty($logs)) : ?>
                    <tr><td colspan="4">No scan or conversion activity yet.</td></tr>
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
        <?php loopis_image_tools_render_reset_links('conversion'); ?>
    </div>
    <h2>Information</h2>
    <p>Select a year and one existing month, or all months. Scans run in batches of at most 100 files.</p>
    <p>Each pending attachment is processed once, including its existing source and associated files. The scan total counts those files per attachment; files shared between attachments may be counted more than once. WordPress generates new image sizes during conversion, so output file counts may differ.</p>
    <p>Only current-site content is checked. The <code>sites</code>, <code>wp-statistics</code>, cleanup, old quarantine and staging directories are excluded.</p>
    <p><strong>Image format: </strong>WebP (quality 75) with maximum dimensions using the site’s <code>large_size_w</code> and <code>large_size_h</code> options (<?php echo esc_html((string) get_option('large_size_w')); ?> × <?php echo esc_html((string) get_option('large_size_h')); ?>).</p>
    <p><strong>File naming: </strong>Featured and secondary images for regular posts (gifts) are named <code>p{post-id}-{slot}.webp</code>, with slots 1–3. Other regular post attachments use <code>{post-title}-{attachment-id}.webp</code>; custom post type images use <code>{post-title}.webp</code>. If the title is empty, <code>post-{post-id}</code> is used instead. A numeric suffix may be added to full-size filenames to avoid collisions. Thumbnails are named by adding dimensions from <code>thumbnail_size_w</code> and <code>thumbnail_size_h</code> options (<?php echo esc_html((string) get_option('thumbnail_size_w')); ?>x<?php echo esc_html((string) get_option('thumbnail_size_h')); ?>) to the filename of the original image.</p>
    <p><strong>Upload directory:</strong><br><code><?php echo esc_html($upload['basedir']); ?></code></p>
    <?php if (!is_wp_error($log_file)) : ?>
    <p><strong>Activity log file:</strong><br> <code><?php echo esc_html($log_file); ?></code>.</p>
    <?php endif; ?>
    <p><strong>Before using:</strong></p>
    <p>Make sure you have a backup of the site’s database and uploads directory! Conversion deletes previous image files after WordPress metadata is updated. There is no recovery interface.</p>

    <script>
    (function() {
        const form = document.getElementById('loopis-image-conversion-process-form');
        if (!form) {
            return;
        }

        const progress = document.getElementById('loopis-image-conversion-progress');
        const stopButton = document.getElementById('loopis-image-conversion-stop');
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
            progress.classList.add('loopis-image-processing');
            progress.textContent = 'Processing the current item...';
            let finished = false;
            let failed = false;

            try {
                while (!stopRequested) {
                    const body = new FormData(form);
                    body.set('action', 'loopis_image_conversion_process_ajax');
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
                progress.classList.remove('loopis-image-processing');
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

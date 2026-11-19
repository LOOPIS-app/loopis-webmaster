<?php
/** Render the separate image cleanup review and queue. */

if (!defined('ABSPATH')) {
    exit;
}

/** Map a review path to its current site's upload URL without exposing other files. */
function loopis_image_cleanup_review_file($path, $upload) {
    $result = array('display' => $path, 'url' => '', 'error' => 'Preview unavailable: file is missing or outside this site’s uploads.');
    if (!empty($upload['error']) || empty($upload['basedir']) || empty($upload['baseurl'])) {
        $result['error'] = 'Preview unavailable: the uploads directory or URL could not be resolved.';
        return $result;
    }
    $url_path = wp_parse_url($upload['baseurl'], PHP_URL_PATH);
    if (!is_string($url_path)) {
        $result['error'] = 'Preview unavailable: the uploads URL is invalid.';
        return $result;
    }
    $url_path = trim(rawurldecode($url_path), '/');
    $parts = explode('/', $url_path);
    $uploads_index = array_search('uploads', $parts, true);
    $display_base = false === $uploads_index ? $url_path : implode('/', array_slice($parts, $uploads_index));
    $base = realpath($upload['basedir']);
    $file = realpath($path);
    $normalized_path = loopis_image_conversion_normalize_path($path);
    foreach (array($base, $upload['basedir']) as $root) {
        if (!$root) {
            continue;
        }
        $prefix = trailingslashit(loopis_image_conversion_normalize_path($root));
        if (strpos($normalized_path, $prefix) === 0) {
            $result['display'] = ($display_base === '' ? 'uploads' : $display_base) . '/' . substr($normalized_path, strlen($prefix));
            break;
        }
    }
    if (!$base || !$file || is_link($path) || !is_file($file) || !loopis_image_conversion_path_is_in_scope($file, $base)) {
        return $result;
    }
    $base = trailingslashit(loopis_image_conversion_normalize_path($base));
    $relative = substr(loopis_image_conversion_normalize_path($file), strlen($base));
    $result['display'] = ($display_base === '' ? 'uploads' : $display_base) . '/' . $relative;
    $result['url'] = trailingslashit($upload['baseurl']) . implode('/', array_map('rawurlencode', explode('/', $relative)));
    $result['error'] = '';
    return $result;
}

function loopis_image_cleanup_render_review_file($path, $upload) {
    $file = loopis_image_cleanup_review_file($path, $upload);
    if ($file['url'] === '') {
        echo esc_html($file['display']) . '<br><span class="description">' . esc_html($file['error']) . '</span>';
        return;
    }
    ?>
    <span class="loopis-cleanup-preview">
        <button type="button" class="button-link loopis-cleanup-preview-trigger" data-preview-url="<?php echo esc_url($file['url']); ?>" aria-label="<?php echo esc_attr('Preview ' . $file['display']); ?>"><?php echo esc_html($file['display']); ?></button>
        <span class="loopis-cleanup-preview-popup">
            <img alt="" hidden>
            <span class="loopis-cleanup-preview-status">Loading preview…</span>
        </span>
    </span>
    <?php
}

function loopis_image_cleanup_render_page() {
    if (!is_super_admin() || !current_user_can('manage_options')) {
        return;
    }
    global $wpdb;
    $table = loopis_image_cleanup_table();
    $counts = $wpdb->get_results("SELECT status, COUNT(*) AS total FROM $table GROUP BY status", OBJECT_K);
    if ('' !== $wpdb->last_error) {
        wp_die(esc_html('Could not read the cleanup queue: ' . $wpdb->last_error));
    }
    $review = isset($counts['review']) ? (int) $counts['review']->total : 0;
    $pending = isset($counts['pending']) ? (int) $counts['pending']->total : 0;
    $processing = isset($counts['processing']) ? (int) $counts['processing']->total : 0;
    $ready = '1' === get_option('loopis_image_cleanup_scan_ready');
    $pages = max(1, (int) ceil($review / 50));
    $page = isset($_GET['review_page']) ? min($pages, max(1, absint($_GET['review_page']))) : 1;
    $offset = ($page - 1) * 50;
    $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE status = 'review' ORDER BY id ASC LIMIT 50 OFFSET %d", $offset));
    if ('' !== $wpdb->last_error) {
        wp_die(esc_html('Could not read cleanup candidates: ' . $wpdb->last_error));
    }
    $log_file = loopis_image_conversion_log_file('cleanup');
    $log_error = get_transient('loopis_image_cleanup_log_error');
    $logs = is_wp_error($log_file) ? $log_file : loopis_image_conversion_recent_activity($log_file);
    $upload = wp_upload_dir();
    ?>
    <div class="wrap">
        <h1>LOOPIS Image Cleanup</h1>
        <p><strong>💡 This tool will find and delete files not referenced by this site's content or metadata.</strong></p>
        <p>This tool operates on the current site only: <strong><?php echo esc_html(get_bloginfo('name')); ?></strong> (site ID <?php echo esc_html((string) get_current_blog_id()); ?>).</p>
        
        <?php if (!$ready) : ?>
            <div class="notice notice-warning"><p>There is no complete cleanup scan ready for review or processing. Resume the current scan or start a new one.</p></div>
        <?php endif; ?>
        <?php if ($processing) : ?>
            <div class="notice notice-warning"><p>An item is still marked processing. Inspect its files and activity before continuing.</p></div>
        <?php endif; ?>
        <?php loopis_image_tools_render_scan('cleanup'); ?>

        <h2>Review</h2>
        <p><?php echo esc_html((string) $review); ?> awaiting review; <?php echo esc_html((string) (isset($counts['skipped']) ? $counts['skipped']->total : 0)); ?> skipped; <?php echo esc_html((string) $pending); ?> queued.</p>
        <p>Review candidates are images with no references found in this site's post content or metadata, and no conflicting attachment registrations. They may be unused attachments or unregistered files. Skip files you want to keep.</p>
        <?php if ($ready && $review) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="loopis_image_cleanup_review">
                <input type="hidden" name="decision" value="accept">
                <?php wp_nonce_field('loopis_image_cleanup_review'); ?>
                <?php submit_button('Accept all', 'primary', 'submit', false); ?>
            </form>
            <p>"Accept all" adds the remaining candidates to the cleanup queue.</p>
            <style>
                .loopis-cleanup-preview { position: relative; display: inline-block; }
                .loopis-cleanup-preview-trigger { text-align: left; overflow-wrap: anywhere; }
                .loopis-cleanup-preview-popup { display: none; position: absolute; z-index: 20; top: 100%; left: 0; padding: 8px; background: #fff; border: 1px solid #8c8f94; box-shadow: 0 2px 6px rgba(0,0,0,.2); max-width: min(260px, 70vw); }
                .loopis-cleanup-preview:hover .loopis-cleanup-preview-popup, .loopis-cleanup-preview:focus-within .loopis-cleanup-preview-popup { display: block; }
                .loopis-cleanup-preview-popup img { max-width: 240px; max-height: 200px; width: auto; height: auto; }
            </style>
            <table class="widefat striped">
                <thead><tr><th>Image</th><th>Type</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach ($items as $item) : ?>
                        <tr>
                            <td><?php loopis_image_cleanup_render_review_file($item->source_path, $upload); ?></td>
                            <td><?php echo esc_html($item->item_type === 'attachment' ? 'Attachment #' . $item->attachment_id : 'Unregistered file'); ?></td>
                            <td>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <input type="hidden" name="action" value="loopis_image_cleanup_review">
                                    <input type="hidden" name="decision" value="skip">
                                    <input type="hidden" name="item_id" value="<?php echo esc_attr((string) $item->id); ?>">
                                    <input type="hidden" name="review_page" value="<?php echo esc_attr((string) $page); ?>">
                                    <?php wp_nonce_field('loopis_image_cleanup_review'); ?>
                                    <button class="button" type="submit">Skip</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p>Page <?php echo esc_html((string) $page); ?> of <?php echo esc_html((string) $pages); ?>.
                <?php if ($page > 1) : ?><a href="<?php echo esc_url(admin_url('admin.php?page=loopis-image-cleanup&review_page=' . ($page - 1))); ?>">Previous</a><?php endif; ?>
                <?php if ($page < $pages) : ?><a href="<?php echo esc_url(admin_url('admin.php?page=loopis-image-cleanup&review_page=' . ($page + 1))); ?>">Next</a><?php endif; ?>
            </p>
        <?php endif; ?>

        <h2>Cleanup queue</h2>
        <p><?php echo esc_html((string) $pending); ?> pending; <?php echo esc_html((string) (isset($counts['quarantined']) ? $counts['quarantined']->total : 0)); ?> quarantined; <?php echo esc_html((string) (isset($counts['failed']) ? $counts['failed']->total : 0)); ?> failed.</p>
        <?php if ($ready && $pending && !$processing) : ?>
            <form id="loopis-image-cleanup-process-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="loopis_image_cleanup_process">
                <?php wp_nonce_field('loopis_image_cleanup_process'); ?>
                <p><label><input type="checkbox" name="confirm_backup" value="1" required> I have verified a current database and uploads backup and authorize cleanup.</label></p>
                <?php submit_button('Start cleanup queue', 'primary', 'submit', false); ?>
                <button type="submit" class="button" name="process_one" value="1">Process one item</button>
                <button type="button" class="button" id="loopis-image-cleanup-stop" hidden>Stop after current item</button>
                <p id="loopis-image-cleanup-progress" aria-live="polite"></p>
            </form>
        <?php endif; ?>
        <h2>Empty folders?</h2>
        <p>Remove empty folders separately after reviewing and processing images. Folders containing files or symlinks are kept.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="loopis_image_cleanup_directories">
            <?php wp_nonce_field('loopis_image_cleanup_directories'); ?>
            <p><label><input type="checkbox" name="confirm_backup" value="1" required> I have verified a current database and uploads backup.</label></p>
            <?php submit_button('Remove empty folders', 'secondary', 'submit', false); ?>
        </form>
        <h2>Recent activity</h2>
        <?php if ($log_error) : ?>
            <div class="notice notice-error"><p>Activity file logging error: <?php echo esc_html($log_error); ?></p></div>
        <?php endif; ?>
        <?php if (is_wp_error($logs)) : ?>
            <div class="notice notice-error"><p><?php echo esc_html($logs->get_error_message()); ?></p></div>
        <?php else : ?>
            <table class="widefat striped"><thead><tr><th>Time (UTC)</th><th>Status</th><th>Details</th></tr></thead><tbody>
                <?php if (empty($logs)) : ?>
                    <tr><td colspan="3">No scan or cleanup activity yet.</td></tr>
                <?php else : ?>
                    <?php foreach ($logs as $log) : ?>
                        <tr><td><?php echo esc_html($log->created_at); ?></td><td><?php echo esc_html($log->status); ?></td><td><?php echo nl2br(esc_html($log->message)); ?></td></tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody></table>
        <?php endif; ?>
        <?php loopis_image_tools_render_reset_links('cleanup'); ?>
    </div>

    <h2>Information</h2>
    <p>Select a year and one existing month, or all months. Scans run in batches of at most 100 files.</p>
    <p>References are checked across the entire site. Attachment IDs identify media records; the number in a gift image filename identifies the post ID.</p>
    <p>Only current-site content is checked. The <code>sites</code>, <code>wp-statistics</code>, cleanup, old quarantine and staging directories are excluded.</p>
    <p><strong>Upload directory:</strong><br><code><?php echo esc_html($upload['basedir']); ?></code></p>
    <?php if (!is_wp_error($log_file)) : ?>
    <p><strong>Activity log file:</strong><br> <code><?php echo esc_html($log_file); ?></code>.</p>
    <?php endif; ?>
    <p><strong>Before using:</strong></p>
    <p>Make sure you have a backup of the site’s database and uploads directory! Files are moved to quarantine and unused attachment records are deleted. There is no recovery interface.</p>
    <p>Usage and file changes are checked immediately before moving. Normal post editing is not locked, so a new reference can still be added after the check. Pause editing during processing to eliminate that race.</p>
    <script>
    (function() {
        function loadPreview(event) {
            const trigger = event.target.closest('.loopis-cleanup-preview-trigger');
            if (!trigger || trigger.dataset.loaded) return;
            const popup = trigger.parentNode.querySelector('.loopis-cleanup-preview-popup');
            const image = popup.querySelector('img');
            const status = popup.querySelector('.loopis-cleanup-preview-status');
            trigger.dataset.loaded = '1';
            image.addEventListener('load', function() { image.hidden = false; status.hidden = true; });
            image.addEventListener('error', function() { status.textContent = 'Preview unavailable for this image.'; });
            image.src = trigger.dataset.previewUrl;
        }
        document.addEventListener('pointerover', loadPreview);
        document.addEventListener('focusin', loadPreview);
    })();
    (function() {
        const form = document.getElementById('loopis-image-cleanup-process-form');
        if (!form) return;
        const stop = document.getElementById('loopis-image-cleanup-stop');
        const progress = document.getElementById('loopis-image-cleanup-progress');
        let stopping = false;
        stop.addEventListener('click', function() { stopping = true; stop.disabled = true; });
        form.addEventListener('submit', async function(event) {
            event.preventDefault();
            if (!form.reportValidity()) return;
            const one = event.submitter && event.submitter.name === 'process_one';
            const buttons = form.querySelectorAll('input[type="submit"], button[name="process_one"]');
            buttons.forEach(function(button) { button.disabled = true; });
            stopping = false;
            stop.hidden = one;
            progress.classList.add('loopis-image-processing');
            progress.textContent = 'Processing...';
            try {
                do {
                    const body = new FormData(form);
                    body.set('action', 'loopis_image_cleanup_process_ajax');
                    const response = await fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body });
                    const result = await response.json();
                    if (!response.ok || !result.success) throw new Error(result.data && result.data.message ? result.data.message : 'Cleanup request failed.');
                    progress.textContent = result.data.message + ' ' + result.data.pending + ' item(s) remain.';
                    if (one || !result.data.pending) break;
                } while (!stopping);
                window.location.reload();
            } catch (error) {
                progress.textContent = 'Stopped: ' + error.message;
                buttons.forEach(function(button) { button.disabled = false; });
                stop.hidden = true;
                stop.disabled = false;
            } finally {
                progress.classList.remove('loopis-image-processing');
            }
        });
    })();
    </script>
    <?php
}

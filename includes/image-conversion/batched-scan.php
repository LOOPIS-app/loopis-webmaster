<?php
/** Month-by-month scanning shared by the image tools. */

if (!defined('ABSPATH')) {
    exit;
}

function loopis_image_tools_scan_option($tool) {
    return 'loopis_image_' . $tool . '_scan_state';
}

function loopis_image_tools_save_scan($tool, $state) {
    $option = loopis_image_tools_scan_option($tool);
    update_option($option, $state, false);
    if (get_option($option) !== $state) {
        return new WP_Error('scan_state_failed', 'Could not save scan progress. Start a new scan before processing.');
    }
    return true;
}

function loopis_image_tools_scan_shared_summary($shared) {
    return $shared > 0 ? sprintf('; %d files with multiple attachment registrations left untouched', $shared) : '';
}

function loopis_image_tools_scan_conversion_summary($state) {
    if (!isset($state['pending_attachments'], $state['pending_source_files'], $state['skipped_attachments'])) {
        return '; attachment file totals unavailable for this scan';
    }
    return sprintf('; %d pending attachments (%d existing source and associated files); %d skipped attachments',
        $state['pending_attachments'], $state['pending_source_files'], $state['skipped_attachments']);
}

function loopis_image_tools_processing_guard($tool) {
    foreach (array('conversion', 'cleanup') as $other) {
        $state = get_option(loopis_image_tools_scan_option($other));
        if (is_array($state) && in_array($state['status'], array('active', 'batch'), true)) {
            return new WP_Error('scan_in_progress', 'An image scan is incomplete. Complete it or reset its queue before processing.');
        }
        if ($tool === $other && is_array($state) && $state['status'] !== 'complete') {
            return new WP_Error('scan_not_ready', 'Run a complete scan before processing.');
        }
    }
    return true;
}

function loopis_image_tools_check_item_scope($tool, $files) {
    $state = get_option(loopis_image_tools_scan_option($tool));
    if (!is_array($state)) {
        return true;
    }
    if ($state['status'] !== 'complete' || empty($state['year'])) {
        return new WP_Error('scan_not_ready', 'Run a complete scan before processing.');
    }
    $selected_month = isset($state['selected_month']) ? $state['selected_month'] : '';
    if (!is_string($selected_month) || ($selected_month !== '' && !preg_match('/^(0[1-9]|1[0-2])$/D', $selected_month))) {
        return new WP_Error('invalid_scan_scope', 'The scan scope is invalid; start a new scan.');
    }
    foreach ($files as $file) {
        $month = basename(dirname($file));
        if ($selected_month !== '' && $month !== $selected_month) {
            return new WP_Error('item_outside_month', 'A queued file is outside the selected month; rescan.');
        }
        $directory = loopis_image_tools_month_directory($state['year'], $month);
        if (is_wp_error($directory)) {
            return $directory;
        }
        if (!$directory || is_link($file) || realpath(dirname($file)) !== $directory) {
            return new WP_Error('item_outside_year', 'A queued file is outside the selected year or a standard month; rescan.');
        }
    }
    return true;
}

function loopis_image_tools_scan_start($tool, $year, $month = '') {
    global $wpdb;
    if (!in_array($tool, array('conversion', 'cleanup'), true)) {
        return new WP_Error('invalid_tool', 'Invalid image tool.');
    }
    $year_dir = loopis_image_tools_year_directory($year);
    if (is_wp_error($year_dir)) {
        return $year_dir;
    }
    if (!is_string($month)) {
        return new WP_Error('invalid_month', 'Select a valid upload month.');
    }
    if ($month !== '') {
        $month_dir = loopis_image_tools_month_directory($year, $month);
        if (is_wp_error($month_dir)) {
            return $month_dir;
        }
        if (!$month_dir) {
            return new WP_Error('month_unavailable', 'The selected upload month does not exist in this year.');
        }
    }
    $lock = loopis_image_conversion_acquire_lock();
    if (is_wp_error($lock)) {
        return $lock;
    }
    try {
        foreach (array('conversion', 'cleanup') as $other) {
            $table = $other === 'conversion' ? loopis_image_conversion_tables()['items'] : loopis_image_cleanup_table();
            $processing = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'processing'");
            if ('' !== $wpdb->last_error) {
                return new WP_Error('queue_read_failed', 'Could not check the queues: ' . $wpdb->last_error);
            }
            if ((int) $processing) {
                return new WP_Error('processing_incomplete', 'An item is marked processing; inspect it before scanning.');
            }
            $other_state = get_option(loopis_image_tools_scan_option($other));
            if ($other !== $tool && is_array($other_state) && in_array($other_state['status'], array('active', 'batch'), true)) {
                return new WP_Error('scan_in_progress', 'The other image tool has an unfinished scan. Complete or reset it first.');
            }
        }
        update_option('loopis_image_' . $tool . '_scan_ready', '0', false);
        if ('0' !== get_option('loopis_image_' . $tool . '_scan_ready')) {
            return new WP_Error('scan_state_failed', 'Could not disable the previous scan.');
        }
        $state = array('id' => wp_generate_uuid4(), 'year' => $year, 'selected_month' => $month, 'month' => $month === '' ? 1 : (int) $month, 'cursor' => '', 'files' => 0, 'candidates' => 0, 'shared' => 0, 'status' => 'batch');
        if ($tool === 'conversion') {
            $state['pending_attachments'] = 0;
            $state['pending_source_files'] = 0;
            $state['skipped_attachments'] = 0;
        }
        $saved = loopis_image_tools_save_scan($tool, $state);
        if (is_wp_error($saved)) {
            return $saved;
        }
        $table = $tool === 'conversion' ? loopis_image_conversion_tables()['items'] : loopis_image_cleanup_table();
        $where = $tool === 'conversion' ? '' : " WHERE status IN ('review', 'skipped', 'pending')";
        if (false === $wpdb->query("UPDATE $table SET status = 'superseded'$where")) {
            return new WP_Error('queue_write_failed', 'Could not archive the previous queue: ' . $wpdb->last_error);
        }
        $state['status'] = 'active';
        $saved = loopis_image_tools_save_scan($tool, $state);
        return is_wp_error($saved) ? $saved : $state;
    } finally {
        loopis_image_conversion_release_lock($lock);
    }
}

/** Find exact full-size attachment registrations for a file. */
function loopis_image_tools_file_attachments($relative, $absolute, $configured_absolute) {
    global $wpdb;
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
        WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%%'
        AND m.meta_key = '_wp_attached_file' AND m.meta_value IN (%s, %s, %s) ORDER BY p.ID",
        $relative,
        $absolute,
        $configured_absolute
    ));
    if ('' !== $wpdb->last_error || !is_array($ids)) {
        return new WP_Error('attachment_lookup_failed', 'Could not find image attachments: ' . $wpdb->last_error);
    }
    return array_map('intval', $ids);
}

function loopis_image_tools_scan_file($tool, $path, $year, $month, &$reserved) {
    global $wpdb;
    $relative = $year . '/' . $month . '/' . wp_basename($path);
    $upload = wp_upload_dir();
    $ids = loopis_image_tools_file_attachments($relative, $path, trailingslashit($upload['basedir']) . $relative);
    if (is_wp_error($ids)) {
        return $ids;
    }
    if (count($ids) > 1) {
        return 'shared';
    }
    $base = realpath($upload['basedir']);
    if (!$base || is_link($path) || !is_file($path) || !loopis_image_conversion_path_is_in_scope($path, $base)) {
        return new WP_Error('file_changed', 'A file changed during the scan; restart: ' . $path);
    }
    if ($tool === 'cleanup') {
        if (!$ids) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (!in_array($extension, array('jpg', 'jpeg', 'png', 'webp', 'heic', 'heif', 'heics', 'heifs', 'gif', 'avif', 'bmp', 'tif', 'tiff', 'svg'), true)) {
                return false;
            }
            return loopis_image_cleanup_add_candidate('file', 0, $path, array($path), $base);
        }
        $id = $ids[0];
        $source = get_attached_file($id);
        if (!$source || realpath($source) !== realpath($path)) {
            return new WP_Error('attachment_changed', 'An attachment changed during the scan; restart: ' . $path);
        }
        $files = loopis_image_cleanup_attachment_files($id, $source, $base);
        if (is_wp_error($files)) {
            return $files;
        }
        $month_dir = loopis_image_tools_month_directory($year, $month);
        if (is_wp_error($month_dir) || !$month_dir) {
            return is_wp_error($month_dir) ? $month_dir : new WP_Error('month_unavailable', 'The upload month disappeared.');
        }
        foreach ($files as $file) {
            if (is_link($file) || !loopis_image_conversion_path_is_inside($file, $month_dir)) {
                return false;
            }
        }
        return loopis_image_cleanup_add_candidate('attachment', $id, $source, $files, $base);
    }
    if (!$ids) {
        return false;
    }
    $id = $ids[0];
    $attachment = get_post($id);
    $source = get_attached_file($id);
    if (!$attachment || $attachment->post_type !== 'attachment' || !$source || realpath($source) !== realpath($path)) {
        return new WP_Error('attachment_changed', 'An attachment changed during the scan; restart: ' . $path);
    }
    $target = '';
    $status = 'skipped';
    $message = '';
    if (!(int) $attachment->post_parent) {
        $message = 'Parentless attachment is not eligible for conversion.';
    } elseif (!loopis_image_conversion_supported_mime($attachment->post_mime_type)) {
        $message = 'Image format is not supported by conversion; no conversion was attempted.';
    } else {
        $target = loopis_image_conversion_target_path($id, (int) $attachment->post_parent, $source, $reserved);
        if (is_wp_error($target)) {
            if ($target->get_error_code() === 'target_name_unavailable') {
                return new WP_Error('target_name_unavailable', $target->get_error_message() . ' Attachment #' . $id . ': ' . $source);
            }
            $message = $target->get_error_message();
            $target = '';
        } elseif (loopis_image_conversion_normalize_path($source) === loopis_image_conversion_normalize_path($target)
            && loopis_image_conversion_webp_is_within_limits($source, $attachment->post_mime_type, absint(get_option('large_size_w')), absint(get_option('large_size_h')))) {
            $message = 'Already a correctly named WebP within the configured dimensions; no conversion needed.';
        } elseif (!wp_image_editor_supports(array('mime_type' => 'image/webp'))) {
            $message = 'The active image editor cannot encode WebP.';
        } else {
            $status = 'pending';
        }
    }
    $file_count = 0;
    if ($status === 'pending') {
        $files = loopis_image_conversion_attachment_files($id, $source);
        if ('' !== $wpdb->last_error) {
            return new WP_Error('attachment_lookup_failed', 'Could not count files for attachment #' . $id . ': ' . $wpdb->last_error);
        }
        $file_count = count($files);
    }
    if (!loopis_image_conversion_insert_item('convert', $id, $source, $target, array(), $status, $message)) {
        return new WP_Error('queue_write_failed', 'Could not save a conversion item.');
    }
    return array('status' => $status, 'source_files' => $file_count);
}

function loopis_image_tools_scan_batch($tool, $id) {
    global $wpdb;
    if (!in_array($tool, array('conversion', 'cleanup'), true)) {
        return new WP_Error('invalid_tool', 'Invalid image tool.');
    }
    $lock = loopis_image_conversion_acquire_lock();
    if (is_wp_error($lock)) {
        return $lock;
    }
    try {
        $state = get_option(loopis_image_tools_scan_option($tool));
        if (!is_array($state) || !isset($state['id'], $state['status']) || $state['id'] !== $id) {
            return new WP_Error('stale_scan', 'This scan was replaced or reset. Reload the page.');
        }
        if ($state['status'] === 'complete') {
            return $state;
        }
        if ($state['status'] !== 'active') {
            return new WP_Error('interrupted_batch', 'The previous batch did not finish safely. Start a new scan.');
        }
        $state['status'] = 'batch';
        $saved = loopis_image_tools_save_scan($tool, $state);
        if (is_wp_error($saved)) {
            return $saved;
        }
        $month = sprintf('%02d', $state['month']);
        $selected_month = isset($state['selected_month']) ? $state['selected_month'] : '';
        if (!is_string($selected_month) || ($selected_month !== '' && (!preg_match('/^(0[1-9]|1[0-2])$/D', $selected_month) || $month !== $selected_month))) {
            return new WP_Error('invalid_scan_scope', 'The scan scope is invalid; start a new scan.');
        }
        $dir = loopis_image_tools_month_directory($state['year'], $month);
        if (is_wp_error($dir)) {
            return $dir;
        }
        if ($selected_month !== '' && !$dir) {
            return new WP_Error('month_unavailable', 'The selected upload month is no longer available; start a new scan.');
        }
        $page = loopis_image_tools_month_files($state['year'], $month, $state['cursor']);
        if (is_wp_error($page)) {
            return $page;
        }
        $reserved = array();
        if ($tool === 'conversion' && $dir) {
            $table = loopis_image_conversion_tables()['items'];
            $upload = wp_upload_dir();
            $configured_dir = trailingslashit($upload['basedir']) . $state['year'] . '/' . $month . '/';
            $targets = $wpdb->get_col($wpdb->prepare(
                "SELECT target_path FROM $table WHERE status IN ('pending', 'skipped') AND (target_path LIKE %s OR target_path LIKE %s)",
                $wpdb->esc_like(trailingslashit($dir)) . '%',
                $wpdb->esc_like($configured_dir) . '%'
            ));
            if ('' !== $wpdb->last_error || !is_array($targets)) {
                return new WP_Error('queue_read_failed', 'Could not read planned conversion filenames: ' . $wpdb->last_error);
            }
            foreach ($targets as $target) {
                $reserved[loopis_image_conversion_normalize_path(trailingslashit(realpath(dirname($target)) ?: dirname($target)) . wp_basename($target))] = true;
            }
        }
        $start = microtime(true);
        $processed = 0;
        foreach ($page['files'] as $name) {
            $result = loopis_image_tools_scan_file($tool, trailingslashit($dir) . $name, $state['year'], $month, $reserved);
            if (is_wp_error($result)) {
                return $result;
            }
            $state['cursor'] = $name;
            $state['files']++;
            $state['candidates'] += ($result === true || is_array($result)) ? 1 : 0;
            if (is_array($result) && isset($state['pending_attachments'], $state['pending_source_files'], $state['skipped_attachments'])) {
                if ($result['status'] === 'pending') {
                    $state['pending_attachments']++;
                    $state['pending_source_files'] += $result['source_files'];
                } else {
                    $state['skipped_attachments']++;
                }
            }
            $state['shared'] += $result === 'shared' ? 1 : 0;
            $processed++;
            if (microtime(true) - $start >= 5) {
                break;
            }
        }
        if ($processed === count($page['files']) && $page['complete']) {
            $state['month']++;
            $state['cursor'] = '';
        }
        if ($state['month'] > ($selected_month === '' ? 12 : (int) $selected_month)) {
            $message = sprintf('%s scan complete for %s%s: %d files checked; %d queue items%s%s.', ucfirst($tool), $state['year'], $selected_month === '' ? '' : '/' . $selected_month, $state['files'], $state['candidates'], $tool === 'conversion' ? loopis_image_tools_scan_conversion_summary($state) : '', loopis_image_tools_scan_shared_summary($state['shared']));
            $logged = loopis_image_conversion_log(0, $tool === 'cleanup' ? 'cleanup_scan' : 'scanned',
                $message, $tool);
            if (is_wp_error($logged)) {
                return $logged;
            }
            $state['status'] = 'complete';
            $saved = loopis_image_tools_save_scan($tool, $state);
            if (is_wp_error($saved)) {
                return $saved;
            }
            update_option('loopis_image_' . $tool . '_scan_ready', '1', false);
            if ('1' !== get_option('loopis_image_' . $tool . '_scan_ready')) {
                return new WP_Error('scan_state_failed', 'Could not enable the completed scan.');
            }
        } else {
            $state['status'] = 'active';
            $saved = loopis_image_tools_save_scan($tool, $state);
            if (is_wp_error($saved)) {
                return $saved;
            }
        }
        return $state;
    } finally {
        loopis_image_conversion_release_lock($lock);
    }
}

function loopis_image_tools_ajax_scan() {
    if (!is_super_admin() || !current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'You are not allowed to run this action.'), 403);
    }
    check_ajax_referer('loopis_image_tools_scan');
    $tool = isset($_POST['tool']) ? sanitize_key(wp_unslash($_POST['tool'])) : '';
    $operation = isset($_POST['operation']) ? sanitize_key(wp_unslash($_POST['operation'])) : '';
    if ($operation === 'start') {
        $year = isset($_POST['year']) ? wp_unslash($_POST['year']) : '';
        $month = isset($_POST['month']) ? wp_unslash($_POST['month']) : '';
        $result = loopis_image_tools_scan_start($tool, $year, $month);
    } elseif ($operation === 'batch') {
        $id = isset($_POST['scan_id']) ? sanitize_text_field(wp_unslash($_POST['scan_id'])) : '';
        $result = loopis_image_tools_scan_batch($tool, $id);
    } else {
        $result = new WP_Error('invalid_action', 'Invalid scan action.');
    }
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()), 409);
    }
    wp_send_json_success($result);
}
add_action('wp_ajax_loopis_image_tools_scan', 'loopis_image_tools_ajax_scan');

function loopis_image_tools_render_scan($tool) {
    $years = loopis_image_tools_year_directories();
    $state = get_option(loopis_image_tools_scan_option($tool));
    ?>
    <style>
        .loopis-image-scan-complete { color: #00802b; }
        .loopis-image-processing { color: #b32d2e; }
    </style>
    <?php
    if (is_wp_error($years)) {
        echo '<div class="notice notice-error"><p>' . esc_html($years->get_error_message()) . '</p></div>';
        return;
    }
    if (!$years) {
        echo '<div class="notice notice-warning"><p>No upload year folders are available.</p></div>';
        return;
    }
    ?>
    <form id="loopis-image-scan-form">
        <input type="hidden" name="action" value="loopis_image_tools_scan">
        <input type="hidden" name="tool" value="<?php echo esc_attr($tool); ?>">
        <?php wp_nonce_field('loopis_image_tools_scan'); ?>
        <label for="loopis-image-scan-year">Upload year:</label>
        <select id="loopis-image-scan-year" name="year">
            <?php foreach ($years as $year) : ?>
                <option value="<?php echo esc_attr($year); ?>" <?php selected(is_array($state) ? $state['year'] : '', $year); ?>><?php echo esc_html($year); ?></option>
            <?php endforeach; ?>
        </select>
        <label for="loopis-image-scan-month">Upload month:</label>
        <select id="loopis-image-scan-month" name="month">
            <option value="">All months</option>
            <?php foreach (array('January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December') as $index => $name) : ?>
                <?php $month = sprintf('%02d', $index + 1); ?>
                <option value="<?php echo esc_attr($month); ?>" <?php selected(is_array($state) && isset($state['selected_month']) ? $state['selected_month'] : '', $month); ?>><?php echo esc_html($month . ' - ' . $name); ?></option>
            <?php endforeach; ?>
        </select>
        <button class="button" type="submit">Start new scan</button>
        <?php if (is_array($state) && $state['status'] === 'active') : ?>
            <button class="button" type="button" id="loopis-image-scan-resume" data-scan-id="<?php echo esc_attr($state['id']); ?>">Resume <?php echo esc_html($state['year'] . (!empty($state['selected_month']) ? '/' . $state['selected_month'] : '')); ?> scan</button>
        <?php elseif (is_array($state) && $state['status'] === 'batch') : ?>
            <p>The last batch may have been interrupted. Wait for any open scan request to finish, then reload. If this message remains, start a new scan.</p>
        <?php endif; ?>
        <p id="loopis-image-scan-progress" aria-live="polite"<?php if (is_array($state) && $state['status'] === 'complete') : ?> class="loopis-image-scan-complete"<?php endif; ?>>
            <?php if (is_array($state) && $state['status'] === 'active') : ?>
                <?php echo esc_html(sprintf('Paused at %s/%02d; %d files checked.', $state['year'], $state['month'], $state['files'])); ?>
            <?php elseif (is_array($state) && $state['status'] === 'complete') : ?>
                <?php echo esc_html(sprintf('Scan of %s%s complete: %d files checked; %d queue items%s%s.', $state['year'], !empty($state['selected_month']) ? '/' . $state['selected_month'] : '', $state['files'], $state['candidates'], $tool === 'conversion' ? loopis_image_tools_scan_conversion_summary($state) : '', loopis_image_tools_scan_shared_summary($state['shared']))); ?>
            <?php endif; ?>
        </p>
        <noscript>JavaScript is required for batched scanning.</noscript>
    </form>
    <script>
    (function() {
        const form = document.getElementById('loopis-image-scan-form');
        const progress = document.getElementById('loopis-image-scan-progress');
        const resume = document.getElementById('loopis-image-scan-resume');
        let running = false;
        async function request(body, operation, id) {
            body.set('operation', operation);
            if (id) body.set('scan_id', id);
            const response = await fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body });
            const text = await response.text();
            let result;
            try {
                result = JSON.parse(text);
            } catch (error) {
                if (!(error instanceof SyntaxError)) throw error;
                throw new Error('The server returned a non-JSON response (HTTP ' + response.status + '). Check the staging PHP error log and the failed request response in your browser.');
            }
            if (!response.ok || !result.success) throw new Error(result.data && result.data.message ? result.data.message : 'Scan request failed.');
            return result.data;
        }
        async function scan(id) {
            if (running) return;
            const body = new FormData(form);
            running = true;
            progress.classList.remove('loopis-image-scan-complete');
            progress.classList.add('loopis-image-processing');
            progress.textContent = 'Scanning...';
            form.querySelectorAll('button, select').forEach(function(element) { element.disabled = true; });
            try {
                let state = id ? { id: id } : await request(body, 'start');
                while (state.status !== 'complete') {
                    state = await request(body, 'batch', state.id);
                    const month = state.selected_month || String(Math.min(state.month, 12)).padStart(2, '0');
                    let conversion = '';
                    if (body.get('tool') === 'conversion') {
                        conversion = state.pending_attachments !== undefined
                            ? '; ' + state.pending_attachments + ' pending attachments (' + state.pending_source_files + ' existing source and associated files); ' + state.skipped_attachments + ' skipped attachments'
                            : '; attachment file totals unavailable for this scan';
                    }
                    progress.textContent = state.year + '/' + month + ': ' + state.files + ' files checked; ' + state.candidates + ' queue items' + conversion + (state.shared > 0 ? '; ' + state.shared + ' files with multiple attachment registrations left untouched' : '') + '.';
                    progress.classList.toggle('loopis-image-scan-complete', state.status === 'complete');
                    progress.classList.toggle('loopis-image-processing', state.status !== 'complete');
                }
                window.location.reload();
            } catch (error) {
                progress.classList.remove('loopis-image-processing');
                progress.textContent = 'Scan stopped: ' + error.message + ' Reload to resume or start a new scan.';
                form.querySelectorAll('button, select').forEach(function(element) { element.disabled = false; });
                running = false;
            }
        }
        form.addEventListener('submit', function(event) {
            event.preventDefault();
            if (window.confirm('Start a new scan? The current queue will be superseded.')) scan('');
        });
        if (resume) resume.addEventListener('click', function() { scan(resume.dataset.scanId); });
    })();
    </script>
    <?php
}

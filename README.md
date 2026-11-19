# 😈 LOOPIS Webmaster
**Plugin providing tools for webmasters of LOOPIS.app**

## 💡 Status
New webmaster tools and content are added here when needed.

## Image tools

The Image Conversion and Image Cleanup pages have separate scans and queues.
Conversion handles eligible attachments; it never processes cleanup items
left in the former Image Migration queue.
For new conversion scans, the status separates pending and skipped attachments
and counts existing source and associated files for pending attachments.
This is a scan-time sum per attachment, not necessarily a count of distinct
files: shared files can be counted more than once. Files can change before
processing, and WordPress generates new image sizes, so the count is not a
prediction of output files. Scans started before this reporting was added do
not have the attachment-file totals; they remain usable.

Select an available year and either one existing month or all months to scan.
The tools scan standard `YYYY/01` through `YYYY/12` upload folders, one month
at a time, in batches of at most 100 files. Selecting one month limits its
queue and processing to that folder. Choosing a month that does not exist in
the selected year produces an error before the previous queue is changed.
Files directly under the year or in non-standard folders are not scanned;
unexpected subdirectories within a month stop the scan. A batch also yields
after approximately five seconds between files, although a single slow
database query or file operation can take longer. Scans require JavaScript.
If conversion cannot find a unique filename within its planning limit, the
scan stops and identifies the attachment; investigate it before starting a
new scan. An unexpected HTML server response should be investigated in the
PHP error log and browser Network response, even if the HTTP status is 200.
Reload the page and use **Resume** after a completed batch if the browser was
closed. If a batch itself was interrupted or failed, start a new scan; partial
queues cannot be reviewed or processed. Starting a new scan supersedes that
tool's previous queue. Clear or delete its tables to cancel an unfinished scan.
Avoid uploading or editing media during scans: changes to files already passed
by the cursor may not be included until a new scan.

Cleanup scans the selected folder(s) and conservatively checks the entire site's content
and metadata for image IDs and filenames, including generated and backup
sizes. Images with possible references are left out, even if the match is
unrelated. Other sites and excluded uploads directories are not scanned.
Review the candidates, skip any to keep, and use **Accept all** to queue the
rest. Hover over or focus a path to load a preview; unsupported image formats
or inaccessible files may not preview in the browser. The attachment ID is
the WordPress media record ID, not necessarily the post ID in a filename.
Processing is a separate step: it rechecks references, registration
and file contents, moves files to `uploads/image-cleanup/quarantine/`, and deletes
the corresponding unused attachment records. Skips persist until a new scan.
Empty upload folders can be removed separately. There is no restore interface.

Each tool has its own activity log in `loopis-logs` alongside the WordPress
root, outside the web root: `image-cleanup-site-X.jsonl` and
`image-migration-site-X.jsonl`, where X is the site ID. Set `LOOPIS_LOG_DIR`
to use another private directory outside the web root. The tools start fresh
logs at these paths; existing logs in previous directories are left untouched.
The old `LOOPIS_IMAGE_CONVERSION_LOG_DIR` and `LOOPIS_IMAGE_MIGRATION_LOG_DIR`
settings are no longer used. Below Recent activity, **Clear tables** clears
that tool's queue and log; **Delete tables**
deletes its queue table and log, which are recreated when needed. Both discard
pending work but leave image and quarantine files untouched. They are blocked
if either tool is processing an item.

Existing conversion queue tables are renamed on upgrade.
Previously quarantined files remain in `uploads/migration/quarantine/`, which
is still excluded from scans. Old staging directories are also excluded.
Previous conversion queues require a new complete scan before processing.

Test on staging first and verify a restorable database and uploads backup.
The scan inventories existing files, not missing attachment files;
it does not repair broken attachment records.
The cleanup lock does not prevent normal edits; a new reference can be added
between the final check and quarantine. Pause publishing and editing while
processing to avoid this race. This tool assumes images are used only by this
site's post/page content and metadata, not by external consumers or settings.

## 🚧 Access
This plugin loads only in the WordPress admin area for superadmin with the `manage_options` capability.

## 👤 Contributors
Johan Hagvil, Hubert Hilborn, Copilot
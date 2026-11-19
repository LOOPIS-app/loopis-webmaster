# 📋 Changelog for "LOOPIS Webmaster"

## Unreleased
- Store new image-tool logs in the shared loopis-logs directory with tool-first filenames.
- Show conversion scan counts for pending attachments and their existing associated files.
- Prevent conversion filename planning from looping indefinitely and clarify unexpected scan responses.
- Show upload-relative paths and on-demand previews in cleanup review.
- Allow image scans to target a single month or all months in the selected year.
- Fix the selected upload year being omitted when starting an image scan.
- Scan a selected upload year one month at a time in resumable batches of up to 100 files.
- Separate image conversion and usage-aware image cleanup into independent admin pages and queues.
- Rename image conversion identifiers and preserve existing queues on upgrade.

## 0.01 (2026-11-16)
- Init comit with new tool "Image migration"
- Moved tools "Move user" and "QR Codes" from "LOOPIS Admin".

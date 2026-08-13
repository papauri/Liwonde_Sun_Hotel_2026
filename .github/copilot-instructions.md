# Copilot Workspace Instructions

## Testing And Verification Rules

- Always check `.env` first before running tests that require database or server credentials.
- For database checks, use `.env` values (`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`) as the source of truth.
- When admin pages are changed, perform a browser-based final pass on the affected page(s) after code edits.
- Prefer validating both:
  - Runtime behavior in browser (logged-in admin flow)
  - Data state in database (using `.env` credentials)

## Security Handling

- Never print secrets in responses.
- Do not hardcode credentials in source files.
- Keep operational credentials in local `.env` only.

## UI And UX Standardization

- Default to standardized UI/UX patterns across admin pages, especially modal structure (header/body/footer), spacing, controls, and typography.
- If a UI change is requested, apply it consistently to all equivalent components unless the user explicitly asks for a page-specific exception.
- Treat custom one-off modal styling as an exception that must be explicitly requested.

## Backup Feature Specific

- For backup-related changes, verify all of the following:
  - Manual "Run Backup Now" in admin UI
  - CLI manual run: `php scripts/backup_database.php`
  - Backup list visibility: `php scripts/restore_database.php --list`
  - Cron command guidance remains present and accurate

## Cache Feature Specific

- For cache-management-related changes, always verify all of the following:
  - Browser final pass on Admin > Cache Management (buttons, cards, typography, and status labels)
  - Global cache toggle save flow and at least one per-cache toggle flow
  - Bulk clear action validation path (no selection warning) and one successful clear path
  - Schedule save flow and settings persistence (`cache_schedule_enabled`, `cache_schedule_interval`, `cache_schedule_time`, `cache_custom_seconds`)
  - Scheduled runner dry run: `php scripts/scheduled-cache-clear.php --dry-run --verbose`
  - Scheduled runner forced run: `php scripts/scheduled-cache-clear.php --force --verbose`
  - Last-run timestamp persistence in DB (`cache_last_run`)

## Copilot Credit Optimization (Ultra-Low-Cost Mode)

- Default to smallest possible scope for every task.
- Read only files directly named by the user or proven by targeted search.
- Never run full-repository scans unless the user explicitly requests full-project analysis.
- Prefer one focused change per request unless the user explicitly asks for batching.
- If a change is likely to touch more than 3 files, ask for scope confirmation first.

## Tool Usage Budget

- Prefer read-only reasoning first; run tools only when needed for correctness.
- Use narrow text/file search before opening files; then read only relevant ranges.
- Avoid repeated reads of unchanged sections.
- Avoid browser automation unless the request explicitly requires UI validation.
- Avoid repeated test runs without code changes between runs.
- For expensive validation flows, run one verification cycle by default.
- Additional verification cycles require explicit user approval.

## Browser Automation Rules (High Credit Cost)

- **Never** open, screenshot, snapshot, or interact with a browser page unless the user explicitly says "check in browser", "validate in browser", or "browser test".
- A code edit alone does NOT trigger browser validation — skip it unless asked.
- When browser validation is requested, perform ONE page load + ONE interaction check, then stop. Do not re-load, re-snapshot, or re-screenshot unless a new bug is found.
- Never use browser tools to read page state that can be inferred from the PHP/JS source code.

## get_errors Scope Rules (High Credit Cost)

- **Never** call `get_errors` with no arguments (workspace-wide scan) as a routine post-edit check.
- After editing a file, run `get_errors` on **that file only** (pass the absolute path).
- Only escalate to a workspace-wide scan if the user explicitly requests it or a cross-file type error is suspected.
- For PHP files, `php -l <file>` is sufficient for syntax validation; reserve `get_errors` for intelephense diagnostic checks on the specific edited file.

## Terminal Log Polling Rules (High Credit Cost)

- The PHP dev server terminal (`php -S ...`) streams continuous access logs. It **never** needs interactive input.
- When notified about that terminal, call `get_terminal_output` **once** to confirm it is not waiting for input, then take no further action.
- Do **not** poll the terminal log again unless a non-200 HTTP status, a PHP error line, or a genuine input prompt appears in the output.
- Never summarise or quote routine access log lines in a response.

## Cost Warning Triggers

- Before executing any of the following, provide a brief cost warning and request approval:
  - full codebase scans
  - multi-page browser validation
  - repeated test loops
  - long-running terminal jobs
  - external API calls that may be billable

## Prompt And Response Efficiency

- Keep responses concise by default: solution first, minimal explanation.
- If the request is broad or ambiguous, ask one clarifying question before exploring.
- Do not restate unchanged plans or duplicate previous summaries.
- For planning/brainstorm requests, stay read-only unless execution is explicitly requested.

## Model And Execution Strategy

- Prefer lower-cost model/settings for drafting, copy edits, and small localized changes.
- Use higher-capability settings only for complex refactors, cross-file architecture, or deep debugging.
- For explain-only questions, do not execute tools unless required for factual accuracy.

## Session Guardrails

- Start with the least expensive path and escalate only when results are insufficient.
- Stop after completing the requested scope; do not perform optional extra checks by default.
- If the user asks to "update everything required," apply only directly relevant policy updates and avoid unrelated rewrites.

# Revision Manager

WordPress revisions bloat `wp_posts` fast. This plugin keeps them under control without editing `wp-config.php`:

- **Per-post-type limits** — e.g. keep 10 revisions for posts, 25 for pages (0 = WordPress default).
- **Enforced at save time** — when a new revision is created, older ones beyond the limit are deleted.
- **Weekly prune** — a WP-Cron job prunes every post to its limit once a week (toggleable).
- **One-click cleanup** — Tools → Revision Manager shows the total revision count and prunes old revisions immediately, with a confirm dialog.

Autosaves are never deleted. Filter `wrm_revision_limit` to override a type's limit in code.

## License

MIT

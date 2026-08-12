# Minimal GitHub Updater

This is a simplified, ready-to-use version of the GitHub Updater plugin.

Setup:
1. Install and activate the plugin in WordPress.
2. (Recommended) Add a 32-byte encryption key to wp-config.php:
   define('GH_UPDATER_SECRET_KEY', 'your-32-byte-random-string');
3. In WordPress admin go to GitHub Updater → Settings.
4. Paste a Personal Access Token (PAT) with scopes: repo (or public_repo) and admin:repo_hook.
5. Save and Test the token.
6. In Repositories tab, create a webhook for a repo and Ping it to verify delivery.

Notes:
- Webhooks require a public HTTPS endpoint. Use ngrok for local testing.
- If you accidentally exposed a token, revoke it immediately on GitHub and create a new one.

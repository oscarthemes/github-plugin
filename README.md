Updated README with setup and usage instructions.

## Quick start
1. Install the plugin on your WordPress site and activate it.
2. Define a secret key in your wp-config.php to encrypt tokens (recommended):

   define('GH_UPDATER_SECRET_KEY', 'pick-a-32-byte-random-string');

   If not set, the plugin will attempt to use AUTH_KEY as a fallback; otherwise the token will be stored unencrypted (not recommended).

3. Open the GitHub Updater admin page (left menu: GitHub Updater).
4. Paste a Personal Access Token (repo, admin:repo_hook scopes) and optionally a webhook secret.
5. Click "Create webhook" for any repository to install a webhook pointing to your site.

Notes
- Webhook endpoint must be public and use HTTPS for GitHub to deliver events.
- For local development, use ngrok or similar tunneling tools.

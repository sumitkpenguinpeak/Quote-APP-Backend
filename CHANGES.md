# Security / review fixes

See the final message for the full list. Before going live:
1. Set a real domain in shopify.app.toml (application_url, redirect_urls, app_proxy.url) and backend .env.
2. `shopify app deploy` (registers compliance webhooks, app proxy, new scopes + new theme extension JS).
3. Rotate: SHOPIFY_API_SECRET, APP_KEY (re-login not needed; run the encrypt migration FIRST with the final APP_KEY), Gmail app password, Google client secret.
4. `php artisan migrate`, run `php artisan queue:work`, APP_DEBUG=false, MySQL/Postgres.

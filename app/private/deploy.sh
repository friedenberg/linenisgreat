#! /bin/sh -xe

# forces php.ini to be reloaded faster
nfsn web-kick

# Clear the frontend's opaque API cache (ApiClient::fetchCached writes
# ../tmp/api-cache-*) so freshly-deployed content shows immediately instead of
# waiting out the 1h TTL. Glob is literal-safe under `rm -f` when nothing matches.
rm -f ../tmp/api-cache-* 2>/dev/null || true

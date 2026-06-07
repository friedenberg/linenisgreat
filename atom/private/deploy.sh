#! /bin/sh -xe

# forces php.ini to be reloaded faster
nfsn web-kick

# Clear the feed app's opaque API cache (FeedClient writes ../tmp/feed-cache-*)
# so freshly-deployed content shows immediately instead of waiting out the TTL.
rm -f ../tmp/feed-cache-* 2>/dev/null || true

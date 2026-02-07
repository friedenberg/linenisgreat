#!/usr/bin/env bash
set -euo pipefail

TEST_NUM=0
FAILED=0

# Define all tests upfront for TAP plan (pattern:::description)
TESTS=(
    '^Options \+FollowSymLinks -Indexes:::Options directive'
    '^RewriteEngine On:::RewriteEngine enabled'
    'RewriteRule.*object\.php\?tab=about:::Homepage route'
    'RewriteRule.*yoga\|code.*\$1\.php:::Section index routes'
    'RewriteRule.*objects.*object_with_metadata\.php:::Objects route'
    'RewriteRule.*notes.*object_with_metadata\.php:::Notes route'
    'RewriteRule.*slides.*object\.php.*template=slide:::Slides route'
    'RewriteRule.*yoga/.*yoga_object\.php:::Yoga object route'
    'RewriteRule.*code/.*code\.php:::Code project route'
    'RewriteCond.*code\.linenisgreat\.com:::Subdomain condition'
    'RewriteRule.*https://linenisgreat\.com/code/:::Subdomain redirect'
)

check_contains() {
    local pattern="$1"
    local description="$2"

    ((++TEST_NUM))

    if echo "$HTACCESS" | grep -qE "$pattern"; then
        echo "ok ${TEST_NUM} - ${description}"
    else
        echo "not ok ${TEST_NUM} - ${description}"
        echo "  ---"
        echo "  pattern: ${pattern}"
        echo "  ..."
        ((++FAILED))
    fi
}

gum style --border normal --padding "0 1" --border-foreground 212 \
    "Testing htaccess generation"

HTACCESS=$(php private/router.php --generate-htaccess)

# TAP header
echo "TAP version 14"
echo "1..${#TESTS[@]}"

# Run tests
for test in "${TESTS[@]}"; do
    pattern="${test%%:::*}"
    description="${test##*:::}"
    check_contains "$pattern" "$description"
done

# Summary
echo
if [[ "$FAILED" -eq 0 ]]; then
    gum style --foreground 212 "All ${#TESTS[@]} tests passed"
    exit 0
else
    gum style --foreground 196 "${FAILED} of ${#TESTS[@]} tests failed"
    exit 1
fi

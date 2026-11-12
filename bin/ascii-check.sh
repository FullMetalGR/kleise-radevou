#!/usr/bin/env bash
#
# The repository's encoding rule as one command: every file is ASCII but those under lang/el,
# the Greek language directory, which hold Greek and must be valid UTF-8 instead. Prints every
# line that breaks either half and exits 1 on any; prints nothing and exits 0 on a clean tree;
# exits 2 when the tree cannot be read whole. CI's encoding step runs it, and so does the sweep
# over a working tree.
#
# The tree is the repository root, or the directory given as the one argument. grep's
# --exclude-dir matches a directory's base name anywhere in the tree and never a path, so .git,
# vendor and node_modules leave by name and lang/el leaves by its path from the root, which a
# directory named el anywhere else cannot share. A file holding a NUL byte is binary rather than
# text, and -I leaves it out. The grep is the system's, never a wrapper a shell defines.

set -u

root=${1:-$(dirname "$0")/..}
cd "$root" || exit 2

# A line grep cannot match whole under a UTF-8 locale holds an invalid sequence. Without the
# locale every byte matches, and the second half would pass whatever lang/el held.
if ! printf '\377\n' | LC_ALL=C.UTF-8 command grep -qaxv '.*'; then
    echo 'The C.UTF-8 locale is missing, so lang/el cannot be checked.' >&2
    exit 2
fi

status=0

matches=$(LC_ALL=C command grep -rnIP -D skip '[^\x00-\x7F]' --exclude-dir=.git --exclude-dir=vendor --exclude-dir=node_modules .)
[ $? -le 1 ] || exit 2

outside=$(printf '%s' "$matches" | LC_ALL=C command grep -v '^\./lang/el/')

if [ -n "$outside" ]; then
    printf '%s\n' "$outside"
    echo 'Non-ASCII bytes in the lines above; only lang/el may hold them.' >&2
    status=1
fi

if [ -d lang/el ]; then
    invalid=$(LC_ALL=C.UTF-8 command grep -rnaxv -D skip '.*' ./lang/el)
    [ $? -le 1 ] || exit 2

    if [ -n "$invalid" ]; then
        printf '%s\n' "$invalid"
        echo 'Invalid UTF-8 in the lang/el lines above.' >&2
        status=1
    fi
fi

exit "$status"

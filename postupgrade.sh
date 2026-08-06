#!/bin/bash
# Dashboard-Designer - postupgrade
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
SELF=$(cd "$(dirname "$0")" && pwd)
if [ -x "$SELF/postinstall.sh" ]; then
    "$SELF/postinstall.sh" "$@"
    exit $?
fi
echo "<FAIL> postinstall.sh nicht gefunden - Upgrade unvollstaendig."
exit 1

#!/bin/bash
mkdir -p "$5/log/plugins/$3" "$5/data/plugins/$3"
# Bei Neuinstallation Dienst starten (bei Upgrade startet postupgrade.sh)
"$5/bin/plugins/$3/start.sh"
exit 0

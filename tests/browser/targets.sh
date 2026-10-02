# Browser-test targets for this module, sourced by the shared runner
# (~/Sites/0_ss-mods-maintenance/tools/browser/run.sh) and by .github/workflows/browser-tests.yml.
# Plain bash assignments only. CI tests only the targets with an empty SS<n>_SRC_REF (= this
# checkout).
# Ports are assigned in ~/Sites/0_ss-mods-maintenance/tools/browser/PORTS.md; take new ones there.

BROWSER_PACKAGE="restruct/silverstripe-mfa-bundle"
BROWSER_TARGETS="ss5 ss6"

# main (1.5.x) requires framework ^5.4 || ^6 and is the only maintained line, so it serves both majors.
SS5_RECIPE="^5"
SS5_PHP="8.3"
SS5_PORT="8891"
SS5_SRC_REF=""

SS6_RECIPE="^6"
SS6_PHP="8.3"
SS6_PORT="8892"
SS6_SRC_REF=""

# TOTP needs SS_MFA_SECRET_KEY to encrypt the secret it stores. A fixed TEST value for the scratch
# hosts and CI only (the same one ci.yml uses for the unit tests).
BROWSER_ENV="SS_MFA_SECRET_KEY=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"

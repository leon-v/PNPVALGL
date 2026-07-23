#!/usr/bin/env zsh
set -euo pipefail

# ── rename.sh — case-preserving project rename ─────────────────────────────
# Usage: ./docker/rename.sh NEW_NAME
#
# Replaces all case variants of the baked-in OLD name (PNPVALGL) with the
# corresponding case variants of NEW across the project. Skips vendor/,
# .git/, logs/, gitignored files, and binary files.

cd "$(dirname "$0")/.."

OLD="PNPVALGL"
NEW="${1:?Usage: $0 NEW_NAME}"

# Validate that NEW is a valid PHP namespace segment: PascalCase, alphanumeric only.
if [[ $NEW =~ ^[0-9] ]]; then
    echo "Error: NEW_NAME must start with a letter, got '$NEW'" >&2
    exit 1
fi
if [[ ! $NEW =~ ^[A-Z][a-zA-Z0-9_]+$ ]]; then
    echo "Error: NEW_NAME must start with a letter and contain only alphanumeric characters or underscores, got '$NEW'" >&2
    exit 1
fi

# ── Derive case variants ───────────────────────────────────────────────────
OLD_UPPER="${OLD:u}"
OLD_LOWER="${OLD:l}"
OLD_PASCAL="${${OLD:0:1}:u}${OLD:1:l}"
OLD_CAMEL="${${OLD:0:1}:l}${OLD:1}"

NEW_UPPER="${NEW:u}"
NEW_LOWER="${NEW:l}"
NEW_PASCAL="${${NEW:0:1}:u}${NEW:1:l}"
NEW_CAMEL="${${NEW:0:1}:l}${NEW:1}"

echo "Replacing:"
echo "  ${OLD_UPPER} → ${NEW_UPPER}"
echo "  ${OLD_LOWER} → ${NEW_LOWER}"
echo "  ${OLD_PASCAL} → ${NEW_PASCAL}"
echo "  ${OLD_CAMEL} → ${NEW_CAMEL}"
echo ""

RED=$'\e[31;9m'
GREEN=$'\e[32m'
RESET=$'\e[0m'

# ── Find text files ────────────────────────────────────────────────────────
# Use git ls-files when available; fall back to find.
if git rev-parse --git-dir > /dev/null 2>&1; then
    FILES=(${(f)"$(git ls-files --cached --others --exclude-standard ':!:vendor/' ':!:docker/*/logs/')"})
else
    FILES=(${(f)"$(find . -type f \
        -not -path './vendor/*' \
        -not -path './.git/*' \
        -not -path './docker/*/logs/*' \
        -not -name '*.png' \
        -not -name '*.jpg' \
        -not -name '*.gif' \
        -not -name '*.ico' \
        -not -name '*.gz' \
        -not -name '*.zip' \
        -not -name '*.phar' \
    )"})
fi

# ── Apply replacements ─────────────────────────────────────────────────────
COUNT=0

for FILE in $FILES; do
    [[ -f $FILE ]] || continue
    [[ $FILE == ./docker/rename.sh ]] && continue

    # Detect binary — skip if grep thinks it's binary.
    if ! grep -qI . "$FILE" 2>/dev/null; then
        continue
    fi

    # Find lines containing any OLD variant.
    MATCHES=$(grep -n -e "$OLD_UPPER" -e "$OLD_LOWER" -e "$OLD_PASCAL" -e "$OLD_CAMEL" "$FILE" 2>/dev/null) || continue

    # Display before state.
    echo "  $FILE"
    while IFS= read -r LINE; do
        CONTENT="${LINE#*:}"
        OLD_LINE=$(printf '%s' "$CONTENT" | sed \
            -e "s/${OLD_UPPER}/${RED}${OLD_UPPER}${RESET}/g" \
            -e "s/${OLD_LOWER}/${RED}${OLD_LOWER}${RESET}/g" \
            -e "s/${OLD_PASCAL}/${RED}${OLD_PASCAL}${RESET}/g" \
            -e "s/${OLD_CAMEL}/${RED}${OLD_CAMEL}${RESET}/g")
        printf '  - %s\n' "$OLD_LINE"
    done <<< "$MATCHES"

    # Apply replacements in-place.
    sed -i '' \
        -e "s/${OLD_UPPER}/${NEW_UPPER}/g" \
        -e "s/${OLD_LOWER}/${NEW_LOWER}/g" \
        -e "s/${OLD_PASCAL}/${NEW_PASCAL}/g" \
        -e "s/${OLD_CAMEL}/${NEW_CAMEL}/g" \
        "$FILE"

    # Display after state by re-reading the now-modified lines.
    while IFS= read -r LINE; do
        LN="${LINE%%:*}"
        NEW_CONTENT=$(sed -n "${LN}p" "$FILE")
        NEW_LINE=$(printf '%s' "$NEW_CONTENT" | sed \
            -e "s/${NEW_UPPER}/${GREEN}${NEW_UPPER}${RESET}/g" \
            -e "s/${NEW_LOWER}/${GREEN}${NEW_LOWER}${RESET}/g" \
            -e "s/${NEW_PASCAL}/${GREEN}${NEW_PASCAL}${RESET}/g" \
            -e "s/${NEW_CAMEL}/${GREEN}${NEW_CAMEL}${RESET}/g")
        printf '  + %s\n' "$NEW_LINE"
    done <<< "$MATCHES"

    ((COUNT++)) || true
done

echo ""
echo "Done — $COUNT file(s) changed."

echo ""
echo "Tearing down old containers (${OLD_LOWER})…"
docker compose -p "$OLD_LOWER" down 2>/dev/null || true

echo ""
echo "Regenerating Composer autoloader for the new namespace…"
docker compose run --rm php composer dump-autoload

echo ""
echo "Rebuilding images and bringing up the new stack…"
docker compose build --no-cache && docker compose up -d

echo ""
echo "Rename complete."

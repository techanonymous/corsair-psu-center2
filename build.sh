#!/usr/bin/env bash
#
# Corsair PSU Center v2 - package builder.
#
# Packs src/ into the Slackware package archive/corsairpsucenter2-<version>-x86_64.txz,
# then writes the new version + md5 into corsairpsucenter2.plg and archive/md5.txt.
#
#   ./build.sh               version = today (YYYY.MM.DD); a, b, c... is appended if
#                            today's package already exists
#   ./build.sh 2026.12.01    explicit version
#   FORCE=1 ./build.sh 2026.12.01   overwrite an existing package of that version
#
# The bundled Python 3.11 runtime + liquidctl (~73 MB) is not kept as loose files in
# git. It is taken from the newest package already in archive/ (any v1 or v2 build)
# and cached in .build/py. Point PY_BASE at a different .txz to take it from there.
#
# Needs: bash, GNU tar, xz, md5sum (Linux, WSL or Git Bash).

set -euo pipefail
cd "$(dirname "$0")"

NAME=corsairpsucenter2
PLG=$NAME.plg
BUILD=.build
DEST=usr/local/emhttp/plugins/$NAME

for t in tar xz md5sum; do
  command -v "$t" >/dev/null || { echo "error: '$t' is required" >&2; exit 1; }
done

# ---- version ----
if [ $# -ge 1 ]; then
  VERSION=$1
else
  VERSION=$(date +%Y.%m.%d)
  if [ -z "${FORCE:-}" ] && [ -e "archive/$NAME-$VERSION-x86_64.txz" ]; then
    for s in a b c d e f g h i j k l m n o p q r s t u v w x y z; do
      [ -e "archive/$NAME-$VERSION$s-x86_64.txz" ] || { VERSION=$VERSION$s; break; }
    done
  fi
fi
[[ $VERSION =~ ^[0-9]{4}\.[0-9]{2}\.[0-9]{2}[a-z]?$ ]] || { echo "error: version must look like YYYY.MM.DD[a-z]" >&2; exit 1; }
OUT=archive/$NAME-$VERSION-x86_64.txz
if [ -e "$OUT" ] && [ -z "${FORCE:-}" ]; then
  echo "error: $OUT already exists (FORCE=1 to overwrite)" >&2; exit 1
fi

# ---- bundled python runtime ----
if [ ! -x "$BUILD/py/bin/python3" ]; then
  BASE=${PY_BASE:-$(ls -t archive/*.txz 2>/dev/null | head -1 || true)}
  [ -n "$BASE" ] && [ -f "$BASE" ] || { echo "error: no package to take the python runtime from (set PY_BASE)" >&2; exit 1; }
  echo "Extracting bundled python runtime from $BASE"
  rm -rf "$BUILD/pyx" "$BUILD/py"; mkdir -p "$BUILD/pyx"
  tar -xJf "$BASE" -C "$BUILD/pyx" --wildcards 'usr/local/emhttp/plugins/*/py/*'
  PYDIR=$(find "$BUILD/pyx/usr/local/emhttp/plugins" -mindepth 2 -maxdepth 2 -type d -name py | head -1)
  [ -n "$PYDIR" ] || { echo "error: $BASE has no py/ runtime" >&2; exit 1; }
  mv "$PYDIR" "$BUILD/py"; rm -rf "$BUILD/pyx"
fi

# ---- stage ----
STAGE=$BUILD/pkg
rm -rf "$STAGE"; mkdir -p "$STAGE/install" "$STAGE/$DEST/icons"
cp src/*.page src/*.php src/*.sh src/README.md "$STAGE/$DEST/"
cp src/icons/* "$STAGE/$DEST/icons/"
cp -a "$BUILD/py" "$STAGE/$DEST/py"
find "$STAGE" -path "$STAGE/$DEST/py" -prune -o -type d -exec chmod 755 {} +
find "$STAGE/$DEST" -maxdepth 2 -type f -not -path '*/py/*' -exec chmod 644 {} +
chmod 755 "$STAGE/$DEST"/*.sh

# ---- pack (root-owned, sorted, mtime pinned to the last commit -> reproducible) ----
MTIME=@$(git log -1 --format=%ct 2>/dev/null || date +%s)
( cd "$STAGE" && find . -mindepth 1 -printf '%P\n' | LC_ALL=C sort > ../filelist )
tar -C "$STAGE" --owner=0 --group=0 --numeric-owner --mtime="$MTIME" --no-recursion \
    -cf - ./ -T "$BUILD/filelist" | xz -9 -T1 > "$OUT"
MD5=$(md5sum "$OUT" | cut -d' ' -f1)

# ---- write version + md5 into the plg and md5 list ----
sed -i -E "s|(<!ENTITY version +\")[^\"]*\"|\1$VERSION\"|; s|(<!ENTITY md5 +\")[^\"]*\"|\1$MD5\"|" "$PLG"
touch archive/md5.txt
grep -v " $NAME-$VERSION-x86_64.txz\$" archive/md5.txt > "$BUILD/md5.txt" || true
{ echo "$MD5  $NAME-$VERSION-x86_64.txz"; cat "$BUILD/md5.txt"; } > archive/md5.txt

echo
echo "Built $OUT"
echo "  md5     $MD5"
echo "  size    $(du -h "$OUT" | cut -f1)"
echo "  $PLG -> version $VERSION"
grep -q "^###$VERSION\$" "$PLG" || echo "  NOTE: add a ###$VERSION entry to <CHANGES> in $PLG (and CHANGELOG.md)"

#!/usr/bin/env bash
#
# Refresh this repo's vendored spec artifacts from the upstream working area.
#
# This repo stands alone: spec/*.json are committed copies, so a fresh clone
# builds and tests with no sibling checkout. They are NOT the editable source —
# edit them upstream, then run this to pull the change in.
#
# Usage: ./scripts/sync-spec.sh /path/to/inoviov2/api-sdk/spec
set -euo pipefail

SRC="${1:-}"
if [ -z "$SRC" ]; then
  echo "usage: $0 /path/to/inoviov2/api-sdk/spec" >&2
  exit 64
fi
for f in spec-enums.json conformance-fixtures.json; do
  [ -f "$SRC/$f" ] || { echo "missing: $SRC/$f" >&2; exit 66; }
done

HERE="$(cd "$(dirname "$0")/.." && pwd)"
cp "$SRC/spec-enums.json" "$SRC/conformance-fixtures.json" "$HERE/spec/"

echo "synced spec artifacts from $SRC"
echo
echo "Next — a spec change that alters an enum or fixture must be committed"
echo "together with the regenerated code it produces:"
echo "  1. regenerate enums (see README)"
echo "  2. run the test suite"
echo "  3. commit spec/ + generated sources together"
echo
echo "This is a COORDINATED change: the other Inovio SDK repos vendor the same"
echo "two files and must be synced in step, or the SDKs stop agreeing."

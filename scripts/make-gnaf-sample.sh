#!/usr/bin/env bash
# Builds database/gnaf-sample/ — a small but REAL G-NAF extract (same PSV layout as the release)
# for tests, CI and `make seed`. Requires GNAF_PATH pointing at the unzipped release.
#
#   scripts/make-gnaf-sample.sh "/path/to/G-NAF AUGUST 2026"
set -euo pipefail

SRC_ROOT="${1:-${GNAF_PATH:?set GNAF_PATH or pass the release folder}}"
STD="$(find "$SRC_ROOT" -type d -name Standard | head -1)"
AUTH="$(find "$SRC_ROOT" -type d -name 'Authority Code' | head -1)"
OUT="$(cd "$(dirname "$0")/.." && pwd)/database/gnaf-sample"
mkdir -p "$OUT/Standard" "$OUT/Authority Code"

# state:locality_pid list (looked up from {STATE}_LOCALITY_psv.psv)
# (bash 3.2 compatible — no associative arrays)
locs() {
  case "$1" in
    NSW) echo "loc22a73fe48374 locc0764149b600 loc08e2f389942a loc581d5d75cd4f loca39fe0127b3c locbb434919d55e loce373f16c4834" ;;
    VIC) echo "loce48c38ae2d6a locb9872f35df41" ;;
    QLD) echo "loc1a4f4b393bf9" ;;
  esac
}

for ST in NSW VIC QLD; do
  LIST="$(locs "$ST")"
  f() { echo "$STD/${ST}_$1_psv.psv"; }
  o() { echo "$OUT/Standard/${ST}_$1_psv.psv"; }

  head -1 "$(f STATE)" > "$(o STATE)"; tail -n +2 "$(f STATE)" >> "$(o STATE)"
  awk -F'|' -v L="$LIST" 'BEGIN{n=split(L,a," ");for(i=1;i<=n;i++)k[a[i]]=1} NR==1||($1 in k)' "$(f LOCALITY)" > "$(o LOCALITY)"
  awk -F'|' -v L="$LIST" 'BEGIN{n=split(L,a," ");for(i=1;i<=n;i++)k[a[i]]=1} NR==1||($8 in k)' "$(f STREET_LOCALITY)" > "$(o STREET_LOCALITY)"
  # ADDRESS_DETAIL: LOCALITY_PID is column 25
  awk -F'|' -v L="$LIST" 'BEGIN{n=split(L,a," ");for(i=1;i<=n;i++)k[a[i]]=1} NR==1||($25 in k)' "$(f ADDRESS_DETAIL)" > "$(o ADDRESS_DETAIL)"
  # Geocodes / primary-secondary / sites keyed off the selected address PIDs
  awk -F'|' 'NR==FNR{if(FNR>1)k[$1]=1;next} FNR==1||($4 in k)' "$(o ADDRESS_DETAIL)" "$(f ADDRESS_DEFAULT_GEOCODE)" > "$(o ADDRESS_DEFAULT_GEOCODE)"
  awk -F'|' 'NR==FNR{if(FNR>1)k[$1]=1;next} FNR==1||($2 in k)||($3 in k)' "$(o ADDRESS_DETAIL)" "$(f PRIMARY_SECONDARY)" > "$(o PRIMARY_SECONDARY)"
  awk -F'|' 'NR==FNR{if(FNR>1&&$31!="")k[$31]=1;next} FNR==1||($1 in k)' "$(o ADDRESS_DETAIL)" "$(f ADDRESS_SITE)" > "$(o ADDRESS_SITE)"
  echo "$ST: $(($(wc -l < "$(o ADDRESS_DETAIL)") - 1)) address rows"
done

cp "$AUTH"/Authority_Code_*_psv.psv "$OUT/Authority Code/"
echo "Sample written to $OUT ($(du -sh "$OUT" | cut -f1))"

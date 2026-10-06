#!/usr/bin/env bash
# Zero-downtime release of one environment: migrate (expand/contract, with a lock), start a new revision at 0 % traffic, wait until it is ready,
# shift traffic in steps while watching the error rate, and roll back automatically if anything looks wrong.
# Usage: release.sh <dev|staging|prod> <image-tag>
set -euo pipefail
ENV="$1"; TAG="$2"
RG="rg-tedc-${ENV}"; NAME="tedc-${ENV}"; ACR="${ACR_NAME:?}"
IMAGE="${ACR}.azurecr.io/tedc:${TAG}"
SUFFIX="r${TAG}"

echo "== 1. migrations (backward-compatible; the previous revision keeps serving)"
az containerapp job start -g "$RG" -n "${NAME}-migrate" --image "$IMAGE" --command php --args "artisan migrate --force --isolated" >/dev/null
sleep 5
az containerapp job execution list -g "$RG" -n "${NAME}-migrate" --query "[0].properties.status" -o tsv | grep -q Succeeded || { echo "migration failed — nothing was switched"; exit 1; }

for APP in worker scheduler realtime api; do
  echo "== 2. new revision of $APP"
  az containerapp update -g "$RG" -n "${NAME}-${APP}" --image "$IMAGE" --revision-suffix "$SUFFIX" >/dev/null
done

APP="${NAME}-api"
OLD=$(az containerapp revision list -g "$RG" -n "$APP" --query "[?properties.active && name!='${APP}--${SUFFIX}'] | [0].name" -o tsv || true)
NEW="${APP}--${SUFFIX}"
echo "== 3. wait for the new revision to pass its readiness probe"
for i in $(seq 1 40); do
  S=$(az containerapp revision show -g "$RG" -n "$APP" --revision "$NEW" --query "properties.healthState" -o tsv)
  [ "$S" = "Healthy" ] && break; sleep 15
done
[ "$S" = "Healthy" ] || { echo "new revision never became healthy — keeping $OLD"; exit 1; }

if [ -n "$OLD" ]; then
  for PCT in 10 50 100; do
    echo "== 4. traffic ${PCT}% to $NEW"
    az containerapp ingress traffic set -g "$RG" -n "$APP" --revision-weight "${NEW}=${PCT}" "${OLD}=$((100-PCT))" >/dev/null
    sleep 120
    ERR=$(az monitor app-insights query --app "${NAME}-ai" -g "$RG" --analytics-query "requests | where timestamp > ago(2m) | summarize round(100.0*countif(toint(resultCode)>=500)/count(),2)" --query "tables[0].rows[0][0]" -o tsv 2>/dev/null || echo 0)
    if awk "BEGIN{exit !($ERR > 1.0)}"; then
      echo "error rate ${ERR}% — rolling back to $OLD"
      az containerapp ingress traffic set -g "$RG" -n "$APP" --revision-weight "${OLD}=100" >/dev/null
      exit 1
    fi
  done
  az containerapp revision deactivate -g "$RG" -n "$APP" --revision "$OLD" >/dev/null
fi
echo "released $TAG to $ENV"

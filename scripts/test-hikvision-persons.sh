#!/usr/bin/env bash
# Test Hikvision person-related APIs with curl.
# Usage: ./scripts/test-hikvision-persons.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if [[ ! -f .env ]]; then
    echo "Missing .env file"
    exit 1
fi

HIKVISION_API_HOST="$(grep -E '^HIKVISION_API_HOST=' .env | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'")"
HIKVISION_API_KEY="$(grep -E '^HIKVISION_API_KEY=' .env | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'")"
HIKVISION_API_SECRET="$(grep -E '^HIKVISION_API_SECRET=' .env | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'")"

if [[ -z "$HIKVISION_API_HOST" || -z "$HIKVISION_API_KEY" || -z "$HIKVISION_API_SECRET" ]]; then
    echo "HIKVISION_API_HOST, HIKVISION_API_KEY, and HIKVISION_API_SECRET must be set in .env"
    echo "(Or save credentials in Application settings and use: php artisan hikvision:test-persons)"
    exit 1
fi

HOST="${HIKVISION_API_HOST%/}"

echo "=== 1) Get token ==="
TOKEN_JSON=$(curl -sS -X POST "$HOST/api/hccgw/platform/v1/token/get" \
    -H "Content-Type: application/json" \
    -d "{\"appKey\":\"$HIKVISION_API_KEY\",\"secretKey\":\"$HIKVISION_API_SECRET\"}")

echo "$TOKEN_JSON" | php -r '$j=json_decode(file_get_contents("php://stdin")); echo "errorCode=".($j->errorCode??"?").PHP_EOL; if(($j->errorCode??"")!=="0"){exit(1);}'

TOKEN=$(echo "$TOKEN_JSON" | php -r 'echo json_decode(file_get_contents("php://stdin"))->data->accessToken;')
AREA=$(echo "$TOKEN_JSON" | php -r 'echo rtrim(json_decode(file_get_contents("php://stdin"))->data->areaDomain??"", "/");')

echo ""
echo "=== 2) VIMS residents (POST /vims/v1/person/search) ==="
echo "    Portal 'Person' tab with departments is usually Access Control, NOT VIMS."
curl -sS -X POST "$AREA/api/hccgw/vims/v1/person/search" \
    -H "Content-Type: application/json" \
    -H "Token: $TOKEN" \
    -d '{
        "pageNum": 1,
        "pageSize": 50,
        "searchRequest": {
            "areaId": "-1",
            "buildId": "",
            "isContainSubArea": 1,
            "filter": {
                "name": "",
                "roomNum": 0,
                "email": "",
                "phone": "",
                "type": 0,
                "isExpired": 0
            }
        }
    }' | php -r '$j=json_decode(file_get_contents("php://stdin"),true); echo "errorCode=".($j["errorCode"]??"?")." totalNum=".($j["data"]["totalNum"]??"?")." count=".count($j["data"]["personList"]??[]).PHP_EOL;'

echo ""
echo "=== 3) Departments (POST /person/v1/groups/search) ==="
curl -sS -X POST "$AREA/api/hccgw/person/v1/groups/search" \
    -H "Content-Type: application/json" \
    -H "Token: $TOKEN" \
    -d '{"parentGroupId":"","depthTraversal":true}' \
    | php -r '$j=json_decode(file_get_contents("php://stdin"),true); echo "errorCode=".($j["errorCode"]??"?")." groups=".count($j["data"]["personGroupList"]??[]).PHP_EOL; foreach($j["data"]["personGroupList"]??[] as $g){echo "  - ".($g["groupName"]??"?").PHP_EOL;}'

echo ""
echo "=== 4) Platform users (POST /platform/v1/users/get) ==="
echo "    Closer match to team accounts; use Hikvision > Users in the app."
curl -sS -X POST "$AREA/api/hccgw/platform/v1/users/get" \
    -H "Content-Type: application/json" \
    -H "Token: $TOKEN" \
    -d '{"pageIndex":1,"pageSize":50}' \
    | php -r '$j=json_decode(file_get_contents("php://stdin"),true); echo "errorCode=".($j["errorCode"]??"?")." total=".($j["data"]["totalCount"]??"?")." count=".count($j["data"]["user"]??[]).PHP_EOL; foreach($j["data"]["user"]??[] as $u){echo "  - ".($u["name"]??"?").PHP_EOL;}'

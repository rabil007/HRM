#!/usr/bin/env bash
# Test fetching Access Control records (portal: Access Record Retrieval).
# Uses ISAPI proxypass on the access controller device — not a direct REST list API.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

HIKVISION_API_HOST="$(grep -E '^HIKVISION_API_HOST=' .env | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'")"
HIKVISION_API_KEY="$(grep -E '^HIKVISION_API_KEY=' .env | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'")"
HIKVISION_API_SECRET="$(grep -E '^HIKVISION_API_SECRET=' .env | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'")"

HOST="${HIKVISION_API_HOST%/}"
TODAY_START="$(php -r 'date_default_timezone_set(getenv("APP_TIMEZONE") ?: "Asia/Dubai"); echo (new DateTime("today"))->format("Y-m-d\TH:i:sP");')"
TODAY_END="$(php -r 'date_default_timezone_set(getenv("APP_TIMEZONE") ?: "Asia/Dubai"); echo (new DateTime("tomorrow"))->modify("-1 second")->format("Y-m-d\TH:i:sP");')"

echo "=== 1) Get token ==="
TOKEN_JSON=$(curl -sS -X POST "$HOST/api/hccgw/platform/v1/token/get" \
    -H "Content-Type: application/json" \
    -d "{\"appKey\":\"$HIKVISION_API_KEY\",\"secretKey\":\"$HIKVISION_API_SECRET\"}")
TOKEN=$(echo "$TOKEN_JSON" | php -r 'echo json_decode(file_get_contents("php://stdin"))->data->accessToken;')
AREA=$(echo "$TOKEN_JSON" | php -r 'echo rtrim(json_decode(file_get_contents("php://stdin"))->data->areaDomain??"", "/");')
echo "errorCode=$(echo "$TOKEN_JSON" | php -r 'echo json_decode(file_get_contents("php://stdin"))->errorCode;')"

echo ""
echo "=== 2) Find access controller device ==="
DEVICES_JSON=$(curl -sS -X POST "$AREA/api/hccgw/resource/v1/devices/get" \
    -H "Content-Type: application/json" -H "Token: $TOKEN" \
    -d '{"pageIndex":1,"pageSize":50,"deviceCategory":"accessControllerDevice"}')
echo "$DEVICES_JSON" | php -r '
$j=json_decode(file_get_contents("php://stdin"),true);
foreach($j["data"]["device"]??[] as $d){
  echo "  device: ".($d["name"]??"?")." serial=".($d["serialNo"]??"?")." id=".($d["id"]??"?").PHP_EOL;
}
'

DEVICE_ID=$(echo "$DEVICES_JSON" | php -r '
$j=json_decode(file_get_contents("php://stdin"),true);
echo $j["data"]["device"][0]["id"] ?? "";
')
if [[ -z "$DEVICE_ID" ]]; then
    echo "No access controller device found."
    exit 1
fi

echo ""
echo "=== 3) ISAPI AcsEvent via proxypass (today) ==="
ISAPI_BODY=$(php -r "echo json_encode([
    'AcsEventCond' => [
        'searchID' => '1',
        'searchResultPosition' => 0,
        'maxResults' => 5,
        'major' => 0,
        'minor' => 0,
        'startTime' => '$TODAY_START',
        'endTime' => '$TODAY_END',
    ],
]);")

PROXY_JSON=$(curl -sS -X POST "$AREA/api/hccgw/video/v1/isapi/proxypass" \
    -H "Content-Type: application/json" -H "Token: $TOKEN" \
    -d "{\"method\":\"POST\",\"url\":\"/ISAPI/AccessControl/AcsEvent?format=json\",\"id\":\"$DEVICE_ID\",\"contentType\":\"application/json\",\"body\":$(echo "$ISAPI_BODY" | php -r 'echo json_encode(file_get_contents("php://stdin"));')}")

echo "$PROXY_JSON" | php -r '
$outer=json_decode(file_get_contents("php://stdin"),true);
echo "proxypass errorCode=".($outer["errorCode"]??"?").PHP_EOL;
$inner=json_decode($outer["data"]??"{}",true);
$acs=$inner["AcsEvent"]??[];
echo "totalMatches=".($acs["totalMatches"]??"?")." sample=".count($acs["InfoList"]??[]).PHP_EOL;
foreach(array_slice($acs["InfoList"]??[],0,5) as $i=>$e){
  $n=$e["name"]??$e["employeeNoString"]??"-";
  echo "  ".($i+1).". ".($e["time"]??"?")." | ".$n." | door=".($e["doorNo"]??"?")." | status=".($e["attendanceStatus"]??"-")." | mode=".($e["currentVerifyMode"]??"-").PHP_EOL;
}
'

echo ""
echo "=== Verdict ==="
echo "Direct REST endpoint for portal Access Record Retrieval: NOT in OpenAPI (404 on guessed paths)."
echo "Working approach: ISAPI proxypass -> /ISAPI/AccessControl/AcsEvent on accessControllerDevice."

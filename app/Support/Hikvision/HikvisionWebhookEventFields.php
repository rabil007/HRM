<?php

namespace App\Support\Hikvision;

class HikvisionWebhookEventFields
{
    /**
     * @param  array<string, mixed>  $eventBasicInfo
     * @param  array<string, mixed>  $intelliInfo
     * @return array{door_no: ?string, resource_name: ?string, card_reader_no: ?string, verify_mode: ?string, snap_urls: list<string>}
     */
    public static function resolve(
        array $eventBasicInfo,
        array $intelliInfo,
        string $deviceName = '',
    ): array {
        $doorNo = self::resolveDoorNo($eventBasicInfo);
        $cardReaderNo = self::resolveCardReaderNo($eventBasicInfo);
        $resourceName = self::resolveResourceName($eventBasicInfo, $deviceName, $doorNo);
        $verifyMode = self::resolveVerifyMode($eventBasicInfo, $intelliInfo);
        $snapUrls = self::resolveSnapUrls($intelliInfo);

        return [
            'door_no' => $doorNo,
            'resource_name' => $resourceName,
            'card_reader_no' => $cardReaderNo,
            'verify_mode' => $verifyMode,
            'snap_urls' => $snapUrls,
        ];
    }

    /**
     * @param  array<string, mixed>  $eventBasicInfo
     */
    public static function resolveSerialNo(array $eventBasicInfo): ?string
    {
        if (! isset($eventBasicInfo['serialNo'])) {
            return null;
        }

        $serialNo = trim((string) $eventBasicInfo['serialNo']);

        return $serialNo !== '' ? $serialNo : null;
    }

    /**
     * @param  array<string, mixed>  $eventBasicInfo
     */
    protected static function resolveDoorNo(array $eventBasicInfo): ?string
    {
        if (isset($eventBasicInfo['doorNo'])) {
            $doorNo = (string) $eventBasicInfo['doorNo'];

            if ($doorNo !== '' && $doorNo !== '0') {
                return $doorNo;
            }
        }

        if (isset($eventBasicInfo['channelNo'])) {
            $channelNo = (string) $eventBasicInfo['channelNo'];

            if ($channelNo !== '' && $channelNo !== '0') {
                return $channelNo;
            }
        }

        if (filled($eventBasicInfo['cardReaderId'] ?? null) || filled($eventBasicInfo['elementId'] ?? null)) {
            return '1';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $eventBasicInfo
     */
    protected static function resolveCardReaderNo(array $eventBasicInfo): ?string
    {
        if (isset($eventBasicInfo['cardReaderNo'])) {
            $cardReaderNo = (string) $eventBasicInfo['cardReaderNo'];

            if ($cardReaderNo !== '' && $cardReaderNo !== '0') {
                return $cardReaderNo;
            }
        }

        if (filled($eventBasicInfo['cardReaderId'] ?? null)) {
            return '1';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $eventBasicInfo
     */
    protected static function resolveResourceName(
        array $eventBasicInfo,
        string $deviceName,
        ?string $doorNo,
    ): ?string {
        if ($doorNo !== null && $doorNo !== '' && $doorNo !== '0') {
            return "Door {$doorNo}";
        }

        $elementName = trim((string) ($eventBasicInfo['elementName'] ?? ''));

        if ($elementName !== '' && ! self::namesMatch($elementName, $deviceName)) {
            return $elementName;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $eventBasicInfo
     * @param  array<string, mixed>  $intelliInfo
     */
    protected static function resolveVerifyMode(array $eventBasicInfo, array $intelliInfo): ?string
    {
        $verifyMode = trim((string) (
            $intelliInfo['currentVerifyMode']
            ?? $intelliInfo['verifyMode']
            ?? $eventBasicInfo['currentVerifyMode']
            ?? $eventBasicInfo['verifyMode']
            ?? ''
        ));

        if ($verifyMode !== '') {
            return $verifyMode;
        }

        if (filled($intelliInfo['personPicUrl'] ?? null)) {
            return 'face';
        }

        $cardNumber = trim((string) ($intelliInfo['cardNumber'] ?? ''));

        if ($cardNumber !== '' && $cardNumber !== '0') {
            return 'card';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $intelliInfo
     * @return list<string>
     */
    protected static function resolveSnapUrls(array $intelliInfo): array
    {
        $personPicUrl = trim((string) ($intelliInfo['personPicUrl'] ?? ''));

        return $personPicUrl !== '' ? [$personPicUrl] : [];
    }

    protected static function namesMatch(string $left, string $right): bool
    {
        return strcasecmp(trim($left), trim($right)) === 0;
    }
}

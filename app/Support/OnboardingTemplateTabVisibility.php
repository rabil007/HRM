<?php

namespace App\Support;

final class OnboardingTemplateTabVisibility
{
    /** @var list<string> */
    private static array $bankKeys = ['bank_id', 'iban', 'account_name'];

    /**
     * @param  array<string, mixed>|null  $tasks
     * @return array{personal: bool, contract: bool, bank: bool, documents: bool, sea_service: bool, vaccination: bool}
     */
    public static function fromTasks(?array $tasks): array
    {
        $defaults = [
            'personal' => true,
            'contract' => true,
            'bank' => true,
            'documents' => true,
            'sea_service' => true,
            'vaccination' => true,
        ];

        if ($tasks === null || ! is_array($tasks)) {
            return $defaults;
        }

        if (($tasks['version'] ?? null) === 2 && isset($tasks['stages']) && is_array($tasks['stages'])) {
            return self::fromVersion2($tasks['stages']);
        }

        if (($tasks['version'] ?? null) === 1 && isset($tasks['stages']) && is_array($tasks['stages'])) {
            return self::fromVersion1($tasks);
        }

        return $defaults;
    }

    /**
     * @param  array<int|string, mixed>  $stages
     * @return array{personal: bool, contract: bool, bank: bool, documents: bool, sea_service: bool, vaccination: bool}
     */
    private static function fromVersion2(array $stages): array
    {
        $aggregateEmployeeNonEmpty = false;
        $aggregateBankNonEmpty = false;
        $aggregateContractNonEmpty = false;
        $aggregateDocsNonEmpty = false;
        $aggregateSeaNonEmpty = false;
        $aggregateVacNonEmpty = false;

        foreach ($stages as $stage) {
            if (! is_array($stage)) {
                continue;
            }

            if (self::fieldsNonEmpty($stage['employee_fields'] ?? null)) {
                $aggregateEmployeeNonEmpty = true;
            }
            if (self::fieldsNonEmpty($stage['bank_account_fields'] ?? null)) {
                $aggregateBankNonEmpty = true;
            }
            if (self::fieldsNonEmpty($stage['contract_fields'] ?? null)) {
                $aggregateContractNonEmpty = true;
            }

            $docs = $stage['documents'] ?? null;

            if (is_array($docs)
                && array_filter(
                    $docs,
                    static fn ($d): bool => is_array($d) && (string) ($d['type'] ?? '') !== '',
                )) {
                $aggregateDocsNonEmpty = true;
            }

            if (self::fieldsNonEmpty($stage['sea_service_fields'] ?? null)) {
                $aggregateSeaNonEmpty = true;
            }
            if (self::fieldsNonEmpty($stage['vaccination_fields'] ?? null)) {
                $aggregateVacNonEmpty = true;
            }
        }

        $hadEmployeeFieldKeyEver = collect($stages)->contains(fn ($s) => is_array($s) && array_key_exists('employee_fields', $s));

        $hadBankFieldKeyEver = collect($stages)->contains(fn ($s) => is_array($s) && array_key_exists('bank_account_fields', $s));

        $hadContractFieldKeyEver = collect($stages)->contains(fn ($s) => is_array($s) && array_key_exists('contract_fields', $s));

        $hadSeaFieldKeyEver = collect($stages)->contains(fn ($s) => is_array($s) && array_key_exists('sea_service_fields', $s));

        $hadVacFieldKeyEver = collect($stages)->contains(fn ($s) => is_array($s) && array_key_exists('vaccination_fields', $s));

        // #region agent log
        try {
            $debugLogPath2 = base_path('.cursor/debug-4512e9.log');
            file_put_contents($debugLogPath2, json_encode([
                'sessionId' => '4512e9', 'hypothesisId' => 'A-C',
                'location' => 'OnboardingTemplateTabVisibility.php:fromVersion2',
                'message' => 'fromVersion2 computed values',
                'data' => [
                    'hadSeaFieldKeyEver' => $hadSeaFieldKeyEver,
                    'aggregateSeaNonEmpty' => $aggregateSeaNonEmpty,
                    'hadVacFieldKeyEver' => $hadVacFieldKeyEver,
                    'aggregateVacNonEmpty' => $aggregateVacNonEmpty,
                    'sea_service_result' => $aggregateSeaNonEmpty,
                    'vaccination_result' => $aggregateVacNonEmpty,
                    'stages_count' => count($stages),
                ],
                'timestamp' => round(microtime(true) * 1000),
            ])."\n", FILE_APPEND);
        } catch (\Throwable) {
        }
        // #endregion

        return [
            'personal' => ! $hadEmployeeFieldKeyEver || $aggregateEmployeeNonEmpty,
            'contract' => ! $hadContractFieldKeyEver || $aggregateContractNonEmpty,
            'bank' => ! $hadBankFieldKeyEver || $aggregateBankNonEmpty,
            'documents' => $aggregateDocsNonEmpty,
            'sea_service' => $aggregateSeaNonEmpty,
            'vaccination' => $aggregateVacNonEmpty,
        ];
    }

    /**
     * @param  array<string, mixed>  $tasks
     * @return array{personal: bool, contract: bool, bank: bool, documents: bool, sea_service: bool, vaccination: bool}
     */
    private static function fromVersion1(array $tasks): array
    {
        $stages = is_array($tasks['stages'] ?? null) ? $tasks['stages'] : [];
        $modules = is_array($tasks['modules'] ?? null) ? $tasks['modules'] : [];

        $v1Profile = is_array($modules['profile']['required_fields'] ?? null)
            ? $modules['profile']['required_fields']
            : [];
        $v1Contract = is_array($modules['contract']['required_fields'] ?? null)
            ? $modules['contract']['required_fields']
            : [];
        $v1Docs = is_array($modules['documents']['required_docs'] ?? null)
            ? $modules['documents']['required_docs']
            : [];

        $bankKeysSet = collect(self::$bankKeys);
        $v1EmployeeCount = collect($v1Profile)->reject(fn ($item) => $bankKeysSet->contains((string) $item))->count();
        $v1BankCount = collect($v1Profile)->filter(fn ($item) => $bankKeysSet->contains((string) $item))->count();

        $hasProfileStage = collect($stages)->contains(
            fn ($s) => is_array($s)
                && is_array($s['modules'] ?? null)
                && in_array('profile', $s['modules'], true),
        );

        $hasContractStage = collect($stages)->contains(
            fn ($s) => is_array($s)
                && is_array($s['modules'] ?? null)
                && in_array('contract', $s['modules'], true),
        );

        $hasDocumentsStage = collect($stages)->contains(
            fn ($s) => is_array($s)
                && is_array($s['modules'] ?? null)
                && in_array('documents', $s['modules'], true),
        );

        return [
            'personal' => $hasProfileStage && $v1EmployeeCount > 0,
            'contract' => $hasContractStage && count($v1Contract) > 0,
            'bank' => $hasProfileStage && $v1BankCount > 0,
            'documents' => $hasDocumentsStage && count($v1Docs) > 0,
            'sea_service' => true,
            'vaccination' => true,
        ];
    }

    private static function fieldsNonEmpty(mixed $fields): bool
    {
        if (! is_array($fields) || $fields === []) {
            return false;
        }

        foreach ($fields as $f) {
            if (is_string($f) && trim($f) !== '') {
                return true;
            }
            if (is_array($f) && (($f['key'] ?? '') !== '')) {
                return true;
            }
        }

        return false;
    }
}

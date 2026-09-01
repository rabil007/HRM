<?php

namespace App\Support\Documents;

use App\Models\DocumentGenerationTemplateVersion;

final class VersionChangeSummary
{
    /**
     * Compare previous → current version. Returns null if $previous is null (first version).
     *
     * @return array{
     *     compared_to_version: int,
     *     pdf_metadata_changed: bool,
     *     fields_added: int,
     *     fields_removed: int,
     *     fields_moved: int,
     *     fields_changed: int,
     *     static_text_added: int,
     *     static_text_removed: int,
     *     static_text_moved: int,
     *     static_text_updated: int,
     *     signatures_added: list<string>,
     *     signatures_removed: list<string>,
     *     signatures_moved: list<string>,
     *     workflow_preset_changed: bool,
     *     signing_preset_changed: bool
     * }|null
     */
    public static function compare(
        ?DocumentGenerationTemplateVersion $previous,
        DocumentGenerationTemplateVersion $current,
    ): ?array {
        if ($previous === null) {
            return null;
        }

        $pdfChanged = $previous->source_pdf_original_name !== $current->source_pdf_original_name
            || (int) ($previous->source_pdf_size_bytes ?? 0) !== (int) ($current->source_pdf_size_bytes ?? 0)
            || (int) ($previous->source_pdf_page_count ?? 0) !== (int) ($current->source_pdf_page_count ?? 0);

        $prevPlacements = self::indexById(
            is_array($previous->placement_config['placements'] ?? null)
                ? $previous->placement_config['placements'] : [],
        );
        $currPlacements = self::indexById(
            is_array($current->placement_config['placements'] ?? null)
                ? $current->placement_config['placements'] : [],
        );

        $prevIds = array_keys($prevPlacements);
        $currIds = array_keys($currPlacements);

        $removedIds = array_diff($prevIds, $currIds);
        $addedIds = array_diff($currIds, $prevIds);
        $sharedIds = array_intersect($prevIds, $currIds);

        $fieldsAdded = $fieldsRemoved = $fieldsMoved = $fieldsChanged = 0;
        $textAdded = $textRemoved = $textMoved = $textUpdated = 0;

        foreach ($removedIds as $id) {
            $item = $prevPlacements[$id];
            $type = ($item['type'] ?? 'field') === 'text' ? 'text' : 'field';
            if ($type === 'field') {
                $fieldsRemoved++;
            } else {
                $textRemoved++;
            }
        }

        foreach ($addedIds as $id) {
            $item = $currPlacements[$id];
            $type = ($item['type'] ?? 'field') === 'text' ? 'text' : 'field';
            if ($type === 'field') {
                $fieldsAdded++;
            } else {
                $textAdded++;
            }
        }

        foreach ($sharedIds as $id) {
            $prev = $prevPlacements[$id];
            $curr = $currPlacements[$id];
            $type = ($curr['type'] ?? 'field') === 'text' ? 'text' : 'field';

            $moved = self::coordinatesDiffer($prev, $curr);

            if ($type === 'field') {
                if ($moved) {
                    $fieldsMoved++;
                }
                if (($prev['field'] ?? '') !== ($curr['field'] ?? '')
                    || ($prev['vertical_align'] ?? 'middle') !== ($curr['vertical_align'] ?? 'middle')
                ) {
                    $fieldsChanged++;
                }
            } else {
                if ($moved) {
                    $textMoved++;
                }
                if (($prev['text_content'] ?? '') !== ($curr['text_content'] ?? '')
                    || ($prev['vertical_align'] ?? 'top') !== ($curr['vertical_align'] ?? 'top')
                ) {
                    $textUpdated++;
                }
            }
        }

        $prevSigs = self::indexBySlotKey(
            is_array($previous->signature_placement_config['placements'] ?? null)
                ? $previous->signature_placement_config['placements'] : [],
        );
        $currSigs = self::indexBySlotKey(
            is_array($current->signature_placement_config['placements'] ?? null)
                ? $current->signature_placement_config['placements'] : [],
        );

        $sigPrevKeys = array_keys($prevSigs);
        $sigCurrKeys = array_keys($currSigs);

        $sigsAdded = array_values(array_diff($sigCurrKeys, $sigPrevKeys));
        $sigsRemoved = array_values(array_diff($sigPrevKeys, $sigCurrKeys));
        $sigsMoved = [];

        foreach (array_intersect($sigPrevKeys, $sigCurrKeys) as $key) {
            if (self::coordinatesDiffer($prevSigs[$key], $currSigs[$key])) {
                $sigsMoved[] = $key;
            }
        }

        return [
            'compared_to_version' => (int) $previous->version,
            'pdf_metadata_changed' => $pdfChanged,
            'fields_added' => $fieldsAdded,
            'fields_removed' => $fieldsRemoved,
            'fields_moved' => $fieldsMoved,
            'fields_changed' => $fieldsChanged,
            'static_text_added' => $textAdded,
            'static_text_removed' => $textRemoved,
            'static_text_moved' => $textMoved,
            'static_text_updated' => $textUpdated,
            'signatures_added' => $sigsAdded,
            'signatures_removed' => $sigsRemoved,
            'signatures_moved' => $sigsMoved,
            'workflow_preset_changed' => (int) ($previous->document_workflow_preset_id ?? 0) !== (int) ($current->document_workflow_preset_id ?? 0),
            'signing_preset_changed' => (int) ($previous->document_signing_preset_id ?? 0) !== (int) ($current->document_signing_preset_id ?? 0),
        ];
    }

    /** @param list<array<string, mixed>> $items */
    private static function indexById(array $items): array
    {
        $indexed = [];
        foreach ($items as $item) {
            if (isset($item['id'])) {
                $indexed[(string) $item['id']] = $item;
            }
        }

        return $indexed;
    }

    /** @param list<array<string, mixed>> $items */
    private static function indexBySlotKey(array $items): array
    {
        $indexed = [];
        foreach ($items as $item) {
            $key = self::signatureComparisonKey($item);
            if ($key !== null) {
                $indexed[$key] = $item;
            }
        }

        return $indexed;
    }

    /**
     * Comparison-only identity for signature placements.
     *
     * Legacy schema v1 configs may omit `slot_key`. Map the historical single
     * placement per role onto the default v2 slots without mutating stored data.
     *
     * @param  array<string, mixed>  $item
     */
    private static function signatureComparisonKey(array $item): ?string
    {
        $slotKey = trim((string) ($item['slot_key'] ?? ''));
        if ($slotKey !== '') {
            return $slotKey;
        }

        return match ((string) ($item['role'] ?? '')) {
            'subject' => 'subject',
            'manager' => 'manager_1',
            'company_signatory' => 'company_signatory_1',
            default => null,
        };
    }

    private static function coordinatesDiffer(array $a, array $b): bool
    {
        foreach (['x', 'y', 'width', 'height'] as $key) {
            if (abs(round((float) ($a[$key] ?? 0), 4) - round((float) ($b[$key] ?? 0), 4)) > 0.0001) {
                return true;
            }
        }

        return false;
    }
}

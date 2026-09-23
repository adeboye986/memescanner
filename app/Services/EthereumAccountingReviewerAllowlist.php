<?php

namespace App\Services;

class EthereumAccountingReviewerAllowlist
{
    /**
     * Positive decimal IDs only; leading zeros and malformed members invalidate the whole list.
     * CSV is accepted only at the top level. No boolean/float/object coercion is permitted.
     *
     * @return list<string>
     */
    public static function normalize(mixed $configured): array
    {
        if (is_string($configured)) {
            $configured = explode(',', $configured);
        } elseif (is_int($configured)) {
            $configured = [$configured];
        }
        if (! is_array($configured) || ! array_is_list($configured)) {
            return [];
        }
        $ids = [];
        foreach ($configured as $id) {
            if (is_int($id)) {
                if ($id <= 0) {
                    return [];
                }
                $id = (string) $id;
            } elseif (is_string($id)) {
                $id = trim($id);
                if (preg_match('/^[1-9][0-9]*$/D', $id) !== 1) {
                    return [];
                }
            } else {
                return [];
            }
            $ids[] = $id;
        }

        return array_values(array_unique($ids, SORT_STRING));
    }
}

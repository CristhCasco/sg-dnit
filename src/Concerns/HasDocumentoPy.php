<?php

namespace CristhCasco\SgDnit\Concerns;

/**
 * Para el modelo Customer: formato del documento paraguayo.
 * Con RUC (física o jurídica) "1234567-8"; sin RUC, solo el CI.
 */
trait HasDocumentoPy
{
    public function getFullDocumentAttribute()
    {
        $number = trim((string) ($this->document_number ?: $this->ci));
        if ($number === '') {
            return null;
        }

        $dv = trim((string) $this->dv);
        return $dv !== '' ? $number . '-' . $dv : $number;
    }

    public function getDocumentLabelAttribute()
    {
        return trim((string) $this->dv) !== '' ? 'RUC' : 'C.I.';
    }
}

<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Dns\UpdateDnsRecordRequest;
use App\Models\DnsRecord;

/**
 * The panel's update rules, for a PATCH: every field left out keeps its current value. The API
 * route names the record {record}, so its type reaches the panel rules through the merged input.
 */
class PatchDnsRecordRequest extends UpdateDnsRecordRequest
{
    protected function prepareForValidation(): void
    {
        /** @var DnsRecord $record */
        $record = $this->route('record');

        $this->merge(array_diff_key([
            'name' => $record->name,
            'type' => $record->type->value,
            'priority' => $record->priority,
            'value' => $record->value,
        ], $this->all()));

        parent::prepareForValidation();
    }
}

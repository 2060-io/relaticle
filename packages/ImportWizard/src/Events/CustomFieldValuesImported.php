<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Events;

final readonly class CustomFieldValuesImported
{
    /**
     * @param  list<array{entity_type: string, entity_id: string, custom_field_id: string, tenant_id: string}>  $values
     */
    public function __construct(
        public array $values,
    ) {}
}

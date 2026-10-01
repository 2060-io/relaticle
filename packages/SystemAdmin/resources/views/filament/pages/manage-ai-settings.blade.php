<x-filament-panels::page>
    {{ $this->form }}

    <x-filament::section heading="This month" description="Billed comes from each provider's cost API (about a day behind). Estimate is our own ledger at list price.">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500 dark:text-gray-400">
                    <th class="py-2">Provider</th>
                    <th class="py-2">Budget</th>
                    <th class="py-2">Billed</th>
                    <th class="py-2">Our estimate</th>
                    <th class="py-2">Difference</th>
                    <th class="py-2">Last fetch</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($this->providerMonth() as $row)
                    <tr class="border-t border-gray-100 dark:border-white/5">
                        <td class="py-2 font-medium">{{ $this->providerLabel($row['provider']) }}</td>
                        <td class="py-2">{{ \Relaticle\SystemAdmin\Metrics\Money::format($row['budget_micros']) }}</td>
                        <td class="py-2">{{ $row['billed_micros'] === null ? \Relaticle\SystemAdmin\Metrics\ProviderBudget::unbilledNote($row['provider']) : \Relaticle\SystemAdmin\Metrics\Money::format($row['billed_micros']) }}</td>
                        <td class="py-2">{{ \Relaticle\SystemAdmin\Metrics\Money::format($row['estimate_micros']) }}</td>
                        <td class="py-2">{{ $row['billed_micros'] === null ? \Relaticle\SystemAdmin\Metrics\Money::EMPTY : \Relaticle\SystemAdmin\Metrics\Money::format($row['billed_micros'] - $row['estimate_micros']) }}</td>
                        <td class="py-2">{{ $row['last_fetched']?->toDateString() ?? \Relaticle\SystemAdmin\Metrics\Money::EMPTY }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>

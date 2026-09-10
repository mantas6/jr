<x-filament-panels::page>
    <p class="text-sm text-gray-500 dark:text-gray-400">
        {{ $this->getConnectionStatus() }}
    </p>

    {{ $this->form }}
</x-filament-panels::page>

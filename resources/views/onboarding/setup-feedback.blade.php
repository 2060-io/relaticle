<x-layouts::filament-standalone :title="__('mail.setup_feedback.title')">
    <div class="flex min-h-screen flex-col">
        <header class="flex justify-center px-6 pt-10">
            <a href="{{ url('/') }}">
                <x-brand.logo-lockup size="lg" class="text-black dark:text-white" />
            </a>
        </header>

        <main class="flex flex-1 items-center justify-center p-4 sm:p-6">
            <div class="w-full max-w-md rounded-2xl border border-gray-200 bg-white p-8 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                @if($sent)
                    <h1 class="text-xl font-semibold tracking-tight text-gray-900 dark:text-white">{{ __('mail.setup_feedback.done_heading') }}</h1>
                    <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">{{ __('mail.setup_feedback.done_body') }}</p>
                @else
                    <h1 class="text-xl font-semibold tracking-tight text-gray-900 dark:text-white">{{ __('mail.setup_feedback.heading', ['workspace' => $workspace->name]) }}</h1>
                    <p class="mt-3 text-sm font-medium text-gray-900 dark:text-white">{{ $reason->getLabel() }}</p>
                    <form method="POST" action="{{ request()->fullUrl() }}" class="mt-6 space-y-4">
                        <label class="block text-sm text-gray-600 dark:text-gray-400" for="note">{{ __('mail.setup_feedback.note') }}</label>
                        <textarea id="note" name="note" rows="3" maxlength="500" class="w-full rounded-lg border border-gray-300 bg-white p-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-950 dark:text-white"></textarea>
                        <button type="submit" class="inline-flex w-full items-center justify-center rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-primary-700">
                            {{ __('mail.setup_feedback.send') }}
                        </button>
                    </form>
                @endif
            </div>
        </main>
    </div>
</x-layouts::filament-standalone>

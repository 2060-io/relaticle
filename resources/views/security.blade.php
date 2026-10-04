@php
    $assistantName = (string) config('chat.assistant_name');
    $emailActive = \Relaticle\EmailIntegration\EmailIntegrationServiceProvider::enabled();
    $hosted = \Laravel\Pennant\Feature::active(\App\Features\Billing::class);

    $title = __('Security: where your CRM data lives').' - Relaticle';
    $description = __(
        'How Relaticle protects accounts and workspaces, what the AI sees, which providers handle your data, and how to export or delete it.'
    );

    $account = [
        ['ri-fingerprint-line', __('Passkeys'), __('Sign in with a passkey instead of a password. Passwords are stored hashed, never in plain text.')],
        ['ri-shield-keyhole-line', __('Two-factor authentication'), __('Require an authenticator code on every sign-in that does not use a passkey. You save recovery codes when you turn it on.')],
        ['ri-login-circle-line', __('Google and Microsoft sign-in'), __('Sign in with the Google or Microsoft account you already use for work.')],
        ['ri-logout-box-r-line', __('Session control'), __('Sign out every other browser session from Settings, Security.')],
    ];

    $workspace = [
        ['ri-building-4-line', __('One workspace per record'), __('Every record belongs to one workspace. Each request is checked against that workspace before it reads or writes.')],
        ['ri-team-line', __('Four roles'), __('Owner, Admin, Member and Viewer decide what each person can see and change.')],
        ['ri-history-line', __('A log of every change'), __('The activity log on a record shows each field change and who made it.')],
    ];

    $ai = [
        [
            __('The built-in assistant'),
            __('A request to :name, a voice message or an email summary sends the content needed to answer it to an AI provider: Anthropic or OpenAI. :name proposes every change as a card and waits for your approval.', ['name' => $assistantName]),
        ],
        [
            __('Assistants you connect'),
            __('Claude, ChatGPT and other MCP clients receive only what they request through the tools you authorized, inside the one workspace you picked. Their changes apply directly. Relaticle does not see or store the conversation in your assistant.'),
        ],
        [
            __('On your own server'),
            __('Self-host Relaticle with your own provider key, or with a local model through Ollama. With a local model, the assistant sends nothing to an outside AI provider.'),
        ],
    ];

    $email = [
        __('Relaticle reads mail and calendar events only from an account you connect, and never changes, labels or deletes messages in your mailbox.'),
        __('Mailbox access tokens are encrypted at rest. Disconnecting deletes them.'),
    ];

    $tokens = [
        ['ri-key-2-line', __('Scoped tokens'), __('A personal access token is stored hashed and carries only the permissions you give it.')],
        ['ri-timer-line', __('Expiring connector access'), __('Connector access tokens expire after 30 days and refresh tokens after 90.')],
        ['ri-close-circle-line', __('Instant revoke'), __('Revoke a token or a connector under Settings, Access Tokens. It stops working at once.')],
    ];

    $ownership = [
        ['ri-download-2-line', __('Export'), __('Download every record type as CSV or Excel, custom fields included. The REST API reads the same records.')],
        ['ri-delete-bin-line', __('Delete'), __('Ask for deletion at privacy@relaticle.com. An account scheduled for deletion is removed after a 30-day grace period. Records in shared workspaces remain.')],
        ['ri-server-line', __('Leave'), __('Relaticle is open source under AGPL-3.0. Move to your own server whenever you want.')],
    ];

    $providers = [
        ['Hetzner', __('Hosts the application and its database'), __('All workspace data')],
        ['Mailcoach', __('Sends account, notification and product update email'), __('Recipient name, address, message content and usage tags')],
        ['Postmark', __('Delivers that email for Mailcoach'), __('Recipient address and message content')],
        ['Stripe', __('Takes payment for Cloud plans'), __('Billing details. Card numbers go to Stripe directly.')],
        ['Sentry', __('Reports application errors'), __('Anonymized error reports')],
        ['Fathom Analytics', __('Counts page views on the website and in the app'), __('Page, referrer and signup events, without cookies')],
        ['Anthropic, OpenAI', __('Run the AI models behind the assistant, voice input and email summaries'), __('The content of that request')],
        ['Google, DuckDuckGo', __('Look up a company logo'), __('The company domain')],
        ['Maxforms', __('Hosts the support and feedback forms'), __('What you type into the form')],
        ['Oh Dear', __('Watches uptime and health checks'), __('No customer data')],
    ];

    $faqs = [
        [
            __('Does Relaticle train AI models on my data?'),
            __('No. Relaticle does not train AI models on your CRM data, does not sell it, and does not use it for advertising.'),
        ],
        [
            __('Is Relaticle SOC 2 or ISO 27001 certified?'),
            __('No. Relaticle holds neither certification today. The code is open source, so the controls on this page can be read rather than taken on trust.'),
        ],
        [
            __('Can I move my data out of Relaticle Cloud?'),
            __('Yes. Export every record type as CSV or Excel, read your records through the REST API, or run the same code on your own server.'),
        ],
        [
            __('How do I report a security problem?'),
            __('Email security@relaticle.com with what you found and the steps to reproduce it. We aim to acknowledge reports within 48 hours.'),
        ],
    ];

    $card = 'rounded-xl border border-gray-200/80 dark:border-white/[0.06] bg-white dark:bg-white/[0.02] p-6';
    $iconWrap = 'flex items-center justify-center w-9 h-9 rounded-lg bg-primary/[0.08] dark:bg-primary/[0.15] mb-4';
    $sectionTitle = 'font-display text-2xl sm:text-3xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white';
    $sectionLead = 'mt-4 text-base text-gray-500 dark:text-gray-400 leading-relaxed';
@endphp

<x-guest-layout
    :title="$title"
    :description="$description"
    :ogTitle="$title"
    :ogDescription="$description"
>
    {{-- Hero --}}
    <section class="relative pt-32 pb-20 md:pt-40 md:pb-24 bg-white dark:bg-gray-950 overflow-hidden">
        <div class="absolute inset-0 bg-[linear-gradient(to_right,rgba(0,0,0,0.015)_1px,transparent_1px),linear-gradient(to_bottom,rgba(0,0,0,0.015)_1px,transparent_1px)] dark:bg-[linear-gradient(to_right,rgba(255,255,255,0.025)_1px,transparent_1px),linear-gradient(to_bottom,rgba(255,255,255,0.025)_1px,transparent_1px)] bg-[size:3rem_3rem] [mask-image:radial-gradient(ellipse_70%_50%_at_50%_50%,black_30%,transparent_100%)]"></div>

        <div class="relative max-w-3xl mx-auto px-6 lg:px-8 text-center">
            <div class="flex justify-center mb-6">
                <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full border border-gray-200/80 dark:border-white/[0.08] bg-white/80 dark:bg-white/[0.04] backdrop-blur-sm shadow-[0_1px_2px_rgba(0,0,0,0.03)]">
                    <x-ri-shield-check-line class="h-3.5 w-3.5 text-primary dark:text-primary-400"/>
                    <span class="uppercase tracking-wider text-[10px] font-medium text-gray-500 dark:text-gray-400">{{ __('Security') }}</span>
                </div>
            </div>

            <h1 class="font-display text-4xl sm:text-5xl font-bold text-gray-950 dark:text-white tracking-[-0.03em] leading-[1.1]">
                {{ __('How Relaticle protects your data') }}
            </h1>

            <p class="mt-5 text-base md:text-lg text-gray-500 dark:text-gray-400 leading-relaxed max-w-2xl mx-auto">
                {{ __('Where your records live, who can reach them, and what the AI sees. Relaticle is open source, so most of this page can be checked in the code.') }}
            </p>

            <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-3">
                <x-marketing.button href="{{ route('policy.show') }}">
                    {{ __('Read the privacy policy') }}
                </x-marketing.button>
                <x-marketing.button variant="secondary" href="#report">
                    {{ __('Report a vulnerability') }}
                </x-marketing.button>
            </div>
        </div>
    </section>

    {{-- Account --}}
    <section class="py-20 md:py-28 bg-gray-50 dark:bg-gray-950">
        <div class="max-w-5xl mx-auto px-6 lg:px-8">
            <div class="max-w-2xl mx-auto text-center mb-14">
                <h2 class="{{ $sectionTitle }}">{{ __('Your account') }}</h2>
                <p class="{{ $sectionLead }}">{{ __('How you sign in, and what protects the account.') }}</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                @foreach($account as [$icon, $cardTitle, $cardDesc])
                    <div class="{{ $card }}">
                        <div class="{{ $iconWrap }}">
                            <x-dynamic-component :component="$icon" class="w-4.5 h-4.5 text-primary dark:text-primary-400"/>
                        </div>
                        <h3 class="font-display text-base font-semibold text-gray-900 dark:text-white mb-1.5">{{ $cardTitle }}</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 leading-relaxed">{{ $cardDesc }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Workspace --}}
    <section class="py-20 md:py-28 bg-white dark:bg-gray-950">
        <div class="max-w-5xl mx-auto px-6 lg:px-8">
            <div class="max-w-2xl mx-auto text-center mb-14">
                <h2 class="{{ $sectionTitle }}">{{ __('Your workspace') }}</h2>
                <p class="{{ $sectionLead }}">{{ __('Who can reach a record, and how you see what they did.') }}</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                @foreach($workspace as [$icon, $cardTitle, $cardDesc])
                    <div class="{{ $card }}">
                        <div class="{{ $iconWrap }}">
                            <x-dynamic-component :component="$icon" class="w-4.5 h-4.5 text-primary dark:text-primary-400"/>
                        </div>
                        <h3 class="font-display text-base font-semibold text-gray-900 dark:text-white mb-1.5">{{ $cardTitle }}</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 leading-relaxed">{{ $cardDesc }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- AI --}}
    <section id="ai" class="py-20 md:py-28 bg-gray-50 dark:bg-gray-950">
        <div class="max-w-3xl mx-auto px-6 lg:px-8">
            <div class="text-center mb-14">
                <h2 class="{{ $sectionTitle }}">{{ __('What the AI sees') }}</h2>
                <p class="{{ $sectionLead }} max-w-xl mx-auto">{{ __('Three ways AI touches your records, and what leaves Relaticle in each.') }}</p>
            </div>

            <div class="space-y-4">
                @foreach($ai as [$term, $meaning])
                    <div class="{{ $card }}">
                        <h3 class="font-display text-base font-semibold text-gray-900 dark:text-white mb-1.5">{{ $term }}</h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400 leading-relaxed">{{ $meaning }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    @if($emailActive)
        {{-- Email --}}
        <section class="py-20 md:py-28 bg-white dark:bg-gray-950">
            <div class="max-w-3xl mx-auto px-6 lg:px-8">
                <div class="text-center mb-14">
                    <h2 class="{{ $sectionTitle }}">{{ __('Email and calendar') }}</h2>
                    <p class="{{ $sectionLead }} max-w-xl mx-auto">{{ __('What happens when you connect a Google or Microsoft account.') }}</p>
                </div>

                <ul class="space-y-3">
                    @foreach($email as $point)
                        <li class="flex gap-3 {{ $card }}">
                            <x-ri-check-line class="mt-0.5 h-4.5 w-4.5 shrink-0 text-primary dark:text-primary-400"/>
                            <span class="text-sm text-gray-700 dark:text-gray-300 leading-relaxed">{{ $point }}</span>
                        </li>
                    @endforeach
                </ul>

                <p class="mt-6 text-center text-sm">
                    <a href="{{ route('policy.show') }}" class="font-medium text-primary dark:text-primary-400 hover:underline">{{ __('Read the privacy policy for sharing settings, deletion and the Google Limited Use commitment.') }}</a>
                </p>
            </div>
        </section>
    @endif

    {{-- Tokens --}}
    <section class="py-20 md:py-28 bg-gray-50 dark:bg-gray-950">
        <div class="max-w-5xl mx-auto px-6 lg:px-8">
            <div class="max-w-2xl mx-auto text-center mb-14">
                <h2 class="{{ $sectionTitle }}">{{ __('API and connector access') }}</h2>
                <p class="{{ $sectionLead }}">{{ __('Every token is scoped, and you can cut any of them off.') }}</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                @foreach($tokens as [$icon, $cardTitle, $cardDesc])
                    <div class="{{ $card }}">
                        <div class="{{ $iconWrap }}">
                            <x-dynamic-component :component="$icon" class="w-4.5 h-4.5 text-primary dark:text-primary-400"/>
                        </div>
                        <h3 class="font-display text-base font-semibold text-gray-900 dark:text-white mb-1.5">{{ $cardTitle }}</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 leading-relaxed">{{ $cardDesc }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Ownership --}}
    <section class="py-20 md:py-28 bg-white dark:bg-gray-950">
        <div class="max-w-5xl mx-auto px-6 lg:px-8">
            <div class="max-w-2xl mx-auto text-center mb-14">
                <h2 class="{{ $sectionTitle }}">{{ __('Your data stays yours') }}</h2>
                <p class="{{ $sectionLead }}">{{ __('Export it, delete it, or take it to your own server.') }}</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                @foreach($ownership as [$icon, $cardTitle, $cardDesc])
                    <div class="{{ $card }}">
                        <div class="{{ $iconWrap }}">
                            <x-dynamic-component :component="$icon" class="w-4.5 h-4.5 text-primary dark:text-primary-400"/>
                        </div>
                        <h3 class="font-display text-base font-semibold text-gray-900 dark:text-white mb-1.5">{{ $cardTitle }}</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 leading-relaxed">{{ $cardDesc }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    @if($hosted)
    {{-- Providers --}}
    <section id="providers" class="py-20 md:py-28 bg-gray-50 dark:bg-gray-950">
        <div class="max-w-4xl mx-auto px-6 lg:px-8">
            <div class="max-w-2xl mx-auto text-center mb-14">
                <h2 class="{{ $sectionTitle }}">{{ __('Service providers') }}</h2>
                <p class="{{ $sectionLead }}">{{ __('The companies that handle data for Relaticle Cloud, and what each one receives.') }}</p>
            </div>

            <div class="overflow-x-auto rounded-xl border border-gray-200/80 dark:border-white/[0.06] bg-white dark:bg-white/[0.02]">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">{{ __('Service providers for Relaticle Cloud') }}</caption>
                    <thead>
                        <tr class="border-b border-gray-200/80 dark:border-white/[0.06]">
                            <th scope="col" class="px-5 py-3 font-display font-semibold text-gray-900 dark:text-white">{{ __('Provider') }}</th>
                            <th scope="col" class="px-5 py-3 font-display font-semibold text-gray-900 dark:text-white">{{ __('What it does') }}</th>
                            <th scope="col" class="px-5 py-3 font-display font-semibold text-gray-900 dark:text-white">{{ __('What it receives') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200/80 dark:divide-white/[0.06]">
                        @foreach($providers as [$provider, $purpose, $data])
                            <tr>
                                <th scope="row" class="px-5 py-3 font-medium text-gray-900 dark:text-white whitespace-nowrap">{{ $provider }}</th>
                                <td class="px-5 py-3 text-gray-600 dark:text-gray-400">{{ $purpose }}</td>
                                <td class="px-5 py-3 text-gray-600 dark:text-gray-400">{{ $data }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="mt-6 text-center text-sm text-gray-500 dark:text-gray-400">
                {{ __('A self-hosted install uses only the logo lookup, unless its operator configures the others.') }}
            </p>
        </div>
    </section>
    @endif

    {{-- Report --}}
    <section id="report" class="py-20 md:py-28 bg-white dark:bg-gray-950">
        <div class="max-w-3xl mx-auto px-6 lg:px-8 text-center">
            <h2 class="{{ $sectionTitle }}">{{ __('Report a vulnerability') }}</h2>
            <p class="{{ $sectionLead }} max-w-xl mx-auto">
                {{ __('Email what you found and the steps to reproduce it. Please do not open a public issue. We aim to acknowledge reports within 48 hours.') }}
            </p>
            <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-3">
                <x-marketing.button href="mailto:security@relaticle.com">
                    security@relaticle.com
                </x-marketing.button>
                <x-marketing.button variant="secondary" href="{{ route('securityTxt') }}">
                    security.txt
                </x-marketing.button>
            </div>
        </div>
    </section>

    {{-- FAQ --}}
    <section class="py-20 md:py-28 bg-gray-50 dark:bg-gray-950">
        <div class="max-w-3xl mx-auto px-6 lg:px-8">
            <div class="text-center mb-10">
                <h2 class="{{ $sectionTitle }}">{{ __('Security questions, answered') }}</h2>
            </div>

            <x-marketing.faq-accordion :faqs="$faqs" id-prefix="security-faq" />
        </div>
    </section>

    @php
        $schema = (new \Spatie\SchemaOrg\Graph())
            ->webPage(fn ($webPage) => $webPage
                ->name($title)
                ->description($description)
                ->url(route('security')))
            ->fAQPage(fn ($faqPage) => $faqPage
                ->mainEntity(collect($faqs)->map(fn (array $faq) => \Spatie\SchemaOrg\Schema::question()
                    ->name($faq[0])
                    ->acceptedAnswer(\Spatie\SchemaOrg\Schema::answer()->text($faq[1])))->all()))
            ->breadcrumbList(fn ($list) => $list
                ->itemListElement([
                    \Spatie\SchemaOrg\Schema::listItem()->position(1)->name('Relaticle')->item(url('/')),
                    \Spatie\SchemaOrg\Schema::listItem()->position(2)->name(__('Security'))->item(route('security')),
                ]));
    @endphp

    {!! $schema->toScript() !!}
</x-guest-layout>

@php
    $faqs = [
        ['Is Relaticle production-ready?', 'Yes. Teams run Relaticle in production today. Every change passes an automated test suite before it ships, and 5-layer authorization keeps each workspace\'s data separate.'],
        ['What can the built-in AI chat do?', 'Ask anything about your CRM and the chat works on your data: list and search records, draft follow-ups, summarize a deal, create a task, update or delete a record. @-mention any record (people, companies, opportunities, tasks, notes) to scope a question. Voice input, persistent searchable history, and dashboard insight cards are included.'],
        ['Can the AI chat delete or change my CRM data without my approval?', 'No. Destructive operations (delete, update existing records) show an approval card with Approve and Reject buttons. Nothing happens until you click. Approved destructive actions can be undone for 5 seconds via a toast. Read-only and create operations don\'t require approval.'],
        ['Does the built-in chat send my data to OpenAI or Anthropic?', 'Inference runs through whichever AI provider your team configures (Anthropic Claude, Google Gemini, or any OpenAI-compatible endpoint). Conversation history is stored only in your Relaticle database, and Relaticle never trains on your data. Self-hosted teams supply their own provider keys, so the destination is yours to choose.'],
        ['What AI agents can I connect from outside?', 'Claude, ChatGPT, Gemini, open-source models, or your own agent. They connect over MCP (Model Context Protocol), an open standard, and can read, create, update, delete, and analyze your CRM data.'],
        ['What is MCP?', 'MCP (Model Context Protocol) is an open standard that lets AI agents interact with tools and data sources. Relaticle\'s MCP server lets external agents list companies, create people, update opportunities, analyze pipelines, and more.'],
        ['How is Relaticle different from HubSpot or Salesforce?', 'Relaticle is self-hosted (you own your data), open-source (AGPL-3.0), ships with a built-in AI chat, lets Claude, ChatGPT, or your own agent work in the same records over MCP, and has no per-seat pricing. It\'s designed for teams who want AI built in and AI integration without vendor lock-in.'],
        ['How do I deploy Relaticle?', 'The quickest way is the hosted version at app.relaticle.com: sign up and start. To run it yourself, deploy with Docker Compose on your own server, and your data never leaves it.'],
        ['Can I customize the data model?', 'Yes. Add custom fields to any record: text, email, phone, currency, date, select, multi-select, and links to other records. You can encrypt sensitive fields. No code changes needed.'],
    ];
@endphp

<x-guest-layout
    :title="config('app.name') . ' - ' . __('CRM Built for People and AI-Powered Work')"
    description="Open-source, self-hosted CRM. Ask the built-in AI chat, or connect Claude and ChatGPT to read and update your records. Unlimited users. Free forever."
    :ogTitle="config('app.name') . ' - ' . __('CRM Built for People and AI-Powered Work')"
    ogDescription="Open-source CRM for teams and AI-powered work. Use the app, ask the built-in chat, or work from Claude and ChatGPT over MCP. Self-hosted, you own your data.">
    @push('header')
        @vite('resources/js/motion.js')
    @endpush

    @include('home.partials.hero')
    @include('home.partials.works-with')
    @include('home.partials.features')
    @include('home.partials.community')
    @include('home.partials.faq')
    @include('home.partials.start-building')

    @php
        $schema = (new \Spatie\SchemaOrg\Graph())
            ->softwareApplication(fn ($app) => $app
                ->name('Relaticle')
                ->applicationCategory('BusinessApplication')
                ->applicationSubCategory('CRM')
                ->operatingSystem('Linux, macOS, Windows')
                ->description('The open-source CRM built for people and AI-powered work. Self-hosted with a built-in AI chat (with @-mentions, safe approvals, voice, and persistent history) plus a production-grade MCP server, REST API, and custom fields. Connect any external agent -- Claude, GPT, Gemini, or open-source models.')
                ->url(url('/'))
                ->offers(\Spatie\SchemaOrg\Schema::offer()->price('0')->priceCurrency('USD'))
                ->setProperty('featureList', [
                    'Built-in AI chat with @-mentions to records, safe approvals on destructive actions, undo, and voice input',
                    'Persistent searchable conversation history',
                    'Dashboard AI insight cards (overdue tasks, recent wins, pipeline)',
                    'MCP server for external AI agents',
                    'REST API with full CRUD operations',
                    'Custom fields with per-field encryption',
                    'Self-hosted with full data ownership',
                    'Multi-workspace isolation with 5-layer authorization',
                    'CSV import and export',
                ])
                ->license('https://www.gnu.org/licenses/agpl-3.0.html')
            )
            ->organization(fn ($org) => $org
                ->name('Relaticle')
                ->url(url('/'))
                ->logo(asset('web-app-manifest-512x512.png'))
                ->sameAs(array_filter([
                    'https://github.com/relaticle/relaticle',
                    config('services.discord.invite_url'),
                ]))
            )
            ->website(fn ($site) => $site
                ->name('Relaticle')
                ->url(url('/'))
            )
            ->fAQPage(function ($faq) use ($faqs) {
                return $faq->mainEntity(array_map(fn ($item) => \Spatie\SchemaOrg\Schema::question()
                    ->name($item[0])
                    ->acceptedAnswer(
                        \Spatie\SchemaOrg\Schema::answer()->text($item[1])
                    ), $faqs));
            });
    @endphp

    {!! $schema->toScript() !!}
</x-guest-layout>

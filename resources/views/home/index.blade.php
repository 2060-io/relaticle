@php
    $mcpToolCount = \App\Support\CompetitorFacts::mcpToolCount();
    $assistantName = (string) config('chat.assistant_name');
    $proposalExpiry = \Carbon\CarbonInterval::minutes((int) config('chat.pending_action_expiry_minutes'))->cascade()->forHumans();
    $emailIntegrationActive = \Laravel\Pennant\Feature::active(\App\Features\EmailIntegration::class);

    $faqs = array_values(array_filter([
        ['Is Relaticle production-ready?', 'Yes. Relaticle runs in production and has 6,000+ automated tests, including 380+ for the MCP server. Every pull request runs PHPStan static analysis and the full Pest suite.'],
        ["What can {$assistantName} do?", "{$assistantName} is the AI assistant built into Relaticle. Ask it anything about your CRM and it works on your data: list and search records, draft follow-ups, summarize an opportunity, create a task, update or delete a record. @-mention any record (people, companies, opportunities, tasks, notes) to scope a question. Voice input and persistent, searchable history are included."],
        ["Can {$assistantName} create, change, or delete my CRM data without my approval?", "No. {$assistantName} proposes every create, update, and delete as a card showing exactly what will change. Nothing is written until you confirm it, and you can discard any proposal. An unanswered proposal expires after {$proposalExpiry}. Reading and searching need no approval."],
        ['Do external AI agents need my approval for each change?', 'No. An agent you connect over MCP, such as Claude or ChatGPT, acts with your permissions in the one workspace you approved, and its changes apply directly. Only {$assistantName} adds a review step before every write.'],
        ["Does {$assistantName} send my data to OpenAI or Anthropic?", "On Relaticle Cloud, {$assistantName} runs on the model you pick from Anthropic, OpenAI, or Google. Self-hosted installs use their own provider keys or a local model through Ollama, so the destination is yours to choose. Conversation history is stored only in your Relaticle database, and Relaticle never trains on your data."],
        ['What AI agents can I connect from outside?', "Any agent that speaks MCP (Model Context Protocol): Claude, ChatGPT, Gemini, Cursor, VS Code, open-source models, or your own custom agents. Relaticle's {$mcpToolCount}-tool MCP server lets them read, create, update, delete, and analyze CRM data."],
        ['What is MCP?', "MCP (Model Context Protocol) is an open standard that lets AI agents interact with tools and data sources. Relaticle's {$mcpToolCount}-tool MCP server lets external agents list companies, create people, update opportunities, analyze pipelines, and more."],
        $emailIntegrationActive
            ? ['Does Relaticle sync my email and calendar?', 'Yes. Connect a Google account and your email and meetings appear on the people, companies, and opportunities they involve. You can reply and send from the record, and you choose how much of each email your workspace can see. Relaticle connects Google accounts for now.']
            : null,
        ['How is Relaticle different from HubSpot or Salesforce?', "Relaticle is open source (AGPL-3.0), can be self-hosted so you own your data, ships with {$assistantName}, a built-in AI assistant, and an MCP server for any external agent, and has no per-seat pricing. It's designed for teams who want AI built in and AI integration without vendor lock-in."],
        ['How do I deploy Relaticle?', 'Deploy with Docker Compose, Coolify, Dokploy, or Laravel Cloud, or manually on PHP 8.5+ with PostgreSQL and Redis. Self-hosted means your data never leaves your server. A managed hosting option is also available at app.relaticle.com.'],
        ['Can I customize the data model?', 'Yes. Relaticle offers 20 custom field types, including text, email, phone, currency, date, select, multi-select, and links to other records, with per-field encryption. No migrations or code changes needed.'],
    ]));
@endphp

<x-guest-layout
    :title="config('app.name') . ' - ' . __('CRM Built for People and AI-Powered Work')"
    :description="'Open-source, self-hosted CRM with '.$assistantName.', a built-in AI assistant, and an MCP server for Claude, ChatGPT, and your own agents. Free to self-host.'"
    :ogTitle="config('app.name') . ' - Human-First CRM with Agent-Native Infrastructure'"
    :ogDescription="'Open-source CRM for teams and AI-powered work. Use the app, ask '.$assistantName.', or connect Claude, ChatGPT, and your own agents over MCP. Self-hosted, you own your data.'">
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
                ->description("The open-source CRM built for people and AI-powered work. Self-hosted with {$assistantName}, a built-in AI assistant (with @-mentions, approval before every write, voice, and persistent history), plus a {$mcpToolCount}-tool MCP server, REST API, and 20 custom field types. Connect any external agent: Claude, ChatGPT, or open-source models.")
                ->url(url('/'))
                ->offers(\Spatie\SchemaOrg\Schema::offer()->name('Self-hosted')->description('Free to self-host under the AGPL-3.0 license.')->price('0')->priceCurrency('USD'))
                ->setProperty('featureList', array_values(array_filter([
                    "{$assistantName}, a built-in AI assistant with @-mentions to records, approval before every write, and voice input",
                    'Persistent searchable conversation history',
                    "{$mcpToolCount}-tool MCP server for external AI agents",
                    'REST API with full CRUD operations',
                    $emailIntegrationActive ? 'Gmail and Google Calendar sync with per-email sharing controls' : null,
                    '20 custom field types with per-field encryption',
                    'Self-hosted with full data ownership',
                    'Multi-workspace isolation with role-based permissions',
                    '6,000+ automated tests',
                    'CSV import and export',
                ])))
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

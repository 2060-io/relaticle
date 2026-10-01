<x-mail::message :reason="__('mail.footer.reason.onboarding', ['company' => config('relaticle.company.name')])">
<x-slot:preheader>{{ __('mail.setup_nudge.preheader', ['workspace' => $workspaceName, 'step' => $stepLabel]) }}</x-slot:preheader>
# {{ __('mail.setup_nudge.heading', ['name' => $greetingName, 'workspace' => $workspaceName]) }}

{{ __('mail.setup_nudge.step', ['step' => $stepLabel]) }} {{ $stepDescription }}.

<x-mail::button :url="$conversationUrl">
{{ __('mail.setup_nudge.cta', ['assistant' => config('chat.assistant_name')]) }}
</x-mail::button>

{{ __('mail.setup_nudge.reasons_intro') }}
@foreach ($reasonLinks as $link)
[{{ $link['label'] }}]({{ $link['url'] }}){{ $loop->last ? '' : ' · ' }}
@endforeach
</x-mail::message>

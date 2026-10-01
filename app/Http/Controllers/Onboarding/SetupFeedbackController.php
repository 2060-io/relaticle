<?php

declare(strict_types=1);

namespace App\Http\Controllers\Onboarding;

use App\Actions\Onboarding\RecordSetupExitReason;
use App\Enums\SetupExitReason;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final readonly class SetupFeedbackController
{
    public function __construct(private RecordSetupExitReason $recordReason) {}

    public function show(Workspace $workspace, SetupExitReason $reason): View
    {
        return $this->page($workspace, $reason, sent: false);
    }

    public function store(Request $request, Workspace $workspace): View|RedirectResponse
    {
        $note = $request->input('note');

        if (is_string($note)) {
            $request->merge(['note' => str_replace("\r\n", "\n", $note)]);
        }

        $validator = Validator::make($request->all(), [
            'reason' => ['required', Rule::enum(SetupExitReason::class)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return redirect($request->fullUrl())->withErrors($validator)->withInput();
        }

        /** @var array{reason: string, note?: string|null} $validated */
        $validated = $validator->validated();
        $reason = SetupExitReason::from($validated['reason']);

        $this->recordReason->execute($workspace, $reason, $validated['note'] ?? null);

        return $this->page($workspace, $reason, sent: true);
    }

    private function page(Workspace $workspace, SetupExitReason $reason, bool $sent): View
    {
        return view('onboarding.setup-feedback', [
            'workspace' => $workspace,
            'reason' => $reason,
            'reasons' => SetupExitReason::cases(),
            'sent' => $sent,
        ]);
    }
}

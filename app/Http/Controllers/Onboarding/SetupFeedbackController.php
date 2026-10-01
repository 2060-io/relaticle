<?php

declare(strict_types=1);

namespace App\Http\Controllers\Onboarding;

use App\Actions\Onboarding\RecordSetupExitReason;
use App\Enums\SetupExitReason;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\View\View;

final readonly class SetupFeedbackController
{
    public function __construct(private RecordSetupExitReason $recordReason) {}

    public function show(Workspace $workspace, SetupExitReason $reason): View
    {
        return view('onboarding.setup-feedback', ['workspace' => $workspace, 'reason' => $reason, 'sent' => false]);
    }

    public function store(Request $request, Workspace $workspace, SetupExitReason $reason): View
    {
        /** @var array{note?: string|null} $validated */
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        $this->recordReason->execute($workspace, $reason, $validated['note'] ?? null);

        return view('onboarding.setup-feedback', ['workspace' => $workspace, 'reason' => $reason, 'sent' => true]);
    }
}

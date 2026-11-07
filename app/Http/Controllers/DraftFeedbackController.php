<?php

namespace App\Http\Controllers;

use App\Models\DraftFeedback;
use App\Models\ResponseDraft;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Thumbs up / down on an AI draft. Thumbs-down reasons feed back into the drafting prompt. */
class DraftFeedbackController extends Controller
{
    public function store(Request $request, string $draft): RedirectResponse
    {
        $model = ResponseDraft::query()->findOrFail($draft);
        $this->authorizeLocation('view-location', $model->location_id);
        abort_if($model->original_text === null, 422, 'Only AI drafts can be rated.');

        $data = $request->validate([
            'rating' => ['required', Rule::in(['up', 'down'])],
            'reason' => ['nullable', Rule::in(DraftFeedback::REASONS)],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        DraftFeedback::query()->updateOrCreate(
            ['draft_id' => $model->id, 'user_id' => $request->user()->id],
            [
                'organization_id' => $model->organization_id,
                'location_id' => $model->location_id,
                'rating' => $data['rating'] === 'up' ? 1 : -1,
                'reason' => $data['rating'] === 'down' ? ($data['reason'] ?? null) : null,
                'comment' => $data['rating'] === 'down' ? ($data['comment'] ?? null) : null,
            ],
        );

        return $this->backWith('Thanks! ReviewPilot uses this to learn your style.');
    }
}

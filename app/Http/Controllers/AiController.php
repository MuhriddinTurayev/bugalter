<?php

namespace App\Http\Controllers;

use App\Ai\AiAccountant;
use App\Ai\AiException;
use App\Ai\AiSettings;
use App\Ai\ClassifyTransactions;
use App\Ai\ProposalService;
use App\Enums\ProposalStatus;
use App\Models\AiConversation;
use App\Models\AiProposal;
use App\Models\Transaction;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** The AI accountant: chat (each person sees their own conversations) and the proposals it made. */
class AiController extends Controller
{
    public function index(Request $request, AiSettings $settings): View
    {
        $conversations = AiConversation::query()->where('user_id', $request->user()->id)->latest('updated_at')->limit(30)->get();
        $current = $request->integer('c') ? $conversations->firstWhere('id', $request->integer('c')) : null;

        return view('ai.index', [
            'conversations' => $conversations,
            'current' => $current?->load('messages'),
            'config' => $settings->resolve(),
            'pendingProposals' => AiProposal::query()->where('status', ProposalStatus::Pending)->count(),
        ]);
    }

    /** One question; answered synchronously (the browser shows a spinner). JSON for the chat UI. */
    public function message(Request $request, AiAccountant $accountant): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'conversation_id' => ['nullable', 'integer'],
        ]);

        $conversation = isset($data['conversation_id'])
            ? AiConversation::query()->where('user_id', $request->user()->id)->findOrFail($data['conversation_id'])
            : AiConversation::create(['user_id' => $request->user()->id, 'title' => __('ai.new_conversation')]);

        try {
            $answer = $accountant->reply($conversation, trim($data['message']), $request->user());
        } catch (AiException $e) {
            return response()->json(['conversation_id' => $conversation->id, 'error' => $e->getMessage()], 422);
        }

        return response()->json([
            'conversation_id' => $conversation->id,
            'title' => $conversation->fresh()->title,
            'answer' => [
                'content' => $answer->content,
                'is_error' => $answer->is_error,
                'tools' => collect($answer->tool_calls ?? [])->map(fn (array $call) => [
                    'name' => __('ai.tools.'.$call['name']),
                    'is_error' => $call['is_error'],
                ])->all(),
                'model' => $answer->model,
            ],
        ]);
    }

    public function destroy(Request $request, AiConversation $conversation): RedirectResponse
    {
        abort_unless($conversation->user_id === $request->user()->id, 404);

        $conversation->delete();

        return redirect()->route('ai.index')->with('status', __('ai.conversation_deleted'));
    }

    public function proposals(Request $request): View
    {
        $this->authorize('view-reports');

        $status = ProposalStatus::tryFrom((string) $request->query('status', 'pending')) ?? ProposalStatus::Pending;
        $proposals = AiProposal::query()->where('status', $status)->latest('id')->paginate(25)->withQueryString();
        $transactions = Transaction::query()->with(['category', 'contractor', 'account'])->whereIn('id', $proposals->pluck('subject_id'))->get()->keyBy('id');

        return view('ai.proposals', compact('proposals', 'transactions', 'status'));
    }

    public function decide(Request $request, AiProposal $proposal, ProposalService $service): RedirectResponse
    {
        $this->authorize('view-reports');

        $data = $request->validate(['decision' => ['required', 'in:apply,reject']]);

        try {
            $data['decision'] === 'apply' ? $service->apply($proposal, $request->user()) : $service->reject($proposal, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', __($data['decision'] === 'apply' ? 'ai.proposal_applied' : 'ai.proposal_rejected'));
    }

    /** Applies every pending proposal at or above a confidence, in one go. */
    public function applyAll(Request $request, ProposalService $service): RedirectResponse
    {
        $this->authorize('view-reports');

        $data = $request->validate(['min_confidence' => ['required', 'integer', 'min:50', 'max:100']]);
        $count = 0;

        foreach (AiProposal::query()->where('status', ProposalStatus::Pending)->where('confidence', '>=', $data['min_confidence'])->get() as $proposal) {
            $service->apply($proposal, $request->user());
            $count++;
        }

        return back()->with('status', __('ai.applied_many', ['count' => $count]));
    }

    /** Runs the classifier now: rule-based suggestions first, then the AI (if configured). */
    public function classify(ClassifyTransactions $classify): RedirectResponse
    {
        $this->authorize('view-reports');

        try {
            $count = $classify();
        } catch (AiException $e) {
            return redirect()->route('ai.proposals')->with('error', $e->getMessage());
        }

        return redirect()->route('ai.proposals')->with('status', __('ai.classified', ['count' => $count]));
    }
}

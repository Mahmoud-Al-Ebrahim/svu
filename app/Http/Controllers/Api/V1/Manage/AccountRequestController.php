<?php

namespace App\Http\Controllers\Api\V1\Manage;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\AccountRequestResource;
use App\Mail\PortalNoticeMail;
use App\Models\AccountRequest;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Admin: review faculty account requests. Approving one creates the tutor's account. */
class AccountRequestController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])]]);
        $rows = AccountRequest::where('status', $request->query('status', 'pending'))->latest();

        return AccountRequestResource::collection($this->paginate($rows, $request));
    }

    public function decide(Request $request, AccountRequest $accountRequest, string $decision): JsonResponse
    {
        $data = $request->validate(['note' => [$decision === 'reject' ? 'required' : 'nullable', 'string', 'max:255']]);
        // Decide under a row lock: a double click (or two admins) waits, then gets "already decided"
        // instead of a second account or a second e-mail.
        [$accountRequest, $user] = DB::transaction(function () use ($accountRequest, $request, $decision, $data) {
            $accountRequest = AccountRequest::whereKey($accountRequest->id)->lockForUpdate()->firstOrFail();
            abort_unless($accountRequest->status === 'pending', 409, __('portal.accounts.already_decided'));

            if ($decision !== 'approve') {
                $accountRequest->update([
                    'status' => 'rejected', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'review_note' => $data['note'],
                ]);

                return [$accountRequest, null];
            }

            // The e-mail or employee ID may have been taken since the request was sent.
            abort_if(
                User::where('email', $accountRequest->email)->orWhere('employee_id', $accountRequest->employee_id)->exists(),
                409, __('portal.accounts.taken'),
            );
            $user = User::create([
                'username' => User::usernameFor($accountRequest->email),
                'email' => $accountRequest->email,
                'employee_id' => $accountRequest->employee_id,
                'password' => $accountRequest->password_hash, // already hashed; the cast keeps it as is
                'name_ar' => $accountRequest->name_ar,
                'name_en' => $accountRequest->name_en,
                'rank_ar' => $accountRequest->rank,
                'rank_en' => $accountRequest->rank,
                'phone' => $accountRequest->phone,
                'role' => Role::Tutor,
            ]);
            $accountRequest->update(['status' => 'approved', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);

            return [$accountRequest, $user];
        });

        if ($user) {
            Mail::to($user->email)->queue(new PortalNoticeMail(__('portal.accounts.approved_subject'), [
                __('portal.accounts.approved_body', ['username' => $user->username]),
            ]));
        } else {
            Mail::to($accountRequest->email)->queue(new PortalNoticeMail(__('portal.accounts.rejected_subject'), [$data['note']]));
        }
        Audit::log("account.{$decision}", $accountRequest, ['user' => $user?->id]);

        return response()->json((new AccountRequestResource($accountRequest->fresh()))->resolve($request) + ['username' => $user?->username]);
    }
}

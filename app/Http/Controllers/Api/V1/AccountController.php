<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexAccountRequest;
use App\Http\Requests\Api\V1\StoreAccountRequest;
use App\Http\Requests\Api\V1\UpdateAccountRequest;
use App\Http\Resources\AccountResource;
use App\Models\Account;
use App\Services\MoneyLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Accounts have no delete: deactivate them with `is_active = false`.
 */
class AccountController extends Controller
{
    public function index(IndexAccountRequest $request): AnonymousResourceCollection
    {
        $accounts = Account::query()
            ->withBalance()
            ->search($request->query('search'))
            ->when($request->boolean('active'), fn ($query) => $query->active())
            ->orderBy('name')
            ->paginate($request->perPage());

        return AccountResource::collection($accounts);
    }

    public function store(StoreAccountRequest $request): JsonResponse
    {
        $account = Account::create($request->validated());

        return (new AccountResource($account))->response()->setStatusCode(201);
    }

    public function show(Account $account): AccountResource
    {
        return new AccountResource($account);
    }

    /**
     * Edit the account. Deactivating it is refused while its balance is not zero.
     */
    public function update(UpdateAccountRequest $request, Account $account, MoneyLedger $ledger): AccountResource
    {
        $data = $request->validated();
        $deactivating = $request->has('is_active') && ! $request->boolean('is_active') && $account->is_active;

        $account = DB::transaction(function () use ($account, $data, $deactivating, $ledger) {
            if ($deactivating) {
                $account = $ledger->deactivate($account);
                unset($data['is_active']);
            }

            $account->update($data);

            return $account;
        });

        return new AccountResource($account);
    }
}

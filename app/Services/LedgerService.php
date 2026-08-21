<?php

namespace App\Services;

use App\Models\AccountTransaction;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The customer/supplier ledger (Section 4).
 *
 * account_transactions is the truth; customers.balance and suppliers.balance are
 * caches of the latest balance_after. Both floor at zero — a negative balance is
 * never allowed on either side (Section 4, Section 7).
 */
class LedgerService
{
    /**
     * Post a signed amount to an account and return the resulting balance.
     *
     * A positive amount raises what is owed; a negative amount reduces it.
     * The balance is clamped at zero, and the caller is told how much of the
     * reduction did not fit — that overflow becomes cash (Section 7).
     *
     * @return array{balance: int, unapplied: int}
     */
    public function post(
        Customer|Supplier $account,
        string $type,
        int $amount,
        ?Model $reference,
        User $user,
        ?string $notes = null,
    ): array {
        $this->assertInTransaction();

        $accountableType = $account instanceof Customer ? 'customer' : 'supplier';

        // Lock the account row: the doc requires the same care here as on batches
        // and the document counter.
        $locked = $account->newQuery()->whereKey($account->id)->lockForUpdate()->firstOrFail();

        $current = (int) $locked->balance;
        $target = $current + $amount;

        // Section 7: the balance never goes below zero. Whatever would have taken
        // it negative is handed back to the caller as cash.
        $balance = max(0, $target);
        $unapplied = $balance - $target;

        if ($amount !== 0 && ! ($amount < 0 && $balance === $current)) {
            AccountTransaction::create([
                'accountable_type' => $accountableType,
                'accountable_id' => $locked->id,
                'type' => $type,
                'reference_type' => $reference ? $this->referenceType($reference) : null,
                'reference_id' => $reference?->getKey(),
                // Record what was actually applied, so the ledger and the cached
                // balance can never disagree.
                'amount' => $balance - $current,
                'balance_after' => $balance,
                'user_id' => $user->id,
                'notes' => $notes,
            ]);
        }

        $locked->forceFill(['balance' => $balance])->save();

        $account->setAttribute('balance', $balance);

        return ['balance' => $balance, 'unapplied' => $unapplied];
    }

    /**
     * Section 4: an admin action that rebuilds every cached balance from the
     * ledger. If the two ever differ, the ledger wins.
     *
     * @return array<int, array{account: string, id: int, cached: int, actual: int}>
     */
    public function recalculateBalances(): array
    {
        $mismatches = [];

        foreach ([['customer', Customer::class], ['supplier', Supplier::class]] as [$typeKey, $class]) {
            foreach ($class::withTrashed()->cursor() as $account) {
                $latest = AccountTransaction::where('accountable_type', $typeKey)
                    ->where('accountable_id', $account->id)
                    ->orderByDesc('id')
                    ->first();

                $actual = (int) ($latest->balance_after ?? 0);

                if ((int) $account->balance !== $actual) {
                    $mismatches[] = [
                        'account' => $typeKey,
                        'id' => $account->id,
                        'cached' => (int) $account->balance,
                        'actual' => $actual,
                    ];

                    $account->forceFill(['balance' => $actual])->save();
                }
            }
        }

        return $mismatches;
    }

    /**
     * Undo everything a document ever posted to an account.
     *
     * Reads what was *actually applied* rather than recomputing what should
     * have been. A balance floors at zero, so a posting of -30,000 against a
     * balance of 20,000 only ever applied -20,000; reversing the intended
     * figure would invent 10,000 out of nowhere. Section 8's edit and
     * Section 8b's soft delete both need this, and so does deleting a return.
     *
     * @return int the amount put back
     */
    public function reverseDocument(
        Customer|Supplier $account,
        Model $reference,
        User $user,
        ?string $notes = null,
    ): int {
        $this->assertInTransaction();

        $applied = (int) AccountTransaction::query()
            ->where('accountable_type', $account instanceof Customer ? 'customer' : 'supplier')
            ->where('accountable_id', $account->id)
            ->where('reference_type', $this->referenceType($reference))
            ->where('reference_id', $reference->getKey())
            ->sum('amount');

        if ($applied === 0) {
            return 0;
        }

        $this->post(
            account: $account,
            type: AccountTransaction::TYPE_REFUND,
            amount: -$applied,
            reference: $reference,
            user: $user,
            notes: $notes ?? __('Reversal of :document', ['document' => $reference->document_no]),
        );

        return $applied;
    }

    private function referenceType(Model $reference): string
    {
        return match ($reference::class) {
            \App\Models\Sale::class => 'sale',
            \App\Models\Purchase::class => 'purchase',
            \App\Models\SaleReturn::class => 'sale_return',
            \App\Models\PurchaseReturn::class => 'purchase_return',
            \App\Models\Payment::class => 'payment',
            default => class_basename($reference),
        };
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('Ledger postings must run inside a transaction.');
        }
    }
}

<?php

namespace App\Actions\Production;

use App\Enums\DocumentStatus;
use App\Enums\ProductType;
use App\Exceptions\InvalidMaterialIssueLineException;
use App\Exceptions\WorkOrderNotIssuableException;
use App\Models\MaterialIssue;
use App\Models\Product;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a draft issue for a Work Order, or replaces the lines of an existing draft.
 * No stock moves here — that happens only in PostMaterialIssueAction.
 */
class SaveMaterialIssueDraftAction
{
    /**
     * @param  array<int, array{productId: int, quantity: string, note: ?string}>  $lines
     */
    public function handle(WorkOrder $workOrder, array $lines, int $userId, ?MaterialIssue $issue = null): MaterialIssue
    {
        if (! $workOrder->canReceiveMaterials()) {
            throw new WorkOrderNotIssuableException($workOrder);
        }

        $issue?->ensureIsDraft();

        // lines with no quantity are simply not part of this issue
        $lines = array_values(array_filter($lines, fn (array $line) => bccomp($line['quantity'] ?: '0', '0', 2) > 0));

        if ($lines === []) {
            throw new InvalidMaterialIssueLineException(InvalidMaterialIssueLineException::NO_LINES);
        }

        $plannedIds = $workOrder->materials()->pluck('product_id')->all();

        foreach ($lines as $line) {
            $product = Product::findOrFail($line['productId']);

            if ($product->type === ProductType::FinishedGoods) {
                throw new InvalidMaterialIssueLineException(InvalidMaterialIssueLineException::NOT_ISSUABLE, $product->product_name);
            }

            // unplanned material is allowed, but must say why (e.g. compensating damaged pellets)
            if (! in_array($product->id, $plannedIds, true) && blank($line['note'] ?? null)) {
                throw new InvalidMaterialIssueLineException(InvalidMaterialIssueLineException::NOTE_REQUIRED, $product->product_name);
            }
        }

        return DB::transaction(function () use ($workOrder, $lines, $userId, $issue) {
            if (! $issue) {
                $issue = new MaterialIssue([
                    'work_order_id' => $workOrder->id,
                    'created_by' => $userId,
                ]);
                $issue->status = DocumentStatus::Draft; // explicit, so the returned model knows its status
                $issue->issue_number = (string) Str::uuid(); // placeholder; real number needs this row's own id
                $issue->save();

                $issue->issue_number = sprintf('MI-%s-%06d', $issue->created_at->format('Y'), $issue->id);
                $issue->save();
            }

            // a draft's lines are a working copy, not history — replace them wholesale
            $issue->items()->delete();

            foreach ($lines as $line) {
                $issue->items()->create([
                    'product_id' => $line['productId'],
                    'quantity' => $line['quantity'],
                    'note' => filled($line['note'] ?? null) ? $line['note'] : null,
                ]);
            }

            return $issue->load('items');
        });
    }
}

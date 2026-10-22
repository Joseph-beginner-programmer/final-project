<?php

namespace App\Actions\Production;

use App\Enums\DocumentStatus;
use App\Enums\ProductType;
use App\Exceptions\InvalidMaterialIssueLineException;
use App\Exceptions\MaterialIssueDraftExistsException;
use App\Exceptions\WorkOrderNotIssuableException;
use App\Models\MaterialIssue;
use App\Models\Product;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

            if (! in_array($product->id, $plannedIds, true) && blank($line['note'] ?? null)) {
                throw new InvalidMaterialIssueLineException(InvalidMaterialIssueLineException::NOTE_REQUIRED, $product->product_name);
            }
        }

        return DB::transaction(function () use ($workOrder, $lines, $userId, $issue) {
            WorkOrder::whereKey($workOrder->id)->lockForUpdate()->first();

            $otherDraft = MaterialIssue::query()
                ->where('work_order_id', $workOrder->id)
                ->where('status', DocumentStatus::Draft->value)
                ->when($issue, fn ($query) => $query->whereKeyNot($issue->id))
                ->first();

            if ($otherDraft) {
                throw new MaterialIssueDraftExistsException($otherDraft);
            }

            if (! $issue) {
                $issue = new MaterialIssue([
                    'work_order_id' => $workOrder->id,
                    'created_by' => $userId,
                ]);
                $issue->status = DocumentStatus::Draft;
                $issue->issue_number = (string) Str::uuid();
                $issue->save();

                $issue->issue_number = sprintf('MI-%s-%06d', $issue->created_at->format('Y'), $issue->id);
                $issue->save();
            }

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

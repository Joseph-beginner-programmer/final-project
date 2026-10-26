<?php

namespace App\Support;

use App\Enums\DocumentStatus;
use App\Enums\JournalStatus;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class UnpaidWages
{
    public static function query(?Carbon $until = null): Builder
    {
        return DB::table('production_result_labors')
            ->join('production_results', 'production_results.id', '=', 'production_result_labors.production_result_id')
            ->where('production_results.status', DocumentStatus::Posted->value)
            ->whereNull('production_result_labors.wage_payment_id')
            ->when($until, fn (Builder $q) => $q->whereDate('production_results.production_date', '<=', $until->toDateString()))
            ->whereExists(fn (Builder $q) => $q->select(DB::raw(1))
                ->from('journal_entries')
                ->where('journal_entries.source_type', 'production_result')
                ->whereColumn('journal_entries.source_id', 'production_results.id')
                ->where('journal_entries.status', JournalStatus::Posted->value)
                ->whereNull('journal_entries.reversal_of_id'));
    }

    /**
     * @return Collection<int, array{employee: Employee, hours: string, amount: string, results: int, from: Carbon, to: Carbon}>
     */
    public static function perEmployee(?Carbon $until = null): Collection
    {
        $rows = self::query($until)
            ->groupBy('production_result_labors.employee_id')
            ->selectRaw('production_result_labors.employee_id, SUM(production_result_labors.hours) as hours, SUM(production_result_labors.total_cost) as amount, COUNT(*) as results, MIN(production_results.production_date) as first_date, MAX(production_results.production_date) as last_date')
            ->get();

        $employees = Employee::whereIn('id', $rows->pluck('employee_id'))->get()->keyBy('id');

        return $rows
            ->map(fn ($row) => [
                'employee' => $employees[$row->employee_id],
                'hours' => bcadd((string) $row->hours, '0', 2),
                'amount' => bcadd((string) $row->amount, '0', 2),
                'results' => (int) $row->results,
                'from' => Carbon::parse($row->first_date),
                'to' => Carbon::parse($row->last_date),
            ])
            ->sortBy(fn (array $row) => $row['employee']->name)
            ->values();
    }

    public static function total(?Carbon $until = null): string
    {
        return bcadd((string) self::query($until)->sum('production_result_labors.total_cost'), '0', 2);
    }
}

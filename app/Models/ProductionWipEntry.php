<?php

namespace App\Models;

use App\Casts\Decimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The work-in-process subledger (MC7). Invariant P2: sum(direction x value)
 * over non-cancelled rows equals the GL balance of AS13. `kind` is one of
 * the KIND_* constants; `source` is the document that wrote the row.
 *
 * Sources by kind: material_in -> MaterialIssueItem; direct_cost ->
 * ProductionDirectCost; completion -> ProductionCompletion; material_out
 * and abandon -> the JournalVoucher they posted (neither has a document row
 * of its own). Rows carrying an item_id are that material's share of the
 * entry's WIP: a completion or abandon writes one per material (quantity
 * and value consumed) plus one item-less row for the direct-cost share, so
 * WipLedger::quantityByItem()/valueByItem() stay the live per-item WIP.
 */
#[Fillable([
    'material_issue_note_id',
    'production_entry_id',
    'date',
    'kind',
    'item_id',
    'quantity',
    'value',
    'direction',
    'source_type',
    'source_id',
    'cancelled',
])]
class ProductionWipEntry extends Model
{
    use HasFactory;

    public const KIND_MATERIAL_IN = 'material_in';

    public const KIND_MATERIAL_OUT = 'material_out';

    public const KIND_DIRECT_COST = 'direct_cost';

    public const KIND_COMPLETION = 'completion';

    public const KIND_ABANDON = 'abandon';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'quantity' => Decimal::class.':4',
            'value' => Decimal::class.':2',
            'direction' => 'integer',
            'cancelled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<MaterialIssueNote, $this>
     */
    public function materialIssueNote(): BelongsTo
    {
        return $this->belongsTo(MaterialIssueNote::class);
    }

    /**
     * @return BelongsTo<ProductionEntry, $this>
     */
    public function productionEntry(): BelongsTo
    {
        return $this->belongsTo(ProductionEntry::class);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}

<?php

namespace App\Support\Branches;

use App\Models\Branch;
use App\Models\CompanySetting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

/**
 * The contact details a printed document's header shows (plan 12, BR21): the
 * company's own address, phone, email and PAN, except that a branch with its
 * own value for a field prints that value instead. Field by field, so a
 * branch that only has a phone overrides only the phone. The company name and
 * logo are never touched.
 *
 * Wired once, by a view composer on the shared print views (AppServiceProvider),
 * which calls forView() and hands the result to the header markup as
 * `$branchPrintDetails`. Individual document views and controllers do not know
 * about it.
 *
 * Parity: the override applies only while the `branches` module is on and the
 * company runs more than one active branch. A single-branch company, a
 * document without a branch (an estimate, an unknown view) or a branch without
 * overrides gets exactly the company's values, so the markup and the PDF bytes
 * are what they were before branches existed.
 *
 * Access: the document's own branch row is read by id. `Branch` carries no
 * access scope, and the print routes have already authorised the document, so
 * a branch-restricted user is not given any other branch's details.
 */
final class BranchPrintDetails
{
    /**
     * View-data key of each printable document, and the column that holds the
     * branch whose details it prints. A stock transfer prints the sender's.
     *
     * @var array<string, string>
     */
    private const DOCUMENT_BRANCH_COLUMNS = [
        'sale' => 'branch_id',
        'salesReturn' => 'branch_id',
        'purchase' => 'branch_id',
        'purchaseReturn' => 'branch_id',
        'quotation' => 'branch_id',
        'receipt' => 'branch_id',
        'voucher' => 'branch_id',
        'capitalSale' => 'branch_id',
        'capitalPurchase' => 'branch_id',
        'stockAdjustment' => 'branch_id',
        'stockConversion' => 'branch_id',
        'stockTransfer' => 'from_branch_id',
        'note' => 'branch_id',
        'entry' => 'branch_id',
    ];

    /**
     * Header details for $branch over $company: per field the branch's value
     * when it has one, else the company's. A null branch gives the company's
     * details unchanged.
     *
     * @return array{address: ?string, phone: ?string, email: ?string, pan: ?string}
     */
    public static function for(?Branch $branch, CompanySetting $company): array
    {
        return [
            'address' => self::pick($branch?->address, $company->address),
            'phone' => self::pick($branch?->phone, $company->phone),
            'email' => self::pick($branch?->email, $company->email),
            'pan' => self::pick($branch?->pan_number, $company->pan_vat_number),
        ];
    }

    /**
     * The details for a print view, from the data it is being rendered with
     * (its `company` and the document model under its usual key). Anything
     * short of a multi-branch company with a document that names a branch
     * gives the company's own details.
     *
     * @param  array<string, mixed>  $viewData
     * @return array{address: ?string, phone: ?string, email: ?string, pan: ?string}
     */
    public static function forView(array $viewData): array
    {
        $company = $viewData['company'] ?? null;

        if (! $company instanceof CompanySetting) {
            return ['address' => null, 'phone' => null, 'email' => null, 'pan' => null];
        }

        return self::for(self::documentBranch($viewData), $company);
    }

    /**
     * @param  array<string, mixed>  $viewData
     */
    private static function documentBranch(array $viewData): ?Branch
    {
        $branchId = null;

        foreach (self::DOCUMENT_BRANCH_COLUMNS as $key => $column) {
            $document = $viewData[$key] ?? null;

            if ($document instanceof Model) {
                $branchId = $document->getAttribute($column);

                break;
            }
        }

        if ($branchId === null || ! ActiveBranchSelection::isMultiBranch(request())) {
            return null;
        }

        try {
            return Branch::query()->find((int) $branchId);
        } catch (QueryException) {
            return null;
        }
    }

    private static function pick(?string $branchValue, ?string $companyValue): ?string
    {
        return $branchValue !== null && trim($branchValue) !== '' ? $branchValue : $companyValue;
    }
}

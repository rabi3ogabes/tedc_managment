<?php

namespace App\Payments;

use App\Models\Employee;
use App\Models\PriceList;
use App\Models\TrainingGroup;
use App\Services\Eligibility\EligibilityEngine;
use App\Services\Eligibility\EmployeeContext;

/**
 * The price of a seat for a buyer. A group's own price list wins over its programme's. Rules are tried in order: the first whose category (and optional eligibility
 * conditions) fits the buyer sets the price, so a course can be free for ministry staff and paid for others. No price list means free.
 */
class PricingService
{
    public const CATEGORIES = ['ministry_staff', 'private_school', 'external', 'entity', 'any'];

    public function __construct(private readonly EligibilityEngine $eligibility) {}

    public function listFor(TrainingGroup $group): ?PriceList
    {
        $l = PriceList::where('group_id', $group->id)->where('is_active', true)->first() ?? PriceList::where('program_id', $group->program_id)->where('is_active', true)->first();

        return $l;
    }

    /** ministry_staff | private_school | external, from the person's school. */
    public function categoryOf(?Employee $employee): string
    {
        if (! $employee || ! $employee->school_id || str_starts_with((string) $employee->employee_no, 'EXT-')) {
            return 'external';
        }
        $type = (string) ($employee->school?->type ?? $employee->school()->value('type'));

        return in_array($type, ['private', 'private_school', 'international'], true) ? 'private_school' : 'ministry_staff';
    }

    /**
     * @return array{price: float, currency: string, vat_rate: float, free: bool, rule: ?string, category: string, list_id: ?string}
     */
    public function resolve(TrainingGroup $group, ?Employee $employee, bool $entity = false): array
    {
        $list = $this->listFor($group);
        $category = $entity ? 'entity' : $this->categoryOf($employee);
        if (! $list) {
            return ['price' => 0.0, 'currency' => 'QAR', 'vat_rate' => 0.0, 'free' => true, 'rule' => null, 'category' => $category, 'list_id' => null];
        }
        $context = $employee && ! $entity ? EmployeeContext::fromEmployee($employee) : null;
        foreach ((array) $list->rules as $i => $rule) {
            $cat = $rule['category'] ?? 'any';
            if ($cat !== 'any' && $cat !== $category) {
                continue;
            }
            if (! empty($rule['match'])) {
                if (! $context) {
                    continue;
                }
                $ok = true;
                foreach ((array) $rule['match'] as $m) {
                    $ok = $ok && $this->eligibility->passes((string) $m['field'], (string) $m['operator'], $m['value'] ?? null, $context);
                }
                if (! $ok) {
                    continue;
                }
            }
            $price = max(0.0, (float) ($rule['price'] ?? 0));

            return ['price' => $price, 'currency' => $list->currency, 'vat_rate' => (float) $list->vat_rate, 'free' => $price <= 0, 'rule' => $rule['label_en'] ?? $rule['label_ar'] ?? 'rule '.($i + 1), 'category' => $category, 'list_id' => $list->id];
        }
        $price = max(0.0, (float) $list->default_price);

        return ['price' => $price, 'currency' => $list->currency, 'vat_rate' => (float) $list->vat_rate, 'free' => $price <= 0, 'rule' => null, 'category' => $category, 'list_id' => $list->id];
    }

    /** What the catalogue shows: "free for you" or the price (VAT included), with the lowest and highest price for people not signed in. @return array<string, mixed> */
    public function display(TrainingGroup $group, ?Employee $employee): array
    {
        $r = $this->resolve($group, $employee);
        $list = $this->listFor($group);
        $prices = $list ? array_merge(array_map(fn ($x) => (float) ($x['price'] ?? 0), (array) $list->rules), [(float) $list->default_price]) : [0.0];

        return ['paid' => ! $r['free'], 'free_for_you' => $r['free'] && $list !== null && max($prices) > 0, 'price' => round($r['price'] * (1 + $r['vat_rate'] / 100), 2), 'price_excl_vat' => $r['price'], 'vat_rate' => $r['vat_rate'], 'currency' => $r['currency'],
            'from' => $list ? min($prices) : 0.0, 'to' => $list ? max($prices) : 0.0, 'category' => $r['category']];
    }

    /** What an administrator sees when previewing a list as a category. @return list<array<string, mixed>> */
    public function preview(PriceList $list): array
    {
        $out = [];
        foreach (['ministry_staff', 'private_school', 'external', 'entity'] as $cat) {
            $price = null;
            $rule = null;
            foreach ((array) $list->rules as $r) {
                if (($r['category'] ?? 'any') === 'any' || ($r['category'] ?? '') === $cat) {
                    if (! empty($r['match'])) {
                        continue;   // conditional rules depend on the person
                    }
                    $price = (float) $r['price'];
                    $rule = $r['label_en'] ?? null;
                    break;
                }
            }
            $price ??= (float) $list->default_price;
            $out[] = ['category' => $cat, 'price' => $price, 'price_with_vat' => round($price * (1 + (float) $list->vat_rate / 100), 2), 'free' => $price <= 0, 'rule' => $rule, 'currency' => $list->currency];
        }

        return $out;
    }
}

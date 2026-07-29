<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Catalog of contract placeholders and the merge engine.
 *
 * Placeholders are written as {{ key }} inside a template body. The editor
 * sidebar lists them (grouped, with labels). At generate time the "auto" ones
 * are pre-filled from the recipient / company / today, and the sender fills the
 * rest; then render() swaps every {{ key }} for its (HTML-escaped) value.
 *
 * Keeping the catalog here (not in a DB table) means adding a new placeholder is
 * a one-line change and there is nothing to migrate.
 */
class ContractVariables
{
    /**
     * Grouped catalog for the editor sidebar.
     * `auto` = filled automatically at generate time (recipient/company/today).
     */
    public static function catalog(): array
    {
        return [
            [
                'group' => 'Recipient',
                'items' => [
                    ['key' => 'employee_name',     'label' => 'Employee Name',    'auto' => true],
                    ['key' => 'employee_email',    'label' => 'Employee Email',   'auto' => true],
                    ['key' => 'employee_phone',    'label' => 'Employee Phone',   'auto' => true],
                    ['key' => 'employee_location', 'label' => 'Location / City',  'auto' => false],
                ],
            ],
            [
                'group' => 'Position & Terms',
                'items' => [
                    ['key' => 'position',         'label' => 'Position / Title',      'auto' => false],
                    ['key' => 'start_date',       'label' => 'Start Date',            'auto' => false],
                    ['key' => 'salary',           'label' => 'Starting Salary',       'auto' => false],
                    ['key' => 'probation_period', 'label' => 'Probation Period',      'auto' => false],
                    ['key' => 'work_location',    'label' => 'Work Location',         'auto' => false],
                    ['key' => 'working_days',     'label' => 'Working Days / Hours',  'auto' => false],
                    ['key' => 'notice_period',    'label' => 'Notice Period',         'auto' => false],
                ],
            ],
            [
                'group' => 'Dates',
                'items' => [
                    ['key' => 'contract_date', 'label' => 'Contract Date', 'auto' => true],
                ],
            ],
            [
                'group' => 'Company',
                'items' => [
                    ['key' => 'company_name',    'label' => 'Company Name',    'auto' => true],
                    ['key' => 'ceo_name',        'label' => 'CEO Name',        'auto' => true],
                    ['key' => 'coo_name',        'label' => 'COO Name',        'auto' => true],
                    ['key' => 'company_website', 'label' => 'Company Website', 'auto' => true],
                ],
            ],
        ];
    }

    /** Flat list of every valid placeholder key. */
    public static function keys(): array
    {
        $keys = [];
        foreach (self::catalog() as $group) {
            foreach ($group['items'] as $item) {
                $keys[] = $item['key'];
            }
        }
        return $keys;
    }

    /**
     * Auto-resolved default values for a recipient. Manual keys come back blank
     * (or a sensible seed like "Remote"), ready for the sender to fill/edit.
     *
     * @param  \App\Models\User|null  $user
     */
    public static function resolveDefaults($user = null): array
    {
        return [
            // Recipient (auto)
            'employee_name'     => $user->name  ?? '',
            'employee_email'    => $user->email ?? '',
            'employee_phone'    => $user->phone ?? '',
            'employee_location' => '',

            // Position & terms (manual — seeded with common defaults)
            'position'          => '',
            'start_date'        => '',
            'salary'            => '',
            'probation_period'  => '',
            'work_location'     => 'Remote',
            'working_days'      => 'Monday to Friday',
            'notice_period'     => '',

            // Dates (auto)
            'contract_date'     => Carbon::now()->format('jS F, Y'),

            // Company (auto — editable if ever needed)
            'company_name'      => 'Archilance LLC',
            'ceo_name'          => 'Faran Shahbaz',
            'coo_name'          => 'Asad Kamal Abbasi',
            'company_website'   => 'www.archilance.net',
        ];
    }

    /**
     * Replace every {{ key }} in $body with its value from $values.
     * Values are HTML-escaped (they are plain text); the surrounding body HTML is
     * left intact. Unknown placeholders resolve to an empty string.
     */
    public static function render(string $body, array $values): string
    {
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function ($m) use ($values) {
            $val = $values[$m[1]] ?? '';
            return e((string) $val);
        }, $body) ?? $body;
    }

    /** Distinct placeholder keys present in a body. */
    public static function extract(string $body): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $body, $m);
        return array_values(array_unique($m[1] ?? []));
    }
}

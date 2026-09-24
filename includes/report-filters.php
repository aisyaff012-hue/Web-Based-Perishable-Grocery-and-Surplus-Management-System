<?php

function validDate(?string $value): ?string
{
    $value = trim((string) $value);

    if ($value === '') {
        return null;
    }

    $parsed = DateTime::createFromFormat('Y-m-d', $value);

    return ($parsed && $parsed->format('Y-m-d') === $value)
        ? $value
        : null;
}

$filterStart = validDate($_GET['start'] ?? null);
$filterEnd = validDate($_GET['end'] ?? null);

// A backwards range is a typo, not an empty report.
if ($filterStart && $filterEnd && $filterStart > $filterEnd) {
    [$filterStart, $filterEnd] = [$filterEnd, $filterStart];
}

$filterCategory = $_GET['category'] ?? 'all';

if (
    $filterCategory !== 'all'
    && !in_array($filterCategory, productCategories(), true)
) {
    $filterCategory = 'all';
}

$filterActive = $filterStart !== null
    || $filterEnd !== null
    || $filterCategory !== 'all';

/*
 * The date column differs per query — surplus_since for stock,
 * request_date for reservations — so it is passed in rather
 * than hard-coded.
 */
function filterClause(string $dateColumn, string $categoryColumn = 'inventory.category'): string
{
    global $filterStart, $filterEnd, $filterCategory;

    $parts = [];

    if ($filterStart !== null) {
        $parts[] = "DATE({$dateColumn}) >= ?";
    }

    if ($filterEnd !== null) {
        $parts[] = "DATE({$dateColumn}) <= ?";
    }

    if ($filterCategory !== 'all') {
        $parts[] = "{$categoryColumn} = ?";
    }

    return $parts ? ' AND ' . implode(' AND ', $parts) : '';
}

function filterValues(): array
{
    global $filterStart, $filterEnd, $filterCategory;

    $values = [];

    if ($filterStart !== null) {
        $values[] = $filterStart;
    }

    if ($filterEnd !== null) {
        $values[] = $filterEnd;
    }

    if ($filterCategory !== 'all') {
        $values[] = $filterCategory;
    }

    return $values;
}

$filterQuery = http_build_query(array_filter([
    'start' => $filterStart,
    'end' => $filterEnd,
    'category' => $filterCategory !== 'all' ? $filterCategory : null
]));

$filterLabel = 'All records';

if ($filterActive) {
    $bits = [];

    if ($filterStart && $filterEnd) {
        $bits[] = date('d M Y', strtotime($filterStart))
            . ' to ' . date('d M Y', strtotime($filterEnd));
    } elseif ($filterStart) {
        $bits[] = 'From ' . date('d M Y', strtotime($filterStart));
    } elseif ($filterEnd) {
        $bits[] = 'Up to ' . date('d M Y', strtotime($filterEnd));
    }

    if ($filterCategory !== 'all') {
        $bits[] = $filterCategory;
    }

    $filterLabel = implode(' · ', $bits);
}

<?php

// Sahkan format tarikh (YYYY-MM-DD); pulangkan null kalau kosong
// atau format/tarikh tak sah (elak query dengan tarikh karut).
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

// Julat terbalik (start lepas end) tu silap taip, bukan maksud
// report kosong — jadi kita tukar balik posisi dia.
if ($filterStart && $filterEnd && $filterStart > $filterEnd) {
    [$filterStart, $filterEnd] = [$filterEnd, $filterStart];
}

$filterCategory = $_GET['category'] ?? 'all';

// Kalau kategori yang dihantar tak wujud dalam senarai sah,
// abaikan je — jatuh balik ke 'all'.
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
 * Lajur tarikh berbeza ikut query — surplus_since untuk stok,
 * request_date untuk tempahan — jadi ia dihantar sebagai
 * parameter, bukan hard-code.
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

// Nilai placeholder (?) untuk filterClause(), ikut turutan yang
// sama macam syarat dibina di atas — kena sepadan bila di-bind.
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

// Query string untuk filter semasa, supaya ia boleh dikekalkan
// bila user klik link lain (cth export, pagination) pada page ni.
$filterQuery = http_build_query(array_filter([
    'start' => $filterStart,
    'end' => $filterEnd,
    'category' => $filterCategory !== 'all' ? $filterCategory : null
]));

$filterLabel = 'All records';

// Bina label ringkas (cth "1 Jan 2026 to 31 Jan 2026 · Bakery")
// untuk tunjuk kat UI filter mana yang sedang aktif.
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
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// Clear invoice groups on explicit reset
if (isset($_GET['reset']) && $_GET['reset'] === '1') {
    unset($_SESSION['invoice_groups']);
    // Strip the query string and redirect to the base path
    $baseUrl = strtok($_SERVER['REQUEST_URI'], '?');
    header('Location: ' . $baseUrl);
    exit;
}
// index.php

require __DIR__ . '/vendor/autoload.php';

use Smalot\PdfParser\Parser;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;

/**
 * Normalize raw SKU from invoice line into the actual SKU we care about.
 *
 * Rules based on Sean's notes:
 * - All HeroClips SKUs start with "2100" and have a hyphen.
 *   - If SKU starts with "2100", keep up to the SECOND hyphen:
 *       210013-010-M210013010  =>  210013-010
 * - Otherwise, if SKU has a hyphen, keep only the part before the FIRST hyphen:
 *       40858-M40858           =>  40858
 *       10110-AA556            =>  10110
 * - If no hyphen, return as-is.
 */
function normalize_sku(string $rawSku): string
{
    $rawSku = trim($rawSku);

    // No hyphen at all → nothing to split, just return.
    if (strpos($rawSku, '-') === false) {
        return $rawSku;
    }

    // HeroClip SKUs: start with 2100 and have at least one hyphen.
    if (str_starts_with($rawSku, '2100')) {
        // Keep up to second hyphen: "210013-010-M210013010" -> "210013-010"
        $parts = explode('-', $rawSku);

        if (count($parts) >= 2) {
            // Join first two segments with a single hyphen
            return $parts[0] . '-' . $parts[1];
        }

        // Fallback: just return raw if something unexpected happens
        return $rawSku;
    }

    // Non-2100 SKUs: keep only before FIRST hyphen
    $pos = strpos($rawSku, '-');
    if ($pos !== false) {
        return substr($rawSku, 0, $pos);
    }

    return $rawSku;
}

/**
 * Parse one invoice PDF's text into structured line items.
 *
 * Rules:
 * - Ignore all text until a line that ends with "Total Value".
 * - Start collecting items on the next lines.
 * - Stop when a line contains "Invoice Line".
 * - Item header line pattern:
 *     QUANTITY EA SKU COUNTRY UNIT_PRICE TOTAL_PRICE
 *   e.g. "12 EA 10689 TW 2.99 35.88"
 * - After that, one or more lines of DESCRIPTION.
 * - Then a line with SCHEDULE B (format ####.##.####).
 */
function parse_invoice_text(string $text): array
{
    $items = [];

    // 1) Extract only the section between "Total Value" and "Invoice Line"
    $startPos = mb_stripos($text, 'Total Value');
    if ($startPos === false) {
        return $items;
    }

    // Start *after* the "Total Value" line
    $startPos = mb_strpos($text, "\n", $startPos);
    if ($startPos === false) {
        return $items;
    }

    $endPos = mb_stripos($text, 'Invoice Line', $startPos);
    if ($endPos === false) {
        $section = mb_substr($text, $startPos);
    } else {
        $section = mb_substr($text, $startPos, $endPos - $startPos);
    }

    // 2) Normalize whitespace so we can regex across what used to be lines
    $section = preg_replace('/\s+/', ' ', $section);

    // 3) Regex pattern:
    //    QTY  EA  SKU  CC  UNIT  TOTAL  DESCRIPTION...  HS
    //
    // - (\d+)                 => quantity
    // - EA                    => literal
    // - (\S+)                 => raw SKU token (digits/letters/hyphens etc.)
    // - ([A-Z]{2})            => country
    // - ([\d.,]+)             => unit price
    // - ([\d.,]+)             => total price
    // - (.+?)                 => description (non-greedy)
    // - (\d{4}\.\d{2}\.\d{4}) => schedule B
    if (
        !preg_match_all(
            '/(\d+)\s+EA\s+(\S+)\s+([A-Z]{2})\s+([\d.,]+)\s+([\d.,]+)\s+(.+?)\s+(\d{4}\.\d{2}\.\d{4})/s',
            $section,
            $matches,
            PREG_SET_ORDER
        )
    ) {
        return $items;
    }

    foreach ($matches as $m) {
        $qty       = (int)$m[1];
        $rawSku    = $m[2];
        $sku       = normalize_sku($rawSku);
        $origin    = $m[3];
        $unit      = (float)str_replace(',', '', $m[4]);
        $total     = (float)str_replace(',', '', $m[5]);
        $descRaw   = trim($m[6]);
        $scheduleB = $m[7];

        // Clean up description: often ends with a trailing '-'
        $desc = preg_replace('/\s+-\s*$/', '', $descRaw);

        $items[] = [
            'quantity'    => $qty,
            'sku'         => $sku,
            'origin'      => $origin,
            'unit_price'  => $unit,
            'total_price' => $total,
            'description' => $desc,
            'schedule_b'  => $scheduleB,
        ];
    }

    return $items;
}

/**
 * Merge items with the same SKU (and matching origin/unit_price/schedule_b/description)
 * across all parsed invoices.
 *
 * @param array $parsedResults The array we already build: [ [file, items[], error], ... ]
 * @return array Merged items: [ [quantity, sku, origin, unit_price, total_price, description, schedule_b], ... ]
 */
function merge_parsed_items(array $parsedResults): array
{
    $merged = [];

    foreach ($parsedResults as $result) {
        foreach ($result['items'] as $item) {
            // Build a key that represents "same product"
            $keyParts = [
                $item['sku'],
                $item['origin'],
                number_format($item['unit_price'], 4, '.', ''), // normalize floats
                $item['schedule_b'],
                $item['description'],
            ];
            $key = implode('|', $keyParts);

            if (!isset($merged[$key])) {
                // Initialize this SKU bucket
                $merged[$key] = [
                    'quantity'    => (int)$item['quantity'],
                    'sku'         => $item['sku'],
                    'origin'      => $item['origin'],
                    'unit_price'  => (float)$item['unit_price'],
                    'total_price' => (float)$item['total_price'],
                    'description' => $item['description'],
                    'schedule_b'  => $item['schedule_b'],
                ];
            } else {
                // Merge quantities and totals
                $merged[$key]['quantity']    += (int)$item['quantity'];
                $merged[$key]['total_price'] += (float)$item['total_price'];
            }
        }
    }

    // Return as a flat array
    return array_values($merged);
}

/**
 * Fetch SKU data from the 'sli' table for a list of SKUs.
 *
 * @param PDO   $pdo
 * @param array $skuList  array of sku strings
 * @return array          [ sku => row ]
 */
function fetch_sku_info(PDO $pdo, array $skuList): array
{
    $skuList = array_values(array_unique(array_filter($skuList)));
    if (empty($skuList)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($skuList), '?'));
    $sql = "SELECT sku, single_weight, mstr_weight, mstr_qty, u_of_m
            FROM sli
            WHERE sku IN ($placeholders)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($skuList);

    $result = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $result[$row['sku']] = $row;
    }

    return $result;
}

/**
 * Build SLI rows from $groups and DB SKU info.
 *
 * Returns [ $sliRows, $missingSkus ]
 *
 * $sliRows: each element:
 *   [
 *      'df'          => 'D' or 'F',
 *      'schedule_b'  => '3402.90.5030',
 *      'net_kg'      => float,
 *      'unit'        => 'kg',
 *      'kg_g'        => float,
 *      'total_value' => float,
 *   ]
 */
function build_sli_data(array $groups, PDO $pdo): array
{
    // 1) collect all SKUs from groups
    $allSkus = [];
    foreach ($groups as $schedule => $byOrigin) {
        foreach ($byOrigin as $bucket => $items) {
            foreach ($items as $it) {
                $allSkus[] = $it['sku'];
            }
        }
    }
    $allSkus = array_values(array_unique($allSkus));

    // 2) query DB
    $skuInfo = fetch_sku_info($pdo, $allSkus);

    // 3) find missing SKUs
    $missing = [];
    foreach ($allSkus as $sku) {
        if (!isset($skuInfo[$sku])) {
            $missing[] = $sku;
        }
    }

    if (!empty($missing)) {
        // Don't try to compute SLI until user fills these in
        return [[], $missing];
    }

    // 4) Build SLI rows in the same order as your tables:
    //    all US (D) first, then Non-US (F)
    $sliRows = [];

    // Optional: sort schedule keys to keep things nice
    if (!empty($groups)) {
        ksort($groups, SORT_STRING);
    }

    // Helper closure to compute weights for one group
    $computeWeights = function (array $items) use ($skuInfo): array {
        $netKg   = 0.0;
        $grossKg = 0.0;
        $value   = 0.0;
        $unit    = '';

        foreach ($items as $it) {
            $sku = $it['sku'];
            $qty = (float)$it['quantity'];
            $val = (float)$it['total_price'];
            $value += $val;

            $row = $skuInfo[$sku];

            $single  = (float)$row['single_weight']; // kg per unit (for m2/pcs) or lb per unit when converting
            $mstrW   = (float)$row['mstr_weight'];   // kg per master carton
            $mstrQ   = (float)$row['mstr_qty'];      // units per master carton
            $uomRaw  = $row['u_of_m'] ?? '';
            $uom     = strtolower(trim($uomRaw));
            $uomNorm = preg_replace('/\s+/', '', $uom);

            // -----------------------------
            // NET KG RULES
            // -----------------------------
            // X, KG, KGS → qty is in pounds, convert to kg using single_weight * 0.454
            // DOZ, DZ    → (qty / 12) * single_weight
            // M2         → qty * single_weight
            // NO, PCS, EA, etc. → qty (no multiplier)
            // -----------------------------
            if (in_array($uomNorm, ['kg', 'kgs', 'x'], true)) {
                // Qty is already kilograms (or X treated like lb -> kg factor via single)
                $netItemKg = ($qty * $single) * 0.454;
                $unit      = 'kg';
            } elseif (in_array($uomNorm, ['doz', 'dz'], true)) {
                $netItemKg = ($qty / 12.0);
                $unit      = 'doz';
            } elseif (in_array($uomNorm, ['m2'], true)) {
                $netItemKg = ($qty * $single);
                $unit      = 'm2';
            } else {
                // NO, PCS, EA, etc.
                $netItemKg = $qty;
                $unit      = 'no';
            }

            $netKg += $netItemKg;

            // -----------------------------
            // GROSS KG
            // -----------------------------
            // Piece-like items use cartons
            $cartons = $qty / $mstrQ;
            $grossKg += ($cartons * $mstrW) * 0.454;
        }

        return [
            'net_kg'   => $netKg,
            'gross_kg' => $grossKg,
            'value'    => $value,
            'unit'     => $unit,
        ];
    };

    // First pass: all US -> D
    foreach ($groups as $schedule => $byOrigin) {
        if (!isset($byOrigin['US'])) {
            continue;
        }
        $items = $byOrigin['US'];
        $w     = $computeWeights($items);

        $sliRows[] = [
            'df'          => 'D',
            'schedule_b'  => $schedule,
            'net_kg'      => $w['net_kg'],
            'unit'        => $w['unit'],
            'kg_g'        => $w['gross_kg'],
            'total_value' => $w['value'],
        ];
    }

    // Second pass: all Non-US -> F
    foreach ($groups as $schedule => $byOrigin) {
        if (!isset($byOrigin['Non-US'])) {
            continue;
        }
        $items = $byOrigin['Non-US'];
        $w     = $computeWeights($items);

        $sliRows[] = [
            'df'          => 'F',
            'schedule_b'  => $schedule,
            'net_kg'      => $w['net_kg'],
            'unit'        => $w['unit'],
            'kg_g'        => $w['gross_kg'],
            'total_value' => $w['value'],
        ];
    }

    return [$sliRows, []];
}

// ----------------------
// Handle uploads on POST
// ----------------------
$action = $_POST['action'] ?? null;

// new: wizard phase flag (check | final)
$wizardPhase = $_POST['wizard_phase'] ?? 'check';

$parsedResults  = [];
$mergedItems    = [];
$groups         = [];
$sliRows        = [];
$sliMissingSkus = [];
$showWizard     = false; // new flag: auto-open wizard on load

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'parse' && isset($_FILES['invoiceFiles'])) {
        $parser = new Parser();

        foreach ($_FILES['invoiceFiles']['error'] as $idx => $err) {
            if ($err !== UPLOAD_ERR_OK) {
                continue;
            }

            $tmpName = $_FILES['invoiceFiles']['tmp_name'][$idx];
            $name    = $_FILES['invoiceFiles']['name'][$idx];

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($ext !== 'pdf') {
                $parsedResults[] = [
                    'file'  => $name,
                    'items' => [],
                    'error' => 'Skipping non-PDF file.',
                ];
                continue;
            }

            try {
                $pdf   = $parser->parseFile($tmpName);
                $text  = $pdf->getText();
                $items = parse_invoice_text($text);
                $parsedResults[] = [
                    'file'  => $name,
                    'items' => $items,
                    'error' => null,
                ];
            } catch (Exception $e) {
                $parsedResults[] = [
                    'file'  => $name,
                    'items' => [],
                    'error' => 'Error reading PDF: ' . $e->getMessage(),
                ];
            }
        }

        // Merge items from all invoices
        $mergedItems = merge_parsed_items($parsedResults);

        // Group by Schedule B + US / Non-US
        foreach ($mergedItems as $item) {
            $schedule = $item['schedule_b'] ?: 'UNKNOWN';
            $bucket   = ($item['origin'] === 'US') ? 'US' : 'Non-US';

            if (!isset($groups[$schedule])) {
                $groups[$schedule] = [];
            }
            if (!isset($groups[$schedule][$bucket])) {
                $groups[$schedule][$bucket] = [];
            }

            $groups[$schedule][$bucket][] = $item;
        }

        // Store groups in session so SLI / add_sku can reuse them
        $_SESSION['invoice_groups'] = $groups;

    } elseif ($action === 'sli') {
        // Load groups from session
        $groups = $_SESSION['invoice_groups'] ?? [];

        if (!empty($groups)) {
            require __DIR__ . '/../config.php';  // gives you $pdo
            [$sliRows, $sliMissingSkus] = build_sli_data($groups, $pdo);

            // Phase 1: just check SKUs, don't open wizard yet
            if ($wizardPhase === 'check') {
                // If there are missing SKUs, we just fall through:
                //  - HTML will render the add_sku modal.
                // If no missing and we have rows, we flag JS to open wizard.
                if (empty($sliMissingSkus) && !empty($sliRows)) {
                    $showWizard = true;
                }
            } else {
                // wizardPhase === 'final' → wizard already collected data,
                // and user wants to actually create the SLI file.
                if (empty($sliMissingSkus) && !empty($sliRows)) {
                    // ---------------------------
                    // 1) Load SLI template
                    // ---------------------------
                    $templatePath = __DIR__ . '/../SLI.xlsx';
                    if (!is_readable($templatePath)) {
                        die('SLI template not found at ' . htmlspecialchars($templatePath));
                    }

                    $spreadsheet = IOFactory::load($templatePath);
                    $ref         = IOFactory::load($templatePath);

                    $sheet     = $spreadsheet->getActiveSheet();
                    $ref_sheet = $ref->getActiveSheet();

                    // ---------------------------
                    // 2) Map wizard POST fields
                    // ---------------------------

                    // FORWARDER INFO (Row 3–7, J–N merged => write to J)
                    $forwarderName    = trim($_POST['forwarder_name']           ?? '');
                    $forwarderAddr1   = trim($_POST['forwarder_addr1']          ?? '');
                    $forwarderAddr2   = trim($_POST['forwarder_addr2']          ?? '');
                    $forwarderAddr3   = trim($_POST['forwarder_addr3']          ?? '');
                    $forwarderCityZip = trim($_POST['forwarder_city_state_zip'] ?? '');

                    $sheet->setCellValue('J3', $forwarderName);
                    $sheet->setCellValue('J4', $forwarderAddr1);

                    // If there is a third address line, city/state/zip stays on J7.
                    // If addr3 is empty, move city/state/zip up to J6.
                    if ($forwarderAddr2 !== '') {
                        $sheet->setCellValue('J5', $forwarderAddr2);
                        if ($forwarderAddr3 !== '') {
                            $sheet->setCellValue('J6', $forwarderAddr3);
                            $sheet->setCellValue('J7', $forwarderCityZip);
                        } else {
                            $sheet->setCellValue('J6', $forwarderCityZip);
                            $sheet->setCellValue('J7', '');
                        }
                    } else {
                        $sheet->setCellValue('J5', $forwarderCityZip);
                        $sheet->setCellValue('J6', '');
                        $sheet->setCellValue('J7', '');
                    }

                    // CONSIGNEE INFO (Row 11–14, A–D merged => write to A)
                    $consigneeName    = trim($_POST['consignee_name']           ?? '');
                    $consigneeAddr1   = trim($_POST['consignee_addr1']          ?? '');
                    $consigneeAddr2   = trim($_POST['consignee_addr2']          ?? '');
                    $consigneeCityZip = trim($_POST['consignee_city_state_zip'] ?? '');

                    $sheet->setCellValue('A11', $consigneeName);
                    $sheet->setCellValue('A12', $consigneeAddr1);

                    // If there is a second address line, city/state/zip stays on A14.
                    // If addr2 is empty, move city/state/zip up to A13.
                    if ($consigneeAddr2 !== '') {
                        $sheet->setCellValue('A13', $consigneeAddr2);
                        $sheet->setCellValue('A14', $consigneeCityZip);
                    } else {
                        $sheet->setCellValue('A13', $consigneeCityZip);
                        $sheet->setCellValue('A14', '');
                    }

                    // ORDER INFO
                    // SO# -> 9/C,D (merged) => write to C9
                    $sheet->setCellValue('C9', $_POST['so_number'] ?? '');

                    // Country of Ultimate Destination -> 16/E,F (merged) => write to E16
                    $destinationCountry = strtoupper(trim($_POST['destination_country'] ?? ''));
                    if ($destinationCountry !== '') {
                        $sheet->setCellValue('E16', $destinationCountry);
                    }

                    // HAZ (Yes/No) -> Row 17, E–F merged => write to E17
                    $haz    = $_POST['haz'] ?? '';
                    $hazVal = strtoupper(trim($haz));   // force ALL CAPS: "YES" or "NO"

                    if ($hazVal !== '') {
                        $sheet->setCellValue('E17', $hazVal);
                    }

                    // Ocean or Air:
                    // Air  -> Row 18, Col G
                    // Ocean-> Row 18, Col H
                    $shipMode = $_POST['ship_mode'] ?? '';
                    if ($shipMode === 'Air') {
                        $rt = new RichText();

                        // Wingdings 'x' (checked box)
                        $wing = $rt->createTextRun('x');
                        $wing->getFont()->setName('Wingdings');
                        $wing->getFont()->setSize(8);

                        // Space + label
                        $normal = $rt->createTextRun('Air');
                        $normal->getFont()->setName('Arial');
                        $normal->getFont()->setSize(7);

                        $sheet->setCellValue('G18', $rt);
                    }

                    if ($shipMode === 'Ocean') {
                        $rt = new RichText();

                        // Wingdings 'x'
                        $wing = $rt->createTextRun('x');
                        $wing->getFont()->setName('Wingdings');
                        $wing->getFont()->setSize(8);

                        // Space + label
                        $normal = $rt->createTextRun('Ocean');
                        $normal->getFont()->setName('Arial');
                        $normal->getFont()->setSize(7);

                        $sheet->setCellValue('H18', $rt);
                    }

                    // Shipping Payment Type -> 19/A–N merged => A19
                    // Value must be: "Shipping Payment Type: " + user input
                    $shipPaymentType = trim($_POST['ship_payment_type'] ?? '');
                    if ($shipPaymentType !== '') {
                        $sheet->setCellValue('A19', 'Shipping Payment Type: ' . strtoupper($shipPaymentType));
                    }

                    // ---------------------------
                    // 3) Write ALL commodity rows starting at row 23
                    // ---------------------------
                    $startCommodityRow = 23;
                    $numCommodities    = count($sliRows);
                    $aesFlag           = false;

                    // Snapshot style from row 23 so we can clone to later rows
                    $baseRowStyle = $sheet->getStyle('A23:N23');

                    $row = 0;
                    foreach ($sliRows as $i => $commodity) {
                        $row = $startCommodityRow + $i; // 23, 24, 25, ...
                        // For rows AFTER 23, clone style + set merges to match row 23
                        if ($row !== 23) {
                            foreach ($sheet->getMergeCells() as $merged) {
                                if (preg_match('/\d+/', $merged, $m)) {
                                    $rowInMerge = (int)$m[0];
                                    if ($rowInMerge === ($row)) {
                                        $sheet->unmergeCells($merged);
                                    }
                                }

                                $defaultStyle = $spreadsheet->getDefaultStyle();

                                $sheet->duplicateStyle($defaultStyle, "A{$row}:N{$row}");
                                // 3. Optionally clear all values
                                foreach (range('A', 'N') as $col) {
                                    $sheet->setCellValue("{$col}$row", null);
                                }
                                $sheet->getRowDimension($row)->setRowHeight(-1);
                            }

                            // Copy borders/font/fill/etc from row 23
                            $sheet->duplicateStyle($baseRowStyle, "A{$row}:N{$row}");

                            // Ensure the column merges match the template layout
                            // B,C,D merged; J,K,L merged
                            $sheet->mergeCells("B{$row}:D{$row}");
                            $sheet->mergeCells("J{$row}:L{$row}");
                        }

                        // For row 23 we assume the template already has:
                        // - B23:D23 merged
                        // - J23:L23 merged
                        // - Correct borders/fonts/etc.

                        // Commodity-specific data
                        // Column A: D or F
                        $sheet->setCellValue("A{$row}", $commodity['df']);

                        // Columns B,C,D: Schedule B (merged) -> write to B
                        $sheet->setCellValue("B{$row}", $commodity['schedule_b']);

                        // Column E: Net kg (rounded to nearest tenth)
                        $sheet->setCellValue("E{$row}", round($commodity['net_kg'], 1));
                        $sheet->getStyle("E{$row}")
                            ->getNumberFormat()
                            ->setFormatCode('#,##0.0');

                        // Column F: unit
                        $sheet->setCellValue("F{$row}", $commodity['unit']);

                        // Column G: Gross kg (rounded to nearest tenth)
                        $sheet->setCellValue("G{$row}", round($commodity['kg_g'], 1));
                        $sheet->getStyle("G{$row}")
                            ->getNumberFormat()
                            ->setFormatCode('#,##0.0');

                        // Column M: Total Commodity Value (currency)
                        $sheet->setCellValue("M{$row}", $commodity['total_value']);
                        $sheet->getStyle("M{$row}")
                            ->getNumberFormat()
                            ->setFormatCode('"$"#,##0.00');

                        // AES flag: any line > 2500
                        if ($commodity['total_value'] > 2500) {
                            $aesFlag = true;
                        }

                        // Fixed columns (same for all rows)
                        // H = EAR99
                        $sheet->setCellValue("H{$row}", 'EAR99');

                        // I = N
                        $sheet->setCellValue("I{$row}", 'N');

                        // JKL (merged) = NLR → write to J
                        $sheet->setCellValue("J{$row}", 'NLR');

                        // N = N/A
                        $sheet->setCellValue("N{$row}", 'N/A');
                    }

                    // How far we pushed the footer down
                    $offset = max(0, $numCommodities - 1);

                    // In the *template*, footer lives here (adjust if your template differs)
                    $footerSrcStart = 24; // first footer row in template
                    $footerSrcEnd   = 33; // last footer row in template

                    $footerDstStart = $footerSrcStart + $offset;
                    $footerDstEnd   = $footerSrcEnd + $offset;

                    // 1) Unmerge anything currently merged in the destination footer area
                    foreach ($sheet->getMergeCells() as $merged) {
                        if (preg_match('/([A-Z]+)(\d+):([A-Z]+)(\d+)/', $merged, $m)) {
                            $col1 = $m[1];
                            $row1 = (int)$m[2];
                            $col2 = $m[3];
                            $row2 = (int)$m[4];

                            // any overlap with our footer dest block?
                            if ($row2 < $footerDstStart || $row1 > $footerDstEnd) {
                                continue;
                            }
                            $sheet->unmergeCells($merged);
                        }
                    }

                    // 2) Copy styles, row heights, and values from ref_sheet footer block

                    $delta = $footerDstStart - $footerSrcStart;

                    // a) styles + row heights + values A–N for each row
                    for ($srcRow = $footerSrcStart; $srcRow <= $footerSrcEnd; $srcRow++) {
                        $dstRow = $srcRow + $delta;

                        // Copy style row-wide
                        $srcStyle = $ref_sheet->getStyle("A{$srcRow}:N{$srcRow}");
                        $sheet->duplicateStyle($srcStyle, "A{$dstRow}:N{$dstRow}");

                        // Copy row height
                        $srcHeight = $ref_sheet->getRowDimension($srcRow)->getRowHeight();
                        $sheet->getRowDimension($dstRow)->setRowHeight($srcHeight);

                        // Copy cell values (including any labels like 'Email', 'Shipper', etc.)
                        foreach (range('A', 'N') as $col) {
                            $val = $ref_sheet->getCell("{$col}{$srcRow}")->getValue();
                            $sheet->setCellValue("{$col}{$dstRow}", $val);
                        }
                    }

                    // b) Copy merged ranges from ref_sheet that belong to footer block
                    foreach ($ref_sheet->getMergeCells() as $mergedRef) {
                        if (preg_match('/([A-Z]+)(\d+):([A-Z]+)(\d+)/', $mergedRef, $m)) {
                            $col1 = $m[1];
                            $row1 = (int)$m[2];
                            $col2 = $m[3];
                            $row2 = (int)$m[4];

                            // Only care about merges that intersect the footer src block
                            if ($row2 < $footerSrcStart || $row1 > $footerSrcEnd) {
                                continue;
                            }

                            $newRow1 = $row1 + $delta;
                            $newRow2 = $row2 + $delta;

                            $newRange = "{$col1}{$newRow1}:{$col2}{$newRow2}";
                            $sheet->mergeCells($newRange);
                        }
                    }

                    $fix_row1 = 24 + $offset;
                    $sheet->getStyle("M{$fix_row1}")->getAlignment()
                        ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                    $sheet->getStyle("N{$fix_row1}")->getAlignment()
                        ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

                    $aes_row = 25 + $offset;
                    if (!$aesFlag) {
                        $rt = new RichText();

                        // Wingdings X
                        $wing = $rt->createTextRun('x');
                        $wing->getFont()->setName('Wingdings');
                        $wing->getFont()->setSize(8);

                        $sheet->setCellValue("A{$aes_row}", $rt);
                    }

                    $rt = new RichText();
                    // Space + label
                    $normal = $rt->createTextRun('32. Check here if there are any remaining non-licensable Schedule B / HTS Numbers that are valued $2500.00 or less and that do not otherwise require AES filing.');
                    $normal->getFont()->setName('Arial');
                    $normal->getFont()->setSize(7);
                    $sheet->setCellValue("B{$aes_row}", $rt);
                    $sheet->getStyle("B{$aes_row}")->getAlignment()
                        ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);

                    $email_phone_row = 28 + $offset;
                    $sheet->setCellValue("D{$email_phone_row}", $_POST['shipper_email'] ?? '');
                    $sheet->getStyle("D{$email_phone_row}")->getFont()->setSize(9);
                    $sheet->getStyle("D{$email_phone_row}")->getAlignment()
                        ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

                    // Name -> 29/H,I,J,K,L,M,N => H29
                    $name_row = 29 + $offset;
                    $sheet->setCellValue("H{$name_row}", $_POST['shipper_name'] ?? '');
                    $sheet->getStyle("H{$name_row}")->getAlignment()
                        ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

                    // Phone# -> 28/L,M,N => L28
                    // Format phone as (###)-###-####
                    $rawPhone = $_POST['shipper_phone'] ?? '';
                    $digits   = preg_replace('/\D+/', '', $rawPhone); // strip all non-digits

                    if (strlen($digits) === 10) {
                        // Format 10-digit US number
                        $formatted = sprintf(
                            '(%s) %s-%s',
                            substr($digits, 0, 3),
                            substr($digits, 3, 3),
                            substr($digits, 6)
                        );
                    } elseif (strlen($digits) === 11 && $digits[0] === '1') {
                        // If someone enters country code (1xxxxxxxxxx)
                        $formatted = sprintf(
                            '(%s) %s-%s',
                            substr($digits, 1, 3),
                            substr($digits, 4, 3),
                            substr($digits, 7)
                        );
                    } else {
                        // Not a standard US number → leave as-is
                        $formatted = $rawPhone;
                    }

                    $sheet->setCellValue("L{$email_phone_row}", $formatted);
                    $sheet->getStyle("L{$email_phone_row}")->getFont()->setSize(9);
                    $sheet->getStyle("L{$email_phone_row}")->getAlignment()
                        ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

                    // Row 30: "Shipper" label in I–L and today's date in N30
                    $title_date_row = 30 + $offset;
                    $sheet->setCellValue("I{$title_date_row}", 'Shipper');
                    $sheet->getStyle("I{$title_date_row}")->getAlignment()
                        ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

                    // Today's date in MM/DD/YYYY for N30
                    $today = (new DateTime())->format('m/d/Y');
                    $sheet->setCellValue("N{$title_date_row}", $today);
                    $sheet->getStyle("N{$title_date_row}")->getFont()->setSize(9);
                    $sheet->getStyle("N{$title_date_row}")->getAlignment()
                        ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

                    // ---------------------------
                    // 5) Stream workbook as download
                    // ---------------------------
                    // IMPORTANT: no HTML must be sent before this point.
                    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                    header('Content-Disposition: attachment; filename="SLI.xlsx"');
                    header('Cache-Control: max-age=0');

                    $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
                    $writer->save('php://output');
                    exit;
                }
            }
        }
    } elseif ($action === 'add_sku') {
        // 1) Read form fields
        $sku          = $_POST['sku']           ?? '';
        $uOfM         = $_POST['u_of_m']        ?? '';
        $mstrWeight   = $_POST['mstr_weight']   ?? '';
        $mstrQty      = $_POST['mstr_qty']      ?? '';
        $singleWeight = $_POST['single_weight'] ?? '';

        // basic sanity
        $sku          = trim($sku);
        $uOfM         = trim($uOfM);
        $mstrWeight   = (float)$mstrWeight;
        $mstrQty      = (int)$mstrQty;
        $singleWeight = (float)$singleWeight;

        // 2) Insert into DB
        require __DIR__ . '/../config.php';  // gives you $pdo

        $sql = "INSERT INTO sli (sku, single_weight, mstr_weight, mstr_qty, u_of_m)
                VALUES (:sku, :single_weight, :mstr_weight, :mstr_qty, :u_of_m)
                ON DUPLICATE KEY UPDATE
                    single_weight = VALUES(single_weight),
                    mstr_weight   = VALUES(mstr_weight),
                    mstr_qty      = VALUES(mstr_qty),
                    u_of_m        = VALUES(u_of_m)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':sku'           => $sku,
            ':single_weight' => $singleWeight,
            ':mstr_weight'   => $mstrWeight,
            ':mstr_qty'      => $mstrQty,
            ':u_of_m'        => strtoupper($uOfM),
        ]);

        // 3) Rebuild SLI using existing groups from session
        $groups = $_SESSION['invoice_groups'] ?? [];
        if (!empty($groups)) {
            [$sliRows, $sliMissingSkus] = build_sli_data($groups, $pdo);
            // If we've now satisfied all SKUs, auto-open wizard on this reload
            if (empty($sliMissingSkus) && !empty($sliRows)) {
                $showWizard = true;
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice → SLI Tool</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>
        :root {
            --bg-color: #000000;
            --card-bg: #111111;
            --accent: #ffffff;
            --accent-soft: #444444;
            --accent-strong: #ffffff;
            --danger: #bb4444;
            --success: #44bb66;
            --font-main: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg-color);
            color: var(--accent);
            font-family: var(--font-main);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .app-shell {
            width: 100%;
            max-width: 960px;
            padding: 24px;
        }

        .card {
            background: var(--card-bg);
            border-radius: 12px;
            border: 1px solid var(--accent-soft);
            padding: 20px 24px 24px;
            box-shadow: 0 0 18px rgba(0, 0, 0, 0.85);
        }

        h1 {
            margin: 0 0 4px;
            font-size: 1.7rem;
            letter-spacing: 0.03em;
        }

        .subtitle {
            margin: 0 0 18px;
            font-size: 0.9rem;
            color: #aaaaaa;
        }

        .section-title {
            font-size: 0.95rem;
            margin: 18px 0 8px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #bbbbbb;
        }

        .controls-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            margin-bottom: 14px;
        }

        .file-input-wrapper {
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .file-input-label {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 14px;
            border-radius: 999px;
            border: 1px solid var(--accent-soft);
            background: #181818;
            cursor: pointer;
            font-size: 0.9rem;
            white-space: nowrap;
        }

        .file-input-label span {
            margin-left: 6px;
        }

        .totals-row {
            font-weight: 600;
            border-top: 2px solid #555555;
            background: #141414;
        }

        input[type="file"] {
            position: absolute;
            left: 0;
            top: 0;
            width: 0.1px;
            height: 0.1px;
            opacity: 0;
            overflow: hidden;
            z-index: -1;
        }

        .file-hint {
            font-size: 0.8rem;
            color: #888888;
        }

        .button-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 8px;
        }

        button {
            border-radius: 999px;
            border: 1px solid var(--accent-soft);
            background: transparent;
            color: var(--accent);
            padding: 7px 14px;
            font-size: 0.9rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: background 0.12s ease, border-color 0.12s ease, transform 0.06s ease;
        }

        button:hover {
            background: #222222;
            border-color: var(--accent-strong);
        }

        button:active {
            transform: scale(0.97);
        }

        button.primary {
            background: #ffffff;
            color: #000000;
            border-color: #ffffff;
            font-weight: 600;
        }

        button.primary:hover {
            background: #f2f2f2;
        }

        button.danger {
            border-color: var(--danger);
            color: #ffdddd;
        }

        button.danger:hover {
            background: #2a1111;
        }

        button.secondary {
            border-style: dashed;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 20px;
            padding: 0 6px;
            height: 18px;
            border-radius: 999px;
            font-size: 0.75rem;
            background: #ffffff;
            color: #000;
        }

        .invoices-panel {
            margin-top: 16px;
            border-radius: 10px;
            border: 1px solid #333333;
            background: #080808;
            max-height: 360px;
            overflow: auto;
        }

        .invoices-header {
            display: grid;
            grid-template-columns: 1.5fr 0.8fr 0.7fr;
            gap: 10px;
            padding: 8px 12px;
            border-bottom: 1px solid #222222;
            font-size: 0.8rem;
            color: #aaaaaa;
        }

        .invoices-empty {
            padding: 16px 12px 18px;
            font-size: 0.9rem;
            color: #777777;
            text-align: center;
        }

        .invoice-row {
            display: grid;
            grid-template-columns: 1.5fr 0.8fr 0.7fr;
            gap: 10px;
            padding: 7px 12px;
            font-size: 0.88rem;
            border-bottom: 1px solid #141414;
            align-items: center;
        }

        .invoice-row:nth-child(odd) {
            background: #101010;
        }

        .invoice-name {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .invoice-size {
            color: #bbbbbb;
            font-variant-numeric: tabular-nums;
        }

        .invoice-type {
            color: #999999;
            font-size: 0.8rem;
        }

        .footer-hint {
            margin-top: 10px;
            font-size: 0.8rem;
            color: #777777;
        }

        .parsed-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            font-size: 0.85rem;
        }

        .parsed-table th,
        .parsed-table td {
            border: 1px solid #333333;
            padding: 4px 6px;
        }

        .parsed-table th {
            background: #181818;
        }

        @media (max-width: 640px) {
            .card {
                padding: 16px;
            }

            .invoices-header,
            .invoice-row {
                grid-template-columns: 2fr 1fr;
            }

            .invoice-type {
                display: none;
            }
        }

        button:disabled {
            opacity: 0.4;
            cursor: not-allowed;
            pointer-events: none;
        }

        button.primary:disabled {
            background: #555555;
            border-color: #555555;
            color: #222222;
        }
    </style>
</head>
<body>
<div class="app-shell">
    <div class="card">
        <h1>Invoice Loader</h1>
        <p class="subtitle">
            Upload commercial invoices, review the list, then build an SLI when you're ready.
        </p>

        <!-- Added form to send files to PHP when "Sort Invoice(s)" is clicked -->
        <form id="invoiceForm" method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" id="actionField" value="parse">
            <!-- new: wizard phase, defaults to "check" -->
            <input type="hidden" name="wizard_phase" id="wizardPhaseField" value="check">

            <div class="section-title">Upload</div>

            <div class="controls-row">
                <div class="file-input-wrapper">
                    <label for="invoiceFiles" class="file-input-label">
                        📄 <span>Upload Invoice(s)</span>
                    </label>
                    <input
                        id="invoiceFiles"
                        name="invoiceFiles[]"
                        type="file"
                        multiple
                        accept=".pdf"
                    />
                    <span class="file-hint">Select one or many PDF invoice files.</span>
                </div>
            </div>

            <div class="button-bar">
                <!-- Sort button now submits the form to trigger parsing -->
                <button type="submit" class="secondary" id="btnSort">
                    ⬍ Sort Invoice(s)
                </button>

                <button type="button" class="danger" id="btnReset">
                    ⟲ Reset
                </button>

                <?php
                // Enable Create SLI only if we have parsed/merged items
                $sessionGroups = $_SESSION['invoice_groups'] ?? [];
                $canCreateSli  = !empty($mergedItems) || !empty($sessionGroups);
                ?>
                <button
                    type="button"
                    class="primary"
                    id="btnCreateSli"
                    <?= $canCreateSli ? '' : 'disabled' ?>
                >
                    ➤ Create SLI
                </button>
            </div>

            <div class="section-title">Queued Invoices</div>

            <div class="invoices-panel" id="invoicesPanel">
                <div class="invoices-header">
                    <div>File Name</div>
                    <div>Size</div>
                    <div>Type</div>
                </div>
                <div class="invoices-empty" id="invoicesEmpty">
                    No invoices added yet. Use <strong>Upload Invoice(s)</strong> to begin.
                </div>
                <div id="invoicesBody"></div>
            </div>

            <?php if (!empty($groups)): ?>
                <div class="section-title">Parsed Data (Grouped by Schedule B &amp; Country)</div>

                <?php
                // Build a flat list of tables: all US first, then all Non-US
                $tables = [];

                // 1) All US tables
                foreach ($groups as $schedule => $byOrigin) {
                    if (isset($byOrigin['US'])) {
                        $tables[] = [
                            'schedule' => $schedule,
                            'bucket'   => 'US',
                            'items'    => $byOrigin['US'],
                        ];
                    }
                }

                // 2) All Non-US tables
                foreach ($groups as $schedule => $byOrigin) {
                    if (isset($byOrigin['Non-US'])) {
                        $tables[] = [
                            'schedule' => $schedule,
                            'bucket'   => 'Non-US',
                            'items'    => $byOrigin['Non-US'],
                        ];
                    }
                }
                ?>

                <?php foreach ($tables as $table): ?>
                    <?php
                    $schedule = $table['schedule'];
                    $bucket   = $table['bucket'];
                    $items    = $table['items'];

                    // Compute totals for this table
                    $totalQty = 0;
                    $totalVal = 0.0;
                    foreach ($items as $it) {
                        $totalQty += (int)$it['quantity'];
                        $totalVal += (float)$it['total_price'];
                    }

                    $titleSuffix = ($bucket === 'US') ? ' - D' : ' - F';
                    ?>
                    <h3><?= htmlspecialchars($schedule . $titleSuffix) ?></h3>

                    <table class="parsed-table">
                        <thead>
                        <tr>
                            <th>Quantity</th>
                            <th>SKU</th>
                            <th>Desc</th>
                            <th>Country of Origin</th>
                            <th>Unit Value</th>
                            <th>Total Value</th>
                            <th>Schedule B</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><?= htmlspecialchars((string)$item['quantity']) ?></td>
                                <td><?= htmlspecialchars($item['sku']) ?></td>
                                <td><?= htmlspecialchars($item['description']) ?></td>
                                <td><?= htmlspecialchars($item['origin']) ?></td>
                                <td><?= htmlspecialchars(number_format($item['unit_price'], 2)) ?></td>
                                <td><?= htmlspecialchars(number_format($item['total_price'], 2)) ?></td>
                                <td><?= htmlspecialchars($item['schedule_b']) ?></td>
                            </tr>
                        <?php endforeach; ?>

                        <tr class="totals-row">
                            <td><?= htmlspecialchars((string)$totalQty) ?></td>
                            <td></td>
                            <td>TOTAL</td>
                            <td></td>
                            <td></td>
                            <td><?= htmlspecialchars(number_format($totalVal, 2)) ?></td>
                            <td></td>
                        </tr>
                        </tbody>
                    </table>
                <?php endforeach; ?>

            <?php elseif (!empty($parsedResults)): ?>
                <div class="section-title">Parsed Data</div>
                <p>No items were parsed from the uploaded PDFs.</p>
            <?php endif; ?>

            <?php if (!empty($sliMissingSkus)): ?>
                <?php $currentSku = $sliMissingSkus[0]; ?>

                <div
                    id="skuModalBackdrop"
                    style="
                        position: fixed;
                        inset: 0;
                        background: rgba(0,0,0,0.7);
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        z-index: 999;
                    "
                >
                    <div
                        style="
                            background: #111;
                            border-radius: 12px;
                            border: 1px solid #444;
                            padding: 20px 24px;
                            width: 100%;
                            max-width: 420px;
                            box-shadow: 0 0 25px rgba(0,0,0,0.9);
                        "
                    >
                        <h2 style="margin-top:0; font-size:1.1rem;">Missing SKU Data</h2>
                        <p style="font-size:0.9rem; color:#ccc;">
                            This SKU is missing from the <code>sli</code> table.
                            Please enter the details and click <strong>Save</strong>.
                        </p>

                        <div
                            style="
                                display:flex;
                                flex-direction:column;
                                gap:8px;
                                margin-top:10px;
                            "
                        >
                            <label style="font-size:0.85rem;">
                                SKU<br>
                                <input
                                    type="text"
                                    name="sku"
                                    id="skuField"
                                    value="<?= htmlspecialchars($currentSku) ?>"
                                    readonly
                                    style="
                                        width:100%;
                                        padding:6px 8px;
                                        border-radius:6px;
                                        border:1px solid #444;
                                        background:#222;
                                        color:#fff;
                                    "
                                />
                            </label>

                            <label style="font-size:0.85rem;">
                                Single Weight or M2<br>
                                <input
                                    type="number"
                                    step="0.0001"
                                    name="single_weight"
                                    id="singleWeightField"
                                    placeholder="From Units of Measure or Eship in the Item Card"
                                    style="
                                        width:100%;
                                        padding:6px 8px;
                                        border-radius:6px;
                                        border:1px solid #444;
                                        background:#222;
                                        color:#fff;
                                    "
                                />
                            </label>

                            <label style="font-size:0.85rem;">
                                Master Weight (Master carton weight)<br>
                                <input
                                    type="number"
                                    step="0.0001"
                                    name="mstr_weight"
                                    id="mstrWeightField"
                                    placeholder="From Units of Measure in the Item Card"
                                    style="
                                        width:100%;
                                        padding:6px 8px;
                                        border-radius:6px;
                                        border:1px solid #444;
                                        background:#222;
                                        color:#fff;
                                    "
                                />
                            </label>

                            <label style="font-size:0.85rem;">
                                Master Quantity (units per master carton)<br>
                                <input
                                    type="number"
                                    step="1"
                                    name="mstr_qty"
                                    id="mstrQtyField"
                                    placeholder="e.g. 144, 72, 288"
                                    style="
                                        width:100%;
                                        padding:6px 8px;
                                        border-radius:6px;
                                        border:1px solid #444;
                                        background:#222;
                                        color:#fff;
                                    "
                                />
                            </label>

                            <label style="font-size:0.85rem;">
                                Unit of Measure<br>
                                <input
                                    type="text"
                                    name="u_of_m"
                                    id="uOfMField"
                                    placeholder="e.g. KG, M2, NO, X, DOZ"
                                    style="
                                        width:100%;
                                        padding:6px 8px;
                                        border-radius:6px;
                                        border:1px solid #444;
                                        background:#222;
                                        color:#fff;
                                    "
                                />
                            </label>
                        </div>

                        <div
                            style="
                                display:flex;
                                justify-content:flex-end;
                                gap:8px;
                                margin-top:16px;
                            "
                        >
                            <button type="button" id="btnSkuCancel" class="secondary">
                                Cancel
                            </button>
                            <button type="button" id="btnSkuSave" class="primary">
                                Save
                            </button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php /* === SLI Wizard Modal (always present) === */ ?>
            <div
                id="sliWizardBackdrop"
                style="
                    position: fixed;
                    inset: 0;
                    background: rgba(0,0,0,0.7);
                    display: none;
                    align-items: center;
                    justify-content: center;
                    z-index: 997;
                "
            >
                <div
                    style="
                        background: #111;
                        border-radius: 12px;
                        border: 1px solid #444;
                        padding: 20px 24px;
                        width: 100%;
                        max-width: 520px;
                        max-height: 80vh;
                        box-shadow: 0 0 25px rgba(0,0,0,0.9);
                        display: flex;
                        flex-direction: column;
                    "
                >
                    <h2 id="wizardTitle" style="margin-top:0; font-size:1.1rem;">
                        Forwarder Info
                    </h2>
                    <p id="wizardSubtitle" style="font-size:0.9rem; color:#ccc; margin-bottom:10px;">
                        Enter the forwarder’s information. You can skip if you don’t need it.
                    </p>

                    <div id="wizardStepForwarder" style="flex:1; overflow:auto;">
                        <div style="display:flex; flex-direction:column; gap:8px; margin-top:8px;">
                            <label style="font-size:0.85rem;">
                                Forwarder Name<br>
                                <input
                                    type="text"
                                    name="forwarder_name"
                                    style="width:100%; padding:6px 8px; border-radius:6px;
                                           border:1px solid #444; background:#222; color:#fff;"
                                />
                            </label>
                            <label style="font-size:0.85rem;">
                                Address 1<br>
                                <input
                                    type="text"
                                    name="forwarder_addr1"
                                    style="width:100%; padding:6px 8px; border-radius:6px;
                                           border:1px solid #444; background:#222; color:#fff;"
                                />
                            </label>
                            <label style="font-size:0.85rem;">
                                Address 2<br>
                                <input
                                    type="text"
                                    name="forwarder_addr2"
                                    style="width:100%; padding:6px 8px; border-radius:6px;
                                           border:1px solid #444; background:#222; color:#fff;"
                                />
                            </label>
                            <label style="font-size:0.85rem;">
                                Address 3<br>
                                <input
                                    type="text"
                                    name="forwarder_addr3"
                                    style="width:100%; padding:6px 8px; border-radius:6px;
                                           border:1px solid #444; background:#222; color:#fff;"
                                />
                            </label>
                            <label style="font-size:0.85rem;">
                                City, State, Zip<br>
                                <input
                                    type="text"
                                    name="forwarder_city_state_zip"
                                    style="width:100%; padding:6px 8px; border-radius:6px;
                                           border:1px solid #444; background:#222; color:#fff;"
                                />
                            </label>
                        </div>
                    </div>

                    <div id="wizardStepConsignee" style="flex:1; overflow:auto; display:none;">
                        <div style="display:flex; flex-direction:column; gap:8px; margin-top:8px;">
                            <label style="font-size:0.85rem;">
                                Consignee Name<br>
                                <input
                                    type="text"
                                    name="consignee_name"
                                    style="width:100%; padding:6px 8px; border-radius:6px;
                                           border:1px solid #444; background:#222; color:#fff;"
                                />
                            </label>
                            <label style="font-size:0.85rem;">
                                Address 1<br>
                                <input
                                    type="text"
                                    name="consignee_addr1"
                                    style="width:100%; padding:6px 8px; border-radius:6px;
                                           border:1px solid #444; background:#222; color:#fff;"
                                />
                            </label>
                            <label style="font-size:0.85rem;">
                                Address 2<br>
                                <input
                                    type="text"
                                    name="consignee_addr2"
                                    style="width:100%; padding:6px 8px; border-radius:6px;
                                           border:1px solid #444; background:#222; color:#fff;"
                                />
                            </label>
                            <label style="font-size:0.85rem;">
                                City, State, Zip<br>
                                <input
                                    type="text"
                                    name="consignee_city_state_zip"
                                    style="width:100%; padding:6px 8px; border-radius:6px;
                                           border:1px solid #444; background:#222; color:#fff;"
                                />
                            </label>
                        </div>
                    </div>

                    <div id="wizardStepOrder" style="flex:1; overflow:auto; display:none;">
                        <div style="display:flex; flex-direction:column; gap:8px; margin-top:8px;">
                            <label style="font-size:0.85rem;">
                                SO#<br>
                                <input
                                    type="text"
                                    name="so_number"
                                    style="width:100%; padding:6px 8px; border-radius:6px;
                                           border:1px solid #444; background:#222; color:#fff;"
                                />
                            </label>
                            <label style="font-size:0.85rem;">
                                Country of Ultimate Destination (2-letter code)<br>
                                <input
                                    type="text"
                                    name="destination_country"
                                    maxlength="2"
                                    style="width:100%; padding:6px 8px; border-radius:6px;
                                           border:1px solid #444; background:#222; color:#fff; text-transform:uppercase;"
                                />
                            </label>
                            <label style="font-size:0.85rem;">
                                HAZ (Yes/No)<br>
                                <select
                                    name="haz"
                                    style="width:100%; padding:6px 8px; border-radius:6px;
                                           border:1px solid #444; background:#222; color:#fff;"
                                >
                                    <option value="">-- Select --</option>
                                    <option value="Yes">Yes</option>
                                    <option value="No">No</option>
                                </select>
                            </label>
                            <label style="font-size:0.85rem;">
                                Ocean or Air<br>
                                <select
                                    name="ship_mode"
                                    style="width:100%; padding:6px 8px; border-radius:6px;
                                           border:1px solid #444; background:#222; color:#fff;"
                                >
                                    <option value="">-- Select --</option>
                                    <option value="Ocean">Ocean</option>
                                    <option value="Air">Air</option>
                                </select>
                            </label>
                            <label style="font-size:0.85rem;">
                                Shipping Payment Type<br>
                                <input
                                    type="text"
                                    name="ship_payment_type"
                                    placeholder="e.g. Prepaid, Collect, 3rd Party"
                                    style="width:100%; padding:6px 8px; border-radius:6px;
                                           border:1px solid #444; background:#222; color:#fff;"
                                />
                            </label>
                        </div>
                    </div>

                    <div id="wizardStepShipper" style="flex:1; overflow:auto; display:none;">
                        <div style="display:flex; flex-direction:column; gap:8px; margin-top:8px;">
                            <label style="font-size:0.85rem;">
                                Email Address<br>
                                <input
                                    type="email"
                                    name="shipper_email"
                                    style="width:100%; padding:6px 8px; border-radius:6px;
                                           border:1px solid #444; background:#222; color:#fff;"
                                />
                            </label>
                            <label style="font-size:0.85rem;">
                                Name<br>
                                <input
                                    type="text"
                                    name="shipper_name"
                                    style="width:100%; padding:6px 8px; border-radius:6px;
                                           border:1px solid #444; background:#222; color:#fff;"
                                />
                            </label>
                            <label style="font-size:0.85rem;">
                                Phone #<br>
                                <input
                                    type="text"
                                    name="shipper_phone"
                                    style="width:100%; padding:6px 8px; border-radius:6px;
                                           border:1px solid #444; background:#222; color:#fff;"
                                />
                            </label>
                        </div>
                    </div>

                    <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:16px;">
                        <button type="button" id="wizardBackBtn" class="secondary" style="display:none;">
                            Back
                        </button>
                        <button type="button" id="wizardCancelBtn" class="secondary">
                            Cancel
                        </button>
                        <button type="button" id="wizardSkipBtn">
                            Skip
                        </button>
                        <button type="button" id="wizardNextBtn" class="primary">
                            Next
                        </button>
                        <button type="button" id="wizardCreateBtn" class="primary" style="display:none;">
                            Create
                        </button>
                    </div>
                </div>
            </div>

        </form>
    </div>
</div>

<script>
    // Basic in-memory list of selected invoice files.
    let invoiceFiles = [];

    const inputFiles    = document.getElementById('invoiceFiles');
    const invoicesBody  = document.getElementById('invoicesBody');
    const invoicesEmpty = document.getElementById('invoicesEmpty');
    const invoiceCount  = document.getElementById('invoiceCount');
    const btnReset      = document.getElementById('btnReset');
    const btnSort       = document.getElementById('btnSort');
    const btnCreateSli  = document.getElementById('btnCreateSli');
    const form          = document.getElementById('invoiceForm');
    const actionField   = document.getElementById('actionField');
    const wizardPhaseField = document.getElementById('wizardPhaseField');

    // -----------------------------
    // PIN handling for adding SKUs
    // -----------------------------
    const VALID_PINS = ['9875', '2112', '1411'];
    let skuPinOk = false;

    function ensureSkuPinOk(onSuccess) {
        // If already validated in this page load, just go
        if (skuPinOk) {
            onSuccess();
            return;
        }

        // If we previously saved a valid pin in localStorage, reuse it
        const storedPin = localStorage.getItem('sliSkuPin');
        if (storedPin && VALID_PINS.includes(storedPin)) {
            skuPinOk = true;
            onSuccess();
            return;
        }

        // Otherwise, ask the user once
        const pin = window.prompt('Enter PIN to save SKU:');

        // User cancelled
        if (pin === null) {
            return;
        }

        if (VALID_PINS.includes(pin)) {
            skuPinOk = true;
            // Persist for future page loads on this browser
            localStorage.setItem('sliSkuPin', pin);
            onSuccess();
        } else {
            alert('Ask Sean for help');
        }
    }

    function formatSize(bytes) {
        if (!bytes && bytes !== 0) return '';
        const units = ['B', 'KB', 'MB', 'GB'];
        let u       = 0;
        let value   = bytes;
        while (value >= 1024 && u < units.length - 1) {
            value /= 1024;
            u++;
        }
        return value.toFixed(value >= 10 || u === 0 ? 0 : 1) + ' ' + units[u];
    }

    function refreshInvoiceList() {
        invoicesBody.innerHTML = '';

        if (invoiceFiles.length === 0) {
            invoicesEmpty.style.display = 'block';
        } else {
            invoicesEmpty.style.display = 'none';
        }

        invoiceFiles.forEach(file => {
            const row = document.createElement('div');
            row.className = 'invoice-row';

            const nameEl = document.createElement('div');
            nameEl.className = 'invoice-name';
            nameEl.textContent = file.name;

            const sizeEl = document.createElement('div');
            sizeEl.className = 'invoice-size';
            sizeEl.textContent = formatSize(file.size);

            const typeEl = document.createElement('div');
            typeEl.className = 'invoice-type';
            typeEl.textContent = file.type || 'Unknown';

            row.appendChild(nameEl);
            row.appendChild(sizeEl);
            row.appendChild(typeEl);

            invoicesBody.appendChild(row);
        });

        if (invoiceCount) {
            invoiceCount.textContent = String(invoiceFiles.length);
        }
    }

    // Handle file selection (multiple or single).
    inputFiles.addEventListener('change', (event) => {
        const files = Array.from(event.target.files || []);
        if (!files.length) {
            return;
        }

        invoiceFiles = invoiceFiles.concat(files);

        // Rebuild the actual <input> FileList so the form submits ALL files
        const dt = new DataTransfer();
        invoiceFiles.forEach(file => dt.items.add(file));
        inputFiles.files = dt.files;
        refreshInvoiceList();
    });

    // Reset button: clear everything (client-side only).
    btnReset.addEventListener('click', () => {
        // Clear client-side file list
        invoiceFiles = [];

        const dt = new DataTransfer();
        inputFiles.files = dt.files;

        // Reload with reset flag so PHP clears session + disables Create SLI
        const baseUrl = window.location.pathname;
        window.location.href = baseUrl + '?reset=1';
    });

    // Sort button: make sure action is "parse"
    btnSort.addEventListener('click', () => {
        if (actionField) {
            actionField.value = 'parse';
        }
        // default submit behavior will happen because button type="submit"
    });

    // Create SLI button:
    //  - First submits with action='sli', phase='check' to validate SKUs.
    //  - Server either shows add_sku modal (if missing) or auto opens wizard (if all good).
    btnCreateSli.addEventListener('click', () => {
        if (btnCreateSli.disabled) {
            return;
        }
        if (actionField) {
            actionField.value = 'sli';
        }
        if (wizardPhaseField) {
            wizardPhaseField.value = 'check';
        }
        form.submit();
    });

    // Guard: don't submit if no files selected when parsing.
    form.addEventListener('submit', (event) => {
        if (actionField && actionField.value === 'parse') {
            if (!inputFiles.files || inputFiles.files.length === 0) {
                event.preventDefault();
                alert('Please choose at least one PDF before parsing.');
            }
        }
    });

    // SKU modal controls (they only exist when there are missing SKUs)
    const skuModalBackdrop = document.getElementById('skuModalBackdrop');
    const btnSkuCancel     = document.getElementById('btnSkuCancel');
    const btnSkuSave       = document.getElementById('btnSkuSave');

    if (btnSkuCancel && skuModalBackdrop) {
        btnSkuCancel.addEventListener('click', (e) => {
            e.preventDefault();
            // Just hide the modal; you could also redirect/reload if you want.
            skuModalBackdrop.style.display = 'none';
        });
    }

    if (btnSkuSave) {
        btnSkuSave.addEventListener('click', (e) => {
            e.preventDefault();

            ensureSkuPinOk(() => {
                if (actionField) {
                    actionField.value = 'add_sku';
                }
                // phase value is irrelevant for add_sku
                form.submit();
            });
        });
    }

    // -----------------------------
    // SLI Wizard logic
    // -----------------------------
    const wizardBackdrop   = document.getElementById('sliWizardBackdrop');
    const wizardTitle      = document.getElementById('wizardTitle');
    const wizardSubtitle   = document.getElementById('wizardSubtitle');
    const stepForwarder    = document.getElementById('wizardStepForwarder');
    const stepConsignee    = document.getElementById('wizardStepConsignee');
    const stepOrder        = document.getElementById('wizardStepOrder');
    const stepShipper      = document.getElementById('wizardStepShipper');
    const wizardBackBtn    = document.getElementById('wizardBackBtn');
    const wizardCancelBtn  = document.getElementById('wizardCancelBtn');
    const wizardSkipBtn    = document.getElementById('wizardSkipBtn');
    const wizardNextBtn    = document.getElementById('wizardNextBtn');
    const wizardCreateBtn  = document.getElementById('wizardCreateBtn');

    let wizardStep = 1; // 1: Forwarder, 2: Consignee, 3: Order, 4: Shipper

    function updateWizardUi() {
        // Show/hide step bodies
        stepForwarder.style.display = (wizardStep === 1) ? 'block' : 'none';
        stepConsignee.style.display = (wizardStep === 2) ? 'block' : 'none';
        stepOrder.style.display     = (wizardStep === 3) ? 'block' : 'none';
        stepShipper.style.display   = (wizardStep === 4) ? 'block' : 'none';

        // Titles & instructions
        if (wizardStep === 1) {
            wizardTitle.textContent    = 'Forwarder Info';
            wizardSubtitle.textContent = 'Enter the forwarder’s information. You can skip if you don’t need it.';
            wizardSkipBtn.style.display = 'inline-flex'; // default
        } else if (wizardStep === 2) {
            wizardTitle.textContent    = 'Consignee Info';
            wizardSubtitle.textContent = 'Enter the consignee’s information. (Required)';
            wizardSkipBtn.style.display = 'none';  // Hide Skip on step 2
        } else if (wizardStep === 3) {
            wizardTitle.textContent    = 'Order Info. (Required)';
            wizardSubtitle.textContent = 'Enter general order details.';
            wizardSkipBtn.style.display = 'none';  // also required
        } else if (wizardStep === 4) {
            wizardTitle.textContent    = 'Shipper Info. (Required)';
            wizardSubtitle.textContent = 'Who is preparing/shipping this order?';
            wizardSkipBtn.style.display = 'none';  // also required
        }

        // Buttons: Back / Next / Create
        wizardBackBtn.style.display   = (wizardStep > 1) ? 'inline-flex' : 'none';
        wizardNextBtn.style.display   = (wizardStep < 4) ? 'inline-flex' : 'none';
        wizardCreateBtn.style.display = (wizardStep === 4) ? 'inline-flex' : 'none';
        // Cancel always visible
    }

    function openSliWizard() {
        wizardStep = 1;
        wizardBackdrop.style.display = 'flex';
        updateWizardUi();
    }

    function closeSliWizard() {
        wizardBackdrop.style.display = 'none';
    }

    if (wizardCancelBtn) {
        wizardCancelBtn.addEventListener('click', (e) => {
            e.preventDefault();
            closeSliWizard(); // Abort, do NOT submit
        });
    }

    // Skip now only really used on step 1; if ever used on final step,
    // treat like "Create" but with empty forwarder.
    if (wizardSkipBtn) {
        wizardSkipBtn.addEventListener('click', (e) => {
            e.preventDefault();
            if (wizardStep < 4) {
                wizardStep++;
                updateWizardUi();
            } else {
                if (actionField) {
                    actionField.value = 'sli';
                }
                if (wizardPhaseField) {
                    wizardPhaseField.value = 'final';
                }
                closeSliWizard();
                form.submit();
            }
        });
    }

    if (wizardNextBtn) {
        wizardNextBtn.addEventListener('click', (e) => {
            // STEP 2 REQUIRED FIELDS
            if (wizardStep === 2) {
                const name = document.querySelector('input[name="consignee_name"]').value.trim();
                const addr1 = document.querySelector('input[name="consignee_addr1"]').value.trim();
                const cityzip = document.querySelector('input[name="consignee_city_state_zip"]').value.trim();

                if (!name || !addr1 || !cityzip) {
                    alert('Please fill out the required consignee fields.');
                    return;
                }
            }

            // STEP 3 REQUIRED FIELDS
            if (wizardStep === 3) {
                const so = document.querySelector('input[name="so_number"]').value.trim();
                const dest = document.querySelector('input[name="destination_country"]').value.trim();
                const haz = document.querySelector('select[name="haz"]').value.trim();
                const mode = document.querySelector('select[name="ship_mode"]').value.trim();
                const pay = document.querySelector('input[name="ship_payment_type"]').value.trim();

                if (!so || !dest || !haz || !mode || !pay) {
                    alert('Please fill out all required order fields.');
                    return;
                }
            }

            e.preventDefault();
            if (wizardStep < 4) {
                wizardStep++;
                updateWizardUi();
            }
        });
    }

    if (wizardBackBtn) {
        wizardBackBtn.addEventListener('click', (e) => {
            e.preventDefault();
            if (wizardStep > 1) {
                wizardStep--;
                updateWizardUi();
            }
        });
    }

    if (wizardCreateBtn) {
        wizardCreateBtn.addEventListener('click', (e) => {
            // STEP 4 REQUIRED FIELDS
            const email = document.querySelector('input[name="shipper_email"]').value.trim();
            const name = document.querySelector('input[name="shipper_name"]').value.trim();
            const phone = document.querySelector('input[name="shipper_phone"]').value.trim();

            if (!email || !name || !phone) {
                alert('Please fill out all required shipper fields.');
                return;
            }

            e.preventDefault();
            if (actionField) {
                actionField.value = 'sli';
            }
            if (wizardPhaseField) {
                wizardPhaseField.value = 'final';
            }
            closeSliWizard();
            form.submit();
        });
    }

    // Initial render
    refreshInvoiceList();

    <?php if (!empty($showWizard)): ?>
    // Server signaled that all SKUs are satisfied and SLI rows exist:
    // auto-open the wizard after load.
    openSliWizard();
    <?php endif; ?>
</script>
</body>
</html>

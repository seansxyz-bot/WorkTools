<?php
// editskus.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require __DIR__ . '/../config.php'; // gives you $pdo

// Prevent caching so you always see fresh data
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$message = '';
$error   = '';

// If redirected here with a message, pick it up
if (isset($_GET['msg']) && $_GET['msg'] !== '') {
    $message = $_GET['msg'];
}
if (isset($_GET['err']) && $_GET['err'] !== '') {
    $error = $_GET['err'];
}

// Handle row actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $sku    = trim($_POST['sku'] ?? '');

    if ($sku === '') {
        $error = 'Missing SKU in request.';
        // Redirect to avoid form resubmission on refresh
        header('Location: ' . $_SERVER['PHP_SELF'] . '?err=' . urlencode($error));
        exit;
    } else {
        if ($action === 'update_row') {
            $uOfM         = trim($_POST['u_of_m'] ?? '');
            $singleWeight = $_POST['single_weight'] ?? '';
            $mstrWeight   = $_POST['mstr_weight']   ?? '';
            $mstrQty      = $_POST['mstr_qty']      ?? '';

            // Allow empty -> null, otherwise cast
            $singleWeight = ($singleWeight === '') ? null : (float)$singleWeight;
            $mstrWeight   = ($mstrWeight   === '') ? null : (float)$mstrWeight;
            $mstrQty      = ($mstrQty      === '') ? null : (int)$mstrQty;

            try {
                $sql = "
                    UPDATE sli
                    SET
                        single_weight = :single_weight,
                        mstr_weight   = :mstr_weight,
                        mstr_qty      = :mstr_qty,
                        u_of_m        = :u_of_m
                    WHERE sku = :sku
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':sku', $sku, PDO::PARAM_STR);
                $stmt->bindValue(':u_of_m', $uOfM, PDO::PARAM_STR);

                // Bind nullable numerics
                if ($singleWeight === null) {
                    $stmt->bindValue(':single_weight', null, PDO::PARAM_NULL);
                } else {
                    $stmt->bindValue(':single_weight', $singleWeight);
                }

                if ($mstrWeight === null) {
                    $stmt->bindValue(':mstr_weight', null, PDO::PARAM_NULL);
                } else {
                    $stmt->bindValue(':mstr_weight', $mstrWeight);
                }

                if ($mstrQty === null) {
                    $stmt->bindValue(':mstr_qty', null, PDO::PARAM_NULL);
                } else {
                    $stmt->bindValue(':mstr_qty', $mstrQty, PDO::PARAM_INT);
                }

                $stmt->execute();
                $message = "Updated SKU {$sku}.";
                // Redirect after POST so refresh doesn’t re-submit
                header('Location: ' . $_SERVER['PHP_SELF'] . '?msg=' . urlencode($message));
                exit;

            } catch (Exception $e) {
                $error = 'DB error while updating: ' . $e->getMessage();
                header('Location: ' . $_SERVER['PHP_SELF'] . '?err=' . urlencode($error));
                exit;
            }

        } elseif ($action === 'delete_row') {
            try {
                $stmt = $pdo->prepare("DELETE FROM sli WHERE sku = :sku");
                $stmt->bindValue(':sku', $sku, PDO::PARAM_STR);
                $stmt->execute();
                $message = "Removed SKU {$sku}.";
                header('Location: ' . $_SERVER['PHP_SELF'] . '?msg=' . urlencode($message));
                exit;
            } catch (Exception $e) {
                $error = 'DB error while deleting: ' . $e->getMessage();
                header('Location: ' . $_SERVER['PHP_SELF'] . '?err=' . urlencode($error));
                exit;
            }
        } else {
            // Unknown action -> just bounce back
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
    }
}

// Fetch all SKUs (this runs on every GET after redirect or a direct load)
$rows = [];
try {
    $stmt = $pdo->query("
        SELECT sku, single_weight, mstr_weight, mstr_qty, u_of_m
        FROM sli
        ORDER BY sku
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = 'DB error while loading SKUs: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit SKUs</title>
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
            max-width: 980px;
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
            margin: 0 0 6px;
            font-size: 1.6rem;
            letter-spacing: 0.03em;
        }

        .subtitle {
            margin: 0 0 16px;
            font-size: 0.9rem;
            color: #aaaaaa;
        }

        .status {
            margin-bottom: 10px;
            font-size: 0.85rem;
            padding: 6px 10px;
            border-radius: 6px;
        }

        .status.ok {
            background: rgba(68, 187, 102, 0.18);
            border: 1px solid var(--success);
        }

        .status.err {
            background: rgba(187, 68, 68, 0.18);
            border: 1px solid var(--danger);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            font-size: 0.85rem;
        }

        th, td {
            border: 1px solid #333333;
            padding: 4px 6px;
            vertical-align: middle;
        }

        th {
            background: #181818;
            position: sticky;
            top: 0;
            z-index: 2;
        }

        tbody tr:nth-child(odd) {
            background: #101010;
        }

        input[type="text"],
        input[type="number"] {
            width: 100%;
            padding: 3px 5px;
            border-radius: 4px;
            border: 1px solid #444;
            background: #222;
            color: #fff;
            font-size: 0.8rem;
        }

        input[readonly] {
            background: #181818;
            color: #ccc;
        }

        button {
            border-radius: 999px;
            border: 1px solid var(--accent-soft);
            background: transparent;
            color: var(--accent);
            padding: 4px 10px;
            font-size: 0.8rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
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

        .scroll-wrap {
            max-height: 70vh;
            overflow: auto;
            margin-top: 8px;
        }

        .sku-col {
            white-space: nowrap;
        }
    </style>
</head>
<body>
<div class="app-shell">
    <div class="card">
        <h1>SKU Editor</h1>
        <p class="subtitle">
            View and update weights / units for items in <code>sli</code>.
        </p>

        <?php if ($message): ?>
            <div class="status ok"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="status err"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (empty($rows)): ?>
            <p>No SKUs found in <code>sli</code>.</p>
        <?php else: ?>
            <div class="scroll-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Single Weight / M2</th>
                        <th>Master Weight</th>
                        <th>Master Qty</th>
                        <th>Unit of Measure</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <form method="post">
                                <td class="sku-col">
                                    <input
                                        type="text"
                                        name="sku"
                                        value="<?= htmlspecialchars($r['sku']) ?>"
                                        readonly
                                    />
                                </td>
                                <td>
                                    <input
                                        type="number"
                                        step="0.0001"
                                        name="single_weight"
                                        value="<?= htmlspecialchars($r['single_weight']) ?>"
                                    />
                                </td>
                                <td>
                                    <input
                                        type="number"
                                        step="0.0001"
                                        name="mstr_weight"
                                        value="<?= htmlspecialchars($r['mstr_weight']) ?>"
                                    />
                                </td>
                                <td>
                                    <input
                                        type="number"
                                        step="1"
                                        name="mstr_qty"
                                        value="<?= htmlspecialchars($r['mstr_qty']) ?>"
                                    />
                                </td>
                                <td>
                                    <input
                                        type="text"
                                        name="u_of_m"
                                        value="<?= htmlspecialchars($r['u_of_m']) ?>"
                                    />
                                </td>
                                <td style="text-align:center; white-space:nowrap;">
                                    <button
                                        type="submit"
                                        name="action"
                                        value="update_row"
                                        class="primary"
                                    >
                                        Update
                                    </button>
                                    <button
                                        type="submit"
                                        name="action"
                                        value="delete_row"
                                        class="danger"
                                        onclick="return confirm('Remove this SKU?');"
                                    >
                                        Remove
                                    </button>
                                </td>
                            </form>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
